<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait PreanswerQueries {
    public function preanswerCall(string $callId): ?array {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$callId)) throw new \InvalidArgumentException('Invalid call ID');
        $s=$this->db->prepare("SELECT a.*,COALESCE((SELECT JSON_UNQUOTE(JSON_EXTRACT(e.payload_json,'$.audio_bytes')) FROM zynervox_bot_call_events e WHERE e.call_id=a.call_id AND e.event_type='PREANSWER_CAPTURE' ORDER BY e.occurred_at DESC,e.received_at DESC LIMIT 1),0) preanswer_bytes FROM zynervox_bot_call_attempts a WHERE a.call_id=? AND a.origin_prefix='7307'");
        $s->execute([$callId]);$row=$s->fetch(\PDO::FETCH_ASSOC);return $row?:null;
    }
    public function pendingPreanswerCalls(int $limit=200): array {
        if ($limit<1 || $limit>1000) throw new \InvalidArgumentException('Invalid pending limit');
        $s=$this->db->prepare("SELECT a.call_id FROM zynervox_bot_call_attempts a WHERE a.origin_prefix='7307' AND a.answered_at IS NULL AND a.dial_status<>'ANSWER' AND a.status<>'LOST' AND a.end_source<>'RECOVERY' AND a.started_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 180 SECOND) AND EXISTS(SELECT 1 FROM zynervox_bot_call_events e WHERE e.call_id=a.call_id AND e.event_type='PREANSWER_START') AND NOT EXISTS(SELECT 1 FROM zynervox_bot_call_events e WHERE e.call_id=a.call_id AND e.event_type IN ('PREANSWER_RESULT','PREANSWER_DONE')) ORDER BY COALESCE((SELECT MAX(e.occurred_at) FROM zynervox_bot_call_events e WHERE e.call_id=a.call_id AND e.event_type='PREANSWER_CAPTURE'),a.started_at),a.attempt_id LIMIT ".$limit);
        $s->execute();return $s->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function savePreanswerResult(string $callId,string $eventId,int $epoch,array $data): bool {
        $statuses=['casilla'=>'PREANSWER_CASILLA','numero_no_existe'=>'PREANSWER_NUMERO_NO_EXISTE','fuera_servicio'=>'PREANSWER_FUERA_SERVICIO'];
        $category=$data['category']??'';
        if (!isset($statuses[$category]) || !is_string($data['text']??null) || strlen($data['text'])>8000 || !preg_match('/^[a-f0-9]{64}$/D',$eventId) || $epoch<1) throw new \InvalidArgumentException('Invalid preanswer classification');
        return $this->callAtomic(function() use($callId,$eventId,$epoch,$data,$category,$statuses) {
            $s=$this->db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE');$s->execute([$callId]);$call=$s->fetch(\PDO::FETCH_ASSOC);
            if (!$call || $call['origin_prefix']!=='7307' || $call['answered_at']!==null || $call['dial_status']==='ANSWER' || $call['end_source']==='RECOVERY' || $call['status']==='LOST') return false;
            // A provider response received after ANSWER must never claim the call.
            $s=$this->db->prepare("SELECT event_id FROM zynervox_bot_call_events WHERE call_id=? AND event_type='PREANSWER_RESULT' LIMIT 1");$s->execute([$callId]);if($s->fetchColumn())return false;
            $this->recordCallEvent($callId,'PREANSWER_RESULT',$eventId,$epoch,$data);
            $this->db->prepare('UPDATE zynervox_bot_call_attempts SET amd_status=?,amd_cause=? WHERE call_id=?')->execute(['PREANSWER',strtoupper($category),$callId]);
            if ($call['finished_at']!==null) {
                $this->db->prepare('UPDATE zynervox_bot_call_attempts SET status=? WHERE call_id=?')->execute([$statuses[$category],$callId]);
                $this->db->prepare('UPDATE zynervox_bot_list SET status=? WHERE lead_id=? AND NOT EXISTS(SELECT 1 FROM zynervox_bot_call_attempts newer WHERE newer.lead_id=? AND newer.attempt_id>?)')->execute([$statuses[$category],$call['lead_id'],$call['lead_id'],$call['attempt_id']]);
            }
            return $call['finished_at']===null;
        });
    }
}
