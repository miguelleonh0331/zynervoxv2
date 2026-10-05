<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait ListQueries {
    public function lists(int $campaignId): array {
        $stmt = $this->db->prepare('SELECT l.*, (SELECT COUNT(*) FROM zynervox_bot_list d WHERE d.list_id=l.list_id) leads_count FROM zynervox_bot_lists l WHERE l.campaign_id=:id ORDER BY l.list_id');
        $stmt->execute([':id'=>$campaignId]);
        return $stmt->fetchAll();
    }
    public function list(int $listId, int $campaignId): array {
        $stmt = $this->db->prepare('SELECT l.*,c.name campaign_name FROM zynervox_bot_lists l JOIN zynervox_bot_campaigns c ON c.campaign_id=l.campaign_id WHERE l.list_id=:list AND l.campaign_id=:campaign');
        $stmt->execute([':list'=>$listId, ':campaign'=>$campaignId]);
        $row = $stmt->fetch();
        if (!$row) throw new \RuntimeException('La lista no existe o no pertenece a esta campaña.');
        return $row;
    }
    public function createList(int $campaignId, string $name, bool $active): int {
        $this->campaign($campaignId);
        $this->db->prepare('INSERT INTO zynervox_bot_lists (campaign_id,name,active) VALUES (:campaign,:name,:active)')->execute([':campaign'=>$campaignId, ':name'=>$name, ':active'=>(int)$active]);
        return (int)$this->db->lastInsertId();
    }
    public function updateList(int $campaignId, int $listId, string $name, bool $active): void {
        $this->list($listId, $campaignId);
        $this->db->prepare('UPDATE zynervox_bot_lists SET name=:name,active=:active WHERE list_id=:list AND campaign_id=:campaign')->execute([':name'=>$name, ':active'=>(int)$active, ':list'=>$listId, ':campaign'=>$campaignId]);
    }
}
