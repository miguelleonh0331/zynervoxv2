<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait CampaignQueries {
    public function campaignMonitor(): array {
        $sql="SELECT c.campaign_id,c.name,c.active,l.list_id,l.name list_name,r.state,CASE WHEN r.state IN ('starting','running','stopping') THEN l.call_channels ELSE r.channels END channels,r.error,
            COALESCE(p.total,0) total,COALESCE(p.calling,0) calling,COALESCE(p.finished,0) finished
            FROM zynervox_bot_campaigns c LEFT JOIN zynervox_bot_lists l ON l.campaign_id=c.campaign_id AND l.active=1
            LEFT JOIN zynervox_bot_dial_runs r ON r.run_id=(SELECT x.run_id FROM zynervox_bot_dial_runs x WHERE x.list_id=l.list_id ORDER BY x.created_at DESC,x.run_id DESC LIMIT 1)
            LEFT JOIN (SELECT d.list_id,COUNT(*) total,
                SUM(a.attempt_id IS NOT NULL AND a.finished_at IS NULL AND a.end_source<>'RECOVERY' AND a.status<>'LOST') calling,
                SUM(a.finished_at IS NOT NULL OR a.end_source='RECOVERY' OR a.status='LOST') finished
                FROM zynervox_bot_list d LEFT JOIN zynervox_bot_call_attempts a ON a.attempt_id=(SELECT MAX(x.attempt_id) FROM zynervox_bot_call_attempts x WHERE x.lead_id=d.lead_id AND x.list_id=d.list_id)
                GROUP BY d.list_id) p ON p.list_id=l.list_id WHERE c.active=1 ORDER BY c.campaign_id,l.list_id";
        $out=[];$priority=['idle'=>0,'completed'=>1,'stopped'=>2,'failed'=>3,'stopping'=>4,'starting'=>5,'running'=>6];
        foreach($this->db->query($sql)->fetchAll(\PDO::FETCH_ASSOC) as $row){
            $id=(int)$row['campaign_id'];if(!isset($out[$id]))$out[$id]=['campaign_id'=>$id,'name'=>$row['name'],'origin'=>'bot_ivr','active'=>(bool)$row['active'],'state'=>'idle','total'=>0,'calling'=>0,'finished'=>0,'lists'=>[],'states'=>[]];
            if($row['list_id']===null)continue;
            $state=$row['state']??'idle';if(($priority[$state]??0)>$priority[$out[$id]['state']])$out[$id]['state']=$state;
            foreach(['total','calling','finished'] as $key)$out[$id][$key]+=(int)$row[$key];
            $out[$id]['lists'][]=['list_id'=>(int)$row['list_id'],'name'=>$row['list_name'],'state'=>$state,'channels'=>(int)$row['channels'],'total'=>(int)$row['total'],'calling'=>(int)$row['calling'],'finished'=>(int)$row['finished'],'error'=>$row['error']??''];
        }
        $states=$this->db->query("SELECT l.campaign_id,d.status,COUNT(*) count FROM zynervox_bot_list d JOIN zynervox_bot_lists l ON l.list_id=d.list_id JOIN zynervox_bot_campaigns c ON c.campaign_id=l.campaign_id WHERE c.active=1 AND l.active=1 GROUP BY l.campaign_id,d.status ORDER BY count DESC,d.status");
        foreach($states->fetchAll(\PDO::FETCH_ASSOC) as $row){$id=(int)$row['campaign_id'];if(isset($out[$id]))$out[$id]['states'][]=['status'=>$row['status'],'count'=>(int)$row['count']];}
        return array_values($out);
    }
    public function campaigns(): array {
        return $this->db->query('SELECT c.*, (SELECT COUNT(*) FROM zynervox_bot_lists l WHERE l.campaign_id=c.campaign_id) lists_count FROM zynervox_bot_campaigns c ORDER BY c.campaign_id DESC')->fetchAll();
    }
    public function campaign(int $id): array {
        $stmt = $this->db->prepare('SELECT * FROM zynervox_bot_campaigns WHERE campaign_id=:id');
        $stmt->execute([':id'=>$id]);
        $row = $stmt->fetch();
        if (!$row) throw new \RuntimeException('Campaña inexistente.');
        return $row;
    }
    public function createCampaign(string $name, bool $active): int {
        $this->db->prepare('INSERT INTO zynervox_bot_campaigns (name,active) VALUES (:name,:active)')->execute([':name'=>$name, ':active'=>(int)$active]);
        return (int)$this->db->lastInsertId();
    }
    public function deactivateCampaign(int $id): void {
        if ($id < 1) throw new \RuntimeException('ID de campaña inválido.');
        // Own transaction ensures the activity snapshot starts after the campaign lock.
        if ($this->db->inTransaction()) throw new \RuntimeException('La desactivación requiere una transacción independiente.');
        $this->callAtomic(function() use ($id) {
            $s = $this->db->prepare('SELECT campaign_id FROM zynervox_bot_campaigns WHERE campaign_id=? FOR UPDATE');
            $s->execute([$id]);
            if (!$s->fetchColumn()) throw new \RuntimeException('Campaña inexistente.');
            // Do not lock runs/lists here: their producers already hold them before the campaign.
            $s = $this->db->prepare("SELECT run_id FROM zynervox_bot_dial_runs WHERE campaign_id=? AND state IN ('starting','running','stopping') LIMIT 1");
            $s->execute([$id]);
            if ($s->fetchColumn()) throw new \RuntimeException('Detén la marcación y espera a que termine antes de desactivar la campaña.');
            $s = $this->db->prepare("SELECT a.attempt_id FROM zynervox_bot_call_attempts a JOIN zynervox_bot_lists l ON l.list_id=a.list_id WHERE l.campaign_id=? AND a.finished_at IS NULL AND a.end_source<>'RECOVERY' AND a.status<>'LOST' LIMIT 1");
            $s->execute([$id]);
            if ($s->fetchColumn()) throw new \RuntimeException('Espera a que finalicen las llamadas antes de desactivar la campaña.');
            $this->db->prepare('UPDATE zynervox_bot_campaigns SET active=0 WHERE campaign_id=?')->execute([$id]);
        });
    }
    public function updateCampaign(int $id, array $values): void {
        // Fixed allowlist: identifiers never come from a request.
        $columns = ['name','active','scheduled','start_time','end_time','campaign_description','user_group','dial_method','lead_order','dial_statuses','hopper_level','auto_dial_level','dial_timeout','dial_prefix','campaign_cid','campaign_recording','max_channels'];
        $assignments = [];
        $bindings = [':id'=>$id];
        foreach ($columns as $key) {
            if (!array_key_exists($key, $values)) throw new \RuntimeException('Falta un campo de campaña validado.');
            $assignments[] = $key.'=:'.$key;
            $bindings[':'.$key] = $values[$key];
        }
        $this->db->prepare('UPDATE zynervox_bot_campaigns SET '.implode(',', $assignments).' WHERE campaign_id=:id')->execute($bindings);
    }
}
