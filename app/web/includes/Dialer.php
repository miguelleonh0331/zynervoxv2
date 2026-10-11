<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Phones.php';

// Motor de marcación con agentes humanos (ver src/features/dialer/CONTRACT.md).
// Siempre zynervox_core, nunca la BD legacy: no hay modo dual como en
// Carriers/Phones porque este motor no tiene equivalente legacy que reusar.
class Dialer {

    const CAMPAIGN_FIELDS = [
        'dial_prefix'       => ['label' => 'Prefijo de marcación', 'type' => 'text', 'length' => 20],
        'caller_id_number'  => ['label' => 'Caller ID', 'type' => 'text', 'length' => 20, 'digits' => true],
        'dial_method'       => ['label' => 'Método de marcado', 'type' => 'select', 'options' => ['MANUAL', 'RATIO', 'ADAPTIVE']],
        'dial_level'        => ['label' => 'Nivel de marcado', 'type' => 'decimal', 'min' => 0, 'max' => 20],
        'level_count_mode'  => ['label' => 'Contar agentes como', 'type' => 'select', 'options' => ['WAITING', 'NON_PAUSED', 'LOGGED_IN']],
        'adaptive_target_abandon_pct' => ['label' => '% abandono objetivo', 'type' => 'decimal', 'min' => 0, 'max' => 100],
        'adaptive_max_level'   => ['label' => 'Nivel adaptativo máximo', 'type' => 'decimal', 'min' => 0, 'max' => 20],
        'adaptive_min_level'   => ['label' => 'Nivel adaptativo mínimo', 'type' => 'decimal', 'min' => 0, 'max' => 20],
        'adaptive_step'        => ['label' => 'Paso adaptativo', 'type' => 'decimal', 'min' => 0.01, 'max' => 5],
        'adaptive_min_sample'  => ['label' => 'Muestra mínima', 'type' => 'number', 'min' => 1, 'max' => 10000],
        'max_concurrent_calls' => ['label' => 'Canales máximos', 'type' => 'number', 'min' => 1, 'max' => 1000],
        'dial_timeout_seconds' => ['label' => 'Timeout de marcado (s)', 'type' => 'number', 'min' => 5, 'max' => 120],
        'drop_call_seconds'    => ['label' => 'Espera máx. sin agente (s)', 'type' => 'number', 'min' => 1, 'max' => 120],
        'drop_action'          => ['label' => 'Acción si no hay agente', 'type' => 'select', 'options' => ['HANGUP', 'MESSAGE']],
        'amd_enabled'          => ['label' => 'Detectar contestador (AMD)', 'type' => 'select', 'options' => ['1', '0']],
        'amd_action_machine'   => ['label' => 'Acción si contestador', 'type' => 'select', 'options' => ['HANGUP', 'MESSAGE']],
        'max_attempts_per_lead'=> ['label' => 'Intentos máx. por lead', 'type' => 'number', 'min' => 1, 'max' => 50],
        'wrapup_seconds'       => ['label' => 'ACW (s)', 'type' => 'number', 'min' => 0, 'max' => 600],
        'lead_order'           => ['label' => 'Orden de leads', 'type' => 'select', 'options' => ['RANK_ASC', 'OLDEST_FIRST', 'RANDOM', 'LAST_CALL_ASC']],
    ];

    private static function database() {
        if (!\Config\Config::deployment('isolated', false)) throw new \RuntimeException('Dialer requiere despliegue v2 aislado.');
        if (\Config\Config::get('CORE_DB_database') !== 'zynervox_core') throw new \RuntimeException('Dialer requiere zynervox_core.');
        return Database::getCoreInstance();
    }

    private static function audit(string $action, string $detail): void {
        Audit::logAccess($_SESSION['user'] ?? 'system', 'DIALER_' . $action, $detail);
    }

    // ---------------------------------------------------------------- Campañas

