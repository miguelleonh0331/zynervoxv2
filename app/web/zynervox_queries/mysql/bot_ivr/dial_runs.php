<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait DialRunQueries {
    private function dialMutationGuard(int $listId): void {
        $s=$this->db->prepare("SELECT run_id FROM zynervox_bot_dial_runs WHERE list_id=? AND state IN ('starting','running','stopping') LIMIT 1");$s->execute([$listId]);if($s->fetchColumn())throw new \RuntimeException('Deten la marcacion antes de modificar la lista.');
    }
    private function dialCandidates(array $run,bool $count=false): array {
        $cfg=json_decode($run['config_json'],true,512,JSON_THROW_ON_ERROR);$statuses=$cfg['statuses'];
        // Política apagada (default) o conteo agregado de "pending": camino
        // idéntico al existente antes del motor de reintentos, sin tocarlo.
        if(!$count && !empty($cfg['retry_policy_enabled'])){
            $chosen=$this->selectWeightedStatus((int)$run['campaign_id'],(int)$run['list_id'],$statuses);
            if($chosen!==null)$statuses=[$chosen];
        }
        $in=implode(',',array_fill(0,count($statuses),'?'));
        $select=$count?'COUNT(*)':'l.lead_id,l.phone';
        $sql="SELECT $select FROM zynervox_bot_list l WHERE l.list_id=? AND l.status IN ($in) AND l.retry_excluded_at IS NULL AND (l.next_call_at IS NULL OR l.next_call_at<=UTC_TIMESTAMP(6)) AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts a WHERE a.lead_id=l.lead_id AND (a.dial_run_id=? OR (a.finished_at IS NULL AND a.end_source<>'RECOVERY' AND a.status<>'LOST')))";
        if(!$count)$sql.=' ORDER BY '.($cfg['order']==='RANDOM'?'RAND()':('l.lead_id '.($cfg['order']==='DOWN'?'DESC':'ASC'))).' LIMIT 1 FOR UPDATE';
        return [$sql,array_merge([$run['list_id']],$statuses,[$run['run_id']])];
    }
    // Selección ponderada (weighted round-robin) entre los estados con regla
    // habilitada y candidatos vencidos. Es una PROBE de elegibilidad, no la
    // selección final: el SELECT ... FOR UPDATE de dialCandidates() vuelve a
    // aplicar la barrera completa (intento abierto/mismo run) después de
    // elegir el estado, así que nunca duplica un claim aunque la probe sea
    // optimista. Un estado sin candidatos vencidos no bloquea a los demás
    // (su peso simplemente no participa en ese ciclo).
    // Un estado configurado en dial_statuses pero SIN regla explícita en
    // zynervox_bot_retry_rules (típicamente NEW, el estado inicial) recibe
    // un peso base por defecto: la política de reintentos gobierna cómo se
    // priorizan los reintentos, nunca debe bloquear la marcación inicial de
    // leads nuevos solo porque el admin no creó una fila de regla para ellos.
    // Una regla explícita con enabled=0 o weight=0 sí excluye ese estado.
    private const DEFAULT_STATUS_WEIGHT = 50;
    private function selectWeightedStatus(int $campaignId,int $listId,array $statuses): ?string {
        $rules=[];foreach($this->retryRules($campaignId) as $r)$rules[$r['status']]=$r;
        $candidates=[];
        foreach($statuses as $status){
            if(isset($rules[$status])){
                if(!$rules[$status]['enabled'])continue;
                $weight=(int)$rules[$status]['weight'];if($weight<=0)continue;
            } else {
                $weight=self::DEFAULT_STATUS_WEIGHT;
            }
            $s=$this->db->prepare("SELECT 1 FROM zynervox_bot_list WHERE list_id=? AND status=? AND retry_excluded_at IS NULL AND (next_call_at IS NULL OR next_call_at<=UTC_TIMESTAMP(6)) LIMIT 1");
            $s->execute([$listId,$status]);
            if($s->fetchColumn())$candidates[$status]=$weight;
        }
        if(!$candidates)return null;
        $total=array_sum($candidates);$pick=random_int(1,$total);
        foreach($candidates as $status=>$weight){$pick-=$weight;if($pick<=0)return $status;}
        return array_key_first($candidates);
    }
    public function retryRules(int $campaignId): array {
        $s=$this->db->prepare('SELECT * FROM zynervox_bot_retry_rules WHERE campaign_id=? ORDER BY status');
        $s->execute([$campaignId]);
        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function upsertRetryRule(int $campaignId, string $status, array $values): void {
        if($campaignId<1 || !preg_match('/^[A-Z0-9_]{1,80}$/D',$status))throw new \RuntimeException('Regla de reintento inválida.');
        $enabled=(int)!empty($values['enabled']);
        $weight=max(0,min(100000,(int)($values['weight']??0)));
        $wait=max(0,min(43200,(int)($values['wait_minutes']??0)));
        $max=isset($values['max_attempts']) && $values['max_attempts']!==null && $values['max_attempts']!==''?max(1,min(1000,(int)$values['max_attempts'])):null;
        $effective=(int)!empty($values['is_effective_contact']);
        $terminal=(int)!empty($values['is_terminal']);
        $this->db->prepare('INSERT INTO zynervox_bot_retry_rules(campaign_id,status,enabled,weight,wait_minutes,max_attempts,is_effective_contact,is_terminal,updated_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),weight=VALUES(weight),wait_minutes=VALUES(wait_minutes),max_attempts=VALUES(max_attempts),is_effective_contact=VALUES(is_effective_contact),is_terminal=VALUES(is_terminal),updated_at=UTC_TIMESTAMP()')
            ->execute([$campaignId,$status,$enabled,$weight,$wait,$max,$effective,$terminal]);
    }
    public function deleteRetryRule(int $campaignId, string $status): void {
        $this->db->prepare('DELETE FROM zynervox_bot_retry_rules WHERE campaign_id=? AND status=?')->execute([$campaignId,$status]);
    }
    // Decisión de reintento al cerrar un intento (resultado definitivo del
    // flujo). Reutiliza EXACTAMENTE el mismo patrón de idempotencia que
    // finishCall()/savePreanswerResult() (NOT EXISTS newer.attempt_id>?):
    // un cierre tardío nunca pisa la agenda de un intento más nuevo.
    private function applyRetryDecision(int $leadId,int $attemptId,string $status): void {
        $s=$this->db->prepare('SELECT p.campaign_id,c.retry_policy_enabled,c.max_attempts_global,c.max_attempts_window_days,c.max_attempts_window_count FROM zynervox_bot_list l JOIN zynervox_bot_lists p ON p.list_id=l.list_id JOIN zynervox_bot_campaigns c ON c.campaign_id=p.campaign_id WHERE l.lead_id=?');
        $s->execute([$leadId]);$ctx=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$ctx || !$ctx['retry_policy_enabled'])return; // política apagada: comportamiento actual, sin next_call_at.
        $s=$this->db->prepare('SELECT * FROM zynervox_bot_retry_rules WHERE campaign_id=? AND status=?');
        $s->execute([(int)$ctx['campaign_id'],$status]);$rule=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$rule || !$rule['enabled'] || $rule['is_terminal']){
            $this->db->prepare('UPDATE zynervox_bot_list SET retry_excluded_at=UTC_TIMESTAMP() WHERE lead_id=? AND retry_excluded_at IS NULL AND NOT EXISTS(SELECT 1 FROM zynervox_bot_call_attempts newer WHERE newer.lead_id=? AND newer.attempt_id>?)')->execute([$leadId,$leadId,$attemptId]);
            return;
        }
        $s=$this->db->prepare('SELECT retry_attempt_count,phone FROM zynervox_bot_list WHERE lead_id=?');$s->execute([$leadId]);$lead=$s->fetch(\PDO::FETCH_ASSOC);
        if(!$lead)return;
        $count=(int)$lead['retry_attempt_count']+1;
        $exceeded=($rule['max_attempts']!==null && $count>=(int)$rule['max_attempts'])
            || ($ctx['max_attempts_global']!==null && $count>=(int)$ctx['max_attempts_global']);
        if(!$exceeded && $ctx['max_attempts_window_count']!==null && $ctx['max_attempts_window_days']!==null){
            $s=$this->db->prepare('SELECT COUNT(*) FROM zynervox_bot_call_attempts a JOIN zynervox_bot_list l ON l.lead_id=a.lead_id WHERE l.phone=? AND a.started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? DAY)');
            $s->execute([$lead['phone'],(int)$ctx['max_attempts_window_days']]);
            if((int)$s->fetchColumn()>=(int)$ctx['max_attempts_window_count'])$exceeded=true;
        }
        if($exceeded){
            $this->db->prepare('UPDATE zynervox_bot_list SET retry_attempt_count=?,retry_excluded_at=UTC_TIMESTAMP() WHERE lead_id=? AND NOT EXISTS(SELECT 1 FROM zynervox_bot_call_attempts newer WHERE newer.lead_id=? AND newer.attempt_id>?)')->execute([$count,$leadId,$leadId,$attemptId]);
            return;
        }
        $this->db->prepare('UPDATE zynervox_bot_list SET retry_attempt_count=?,next_call_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE) WHERE lead_id=? AND NOT EXISTS(SELECT 1 FROM zynervox_bot_call_attempts newer WHERE newer.lead_id=? AND newer.attempt_id>?)')->execute([$count,(int)$rule['wait_minutes'],$leadId,$leadId,$attemptId]);
    }
    public function createDialRun(int $listId,int $campaignId,string $runId,int $channels,array $config,string $actor): void {
        if(!preg_match('/^[a-f0-9]{32}$/D',$runId) || !in_array($channels,[1,3,5,10,20,25,30,35,40,50,100],true) || empty($config['statuses']) || count($config['statuses'])>64 || !in_array($config['order'],['UP','DOWN','RANDOM'],true) || !preg_match('/^[0-9]{1,20}$/D',$config['prefix']) || (int)$config['flow_id']<1)throw new \RuntimeException('Configuracion de marcacion invalida.');
        foreach($config['statuses'] as $status)if(!preg_match('/^[A-Z0-9_]{1,80}$/D',$status) || $status==='CALL')throw new \RuntimeException('Estado de marcacion no disponible.');
        $this->callAtomic(function()use($listId,$campaignId,$runId,$channels,$config,$actor){
            $s=$this->db->prepare('SELECT l.active,l.id_flujo,l.dial_prefix,l.call_channels,c.active campaign_active,c.retry_policy_enabled FROM zynervox_bot_lists l JOIN zynervox_bot_campaigns c ON c.campaign_id=l.campaign_id WHERE l.list_id=? AND l.campaign_id=? FOR UPDATE');$s->execute([$listId,$campaignId]);$list=$s->fetch();
            if(!$list || !$list['active'] || !$list['campaign_active'] || (int)$list['id_flujo']!==(int)$config['flow_id'] || $list['dial_prefix']!==$config['prefix'])throw new \RuntimeException('La lista o su origen cambiaron. Recarga la pagina.');
            if ((int)$list['call_channels']!==$channels) throw new \RuntimeException('Los canales cambiaron. Recarga la pagina.');
            $s=$this->db->prepare("SELECT run_id FROM zynervox_bot_dial_runs WHERE list_id=? AND state IN ('starting','running','stopping')");$s->execute([$listId]);if($s->fetchColumn())throw new \RuntimeException('La lista ya tiene una marcacion activa.');
            // retry_policy_enabled nunca viene del cliente: se toma en vivo de
            // la campaña para que no pueda falsificarse desde el POST de Play.
            $config['retry_policy_enabled']=(bool)$list['retry_policy_enabled'];
            $this->db->prepare("INSERT INTO zynervox_bot_dial_runs(run_id,list_id,campaign_id,state,channels,dial_prefix,flow_id,config_json,created_by,created_at,updated_at) VALUES (?,?,?,'starting',?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))")->execute([$runId,$listId,$campaignId,$channels,$config['prefix'],$config['flow_id'],json_encode($config,JSON_THROW_ON_ERROR),substr($actor,0,80)]);
        });
    }
    public function dialRun(int $listId,int $campaignId,?string $runId=null): ?array {
        $list=$this->list($listId,$campaignId);$sql='SELECT * FROM zynervox_bot_dial_runs WHERE list_id=? AND campaign_id=?';$args=[$listId,$campaignId];if($runId!==null){$sql.=' AND run_id=?';$args[]=$runId;}$sql.=' ORDER BY created_at DESC,run_id DESC LIMIT 1';
        $s=$this->db->prepare($sql);$s->execute($args);$run=$s->fetch(\PDO::FETCH_ASSOC);if(!$run)return null;
        if(in_array($run['state'],['starting','running','stopping'],true))$run['channels']=(int)$list['call_channels'];
        $s=$this->db->prepare("SELECT COUNT(*) launched,COALESCE(SUM(finished_at IS NOT NULL OR end_source='RECOVERY'),0) completed,COALESCE(SUM(finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST'),0) active,COALESCE(SUM(status IN ('FAIL','UNAV','CONG','LOST') OR end_source='RECOVERY'),0) failed FROM zynervox_bot_call_attempts WHERE dial_run_id=?");$s->execute([$run['run_id']]);foreach($s->fetch() as $k=>$v)if(is_string($k))$run[$k]=(int)$v;
        $s=$this->db->prepare("SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE list_id=? AND finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST'");$s->execute([$listId]);$run['active_list']=(int)$s->fetchColumn();
        [$sql,$args]=$this->dialCandidates($run,true);$s=$this->db->prepare($sql);$s->execute($args);$run['pending']=(int)$s->fetchColumn();
        return $run;
    }
    public function transitionDialRun(string $runId,string $state,string $error=''): void {
        $this->callAtomic(function()use($runId,$state,$error){$s=$this->db->prepare('SELECT state FROM zynervox_bot_dial_runs WHERE run_id=? FOR UPDATE');$s->execute([$runId]);$old=$s->fetchColumn();if(!$old)throw new \RuntimeException('Marcacion no encontrada.');if($old===$state)return;
            if($state==='running' && $old==='stopping')return;
            if($state==='completed' && $old==='stopping')$state='stopped';
            if($state==='stopping' && in_array($old,['stopped','completed','failed'],true))return;
            $allowed=['starting'=>['running','stopping','failed'],'running'=>['stopping','completed','failed'],'stopping'=>['stopped','failed']];if(!in_array($state,$allowed[$old]??[],true))throw new \RuntimeException('Transicion de marcacion invalida.');
            $this->db->prepare("UPDATE zynervox_bot_dial_runs SET state=?,error=?,updated_at=UTC_TIMESTAMP(6),finished_at=IF(? IN ('completed','stopped','failed'),UTC_TIMESTAMP(6),NULL) WHERE run_id=?")->execute([$state,substr($error,0,255),$state,$runId]);
        });
    }
    public function claimDialLead(string $runId,string $callId): ?array {
        return $this->callAtomic(function()use($runId,$callId){$s=$this->db->prepare('SELECT * FROM zynervox_bot_dial_runs WHERE run_id=? FOR UPDATE');$s->execute([$runId]);$run=$s->fetch(\PDO::FETCH_ASSOC);if(!$run || $run['state']!=='running')return null;
            $s=$this->db->prepare('SELECT l.id_flujo,l.dial_prefix,l.active,l.call_channels,c.active campaign_active FROM zynervox_bot_lists l JOIN zynervox_bot_campaigns c ON c.campaign_id=l.campaign_id WHERE l.list_id=? AND l.campaign_id=? FOR UPDATE');$s->execute([$run['list_id'],$run['campaign_id']]);$list=$s->fetch();
            if(!$list || !$list['active'] || !$list['campaign_active'] || (int)$list['id_flujo']!==(int)$run['flow_id'] || $list['dial_prefix']!==$run['dial_prefix'])throw new \RuntimeException('Configuracion cambiada durante la marcacion.');
            $s=$this->db->prepare("SELECT COUNT(*) FROM zynervox_bot_call_attempts WHERE list_id=? AND finished_at IS NULL AND end_source<>'RECOVERY' AND status<>'LOST'");$s->execute([$run['list_id']]);if((int)$s->fetchColumn()>=(int)$list['call_channels'])return null;
            [$sql,$args]=$this->dialCandidates($run);$s=$this->db->prepare($sql);$s->execute($args);$lead=$s->fetch(\PDO::FETCH_ASSOC);if(!$lead)return null;
            $id=$this->startCall((int)$run['list_id'],$lead['phone'],$callId);if($id!==(int)$lead['lead_id'])throw new \RuntimeException('Identidad de lead ambigua.');
            $this->db->prepare('UPDATE zynervox_bot_call_attempts SET dial_run_id=?,origin_prefix=? WHERE call_id=?')->execute([$runId,$run['dial_prefix'],$callId]);
            return ['call_id'=>$callId,'lead_id'=>$id,'phone'=>$lead['phone'],'list_id'=>(int)$run['list_id']];
        });
    }
    public function rejectDialClaim(string $runId,string $callId): void {
        $this->callAtomic(function()use($runId,$callId){$s=$this->db->prepare('SELECT caller_channel,finished_at FROM zynervox_bot_call_attempts WHERE call_id=? AND dial_run_id=? FOR UPDATE');$s->execute([$callId,$runId]);$call=$s->fetch();if(!$call || $call['caller_channel']!=='' || $call['finished_at']!==null)return;
            $this->finishCall($callId,'CHANUNAVAIL','','',0,false);
            $this->db->prepare("UPDATE zynervox_bot_call_attempts SET end_source='AMI_REJECTED',duration_seconds=GREATEST(0,TIMESTAMPDIFF(SECOND,started_at,finished_at)),billsec=0 WHERE call_id=?")->execute([$callId]);
            $this->recordCallEvent($callId,'DIAL_REJECTED',hash('sha256',$callId.'|DIAL_REJECTED'),time(),['reason'=>'AMI rejected originate']);
        });
    }
}
