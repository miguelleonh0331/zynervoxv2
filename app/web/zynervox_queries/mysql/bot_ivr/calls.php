<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait CallQueries {
    private function callAtomic(callable $operation) {
        $outer=$this->db->inTransaction();
        if ($outer) $this->db->exec('SAVEPOINT call_tracking'); else $this->db->beginTransaction();
        try {
            $result=$operation();
            if ($outer) $this->db->exec('RELEASE SAVEPOINT call_tracking'); else $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($outer) $this->db->exec('ROLLBACK TO SAVEPOINT call_tracking'); elseif ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
    public function startCall(int $listId, string $phone, string $callId): int {
        if ($listId<1 || !preg_match('/^[0-9]{1,20}$/D',$phone) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$callId)) throw new \RuntimeException('Invalid call identity');
        return $this->callAtomic(function() use($listId,$phone,$callId) {
            $s=$this->db->prepare('SELECT list_id FROM zynervox_bot_lists WHERE list_id=? FOR UPDATE'); $s->execute([$listId]);
            if (!$s->fetchColumn()) throw new \RuntimeException('List not found');
            $s=$this->db->prepare('SELECT lead_id,list_id,phone FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE'); $s->execute([$callId]); $old=$s->fetch();
            if ($old) {
                if ((int)$old['list_id']!==$listId || $old['phone']!==$phone) throw new \RuntimeException('Call ID mismatch');
                return (int)$old['lead_id'];
            }
            $s=$this->db->prepare('SELECT lead_id FROM zynervox_bot_list WHERE list_id=? AND phone=? ORDER BY lead_id LIMIT 1 FOR UPDATE'); $s->execute([$listId,$phone]); $lead=$s->fetchColumn();
            if (!$lead) {
                $this->db->prepare('INSERT INTO zynervox_bot_list(list_id,phone) VALUES (?,?)')->execute([$listId,$phone]);
                $lead=$this->db->lastInsertId();
            }
            $this->db->prepare('INSERT INTO zynervox_bot_call_attempts(call_id,list_id,lead_id,phone,started_at) VALUES (?,?,?,?,UTC_TIMESTAMP())')->execute([$callId,$listId,$lead,$phone]);
            $this->db->prepare("UPDATE zynervox_bot_list SET called_count=called_count+1,last_call_at=UTC_TIMESTAMP(),status='CALL' WHERE lead_id=?")->execute([$lead]);
            return (int)$lead;
        });
    }
    public function finishCall(string $callId, string $dialStatus, string $amdStatus, string $amdCause, int $hangupCause, bool $answered): void {
        $this->callAtomic(function() use($callId,$dialStatus,$amdStatus,$amdCause,$hangupCause,$answered) {
            $s=$this->db->prepare('SELECT * FROM zynervox_bot_call_attempts WHERE call_id=? FOR UPDATE'); $s->execute([$callId]); $call=$s->fetch();
            if (!$call) throw new \RuntimeException('Attempt not found');
            if ($call['finished_at']!==null) return;
            $status=$amdStatus==='MACHINE' ? 'AA' : (($answered || $dialStatus==='ANSWER') ? 'ANSWER' : (['BUSY'=>'B','NOANSWER'=>'NA','CHANUNAVAIL'=>'UNAV','CONGESTION'=>'CONG','CANCEL'=>'CANCEL'][ $dialStatus ] ?? 'FAIL'));
            $this->db->prepare('UPDATE zynervox_bot_call_attempts SET status=?,dial_status=?,amd_status=?,amd_cause=?,hangup_cause=?,finished_at=UTC_TIMESTAMP() WHERE call_id=?')->execute([$status,substr($dialStatus,0,20),substr($amdStatus,0,20),substr($amdCause,0,160),$hangupCause,$callId]);
            // A late callback cannot overwrite a newer attempt for this lead.
            $this->db->prepare('UPDATE zynervox_bot_list l SET status=? WHERE lead_id=? AND NOT EXISTS (SELECT 1 FROM zynervox_bot_call_attempts a WHERE a.lead_id=l.lead_id AND a.attempt_id>?)')->execute([$status,$call['lead_id'],$call['attempt_id']]);
        });
    }
}
