<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Phones.php';
require_once __DIR__ . '/PbxAmi.php';

// Motor de marcación con agentes humanos (ver src/features/dialer/CONTRACT.md).
// Siempre zynervox_core, nunca la BD legacy: no hay modo dual como en
// Carriers/Phones porque este motor no tiene equivalente legacy que reusar.
class Dialer {

    // Ruta absoluta fija (como los AGI de bot_ivr); produccion vive siempre
    // en /srv/www/htdocs/zynervoxv2.
    const AGI_SCRIPT = '/srv/www/htdocs/zynervoxv2/modules/admin/dialer_call_tracking_agi.php';

    const CAMPAIGN_FIELDS = [
        'dial_prefix'       => ['label' => 'Prefijo de marcación', 'type' => 'text', 'length' => 20, 'digits' => true],
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
        self::syncDialplan($id);
        self::syncQueues();
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
        self::syncDialplan($id);
        self::syncQueues();
    }

    // ------------------------------------------------------------- Dialplan/Queue (Fase 4)

    // Regenera la entrada de dialplan de la campaña a partir de su config
    // (prefijo, AMD, cola, espera sin agente) via Includes\Dialplans, el
    // mismo mecanismo que usa /modules/admin/dialplan.php. No hace nada si
    // la campaña no tiene prefijo o esta inactiva (se desactiva el plan).
    public static function syncDialplan(int $campaignId): void {
        require_once __DIR__ . '/Dialplans.php';
        $campaign = self::campaign($campaignId);
        $prefix = (string) $campaign['dial_prefix'];
        $active = $prefix !== '' && (int) $campaign['active'] === 1 ? 'Y' : 'N';
        if ($prefix === '') {
            if ($campaign['dialplan_id'] !== null) {
                try { \Includes\Dialplans::save((int) $campaign['dialplan_id'], 'v2dialer_campaign_' . $campaignId, "exten => _0000000000000000000X.,1,Hangup()\n", 'N'); } catch (\Throwable $e) { /* no bloquea guardar la campaña */ }
            }
            return;
        }
        // El AGI avisa en cada paso a Dialer::markAnswered/markAmd/closeCall
        // (ver dialer_call_tracking_agi.php), mismo patron que
        // bot_ivr/call_tracking_agi.php, en vez de un listener de eventos
        // AMI aparte.
        $agi = 'AGI(' . self::AGI_SCRIPT . ',';
        $lines = [
            "exten => _{$prefix}X.,1,Answer()",
            ' same => n,' . $agi . 'answered,${ZV2_DIALER_CALL_ID})',
        ];
        if ((int) $campaign['amd_enabled'] === 1) {
            $lines[] = ' same => n,AMD()';
            $lines[] = ' same => n,' . $agi . 'amd,${ZV2_DIALER_CALL_ID},${AMDSTATUS},${AMDCAUSE})';
            $lines[] = ' same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?maquina)';
        }
        $lines[] = ' same => n,Queue(' . $campaign['queue_name'] . ',,,,' . (int) $campaign['drop_call_seconds'] . ')';
        $lines[] = ' same => n,' . $agi . 'closed,${ZV2_DIALER_CALL_ID},${QUEUESTATUS},${UNIQUEID})';
        $lines[] = ' same => n,Hangup()';
        if ((int) $campaign['amd_enabled'] === 1) {
            // amd_action_machine=MESSAGE queda pendiente (requiere audio
            // configurado); por ahora ambas opciones cuelgan. No cuenta
            // como abandono (manual §9.2): el closer no corre para MACHINE.
            $lines[] = ' same => n(maquina),' . $agi . 'amd_hangup,${ZV2_DIALER_CALL_ID})';
            $lines[] = ' same => n,Hangup()';
        }
        $body = implode("\n", $lines) . "\n";
        try {
            $result = \Includes\Dialplans::save($campaign['dialplan_id'] !== null ? (int) $campaign['dialplan_id'] : null, 'v2dialer_campaign_' . $campaignId, $body, $active);
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo generar el dialplan de la campaña: ' . $e->getMessage());
        }
        if (!empty($result['id']) && (int) $result['id'] !== (int) ($campaign['dialplan_id'] ?? 0)) {
            self::database()->prepare('UPDATE v2_dialer_campaigns SET dialplan_id=:d WHERE campaign_id=:c')
                ->execute(['d' => $result['id'], 'c' => $campaignId]);
        }
        // Dialplans::save() (via Carriers::regenerateAndReload) solo escribe
        // extensions-zynervoxv2.conf en modo aislado, NO recarga Asterisk
        // (mismo motivo por el que existe el boton "Dialplan reload" en
        // Servicios > PBX). Sin esto el cambio queda inactivo.
        try {
            \Includes\PbxAmi::command(\Includes\PbxAmi::load(), 'dialplan reload');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Dialplan generado pero no se pudo recargar Asterisk: ' . $e->getMessage());
        }
    }