    public static function campaigns(): array {
        return self::database()->query(
            'SELECT c.*, (SELECT COUNT(*) FROM v2_dialer_lists l WHERE l.campaign_id=c.campaign_id) lists_count,
                    (SELECT COUNT(*) FROM v2_dialer_agent_campaigns a WHERE a.campaign_id=c.campaign_id) agents_count
             FROM v2_dialer_campaigns c ORDER BY c.campaign_id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function campaign(int $id): array {
        $stmt = self::database()->prepare('SELECT * FROM v2_dialer_campaigns WHERE campaign_id=:id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Campaña inexistente.');
        return $row;
    }

    private static function campaignName(string $name): string {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) throw new \RuntimeException('Nombre de campaña inválido (1-120 caracteres).');
        return $name;
    }

    private static function fieldValue(string $key, $value) {
        $field = self::CAMPAIGN_FIELDS[$key];
        $value = trim((string) $value);
        if ($field['type'] === 'select') {
            if (!in_array($value, $field['options'], true)) throw new \RuntimeException($field['label'] . ': valor inválido.');
            return $value;
        }
        if ($field['type'] === 'number') {
            if (!preg_match('/^\d+$/', $value) || (int) $value < $field['min'] || (int) $value > $field['max']) {
                throw new \RuntimeException($field['label'] . ': debe ser un entero entre ' . $field['min'] . ' y ' . $field['max'] . '.');
            }
            return (int) $value;
        }
        if ($field['type'] === 'decimal') {
            if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value) || (float) $value < $field['min'] || (float) $value > $field['max']) {
                throw new \RuntimeException($field['label'] . ': debe ser un número entre ' . $field['min'] . ' y ' . $field['max'] . '.');
            }
            return $value;
        }
        // text
        if (mb_strlen($value, 'UTF-8') > $field['length'] || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw new \RuntimeException($field['label'] . ': valor inválido o demasiado largo.');
        }
        if (!empty($field['digits']) && $value !== '' && !preg_match('/^[+0-9*#]*$/', $value)) {
            throw new \RuntimeException($field['label'] . ': solo dígitos.');
        }
        return $value;
    }

    private static function queueName(int $campaignId): string {
        return 'v2dialer_' . $campaignId;
    }

    public static function createCampaign(string $name, string $carrierId, bool $active): int {
        $name = self::campaignName($name);
        $carrierId = trim($carrierId);
        if ($carrierId === '') throw new \RuntimeException('Selecciona una troncal.');
        $db = self::database();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO v2_dialer_campaigns (name,active,carrier_id,queue_name) VALUES (:n,:a,:c,"")');
            $stmt->execute(['n' => $name, 'a' => (int) $active, 'c' => $carrierId]);
            $id = (int) $db->lastInsertId();
            $db->prepare('UPDATE v2_dialer_campaigns SET queue_name=:q WHERE campaign_id=:id')
                ->execute(['q' => self::queueName($id), 'id' => $id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        self::audit('CAMPAIGN_CREATE', (string) $id);
        return $id;
    }

    public static function updateCampaign(int $id, array $input): void {
        self::campaign($id);
        $name = self::campaignName((string) ($input['name'] ?? ''));
        $active = (string) ($input['active'] ?? '') === '1' ? 1 : 0;
        $values = ['name' => $name, 'active' => $active];
        foreach (self::CAMPAIGN_FIELDS as $key => $field) {
            $values[$key] = self::fieldValue($key, $input[$key] ?? '');
        }
        $assignments = [];
        $bindings = [':id' => $id];
        foreach ($values as $key => $value) {
            $assignments[] = $key . '=:' . $key;
            $bindings[':' . $key] = $value;
        }
        self::database()->prepare('UPDATE v2_dialer_campaigns SET ' . implode(',', $assignments) . ' WHERE campaign_id=:id')->execute($bindings);
        self::audit('CAMPAIGN_UPDATE', (string) $id);
    }

    public static function toggleActive(int $id, string $currentActive): void {
        $new = $currentActive === '1' ? 0 : 1;
        self::database()->prepare('UPDATE v2_dialer_campaigns SET active=:a WHERE campaign_id=:id')->execute(['a' => $new, 'id' => $id]);
        self::audit('CAMPAIGN_' . ($new ? 'ACTIVATE' : 'DEACTIVATE'), (string) $id);
    }

    public static function deleteCampaign(int $id): void {
        self::database()->prepare('DELETE FROM v2_dialer_campaigns WHERE campaign_id=:id')->execute(['id' => $id]);
        self::audit('CAMPAIGN_DELETE', (string) $id);
    }

    // ------------------------------------------------------------------ Listas

    public static function lists(int $campaignId): array {
        $stmt = self::database()->prepare(
            'SELECT l.*, (SELECT COUNT(*) FROM v2_dialer_leads d WHERE d.list_id=l.list_id) leads_count
             FROM v2_dialer_lists l WHERE l.campaign_id=:c ORDER BY l.list_id DESC'
        );
        $stmt->execute(['c' => $campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function list(int $listId, int $campaignId): array {
        $stmt = self::database()->prepare('SELECT * FROM v2_dialer_lists WHERE list_id=:l AND campaign_id=:c');
        $stmt->execute(['l' => $listId, 'c' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Lista inexistente.');
        return $row;
    }

    public static function createList(int $campaignId, string $name): int {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) throw new \RuntimeException('Nombre de lista inválido.');
        self::campaign($campaignId);
        $db = self::database();
        $db->prepare('INSERT INTO v2_dialer_lists (campaign_id,name) VALUES (:c,:n)')->execute(['c' => $campaignId, 'n' => $name]);
        $id = (int) $db->lastInsertId();
        self::audit('LIST_CREATE', (string) $id);
        return $id;
    }

    // ------------------------------------------------------------------ Leads

    // Mismo formato que bot_ivr: TXT CSV con cabecera, columna obligatoria
    // "numero", alias opcionales "nombre"/"name"/"cliente"/"nombres", resto
    // de columnas quedan en custom_fields como JSON.
    public static function parseLeadsTxt(string $contents): array {
        if (strlen($contents) > 10 * 1024 * 1024) throw new \RuntimeException('El archivo supera 10 MB.');
        if (!mb_check_encoding($contents, 'UTF-8')) throw new \RuntimeException('Guarda el archivo en UTF-8 antes de cargarlo.');
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) throw new \RuntimeException('No se pudo leer el archivo.');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $raw = fgetcsv($stream, 0, ',', '"', '');
            $header = array_map(static function ($v) { return strtolower(trim((string) $v)); }, $raw ?: []);
            if (count($header) < 1 || count($header) > 10) throw new \RuntimeException('El TXT debe tener de 1 a 10 columnas separadas por comas.');
            if (in_array('', $header, true) || count(array_unique($header)) !== count($header)) throw new \RuntimeException('Las cabeceras no pueden estar vacías ni repetidas.');
            $phoneIndex = array_search('numero', $header, true);
            if ($phoneIndex === false) throw new \RuntimeException('Falta la columna obligatoria numero.');
            $firstIndex = false;
            foreach (['nombre', 'name', 'cliente', 'nombres'] as $alias) {
                $index = array_search($alias, $header, true);
                if ($index !== false) { $firstIndex = $index; break; }
            }
            $rows = [];
            $duplicates = 0;
            $rejected = 0;
            $seen = [];
            while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if (count($cells) === 1 && trim((string) $cells[0]) === '') continue;
                $phone = preg_replace('/[^0-9+]/', '', (string) ($cells[$phoneIndex] ?? ''));
                if ($phone === '' || strlen($phone) > 20) { $rejected++; continue; }
                if (isset($seen[$phone])) { $duplicates++; continue; }
                $seen[$phone] = true;
                $extra = [];
                foreach ($header as $i => $key) {
                    if ($i === $phoneIndex) continue;
                    $extra[$key] = (string) ($cells[$i] ?? '');
                }
                $rows[] = [
                    'phone_number' => $phone,
                    'first_name' => $firstIndex !== false ? mb_substr((string) ($cells[$firstIndex] ?? ''), 0, 80, 'UTF-8') : '',
                    'custom_fields' => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null,
                ];
            }
            return ['rows' => $rows, 'duplicates' => $duplicates, 'rejected' => $rejected];
        } finally {
            fclose($stream);
        }
    }

    public static function importLeads(int $listId, int $campaignId, array $parsed): array {
        self::list($listId, $campaignId);
        $db = self::database();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM v2_dialer_leads WHERE list_id=:l')->execute(['l' => $listId]);
            $stmt = $db->prepare(
                'INSERT INTO v2_dialer_leads (list_id,phone_number,first_name,custom_fields) VALUES (:l,:p,:f,:c)'
            );
            $saved = 0;
            foreach ($parsed['rows'] as $row) {
                $stmt->execute(['l' => $listId, 'p' => $row['phone_number'], 'f' => $row['first_name'], 'c' => $row['custom_fields']]);
                $saved++;
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        self::audit('LEADS_IMPORT', $listId . ':' . $saved);
        return ['saved' => $saved, 'duplicates' => $parsed['duplicates'], 'rejected' => $parsed['rejected']];
    }

    public static function leadCount(int $listId): int {
        $stmt = self::database()->prepare('SELECT COUNT(*) FROM v2_dialer_leads WHERE list_id=:l');
        $stmt->execute(['l' => $listId]);
        return (int) $stmt->fetchColumn();
    }

    public static function leadsPreview(int $listId, int $limit = 50): array {
        $stmt = self::database()->prepare('SELECT lead_id,phone_number,first_name,status FROM v2_dialer_leads WHERE list_id=:l ORDER BY lead_id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['l' => $listId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------- Disposiciones

    public static function dispositions(int $campaignId): array {
        $stmt = self::database()->prepare('SELECT * FROM v2_dialer_dispositions WHERE campaign_id=:c ORDER BY code ASC');
        $stmt->execute(['c' => $campaignId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function createDisposition(int $campaignId, string $code, string $label, bool $isContact, bool $isSale, bool $retryable, int $retryDelayMinutes): void {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9_]{1,20}$/D', $code)) throw new \RuntimeException('Código inválido (A-Z 0-9 _, hasta 20 caracteres).');
        $label = trim($label);
        if ($label === '' || mb_strlen($label, 'UTF-8') > 80) throw new \RuntimeException('Etiqueta inválida.');
        if ($retryDelayMinutes < 0 || $retryDelayMinutes > 100000) throw new \RuntimeException('Minutos de reintento inválidos.');
        self::campaign($campaignId);
        self::database()->prepare(
            'INSERT INTO v2_dialer_dispositions (campaign_id,code,label,is_contact,is_sale,retryable,retry_delay_minutes)
             VALUES (:c,:code,:label,:ic,:is,:rt,:rd)'
        )->execute([
            'c' => $campaignId, 'code' => $code, 'label' => $label,
            'ic' => (int) $isContact, 'is' => (int) $isSale, 'rt' => (int) $retryable, 'rd' => $retryDelayMinutes,
        ]);
        self::audit('DISPOSITION_CREATE', $campaignId . ':' . $code);
    }

    public static function deleteDisposition(int $dispositionId, int $campaignId): void {
        self::database()->prepare('DELETE FROM v2_dialer_dispositions WHERE disposition_id=:id AND campaign_id=:c')
            ->execute(['id' => $dispositionId, 'c' => $campaignId]);
        self::audit('DISPOSITION_DELETE', (string) $dispositionId);
    }

    // -------------------------------------------------------------- Agentes

    // Catálogo de agentes disponibles = usuarios con anexo propio (v2_phones).
    public static function availableAgents(): array {
        return \Includes\Phones::getAll();
    }

    public static function agentsForCampaign(int $campaignId): array {
        $stmt = self::database()->prepare('SELECT agent_user FROM v2_dialer_agent_campaigns WHERE campaign_id=:c');
        $stmt->execute(['c' => $campaignId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function setCampaignAgents(int $campaignId, array $agentUsers): void {
        self::campaign($campaignId);
        $agentUsers = array_values(array_unique(array_filter(array_map('strval', $agentUsers), static function ($u) {
            return preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $u) === 1;
        })));
        $db = self::database();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM v2_dialer_agent_campaigns WHERE campaign_id=:c')->execute(['c' => $campaignId]);
            $stmt = $db->prepare('INSERT INTO v2_dialer_agent_campaigns (agent_user,campaign_id) VALUES (:u,:c)');
            foreach ($agentUsers as $user) {
                $stmt->execute(['u' => $user, 'c' => $campaignId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        self::audit('AGENTS_ASSIGN', $campaignId . ':' . implode(',', $agentUsers));
    }
}
