<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait LeadQueries {
    public function leadCount(int $listId): int {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM zynervox_bot_list WHERE list_id=:id');
        $stmt->execute([':id'=>$listId]);
        return (int)$stmt->fetchColumn();
    }
    public function importLeads(int $listId, int $campaignId, array $parsed): array {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            // Serialize imports for the same list, preserving existing contacts.
            $lock = $this->db->prepare('SELECT list_id FROM zynervox_bot_lists WHERE list_id=:list AND campaign_id=:campaign FOR UPDATE');
            $lock->execute([':list'=>$listId, ':campaign'=>$campaignId]);
            if (!$lock->fetch()) throw new \RuntimeException('La lista no pertenece a esta campaña.');
            $existing = [];
            foreach (array_chunk(array_column($parsed['rows'], 'phone'), 500) as $phones) {
                $stmt = $this->db->prepare('SELECT phone FROM zynervox_bot_list WHERE list_id=? AND phone IN ('.implode(',', array_fill(0, count($phones), '?')).')');
                $stmt->execute(array_merge([$listId], $phones));
                while (($phone = $stmt->fetchColumn()) !== false) $existing[(string)$phone] = true;
            }
            $saved = 0;
            $duplicates = (int)$parsed['duplicates'];
            foreach (array_chunk($parsed['rows'], 100) as $batch) {
                $values = [];
                $bindings = [];
                foreach ($batch as $row) {
                    if (isset($existing[$row['phone']])) { $duplicates++; continue; }
                    $existing[$row['phone']] = true;
                    $values[] = '(?,?,?,?)';
                    array_push($bindings, $listId, $row['phone'], $row['customer_name'], json_encode($row['extra'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                }
                if ($values) {
                    $this->db->prepare('INSERT INTO zynervox_bot_list (list_id,phone,customer_name,extra_json) VALUES '.implode(',', $values))->execute($bindings);
                    $saved += count($values);
                }
            }
            if ($ownsTransaction) $this->db->commit();
            return ['saved'=>$saved, 'duplicates'=>$duplicates, 'rejected'=>(int)$parsed['rejected'], 'errors'=>$parsed['errors']];
        } catch (\Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