    // Regenera queues-zynervoxv2.conf con un bloque por cada campaña activa
    // y recarga app_queue. Separado de syncDialplan porque Dialplans::save
    // regenera un archivo distinto (extensions-zynervoxv2.conf); aqui no
    // hay tabla propia, se arma directo de v2_dialer_campaigns.
    public static function syncQueues(): array {
        require_once __DIR__ . '/PbxAmi.php';
        $rows = self::database()->query("SELECT campaign_id,queue_name,wrapup_seconds FROM v2_dialer_campaigns WHERE active=1 AND queue_name<>'' ORDER BY campaign_id")->fetchAll(PDO::FETCH_ASSOC);
        $out = "; Generado por Includes\\Dialer::syncQueues() - " . date('Y-m-d H:i:s') . "\n";
        $out .= "; NO EDITAR A MANO, se sobreescribe desde el panel de administracion.\n\n";
        foreach ($rows as $row) {
            $out .= "[{$row['queue_name']}]\n";
            $out .= "musiconhold=default\n";
            $out .= "strategy=leastrecent\n";
            $out .= "timeout=15\n";
            $out .= 'wrapuptime=' . (int) $row['wrapup_seconds'] . "\n\n";
        }
        $runtime = (string) \Config\Config::deployment('runtime', '');
        if ($runtime === '' || $runtime === '/' || strpos($runtime, '/etc/asterisk') === 0) {
            throw new \RuntimeException('Runtime del motor inválido.');
        }
        $path = rtrim($runtime, '/') . '/modules/asterisk/queues-zynervoxv2.conf';
        if (!is_dir(dirname($path)) || realpath(dirname($path)) !== dirname($path) || is_link($path)) {
            throw new \RuntimeException('Directorio de colas no disponible.');
        }
        $tmp = tempnam(dirname($path), '.queues-');
        if ($tmp === false || file_put_contents($tmp, $out, LOCK_EX) === false || !chmod($tmp, 0640) || !rename($tmp, $path)) {
            if ($tmp !== false && is_file($tmp)) @unlink($tmp);
            throw new \RuntimeException('No se pudo escribir queues-zynervoxv2.conf.');
        }
        $config = \Includes\PbxAmi::load();
        $reload = \Includes\PbxAmi::command($config, 'module reload app_queue.so');
        return ['queues' => count($rows), 'reload' => $reload];
    }

    public static function toggleActive(int $id, string $currentActive): void {
        $new = $currentActive === '1' ? 0 : 1;
        self::database()->prepare('UPDATE v2_dialer_campaigns SET active=:a WHERE campaign_id=:id')->execute(['a' => $new, 'id' => $id]);
        self::audit('CAMPAIGN_' . ($new ? 'ACTIVATE' : 'DEACTIVATE'), (string) $id);
        self::syncDialplan($id);
        self::syncQueues();
    }

