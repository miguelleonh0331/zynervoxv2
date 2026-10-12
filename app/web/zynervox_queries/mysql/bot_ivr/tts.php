<?php
declare(strict_types=1);
namespace ZynervoxQueries\Mysql\BotIvr;
trait TtsQueries {
    public function ttsProviders(): array {
        return $this->db->query('SELECT provider_id,name,endpoint FROM zynervox_bot_tts_providers ORDER BY name,provider_id')->fetchAll(\PDO::FETCH_ASSOC);
    }
    public function createTtsProvider(string $name, string $endpoint): int {
        if ($name === '' || mb_strlen($name, 'UTF-8') > 100 || strlen($endpoint) > 512) throw new \RuntimeException('Proveedor inválido.');
        try {
            $this->db->prepare('INSERT INTO zynervox_bot_tts_providers(name,endpoint) VALUES (?,?)')->execute([$name,$endpoint]);
        } catch (\PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) throw new \RuntimeException('Esta URL ya está registrada.');
            throw $e;
        }
        return (int)$this->db->lastInsertId();
    }
}
