<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait CampaignQueries {
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