    public static function deleteCampaign(int $id): void {
        $campaign = self::campaign($id);
        self::database()->prepare('DELETE FROM v2_dialer_campaigns WHERE campaign_id=:id')->execute(['id' => $id]);
        self::audit('CAMPAIGN_DELETE', (string) $id);
        if ($campaign['dialplan_id'] !== null) {
            require_once __DIR__ . '/Dialplans.php';
            try { \Includes\Dialplans::delete((int) $campaign['dialplan_id']); } catch (\Throwable $e) { /* best-effort */ }
        }
        self::syncQueues();
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

    // ---------------------------------------------------------- Originator (Fase 3)

    // Aproximacion SOLO de Fase 3: sin closer real (eso es Fase 5, que
    // escuchara eventos AMI de Hangup), no sabemos cuando termina una
    // llamada. Mientras tanto contamos "en vuelo" por ventana de tiempo
    // desde que se origino. Se reemplaza en Fase 5 por el conteo real.
    public static function inFlightCount(int $campaignId, int $windowSeconds): int {
        $stmt = self::database()->prepare(
            'SELECT COUNT(*) FROM v2_dialer_call_log WHERE campaign_id=:c AND originated_at > (NOW() - INTERVAL :w SECOND)'
        );
        $stmt->bindValue(':c', $campaignId, PDO::PARAM_INT);
        $stmt->bindValue(':w', $windowSeconds, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // Reserva atomica de un lead elegible (FOR UPDATE SKIP LOCKED, igual
    // que recomienda el manual de diseno) y registra el intento. Devuelve
    // null si no hay leads elegibles. Un solo proceso a la vez por
    // campaign_id (el CLI que llama a esto se invoca manualmente por ahora,
    // sin cron ni servicio).
    public static function claimLead(int $campaignId, string $dialPrefix): ?array {
        $db = self::database();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'SELECT d.lead_id,d.phone_number FROM v2_dialer_leads d
                 JOIN v2_dialer_lists l ON l.list_id=d.list_id
                 WHERE l.campaign_id=:c AND l.active=1 AND d.status=\'NEW\' AND d.next_eligible_at<=NOW()
                 ORDER BY d.lead_id ASC LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute(['c' => $campaignId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) { $db->commit(); return null; }
            $db->prepare('UPDATE v2_dialer_leads SET status=\'DIALING\',called_count=called_count+1,last_call_time=NOW() WHERE lead_id=:id')
                ->execute(['id' => $lead['lead_id']]);
            $db->prepare('INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at) VALUES (:c,:l,:p,NOW())')
                ->execute(['c' => $campaignId, 'l' => $lead['lead_id'], 'p' => $lead['phone_number']]);
            $callId = (int) $db->lastInsertId();
            $db->commit();
            return ['call_id' => $callId, 'lead_id' => (int) $lead['lead_id'], 'phone_number' => $lead['phone_number'], 'channel' => 'Local/' . $dialPrefix . $lead['phone_number'] . '@zynervoxv2/n'];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    // Si AMI rechazo el originate, no dejar el lead colgado en DIALING para
    // siempre: lo regresa a NEW y borra el intento fallido.
    public static function releaseFailedClaim(int $callId, int $leadId): void {
        $db = self::database();
        $db->prepare('DELETE FROM v2_dialer_call_log WHERE call_id=:id')->execute(['id' => $callId]);
        $db->prepare('UPDATE v2_dialer_leads SET status=\'NEW\' WHERE lead_id=:id')->execute(['id' => $leadId]);
    }

    // ---------------------------------------------------------------- Closer (Fase 5)
    // Llamado desde dialer_call_tracking_agi.php en cada paso del dialplan
    // generado por syncDialplan(). Reemplaza la aproximacion por ventana de
    // tiempo de inFlightCount() con cierre real basado en lo que de verdad
    // pasó en la llamada.

    public static function markAnswered(int $callId): void {
        self::database()->prepare('UPDATE v2_dialer_call_log SET answered_at=COALESCE(answered_at,NOW()) WHERE call_id=:id')
            ->execute(['id' => $callId]);
    }

    public static function markAmd(int $callId, string $status): void {
        if (!in_array($status, ['HUMAN', 'MACHINE', 'NOTSURE'], true)) return;
        self::database()->prepare('UPDATE v2_dialer_call_log SET amd_result=:s WHERE call_id=:id')
            ->execute(['s' => $status, 'id' => $callId]);
    }

    // Contestador detectado: NUNCA cuenta como abandono (manual §9.2) — el
    // lead simplemente no llegó a entrar a la cola. Libera el lead para
    // reciclado como MACHINE en vez de dejarlo en DIALING para siempre.
    public static function closeMachine(int $callId): void {
        $db = self::database();
        $stmt = $db->prepare('SELECT lead_id FROM v2_dialer_call_log WHERE call_id=:id');
        $stmt->execute(['id' => $callId]);
        $leadId = $stmt->fetchColumn();
        $db->prepare('UPDATE v2_dialer_call_log SET ended_at=NOW(),termination_reason=\'MACHINE\' WHERE call_id=:id')
            ->execute(['id' => $callId]);
        if ($leadId) {
            $db->prepare("UPDATE v2_dialer_leads SET status='NEW',next_eligible_at=NOW()+INTERVAL 24 HOUR WHERE lead_id=:id")
                ->execute(['id' => $leadId]);
        }
    }

    // Cierra la llamada cuando Queue() devuelve el control. queueStatus es
    // ${QUEUESTATUS}: vacio/CONTINUE = se tendió el puente y terminó solo
    // (completado); TIMEOUT/FULL/JOINEMPTY/LEAVEEMPTY/JOINUNAVAIL/
    // LEAVEUNAVAIL = nunca hubo agente a tiempo (abandono, por simplicidad
    // de Fase 5 se agrupan todos como DROPPED). El agente que atendió se
    // busca en queue_log (evento CONNECT) por no tener listener de eventos
    // AMI en tiempo real.
    public static function closeCall(int $callId, string $queueStatus, string $uniqueId): void {
        $db = self::database();
        $stmt = $db->prepare('SELECT lead_id FROM v2_dialer_call_log WHERE call_id=:id');
        $stmt->execute(['id' => $callId]);
        $leadId = $stmt->fetchColumn();
        $dropped = in_array($queueStatus, ['TIMEOUT', 'FULL', 'JOINEMPTY', 'LEAVEEMPTY', 'JOINUNAVAIL', 'LEAVEUNAVAIL'], true);
        $reason = $dropped ? 'DROPPED' : 'COMPLETED';
        $agentUser = $dropped ? null : self::agentFromQueueLog($uniqueId);
        $db->prepare('UPDATE v2_dialer_call_log SET ended_at=NOW(),termination_reason=:r,agent_user=:a,bridged_at=CASE WHEN :a2 IS NOT NULL THEN NOW() ELSE bridged_at END WHERE call_id=:id')
            ->execute(['r' => $reason, 'a' => $agentUser, 'a2' => $agentUser, 'id' => $callId]);
        if ($leadId) {
            $delay = $dropped ? 5 : 0;
            $status = $dropped ? 'NEW' : 'CONTACTED';
            $db->prepare("UPDATE v2_dialer_leads SET status=:s,next_eligible_at=NOW()+INTERVAL :d MINUTE WHERE lead_id=:id")
                ->execute(['s' => $status, 'd' => $delay, 'id' => $leadId]);
        }
    }

    // Busca en queue_log (texto plano, formato
    // epoch|callid|queue|agent|event|...) la línea CONNECT de este
    // uniqueid y traduce la interfaz (SIP/10000) al login del agente via
    // v2_phones. Best-effort: si no encuentra nada devuelve null, nunca
    // rompe el cierre de la llamada.
    private static function agentFromQueueLog(string $uniqueId): ?string {
        $path = '/var/log/asterisk/queue_log';
        if ($uniqueId === '' || !is_file($path) || !is_readable($path)) return null;
        $handle = @fopen($path, 'r');
        if (!$handle) return null;
        $interface = null;
        try {
            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);
            $chunk = (string) @stream_get_contents($handle, min($size, 200000), max(0, $size - 200000));
        } finally { fclose($handle); }
        foreach (array_reverse(explode("\n", $chunk)) as $line) {
            $cols = explode('|', $line);
            if (count($cols) >= 4 && $cols[1] === $uniqueId && ($cols[4] ?? '') === 'CONNECT') { $interface = $cols[3]; break; }
        }
        if ($interface === null || strpos($interface, '/') === false) return null;
        [, $extension] = explode('/', $interface, 2);
        foreach (\Includes\Phones::getAll() as $phone) {
            if ($phone['extension'] === $extension) return $phone['login'];
        }
        return null;
    }

    // -------------------------------------------------------------- Nivel adaptativo (Fase 6)
    // Algoritmo del manual §8.2: histéresis (banda muerta al 80% del
    // objetivo), no toca nada sin muestra mínima, clamp min/max. Estadística
    // del día (CURDATE()), igual que el "stats:{YYYYMMDD}" del diseño
    // original pero en MySQL en vez de Redis.
    public static function recalculateDialLevel(int $campaignId): float {
        $campaign = self::campaign($campaignId);
        $level = (float) $campaign['dial_level'];
        if ($campaign['dial_method'] !== 'ADAPTIVE') return $level;
        $stmt = self::database()->prepare(
            "SELECT COUNT(*) placed,
                    SUM(answered_at IS NOT NULL AND (amd_result IS NULL OR amd_result<>'MACHINE')) answered,
                    SUM(termination_reason='DROPPED') dropped
             FROM v2_dialer_call_log WHERE campaign_id=:c AND originated_at>=CURDATE()"
        );
        $stmt->execute(['c' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $placed = (int) $row['placed'];
        $answered = (int) $row['answered'];
        $dropped = (int) $row['dropped'];
        if ($placed >= (int) $campaign['adaptive_min_sample']) {
            $target = (float) $campaign['adaptive_target_abandon_pct'];
            $abandonPct = $answered > 0 ? ($dropped / $answered) * 100 : 0.0;
            $step = (float) $campaign['adaptive_step'];
            if ($abandonPct > $target) $level -= $step;
            elseif ($abandonPct < $target * 0.8) $level += $step;
            $level = max((float) $campaign['adaptive_min_level'], min((float) $campaign['adaptive_max_level'], $level));
        }
        $level = round($level, 2);
        self::database()->prepare('UPDATE v2_dialer_campaigns SET dial_level=:l WHERE campaign_id=:c')
            ->execute(['l' => $level, 'c' => $campaignId]);
        return $level;
    }

    // Cuenta agentes "disponibles" parseando `queue show <name>` (best
    // effort, no hay evento AMI en vivo de estado de miembro — mismo
    // compromiso que la correlación de agente en closeCall()).
    // WAITING = libres ahora mismo; NON_PAUSED = no pausados (aunque estén
    // en llamada); LOGGED_IN = todos los miembros.
    public static function availableAgentsCount(int $campaignId, string $levelCountMode): int {
        $campaign = self::campaign($campaignId);
        $config = \Includes\PbxAmi::load();
        $out = \Includes\PbxAmi::command($config, 'queue show ' . $campaign['queue_name']);
        $members = 0;
        // Formato real (Asterisk 16): "      10000 (SIP/10000) (ringinuse
        // enabled) (dynamic) (Not in use) has taken ..." — la interfaz va
        // entre parentesis, no al inicio de linea.
        foreach (explode("\n", $out) as $line) {
            if (!preg_match('/\((SIP|PJSIP|Local)\//', $line)) continue;
            $paused = stripos($line, '(paused)') !== false;
            $free = stripos($line, '(not in use)') !== false;
            if ($levelCountMode === 'WAITING' && (!$free || $paused)) continue;
            if ($levelCountMode === 'NON_PAUSED' && $paused) continue;
            $members++;
        }
        return $members;
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

    // Campañas asignadas a un agente (vista desde el agente, no desde el admin).
    public static function campaignsForAgent(string $user): array {
        $stmt = self::database()->prepare(
            'SELECT c.campaign_id,c.name,c.queue_name,c.active FROM v2_dialer_agent_campaigns a
             JOIN v2_dialer_campaigns c ON c.campaign_id=a.campaign_id
             WHERE a.agent_user=:u ORDER BY c.campaign_id'
        );
        $stmt->execute(['u' => $user]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function agentInterface(string $user): array {
        $phone = null;
        foreach (\Includes\Phones::getAll() as $row) {
            if ($row['login'] === $user) { $phone = $row; break; }
        }
        if (!$phone) throw new \RuntimeException('No tienes un anexo asignado (Anexos/Teléfonos).');
        return [$phone['protocol'] . '/' . $phone['extension'], $phone['fullname'] ?: $user];
    }

    public static function agentJoinQueue(string $user, int $campaignId): string {
        $campaign = self::campaign($campaignId);
        [$interface, $name] = self::agentInterface($user);
        $config = \Includes\PbxAmi::load();
        $msg = \Includes\PbxAmi::rawAction($config, 'QueueAdd', [
            'Queue' => $campaign['queue_name'], 'Interface' => $interface,
            'MemberName' => $name, 'StateInterface' => $interface,
        ]);
        self::audit('AGENT_QUEUE_JOIN', $user . ':' . $campaign['queue_name']);
        return $msg;
    }

    public static function agentLeaveQueue(string $user, int $campaignId): string {
        $campaign = self::campaign($campaignId);
        [$interface] = self::agentInterface($user);
        $config = \Includes\PbxAmi::load();
        $msg = \Includes\PbxAmi::rawAction($config, 'QueueRemove', [
            'Queue' => $campaign['queue_name'], 'Interface' => $interface,
        ]);
        self::audit('AGENT_QUEUE_LEAVE', $user . ':' . $campaign['queue_name']);
        return $msg;
    }

    public static function agentSetPause(string $user, int $campaignId, bool $paused, string $reason = ''): string {
        $campaign = self::campaign($campaignId);
        [$interface] = self::agentInterface($user);
        $config = \Includes\PbxAmi::load();
        $fields = ['Queue' => $campaign['queue_name'], 'Interface' => $interface, 'Paused' => $paused ? 'true' : 'false'];
        if ($reason !== '') $fields['Reason'] = $reason;
        $msg = \Includes\PbxAmi::rawAction($config, 'QueuePause', $fields);
        self::audit('AGENT_QUEUE_PAUSE', $user . ':' . $campaign['queue_name'] . ':' . ($paused ? '1' : '0'));
        return $msg;
    }

    // Ultima llamada de este agente sin tipificar todavia (para el panel).
    public static function pendingDispositionForAgent(string $user): ?array {
        $stmt = self::database()->prepare(
            "SELECT l.call_id,l.campaign_id,l.phone_number,l.originated_at,c.name campaign_name
             FROM v2_dialer_call_log l JOIN v2_dialer_campaigns c ON c.campaign_id=l.campaign_id
             WHERE l.agent_user=:u AND l.disposition_code IS NULL ORDER BY l.call_id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $user]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function setDisposition(int $callId, string $user, string $code): void {
        $stmt = self::database()->prepare('SELECT campaign_id,agent_user FROM v2_dialer_call_log WHERE call_id=:id');
        $stmt->execute(['id' => $callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$call || $call['agent_user'] !== $user) throw new \RuntimeException('Llamada inexistente o de otro agente.');
        $valid = self::database()->prepare('SELECT 1 FROM v2_dialer_dispositions WHERE campaign_id=:c AND code=:code');
        $valid->execute(['c' => $call['campaign_id'], 'code' => $code]);
        if (!$valid->fetchColumn()) throw new \RuntimeException('Disposición inválida para esta campaña.');
        self::database()->prepare('UPDATE v2_dialer_call_log SET disposition_code=:code,ended_at=COALESCE(ended_at,NOW()) WHERE call_id=:id')
            ->execute(['code' => $code, 'id' => $callId]);
        self::audit('DISPOSITION_SET', $callId . ':' . $code);
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
