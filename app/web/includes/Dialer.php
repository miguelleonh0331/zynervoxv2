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
    const RECORDING_DIRECTORY = '/var/spool/asterisk/monitor/zynervoxv2/dialer';

    const CAMPAIGN_FIELDS = [
        'dial_prefix'       => ['label' => 'Prefijo interno del dialplan', 'type' => 'text', 'length' => 20, 'digits' => true],
        'outbound_prefix'   => ['label' => 'Prefijo del número en la troncal (vacío = sin prefijo)', 'type' => 'text', 'length' => 20, 'digits' => true],
        'drop_message'      => ['label' => 'Audio de abandono (ruta relativa en sounds)', 'type' => 'text', 'length' => 255],
        'amd_message'       => ['label' => 'Audio para contestador (ruta relativa en sounds)', 'type' => 'text', 'length' => 255],
        'caller_id_number'  => ['label' => 'Caller ID', 'type' => 'text', 'length' => 20, 'digits' => true],
        'dial_method'       => ['label' => 'Método de marcado', 'type' => 'select', 'options' => ['MANUAL', 'PROGRESSIVE', 'ADAPTIVE', 'RATIO']],
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
        'local_call_time_start'=> ['label' => 'Horario inicial (hora local del lead)', 'type' => 'time', 'length' => 8],
        'local_call_time_end'  => ['label' => 'Horario final (hora local del lead)', 'type' => 'time', 'length' => 8],
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
        if ($field['type'] === 'time' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $value)) throw new \RuntimeException('Horario inválido; utiliza HH:MM:SS.');
        if (mb_strlen($value, 'UTF-8') > $field['length'] || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw new \RuntimeException($field['label'] . ': valor inválido o demasiado largo.');
        }
        if (!empty($field['digits']) && $value !== '' && !preg_match('/^[0-9]*$/D', $value)) {
            throw new \RuntimeException($field['label'] . ': solo dígitos.');
        }
        return $value;
    }

    private static function queueName(int $campaignId): string {
        return 'v2dialer_' . $campaignId;
    }

    public static function availableDialplans(): array {
        require_once __DIR__.'/Dialplans.php';
        $routes=[];
        foreach (\Includes\Dialplans::origins() as $row) {
            if (strpos($row['name'],'v2dialer_campaign_')===0) continue;
            try { $routes[]=\Includes\Dialplans::outboundRoute((int)$row['dialplan_id']); }
            catch (\InvalidArgumentException $error) { /* Not a SIP outbound route supported by this engine. */ }
        }
        return $routes;
    }

    public static function createCampaignFromDialplan(string $name, int $dialplanId, bool $active): int {
        require_once __DIR__.'/Dialplans.php';
        $name=self::campaignName($name);
        \Includes\Dialplans::outboundRoute($dialplanId);
        $db=self::database(); $db->beginTransaction();
        try {
            $db->prepare('INSERT INTO v2_dialer_campaigns (name,active,carrier_id,queue_name,selected_dialplan_id,outbound_prefix) VALUES (?,?,NULL,"",?,"")')->execute([$name,(int)$active,$dialplanId]);
            $id=(int)$db->lastInsertId();
            $db->prepare('UPDATE v2_dialer_campaigns SET queue_name=?,dial_prefix=? WHERE campaign_id=?')->execute([self::queueName($id),self::callbackPrefix($id),$id]);
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        self::audit('CAMPAIGN_CREATE',(string)$id);
        self::syncDialplan($id); self::syncQueues();
        return $id;
    }

    private static function callbackPrefix(int $id): string {
        require_once __DIR__.'/Dialplans.php';
        $used=array_column(\Includes\Dialplans::getAll(),'dial_prefix');
        for ($suffix=0;$suffix<100;$suffix++) {
            $prefix='7978'.str_pad((string)$id,8,'0',STR_PAD_LEFT).str_pad((string)$suffix,2,'0',STR_PAD_LEFT);
            if (!in_array($prefix,$used,true)) return $prefix;
        }
        throw new \RuntimeException('No se pudo asignar la ruta interna del marcador.');
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
        $current=self::campaign($id);
        $name = self::campaignName((string) ($input['name'] ?? ''));
        $active = (string) ($input['active'] ?? '') === '1' ? 1 : 0;
        $values = ['name' => $name, 'active' => $active];
        if (!$active) $values['engine_state'] = 'STOPPED';
        foreach (self::CAMPAIGN_FIELDS as $key => $field) {
            if (in_array($key,['dial_prefix','outbound_prefix'],true) && !array_key_exists($key,$input)) { $values[$key]=$current[$key]; continue; }
            $values[$key] = self::fieldValue($key, $input[$key] ?? '');
        }
        foreach (['drop' => 'drop_action', 'amd' => 'amd_action_machine'] as $kind => $action) {
            if ($values[$action] === 'MESSAGE') self::messageAudio($values[$kind . '_message']);
        }
        $assignments = [];
        $bindings = [':id' => $id];
        foreach ($values as $key => $value) {
            $assignments[] = $key . '=:' . $key;
            $bindings[':' . $key] = $value;
        }
        $db=self::database(); $db->beginTransaction();
        try {
            $lock=$db->prepare('SELECT * FROM v2_dialer_campaigns WHERE campaign_id=? FOR UPDATE'); $lock->execute([$id]); $current=$lock->fetch(PDO::FETCH_ASSOC);
            if (!$current) throw new \RuntimeException('Campaña inexistente.');
            if ($values['dial_method'] !== $current['dial_method'] && ($current['engine_state'] !== 'STOPPED' || self::inFlightCount($id)>0)) throw new \RuntimeException('Detén el motor y espera a que terminen las llamadas antes de cambiar el modo.');
            if (array_key_exists('selected_dialplan_id',$input)) {
                $selected=filter_var($input['selected_dialplan_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if (!$selected) throw new \RuntimeException('Selecciona un dialplan de salida.');
                require_once __DIR__.'/Dialplans.php';
                \Includes\Dialplans::outboundRoute($selected);
                if ($selected!==(int)($current['selected_dialplan_id'] ?? 0)) {
                    if ($current['engine_state']!=='STOPPED' || self::inFlightCount($id)>0) throw new \RuntimeException('Detén el motor y espera a que terminen las llamadas antes de cambiar el dialplan.');
                    $assignments[]='selected_dialplan_id=:selected'; $bindings[':selected']=$selected;
                    $assignments[]='carrier_id=NULL';
                    // Convert an unassigned legacy campaign without reusing its public prefix.
                    if (empty($current['selected_dialplan_id']) && $current['dialplan_id']===null) $bindings[':dial_prefix']=self::callbackPrefix($id);
                    $bindings[':outbound_prefix']='';
                }
            }
            if (!empty($current['selected_dialplan_id'])) {
                $bindings[':dial_prefix']=$current['dial_prefix']; $bindings[':outbound_prefix']='';
            }
            $db->prepare('UPDATE v2_dialer_campaigns SET ' . implode(',', $assignments) . ' WHERE campaign_id=:id')->execute($bindings);
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
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
        $body = self::dialplanBody($campaign);
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

    private static function dialplanBody(array $campaign): string {
        $prefix = (string) $campaign['dial_prefix'];
        if (!preg_match('/^[0-9]{1,20}$/D', $prefix) || !preg_match('/^v2dialer_[0-9]+$/D', $campaign['queue_name'])) throw new \RuntimeException('Prefijo/cola inválidos.');
        $agi = 'AGI(' . self::AGI_SCRIPT . ',';
        $handler = 'zynervoxv2-dialer-close-' . (int) $campaign['campaign_id'];
        $bridge = 'zynervoxv2-dialer-bridge-' . (int) $campaign['campaign_id'];
        $lines = [
            "exten => _{$prefix}X.,1,Answer()",
            ' same => n,Set(CHANNEL(hangup_handler_push)=' . $handler . ',s,1)',
            ' same => n,' . $agi . 'answered,${ZV2_DIALER_CALL_ID},${UNIQUEID})',
            ' same => n,GotoIf($["${ZV2_DIALER_READY}" != "1"]?invalid)',
            ' same => n,MixMonitor(${ZV2_RECORDING})',
            ' same => n,' . $agi . 'recording,${ZV2_DIALER_CALL_ID},${MIXMONITOR_FILENAME})',
            ' same => n,GotoIf($["${ZV2_RECORDING_READY}" != "1"]?invalid)',
        ];
        if ((int) $campaign['amd_enabled'] === 1) {
            $lines[] = ' same => n,AMD()';
            $lines[] = ' same => n,' . $agi . 'amd,${ZV2_DIALER_CALL_ID},${AMDSTATUS})';
            $lines[] = ' same => n,GotoIf($["${AMDSTATUS}" = "MACHINE"]?maquina)';
        }
        $lines[] = ' same => n,Queue(${ZV2_DIALER_QUEUE},c,,,' . (int) $campaign['drop_call_seconds'] . ',,,' . $bridge . ')';
        if ($campaign['drop_action'] === 'MESSAGE') {
            $audio = self::messageAudio((string) $campaign['drop_message']);
            $lines[] = ' same => n,GotoIf($["${QUEUESTATUS}" = "CONTINUE"]?invalid)';
            $lines[] = ' same => n,Playback(' . $audio . ')';
        }
        $lines[] = ' same => n(invalid),Hangup()';
        if ((int) $campaign['amd_enabled'] === 1) {
            $lines[] = ' same => n(maquina),NoOp(Contestador detectado)';
            if ($campaign['amd_action_machine'] === 'MESSAGE') $lines[] = ' same => n,Playback(' . self::messageAudio((string) $campaign['amd_message']) . ')';
            $lines[] = ' same => n,Hangup()';
        }
        $lines[] = '[' . $handler . ']';
        $lines[] = 'exten => s,1,StopMixMonitor()';
        $lines[] = ' same => n,' . $agi . 'closed,${ZV2_DIALER_CALL_ID},${QUEUESTATUS},${UNIQUEID})';
        $lines[] = ' same => n,Return()';
        $lines[] = '[' . $bridge . ']';
        $lines[] = 'exten => s,1,' . $agi . 'bridged,${ZV2_DIALER_CALL_ID},${CHANNEL(name)})';
        $lines[] = ' same => n,Return()';
        if (($campaign['dial_method'] ?? '') === 'MANUAL') {
            $manual = 'zynervoxv2-dialer-manual-' . (int) $campaign['campaign_id'];
            $lines = array_merge($lines, [
                '[' . $manual . ']',
                'exten => s,1,Set(CHANNEL(hangup_handler_push)=' . $manual . '-close,s,1)',
                ' same => n,Set(__ZV2_DIALER_PARENT_UID=${UNIQUEID})',
                ' same => n,Set(__ZV2_DIALER_AGENT_CHANNEL=${CHANNEL(name)})',
                ' same => n,' . $agi . 'manual_ready,${ZV2_DIALER_CALL_ID},${CHANNEL(name)})',
                ' same => n,GotoIf($["${ZV2_DIALER_READY}" != "1"]?invalid)',
                // Native Dial passes ringing and provider early media to the answered agent.
                ' same => n,Dial(${ZV2_DIALER_DESTINATION},${ZV2_DIALER_TIMEOUT},gU(' . $manual . '-answer))',
                ' same => n(invalid),Hangup()',
                '[' . $manual . '-answer]',
                'exten => s,1,Set(GOSUB_RESULT=ABORT)',
                ' same => n,Set(ZV2_DIALER_READY=0)',
                ' same => n,Set(CHANNEL(hangup_handler_push)=' . $manual . '-recording-close,s,1)',
                ' same => n,' . $agi . 'answered,${ZV2_DIALER_CALL_ID},${ZV2_DIALER_PARENT_UID})',
                ' same => n,GotoIf($["${ZV2_DIALER_READY}" != "1"]?invalid)',
                ' same => n,MixMonitor(${ZV2_RECORDING},b)',
                ' same => n,' . $agi . 'recording,${ZV2_DIALER_CALL_ID},${MIXMONITOR_FILENAME})',
                ' same => n,GotoIf($["${ZV2_RECORDING_READY}" != "1"]?invalid)',
                ' same => n,Set(ZV2_DIALER_BRIDGE_READY=0)',
                ' same => n,' . $agi . 'manual_bridged,${ZV2_DIALER_CALL_ID},${ZV2_DIALER_AGENT_CHANNEL})',
                ' same => n,GotoIf($["${ZV2_DIALER_BRIDGE_READY}" != "1"]?invalid)',
                ' same => n,Set(GOSUB_RESULT=)',
                ' same => n(invalid),Return()',
                '[' . $manual . '-recording-close]',
                'exten => s,1,StopMixMonitor()',
                ' same => n,Return()',
                '[' . $manual . '-close]',
                'exten => s,1,' . $agi . 'manual_closed,${ZV2_DIALER_CALL_ID},${DIALSTATUS},${UNIQUEID})',
                ' same => n,Return()',
            ]);
        }
        return implode("\n", $lines) . "\n";
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
            $names = [$row['queue_name']];
            foreach (self::agentsForCampaign((int) $row['campaign_id']) as $user) $names[] = self::personalQueue((int) $row['campaign_id'], $user);
            foreach ($names as $name) {
            $out .= "[$name]\n";
            $out .= "musiconhold=default\n";
            $out .= "strategy=leastrecent\n";
            $out .= "ringinuse=no\nautofill=yes\n";
            $out .= "timeout=15\n";
            $out .= 'wrapuptime=' . (int) $row['wrapup_seconds'] . "\n\n";
            }
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
        self::database()->prepare("UPDATE v2_dialer_campaigns SET active=:a,engine_state='STOPPED' WHERE campaign_id=:id")->execute(['a' => $new, 'id' => $id]);
        self::audit('CAMPAIGN_' . ($new ? 'ACTIVATE' : 'DEACTIVATE'), (string) $id);
        self::syncDialplan($id);
        self::syncQueues();
    }

    public static function deleteCampaign(int $id): void {
        $db=self::database(); $members=[];
        $db->beginTransaction();
        try {
            $lock=$db->prepare('SELECT * FROM v2_dialer_campaigns WHERE campaign_id=:id FOR UPDATE');
            $lock->execute(['id'=>$id]); $campaign=$lock->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) throw new \RuntimeException('Campaña inexistente.');
            if ($campaign['engine_state']==='RUNNING') throw new \RuntimeException('Detén el motor antes de eliminar la campaña.');
            $open=$db->prepare('SELECT COUNT(*) FROM v2_dialer_call_log WHERE campaign_id=:id AND ended_at IS NULL'); $open->execute(['id'=>$id]);
            if ((int)$open->fetchColumn()>0) throw new \RuntimeException('La campaña tiene llamadas abiertas; espera a que terminen antes de eliminarla.');
            $agents=self::agentsForCampaign($id);
            if ($agents) foreach (\Includes\Phones::getAll() as $phone) {
                if (!in_array($phone['login'],$agents,true) || !in_array($phone['protocol'],['SIP','PJSIP'],true) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$phone['extension'])) continue;
                foreach ([$campaign['queue_name'],self::personalQueue($id,$phone['login'])] as $queue) $members[]=['Queue'=>$queue,'Interface'=>$phone['protocol'].'/'.$phone['extension']];
            }
            $db->prepare('DELETE FROM v2_dialer_call_log WHERE campaign_id=:id')->execute(['id'=>$id]);
            $db->prepare('DELETE d FROM v2_dialer_leads d JOIN v2_dialer_lists l ON l.list_id=d.list_id WHERE l.campaign_id=:id')->execute(['id'=>$id]);
            foreach (['v2_dialer_lists','v2_dialer_dispositions','v2_dialer_agent_campaigns','v2_dialer_agent_intervals','v2_dialer_dnc','v2_dialer_campaigns'] as $table) {
                $db->prepare('DELETE FROM '.$table.' WHERE campaign_id=:id')->execute(['id'=>$id]);
            }
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        self::audit('CAMPAIGN_DELETE', (string)$id);
        // WAV files are retained; removing call history only removes their web index.
        try {
            $config=\Includes\PbxAmi::load();
            foreach ($members as $member) {
                if (self::memberLine(['queue_name'=>$member['Queue']],$member['Interface'])!==null) \Includes\PbxAmi::rawAction($config,'QueueRemove',$member);
            }
            if ($campaign['dialplan_id']!==null) {
                require_once __DIR__.'/Dialplans.php';
                \Includes\Dialplans::delete((int)$campaign['dialplan_id']);
                \Includes\PbxAmi::command($config,'dialplan reload');
            }
            self::syncQueues();
        } catch (\Throwable $error) {
            self::audit('CAMPAIGN_DELETE_PBX_PENDING',(string)$id);
            error_log('dialer campaign deletion PBX cleanup: '.$error->getMessage());
            throw new \RuntimeException('Campaña eliminada; limpieza PBX pendiente. Revisa el registro del servicio.',0,$error);
        }
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

    // El segundo argumento se conserva por compatibilidad; una llamada activa
    // sigue ocupando capacidad hasta su cierre, aunque dure más que esa ventana.
    public static function inFlightCount(int $campaignId, int $windowSeconds = 0): int {
        $stmt = self::database()->prepare(
            'SELECT COUNT(*) FROM v2_dialer_call_log WHERE campaign_id=:c AND ended_at IS NULL'
        );
        $stmt->bindValue(':c', $campaignId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    // Reserva atomica de un lead elegible (FOR UPDATE SKIP LOCKED, igual
    // que recomienda el manual de diseno) y registra el intento. Devuelve
    // null si no hay leads elegibles. Un solo proceso a la vez por
    // campaign_id (el CLI que llama a esto se invoca manualmente por ahora,
    // sin cron ni servicio).
    public static function claimLead(int $campaignId, string $dialPrefix, ?int $capacity = null, ?string $reservedAgent = null, bool $requireRunning = false, ?array $expectedCampaign = null): ?array {
        $db = self::database();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT * FROM v2_dialer_campaigns WHERE campaign_id=:c FOR UPDATE');
            $lock->execute(['c' => $campaignId]);
            $campaign = $lock->fetch(PDO::FETCH_ASSOC);
            if (!$campaign || !$campaign['active']) { $db->commit(); return null; }
            if ($requireRunning && $campaign['engine_state'] !== 'RUNNING') { $db->commit(); return null; }
            // Reject a route changed after the worker read it, before spending an attempt.
            if ($expectedCampaign !== null) {
                foreach (['selected_dialplan_id','dialplan_id','carrier_id','dial_prefix','outbound_prefix','dial_method','caller_id_number','dial_timeout_seconds'] as $field) {
                    if (($campaign[$field] ?? null) !== ($expectedCampaign[$field] ?? null)) { $db->commit(); return null; }
                }
            }
            if ($reservedAgent !== null && self::agentHasWork($reservedAgent)) { $db->commit(); return null; }
            if ($requireRunning && $reservedAgent!==null) {
                $db->prepare('INSERT IGNORE INTO v2_dialer_agent_status (agent_user,logged_in) VALUES (?,1)')->execute([$reservedAgent]);
                $state=$db->prepare('SELECT logged_in FROM v2_dialer_agent_status WHERE agent_user=? FOR UPDATE'); $state->execute([$reservedAgent]);
                if (!(int)$state->fetchColumn() || self::agentPause($reservedAgent)!==null) { $db->commit(); return null; }
            }
            $cap = min((int) $campaign['max_concurrent_calls'], $capacity ?? (int) $campaign['max_concurrent_calls']);
            if (self::inFlightCount($campaignId) >= $cap) { $db->commit(); return null; }
            $order = ['OLDEST_FIRST' => 'd.created_at,d.lead_id', 'RANK_ASC' => 'd.lead_id', 'RANDOM' => 'RAND()', 'LAST_CALL_ASC' => 'd.last_call_time,d.lead_id'][$campaign['lead_order']] ?? 'd.lead_id';
            $stmt = $db->prepare(
                'SELECT d.lead_id,d.phone_number FROM v2_dialer_leads d
                 JOIN v2_dialer_lists l ON l.list_id=d.list_id
                 WHERE l.campaign_id=:c AND l.active=1 AND d.status=\'NEW\' AND d.next_eligible_at<=NOW()
                 AND d.called_count<:max_attempts
                 AND NOT EXISTS (SELECT 1 FROM v2_dialer_dnc n WHERE n.phone_number=d.phone_number AND (n.campaign_id IS NULL OR n.campaign_id=l.campaign_id))
                 AND ((:start_time<=:end_time AND TIME(DATE_ADD(UTC_TIMESTAMP(),INTERVAL ROUND(d.gmt_offset*60) MINUTE)) BETWEEN :start1 AND :end1)
                   OR (:start2>:end2 AND (TIME(DATE_ADD(UTC_TIMESTAMP(),INTERVAL ROUND(d.gmt_offset*60) MINUTE))>=:start3 OR TIME(DATE_ADD(UTC_TIMESTAMP(),INTERVAL ROUND(d.gmt_offset*60) MINUTE))<=:end3)))
                 ORDER BY ' . $order . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute(['c' => $campaignId, 'max_attempts' => $campaign['max_attempts_per_lead'],
                'start_time' => $campaign['local_call_time_start'], 'end_time' => $campaign['local_call_time_end'],
                'start1' => $campaign['local_call_time_start'], 'end1' => $campaign['local_call_time_end'],
                'start2' => $campaign['local_call_time_start'], 'end2' => $campaign['local_call_time_end'],
                'start3' => $campaign['local_call_time_start'], 'end3' => $campaign['local_call_time_end']]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) { $db->commit(); return null; }
            $db->prepare('UPDATE v2_dialer_leads SET status=\'DIALING\',called_count=called_count+1,last_call_time=NOW() WHERE lead_id=:id')
                ->execute(['id' => $lead['lead_id']]);
            // Conservar el nivel usado al originar para diagnosticar abandono.
            $level = in_array($campaign['dial_method'], ['MANUAL','PROGRESSIVE'], true) ? 1 : $campaign['dial_level'];
            $db->prepare('INSERT INTO v2_dialer_call_log (campaign_id,lead_id,phone_number,originated_at,dial_level_at_origin,reserved_agent_user,agent_slot,disposition_agent_user) VALUES (:c,:l,:p,NOW(),:dl,:agent,:slot,:disposition_agent)')
                ->execute(['c' => $campaignId, 'l' => $lead['lead_id'], 'p' => $lead['phone_number'], 'dl' => $level, 'agent' => $reservedAgent, 'slot' => $reservedAgent, 'disposition_agent' => $campaign['dial_method']==='MANUAL' ? $reservedAgent : null]);
            $callId = (int) $db->lastInsertId();
            $db->prepare('UPDATE v2_dialer_call_log SET channel_uniqueid=:u WHERE call_id=:id')->execute(['u' => 'zv2dialer-' . $callId, 'id' => $callId]);
            $db->commit();
            return ['call_id' => $callId, 'lead_id' => (int) $lead['lead_id'], 'phone_number' => $lead['phone_number']];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ($e instanceof \PDOException && ($e->errorInfo[1] ?? null) === 1062) return null;
            throw $e;
        }
    }

    // Conservar el intento rechazado para diagnóstico y no dejar DIALING.
    public static function releaseFailedClaim(int $callId, int $leadId): void {
        self::closeUnanswered($callId, 'ORIGINATE_ERROR');
    }

    private static function carrierEndpoint(array $carrier): string {
        $protocol = $carrier['protocol'];
        $matches = [];
        preg_match_all('/^\s*\[([A-Za-z0-9_-]+)\]\s*\r?\n([^\[]*)/m', (string) $carrier['account_entry'], $matches, PREG_SET_ORDER);
        $endpoints = [];
        foreach ($matches as $block) {
            if (preg_match('/^\s*type\s*=\s*' . ($protocol === 'SIP' ? '(?:peer|friend)' : 'endpoint') . '\s*(?:;[^\r\n]*)?$/mi', $block[2])) $endpoints[] = $block[1];
        }
        if (count($endpoints) !== 1) throw new \RuntimeException('La troncal debe tener un único endpoint SIP/PJSIP identificable.');
        return $endpoints[0];
    }

    private static function messageAudio(string $name): string {
        if (!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $name)) throw new \RuntimeException('MESSAGE requiere un audio válido, relativo a sounds y sin extensión.');
        $base = realpath('/var/lib/asterisk/sounds');
        foreach (['wav', 'gsm', 'ulaw', 'alaw', 'sln'] as $extension) {
            $path = '/var/lib/asterisk/sounds/' . $name . '.' . $extension;
            $candidate = '/var/lib/asterisk/sounds';
            foreach (explode('/', $name . '.' . $extension) as $part) {
                $candidate .= '/' . $part;
                if (is_link($candidate)) throw new \RuntimeException('El audio no puede contener enlaces simbólicos.');
            }
            $real = realpath($path);
            if ($base && $real && strpos($real, $base . '/') === 0 && is_file($real) && is_readable($real) && !is_link($path)) return $name;
        }
        throw new \RuntimeException('El audio MESSAGE no existe o no es legible bajo sounds.');
    }

    public static function originateFields(array $campaign, array $lead, array $carrier): array {
        foreach (['dial_prefix', 'outbound_prefix', 'caller_id_number'] as $field) {
            if (!isset($campaign[$field]) || !preg_match('/^[0-9]{0,20}$/D', (string) $campaign[$field])) throw new \RuntimeException('Configura y guarda los prefijos y Caller ID antes de marcar.');
        }
        if ($campaign['dial_prefix'] === '' || !preg_match('/^\+?[0-9]{1,20}$/D', (string) $lead['phone_number']) || (int) $lead['call_id'] < 1) throw new \RuntimeException('Destino de marcación inválido.');
        if (($carrier['active'] ?? '') !== 'Y' || !in_array($carrier['protocol'], ['SIP', 'PJSIP'], true)) throw new \RuntimeException('Troncal SIP/PJSIP inactiva o inválida.');
        foreach (['drop'=>'drop_action','amd'=>'amd_action_machine'] as $kind=>$action) {
            if (($campaign[$action] ?? '')==='MESSAGE') self::messageAudio((string)($campaign[$kind.'_message'] ?? ''));
        }
        $endpoint = $carrier['endpoint'] ?? self::carrierEndpoint($carrier);
        if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$endpoint)) throw new \RuntimeException('Endpoint de salida inválido.');
        $number = $campaign['outbound_prefix'] === '' ? $lead['phone_number'] : $campaign['outbound_prefix'] . ltrim($lead['phone_number'], '+');
        $channel = $carrier['protocol'] === 'SIP' ? 'SIP/' . $endpoint . '/' . $number : 'PJSIP/' . $number . '@' . $endpoint;
        return ['Channel' => $channel, 'Context' => 'zynervoxv2', 'Exten' => $campaign['dial_prefix'] . ltrim($lead['phone_number'], '+'),
            'Priority' => '1', 'Timeout' => (string) ((int) $campaign['dial_timeout_seconds'] * 1000),
            'CallerID' => $campaign['caller_id_number'], 'Async' => 'true', 'ChannelId' => 'zv2dialer-' . (int) $lead['call_id'],
            'Variable' => '__ZV2_DIALER_CALL_ID=' . (int) $lead['call_id']];
    }

    private static function routedFields(array $campaign, array $lead): array {
        if (empty($campaign['selected_dialplan_id'])) {
            require_once __DIR__.'/Carriers.php';
            $carrier=\Includes\Carriers::getById($campaign['carrier_id']);
            if (!$carrier) throw new \RuntimeException('Selecciona un dialplan de salida.');
            return self::originateFields($campaign,$lead,$carrier);
        }
        require_once __DIR__.'/Dialplans.php';
        $route=\Includes\Dialplans::outboundRoute((int)$campaign['selected_dialplan_id']);
        // Reuse callback validation; the endpoint and prefix come solely from the selected dialplan.
        $campaign['outbound_prefix']=$route['outbound_prefix'];
        return self::originateFields($campaign,$lead,['active'=>'Y','protocol'=>$route['protocol'],'endpoint'=>$route['endpoint']]);
    }

    public static function originateNext(int $campaignId, ?string $agentUser = null): ?int {
        $campaign = self::campaign($campaignId);
        if (!$campaign['active']) return null;
        if ($campaign['engine_state'] !== 'RUNNING') return null;
        if ($agentUser !== null && !in_array($agentUser, self::agentsForCampaign($campaignId), true)) throw new \RuntimeException('Campaña no asignada a este agente.');
        if ($agentUser !== null && $campaign['dial_method'] !== 'MANUAL') throw new \RuntimeException('La marcación individual requiere modo MANUAL.');
        if ($agentUser !== null) self::reconcileUnanswered($campaignId);
        if ($agentUser === null && $campaign['dial_method'] === 'MANUAL') return null;
        // Validar antes de reservar, sin registrar ni mostrar credenciales.
        self::routedFields($campaign, ['call_id' => 1, 'phone_number' => '0']);
        if (!is_dir(self::RECORDING_DIRECTORY) || !is_writable(self::RECORDING_DIRECTORY)) throw new \RuntimeException('Directorio de grabación no disponible.');
        $agents = self::availableAgentsCount($campaignId, 'WAITING');
        // No originar sin al menos un dispositivo disponible, incluso MANUAL.
        if ($agents === 0) return null;
        $level = $campaign['dial_method'] === 'ADAPTIVE' ? self::recalculateDialLevel($campaignId) : (float) $campaign['dial_level'];
        if ($agentUser !== null && (!self::agentWaiting($agentUser, $campaign) || self::agentHasWork($agentUser))) return null;
        $count = self::availableAgentsCount($campaignId, $campaign['level_count_mode']);
        $reservedAgent = $agentUser;
        if ($campaign['dial_method'] === 'PROGRESSIVE') {
            foreach (self::agentsForCampaign($campaignId) as $candidate) {
                if (!self::agentHasWork($candidate) && self::agentWaiting($candidate, $campaign)) { $reservedAgent = $candidate; break; }
            }
            if ($reservedAgent === null) return null;
        }
        $capacity = $reservedAgent !== null ? (int) $campaign['max_concurrent_calls'] : (int) floor($count * $level);
        $lead = self::claimLead($campaignId, $campaign['dial_prefix'], $capacity, $reservedAgent, true, $campaign);
        if ($lead === null) return null;
        try {
            if ($reservedAgent !== null) self::suspendReservedAgent($reservedAgent);
            $fields = self::routedFields($campaign, $lead);
            if ($campaign['dial_method'] === 'MANUAL') {
                [$interface] = self::agentInterface($agentUser);
                $fields = self::manualOriginateFields($campaign, $fields, $interface);
            }
            \Includes\PbxAmi::rawAction(\Includes\PbxAmi::load(), 'Originate', $fields);
        } catch (\Throwable $error) {
            // Una respuesta AMI perdida puede haber originado: el reconciliador
            // conserva el cupo hasta confirmar ausencia. No liberar a ciegas.
            self::audit('ORIGINATE_UNCONFIRMED', (string) $lead['call_id']);
            throw $error;
        }
        self::audit('ORIGINATE', (string) $lead['call_id']);
        return $lead['call_id'];
    }

    private static function agentWaiting(string $user, array $campaign): bool {
        [$interface] = self::agentInterface($user);
        $line = self::memberLine($campaign,$interface);
        return $line !== null && stripos($line,'(Not in use)') !== false && stripos($line,'paused') === false;
    }

    public static function manualOriginateFields(array $campaign, array $fields, string $interface): array {
        if ($campaign['dial_method'] !== 'MANUAL' || !preg_match('~^(SIP|PJSIP)/[A-Za-z0-9_-]{1,60}$~D', $interface)) throw new \RuntimeException('Anexo manual inválido.');
        if (!preg_match('~^(SIP/[A-Za-z0-9_-]{1,60}/\+?[0-9]{1,40}|PJSIP/\+?[0-9]{1,40}@[A-Za-z0-9_-]{1,60})$~D', $fields['Channel'])) throw new \RuntimeException('Destino manual inválido.');
        $fields['Variable'] .= ',__ZV2_DIALER_DESTINATION=' . $fields['Channel'] . ',__ZV2_DIALER_TIMEOUT=' . (int) $campaign['dial_timeout_seconds'];
        $fields['Channel'] = $interface;
        $fields['Context'] = 'zynervoxv2-dialer-manual-' . (int) $campaign['campaign_id'];
        $fields['Exten'] = 's';
        return $fields;
    }

    public static function manualReady(int $callId, string $channel): void {
        $q = self::database()->prepare('SELECT l.campaign_id,l.reserved_agent_user FROM v2_dialer_call_log l JOIN v2_dialer_campaigns c ON c.campaign_id=l.campaign_id WHERE l.call_id=:id AND l.ended_at IS NULL AND l.answered_at IS NULL AND c.dial_method=\'MANUAL\'');
        $q->execute(['id'=>$callId]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['reserved_agent_user'] || !in_array($row['reserved_agent_user'],self::agentsForCampaign((int)$row['campaign_id']),true)) throw new \RuntimeException('Reserva manual inválida.');
        [$interface] = self::agentInterface($row['reserved_agent_user']);
        if (!preg_match('~^' . preg_quote($interface,'~') . '-[a-f0-9]+$~D', $channel)) throw new \RuntimeException('Canal ajeno a la reserva manual.');
    }

    public static function closeManual(int $callId, string $dialStatus, string $uniqueId): void {
        $q=self::database()->prepare('SELECT answered_at FROM v2_dialer_call_log WHERE call_id=:id AND ended_at IS NULL');
        $q->execute(['id'=>$callId]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        if ($row['answered_at'] !== null) self::closeCall($callId,'',$uniqueId);
        elseif (!self::closeUnanswered($callId,in_array($dialStatus,['BUSY','NOANSWER','CONGESTION','CHANUNAVAIL'],true) ? $dialStatus : 'HANGUP')) {
            // The answer hook may commit between the read and the preanswer closure.
            self::closeCall($callId,'',$uniqueId);
        }
    }

    public static function reconcileUnanswered(int $campaignId): int {
        $db = self::database();
        $stmt = $db->prepare('SELECT l.call_id,l.channel_uniqueid,l.answered_at FROM v2_dialer_call_log l JOIN v2_dialer_campaigns c ON c.campaign_id=l.campaign_id
            WHERE l.campaign_id=:c AND l.ended_at IS NULL
              AND ((l.answered_at IS NULL AND l.originated_at<NOW()-INTERVAL (c.dial_timeout_seconds+5) SECOND)
                OR l.answered_at<NOW()-INTERVAL 5 SECOND)');
        $stmt->execute(['c' => $campaignId]);
        $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$pending) return 0;
        $output = \Includes\PbxAmi::command(\Includes\PbxAmi::load(), 'core show channels concise');
        $live = [];
        foreach (explode("\n", $output) as $line) {
            if ($line === '') continue;
            if ($line === 'Message: Command output follows' || trim($line) === 'Output:') continue;
            if (strpos($line, 'Output: ') === 0) $line = substr($line, 8);
            $columns = explode('!', $line);
            if (count($columns) < 12) throw new \RuntimeException('Consulta de canales incompleta; no se liberan intentos.');
            $live[end($columns)] = true;
        }
        $closed = 0;
        foreach ($pending as $call) {
            if (strpos((string) $call['channel_uniqueid'], 'zv2dialer-') === 0 && !isset($live[$call['channel_uniqueid']])) {
                if ($call['answered_at']!==null) { self::closeCall((int)$call['call_id'],'PBX_LOST',$call['channel_uniqueid']); $closed++; }
                elseif (self::closeUnanswered((int) $call['call_id'], 'NOANSWER')) $closed++;
            }
        }
        return $closed;
    }

    private static function closeUnanswered(int $callId, string $reason): bool {
        $db = self::database();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT lead_id FROM v2_dialer_call_log WHERE call_id=:id AND ended_at IS NULL AND answered_at IS NULL FOR UPDATE');
            $stmt->execute(['id' => $callId]);
            $leadId = $stmt->fetchColumn();
            if (!$leadId) { $db->commit(); return false; }
            $db->prepare('UPDATE v2_dialer_call_log SET ended_at=NOW(),termination_reason=:r,agent_slot=NULL WHERE call_id=:id')->execute(['r' => $reason, 'id' => $callId]);
            $db->prepare("UPDATE v2_dialer_leads SET status='NEW',next_eligible_at=NOW()+INTERVAL 5 MINUTE WHERE lead_id=:l")->execute(['l' => $leadId]);
            $db->commit();
            self::releaseReservedAgent($callId);
            return true;
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    // ---------------------------------------------------------------- Closer (Fase 5)
    // Llamado desde dialer_call_tracking_agi.php en cada paso del dialplan
    // generado por syncDialplan(). Reemplaza la aproximacion por ventana de
    // tiempo de inFlightCount() con cierre real basado en lo que de verdad
    // pasó en la llamada.

    public static function markAnswered(int $callId, string $uniqueId = ''): void {
        $db = self::database();
        $db->beginTransaction();
        try {
        $stmt = $db->prepare('SELECT ended_at FROM v2_dialer_call_log WHERE call_id=:id FOR UPDATE');
        $stmt->execute(['id' => $callId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['ended_at'] !== null) throw new \RuntimeException('Intento inexistente o ya cerrado.');
        $path = self::RECORDING_DIRECTORY . '/call-' . $callId . '.wav';
        if (is_link($path)) throw new \RuntimeException('Ruta de grabación inválida.');
        $db->prepare('UPDATE v2_dialer_call_log SET answered_at=COALESCE(answered_at,NOW()),recording_path=:p,channel_uniqueid=COALESCE(NULLIF(:u,\'\'),channel_uniqueid) WHERE call_id=:id AND ended_at IS NULL')
            ->execute(['id' => $callId, 'p' => $path, 'u' => $uniqueId]);
        $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public static function verifyRecording(int $callId, string $filename): void {
        $expected = self::RECORDING_DIRECTORY . '/call-' . $callId . '.wav';
        for ($i = 0; $i < 10; $i++) {
            clearstatcache(true, $expected);
            if ($filename === $expected && !is_link($expected) && is_file($expected) && filesize($expected) >= 44) return;
            usleep(100000);
        }
        self::database()->prepare("UPDATE v2_dialer_call_log SET termination_reason='RECORDING_ERROR',recording_path=NULL WHERE call_id=:id AND ended_at IS NULL")->execute(['id' => $callId]);
        throw new \RuntimeException('Grabación no iniciada; no se entrega al agente.');
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
        $db->beginTransaction();
        try {
        $stmt = $db->prepare('SELECT lead_id FROM v2_dialer_call_log WHERE call_id=:id AND ended_at IS NULL FOR UPDATE');
        $stmt->execute(['id' => $callId]);
        $leadId = $stmt->fetchColumn();
        if (!$leadId) { $db->commit(); return; }
        $db->prepare('UPDATE v2_dialer_call_log SET ended_at=NOW(),termination_reason=\'MACHINE\',agent_slot=NULL WHERE call_id=:id')
            ->execute(['id' => $callId]);
        if ($leadId) {
            $db->prepare("UPDATE v2_dialer_leads SET status='NEW',next_eligible_at=NOW()+INTERVAL 24 HOUR WHERE lead_id=:id")
                ->execute(['id' => $leadId]);
        }
        $db->commit();
        self::releaseReservedAgent($callId);
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
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
        $connection = self::connectionFromQueueLog($uniqueId);
        $db->beginTransaction();
        try {
        $stmt = $db->prepare('SELECT lead_id,amd_result,termination_reason,agent_user,bridged_at FROM v2_dialer_call_log WHERE call_id=:id AND ended_at IS NULL FOR UPDATE');
        $stmt->execute(['id' => $callId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $db->commit(); return; }
        $leadId = $row['lead_id'];
        $dropped = in_array($queueStatus, ['TIMEOUT', 'FULL', 'JOINEMPTY', 'LEAVEEMPTY', 'JOINUNAVAIL', 'LEAVEUNAVAIL'], true);
        $dropped = $dropped || !empty($connection['abandoned']);
        $agent=$connection['agent'] ?? $row['agent_user'];
        $reason = $row['amd_result'] === 'MACHINE' ? 'MACHINE' : ($dropped ? 'DROPPED' : (($connection['epoch'] ?? null) !== null || $row['bridged_at']!==null || $queueStatus === 'CONTINUE' ? 'COMPLETED' : 'HANGUP'));
        if ($queueStatus==='PBX_LOST' && $row['amd_result']!=='MACHINE') $reason='PBX_LOST';
        if ($row['termination_reason'] === 'RECORDING_ERROR') $reason = 'RECORDING_ERROR';
        $db->prepare('UPDATE v2_dialer_call_log SET ended_at=COALESCE(ended_at,NOW()),termination_reason=:r,agent_user=:a,agent_slot=NULL,
            bridged_at=COALESCE(FROM_UNIXTIME(:epoch),bridged_at),
            duration_wait=CASE WHEN bridged_at>=answered_at THEN TIMESTAMPDIFF(SECOND,answered_at,bridged_at) ELSE duration_wait END,
            duration_talk=CASE WHEN :lost=1 THEN NULL WHEN ended_at>=bridged_at THEN TIMESTAMPDIFF(SECOND,bridged_at,ended_at) ELSE duration_talk END
            WHERE call_id=:id')
            ->execute(['r' => $reason, 'a' => $agent, 'epoch' => $connection['epoch'] ?? null, 'id' => $callId,'lost'=>$queueStatus==='PBX_LOST' ? 1 : 0]);
        if ($leadId) {
            $delay = $reason === 'MACHINE' ? 1440 : ($reason === 'COMPLETED' ? 0 : 5);
            $status = $reason === 'COMPLETED' ? 'CONTACTED' : 'NEW';
            $db->prepare("UPDATE v2_dialer_leads SET status=:s,next_eligible_at=NOW()+INTERVAL :d MINUTE WHERE lead_id=:id")
                ->execute(['s' => $status, 'd' => $delay, 'id' => $leadId]);
        }
        $db->commit();
        if (!empty($agent)) {
            try { self::pauseForWrapup($agent); }
            catch (\Throwable $error) { self::audit('WRAPUP_PAUSE_ERROR', $agent); }
        } else self::releaseReservedAgent($callId);
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    // Busca en queue_log (texto plano, formato
    // epoch|callid|queue|agent|event|...) la línea CONNECT de este
    // uniqueid y traduce la interfaz (SIP/10000) al login del agente via
    // v2_phones. Best-effort: si no encuentra nada devuelve null, nunca
    // rompe el cierre de la llamada.
    private static function connectionFromQueueLog(string $uniqueId): ?array {
        $path = '/var/log/asterisk/queue_log';
        if ($uniqueId === '' || !is_file($path) || !is_readable($path)) return null;
        $handle = @fopen($path, 'r');
        if (!$handle) return null;
        try {
            fseek($handle, 0, SEEK_END);
            $size = ftell($handle);
            $chunk = (string) @stream_get_contents($handle, min($size, 200000), max(0, $size - 200000));
        } finally { fclose($handle); }
        $connection = self::parseQueueConnection($chunk, $uniqueId);
        $abandoned = false;
        foreach (explode("\n", $chunk) as $line) {
            $cols = explode('|', $line);
            if (($cols[1] ?? '') === $uniqueId && ($cols[4] ?? '') === 'ABANDON') $abandoned = true;
        }
        if ($connection === null) return ['agent' => null, 'epoch' => null, 'abandoned' => $abandoned];
        $interface = $connection['interface'];
        [, $extension] = explode('/', $interface, 2);
        try {
            foreach (\Includes\Phones::getAll() as $phone) {
                if ($phone['extension'] === $extension) return ['agent' => $phone['login'], 'epoch' => $connection['epoch'], 'abandoned' => false];
            }
        } catch (\Throwable $e) { /* La identidad best-effort no debe impedir el cierre. */ }
        return ['agent' => null, 'epoch' => $connection['epoch'], 'abandoned' => false];
    }

    private static function parseQueueConnection(string $chunk, string $uniqueId): ?array {
        if ($uniqueId === '') return null;
        foreach (array_reverse(explode("\n", $chunk)) as $line) {
            $cols = explode('|', $line);
            if (count($cols) >= 5 && $cols[1] === $uniqueId && $cols[4] === 'CONNECT'
                && ctype_digit($cols[0]) && (int) $cols[0] > 0 && strpos($cols[3], '/') !== false) {
                return ['interface' => $cols[3], 'epoch' => (int) $cols[0]];
            }
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
        if (!empty($campaign['adaptive_recalculated_at']) && strtotime($campaign['adaptive_recalculated_at'])>time()-10) return $level;
        $stmt = self::database()->prepare(
            "SELECT COUNT(*) placed,
                    SUM(answered_at IS NOT NULL AND (amd_result IS NULL OR amd_result<>'MACHINE')) answered,
                    SUM(answered_at IS NOT NULL AND COALESCE(amd_result,'')<>'MACHINE' AND termination_reason='DROPPED') dropped
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
        self::database()->prepare('UPDATE v2_dialer_campaigns SET dial_level=:l,adaptive_recalculated_at=NOW() WHERE campaign_id=:c')
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
            if (!preg_match('~(?:^|[\s(])((?:SIP|PJSIP|Local)/[A-Za-z0-9_/@.-]+)(?:[\s)])~', $line, $member)) continue;
            $paused = preg_match('/\(paused\b/i', $line) === 1;
            $free = stripos($line, '(not in use)') !== false;
            [, $extension] = explode('/',$member[1],2);
            $phone = \Includes\Phones::getById($extension);
            if ($phone && (!self::agentLoggedIn($phone['login']) || ($levelCountMode!=='LOGGED_IN' && self::agentPause($phone['login'])!==null))) continue;
            if ($levelCountMode === 'WAITING' && (!$free || $paused)) continue;
            if ($levelCountMode === 'WAITING') {
                if ($phone && self::agentHasWork($phone['login'])) continue;
            }
            if ($levelCountMode === 'NON_PAUSED' && $paused) continue;
            $members++;
        }
        return $members;
    }

    // ------------------------------------------------------------- Reportería (Fase 7)
    // KPIs del manual §10, calculados directo de v2_dialer_call_log — nada
    // de instrumentación aparte.

    private static function reportDates(string $sinceDate, string $untilDate): array {
        $since = $sinceDate !== '' ? $sinceDate : date('Y-m-d', strtotime('-30 days'));
        $until = $untilDate !== '' ? $untilDate : date('Y-m-d');
        foreach ([$since, $until] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) {
                throw new \InvalidArgumentException('Fecha inválida; utiliza AAAA-MM-DD.');
            }
        }
        if ($since > $until) throw new \InvalidArgumentException('La fecha inicial debe ser anterior o igual a la final.');
        return ['since' => $since, 'until' => $until, 'before' => (new \DateTimeImmutable($until))->modify('+1 day')->format('Y-m-d')];
    }

    public static function kpis(int $campaignId, string $sinceDate = '', string $untilDate = ''): array {
        $dates = self::reportDates($sinceDate, $untilDate);
        self::campaign($campaignId);
        $stmt = self::database()->prepare(
            "SELECT
                COUNT(*) placed,
                SUM(l.answered_at IS NOT NULL) answered_total,
                SUM(l.answered_at IS NOT NULL AND COALESCE(l.amd_result,'')<>'MACHINE') answered_human,
                SUM(l.answered_at IS NOT NULL AND l.amd_result='MACHINE') answered_machine,
                SUM(l.answered_at IS NOT NULL AND COALESCE(l.amd_result,'')<>'MACHINE' AND l.termination_reason='DROPPED') dropped,
                SUM(l.termination_reason='COMPLETED' AND d.is_contact=1) contacted,
                SUM(l.termination_reason='COMPLETED' AND d.is_contact=1 AND d.is_sale=1) sales,
                SUM(l.ended_at IS NULL) in_progress,
                COUNT(CASE WHEN l.bridged_at IS NOT NULL AND l.duration_wait>=0 THEN 1 END) wait_samples,
                AVG(CASE WHEN l.bridged_at IS NOT NULL AND l.duration_wait>=0 THEN l.duration_wait END) avg_wait_seconds,
                COUNT(CASE WHEN l.ended_at IS NOT NULL AND l.bridged_at IS NOT NULL AND l.duration_talk>=0 AND l.duration_wrapup>=0 THEN 1 END) aht_samples,
                AVG(CASE WHEN l.ended_at IS NOT NULL AND l.bridged_at IS NOT NULL AND l.duration_talk>=0 AND l.duration_wrapup>=0 THEN l.duration_talk+l.duration_wrapup END) aht_seconds
             FROM v2_dialer_call_log l
             LEFT JOIN v2_dialer_dispositions d ON d.campaign_id=l.campaign_id AND d.code=l.disposition_code
             WHERE l.campaign_id=:c AND l.originated_at>=:since AND l.originated_at<:before"
        );
        $stmt->execute(['c' => $campaignId, 'since' => $dates['since'], 'before' => $dates['before']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $placed = (int) ($row['placed'] ?? 0);
        $answeredTotal = (int) ($row['answered_total'] ?? 0);
        $answeredHuman = (int) ($row['answered_human'] ?? 0);
        $answeredMachine = (int) ($row['answered_machine'] ?? 0);
        $dropped = (int) ($row['dropped'] ?? 0);
        $pct = static function ($num, $den) { return $den > 0 ? round($num / $den * 100, 2) : null; };
        $occupancy = self::occupancy($campaignId,$dates['since'],$dates['before']);
        return [
            'since' => $dates['since'],
            'until' => $dates['until'],
            'placed' => $placed,
            'answered_total' => $answeredTotal,
            'answered_human' => $answeredHuman,
            'answered_machine' => $answeredMachine,
            'dropped' => $dropped,
            'contacted' => (int) ($row['contacted'] ?? 0),
            'sales' => (int) ($row['sales'] ?? 0),
            'in_progress' => (int) ($row['in_progress'] ?? 0),
            'wait_samples' => (int) ($row['wait_samples'] ?? 0),
            'aht_samples' => (int) ($row['aht_samples'] ?? 0),
            'abandon_pct' => $pct($dropped, $answeredHuman),
            'contact_pct' => $pct($answeredHuman, $placed),
            'asr_pct' => $pct($answeredTotal, $placed),
            'machine_pct' => $pct($answeredMachine, $answeredTotal),
            'conversion_pct' => $pct((int) ($row['sales'] ?? 0), (int) ($row['contacted'] ?? 0)),
            'occupancy_pct' => $occupancy['pct'],
            'logged_seconds' => $occupancy['logged'],
            'occupancy_coverage' => $occupancy['coverage'],
            'avg_wait_seconds' => isset($row['avg_wait_seconds']) ? round((float) $row['avg_wait_seconds'], 1) : null,
            'aht_seconds' => isset($row['aht_seconds']) ? round((float) $row['aht_seconds'], 1) : null,
        ];
    }

    private static function occupancy(int $campaignId, string $since, string $before): array {
        $db = self::database();
        $stmt = $db->prepare('SELECT agent_user,state,UNIX_TIMESTAMP(started_at) start_epoch,UNIX_TIMESTAMP(COALESCE(ended_at,LEAST(NOW(),last_seen_at+INTERVAL 5 SECOND))) end_epoch FROM v2_dialer_agent_intervals WHERE campaign_id=:c AND started_at<:b');
        $stmt->execute(['c'=>$campaignId,'b'=>$before]);
        $from = strtotime($since); $to = strtotime($before);
        $segments = []; $earliest = null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $earliest = $earliest === null ? (int)$row['start_epoch'] : min($earliest,(int)$row['start_epoch']);
            if ($row['state'] === 'PAUSED') continue;
            $a=max($from,(int)$row['start_epoch']); $b=min($to,(int)$row['end_epoch']);
            if ($b>$a) $segments[$row['agent_user']][]=[$a,$b];
        }
        $logged=0;
        foreach ($segments as $user=>$ranges) {
            usort($ranges,static function ($a,$b) { return $a[0]<=>$b[0]; });
            $merged=[];
            foreach ($ranges as $range) {
                $last=count($merged)-1;
                if ($last>=0 && $range[0]<=$merged[$last][1]) $merged[$last][1]=max($range[1],$merged[$last][1]);
                else $merged[]=$range;
            }
            $segments[$user]=$merged;
            foreach ($merged as $range) $logged+=$range[1]-$range[0];
        }
        $stmt=$db->prepare('SELECT agent_user,UNIX_TIMESTAMP(bridged_at) bridge_epoch,UNIX_TIMESTAMP(ended_at) end_epoch,duration_wrapup FROM v2_dialer_call_log WHERE campaign_id=:c AND bridged_at IS NOT NULL AND bridged_at<:b AND ended_at>=:s');
        $stmt->execute(['c'=>$campaignId,'b'=>$before,'s'=>$since]);
        $busy=0; $historical=false;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($earliest===null || (int)$row['bridge_epoch']<$earliest) $historical=true;
            $a=max($from,(int)$row['bridge_epoch']);
            $b=min($to,(int)$row['end_epoch']+(int)$row['duration_wrapup']);
            $covered=0;
            foreach ($segments[$row['agent_user']] ?? [] as $range) $covered+=max(0,min($b,$range[1])-max($a,$range[0]));
            if ($covered<max(0,$b-$a)) $historical=true;
            $busy+=$covered;
        }
        return ['pct'=>$logged>0 && !$historical ? round(min($busy,$logged)/$logged*100,2) : null,'logged'=>$logged,'coverage'=>$earliest===null ? 'none' : ($historical ? 'partial' : 'observed')];
    }

    // "¿Hasta qué nivel puedo subir sin pasarme del abandono permitido?"
    // (manual §10.1) — el motivo por el que dial_level_at_origin existe.
    public static function abandonByLevel(int $campaignId, string $sinceDate = '', string $untilDate = ''): array {
        $dates = self::reportDates($sinceDate, $untilDate);
        self::campaign($campaignId);
        $stmt = self::database()->prepare(
            "SELECT dial_level_at_origin,
                    COUNT(*) llamadas,
                    SUM(answered_at IS NOT NULL AND COALESCE(amd_result,'')<>'MACHINE') humanos,
                    SUM(answered_at IS NOT NULL AND COALESCE(amd_result,'')<>'MACHINE' AND termination_reason='DROPPED') abandonos,
                    ROUND(SUM(answered_at IS NOT NULL AND COALESCE(amd_result,'')<>'MACHINE' AND termination_reason='DROPPED')/
                        NULLIF(SUM(answered_at IS NOT NULL AND COALESCE(amd_result,'')<>'MACHINE'),0)*100,2) abandono_pct
             FROM v2_dialer_call_log
             WHERE campaign_id=:c AND originated_at>=:since AND originated_at<:before AND dial_level_at_origin IS NOT NULL
             GROUP BY dial_level_at_origin ORDER BY dial_level_at_origin"
        );
        $stmt->execute(['c' => $campaignId, 'since' => $dates['since'], 'before' => $dates['before']]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        if ($isSale) $isContact=true;
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

    public static function setEngineState(int $campaignId, string $state): void {
        if (!in_array($state, ['RUNNING','STOPPED'], true)) throw new \InvalidArgumentException('Estado del motor invalido.');
        $db=self::database(); $db->beginTransaction();
        try {
            $lock=$db->prepare('SELECT * FROM v2_dialer_campaigns WHERE campaign_id=? FOR UPDATE'); $lock->execute([$campaignId]); $campaign=$lock->fetch(PDO::FETCH_ASSOC);
            if (!$campaign) throw new \RuntimeException('Campaña inexistente.');
            if ($state === 'RUNNING') {
                if (!$campaign['active'] || !$campaign['dial_prefix']) throw new \RuntimeException('Activa y configura la campaña antes de iniciar.');
                self::routedFields($campaign, ['call_id'=>1,'phone_number'=>'0']);
            }
            $db->prepare('UPDATE v2_dialer_campaigns SET engine_state=:s,worker_error=NULL WHERE campaign_id=:c')->execute(['s'=>$state,'c'=>$campaignId]);
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        self::audit('ENGINE_'.$state, (string) $campaignId);
    }

    public static function workerTick(): void {
        foreach (self::campaigns() as $campaign) {
            $id = (int) $campaign['campaign_id'];
            try {
                self::reconcileUnanswered($id);
                self::observeAgentSessions($id);
                if ($campaign['active'] && $campaign['engine_state'] === 'RUNNING' && $campaign['dial_method'] !== 'MANUAL') {
                    // One cycle can fill several free slots; every claim rechecks DB state/capacity.
                    for ($i=0; $i<min(25,(int)$campaign['max_concurrent_calls']); $i++) if (self::originateNext($id) === null) break;
                }
                self::database()->prepare('UPDATE v2_dialer_campaigns SET worker_seen_at=NOW(),worker_error=NULL WHERE campaign_id=:c')->execute(['c'=>$id]);
            } catch (\Throwable $error) {
                $message = mb_substr($error->getMessage(),0,255,'UTF-8');
                self::database()->prepare('UPDATE v2_dialer_campaigns SET worker_seen_at=NOW(),worker_error=:e WHERE campaign_id=:c')->execute(['e'=>$message,'c'=>$id]);
                error_log('dialer campaign '.$id.': '.$message);
            }
        }
    }

    private static function personalQueue(int $campaignId, string $user): string {
        return 'v2dialer_'.$campaignId.'_a_'.substr(hash('sha256',$user),0,16);
    }

    public static function targetQueue(int $callId): string {
        $stmt = self::database()->prepare('SELECT l.campaign_id,l.reserved_agent_user,c.queue_name FROM v2_dialer_call_log l JOIN v2_dialer_campaigns c ON c.campaign_id=l.campaign_id WHERE l.call_id=:id');
        $stmt->execute(['id'=>$callId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \RuntimeException('Intento inexistente.');
        return $row['reserved_agent_user'] !== null ? self::personalQueue((int)$row['campaign_id'],$row['reserved_agent_user']) : $row['queue_name'];
    }

    private static function agentHasWork(string $user): bool {
        $stmt = self::database()->prepare('SELECT 1 FROM v2_dialer_call_log WHERE agent_slot=:slot OR (COALESCE(agent_user,disposition_agent_user)=:agent AND ended_at IS NOT NULL AND disposition_code IS NULL) LIMIT 1');
        $stmt->execute(['slot'=>$user,'agent'=>$user]);
        return (bool) $stmt->fetchColumn();
    }

    private static function suspendReservedAgent(string $user): void {
        [$interface] = self::agentInterface($user);
        foreach (self::campaignsForAgent($user) as $campaign) {
            $line=self::memberLine($campaign,$interface);
            if ($line!==null && stripos($line,'paused')===false) \Includes\PbxAmi::rawAction(\Includes\PbxAmi::load(),'QueuePause',['Queue'=>$campaign['queue_name'],'Interface'=>$interface,'Paused'=>'true','Reason'=>'DIALER_RESERVED']);
        }
    }

    private static function releaseReservedAgent(int $callId): void {
        $stmt=self::database()->prepare('SELECT reserved_agent_user FROM v2_dialer_call_log WHERE call_id=:id'); $stmt->execute(['id'=>$callId]);
        $user=$stmt->fetchColumn(); if (!$user) return;
        $campaigns=self::campaignsForAgent($user); if (!$campaigns) return;
        [$interface]=self::agentInterface($user);
        foreach ($campaigns as $campaign) {
            self::applyAgentState($user,$campaign);
        }
    }

    private static function sessionState(string $user, int $campaignId, ?string $state): void {
        $db = self::database();
        $slot = $campaignId.':'.$user;
        $db->beginTransaction();
        try {
            // Campaign lock also serializes initial login, where no interval exists yet.
            $db->prepare('SELECT campaign_id FROM v2_dialer_campaigns WHERE campaign_id=:c FOR UPDATE')->execute(['c'=>$campaignId]);
            $stmt = $db->prepare('SELECT interval_id,state,last_seen_at FROM v2_dialer_agent_intervals WHERE open_slot=:slot FOR UPDATE');
            $stmt->execute(['slot'=>$slot]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && strtotime($row['last_seen_at']) < time()-15) {
                $db->prepare('UPDATE v2_dialer_agent_intervals SET ended_at=last_seen_at+INTERVAL 5 SECOND,open_slot=NULL WHERE interval_id=:id')->execute(['id'=>$row['interval_id']]);
                $row=null;
            }
            if ($row && $row['state'] === $state) {
                $db->prepare('UPDATE v2_dialer_agent_intervals SET last_seen_at=NOW() WHERE interval_id=:id')->execute(['id'=>$row['interval_id']]);
            } else {
                if ($row) $db->prepare('UPDATE v2_dialer_agent_intervals SET ended_at=NOW(),last_seen_at=NOW(),open_slot=NULL WHERE interval_id=:id')->execute(['id'=>$row['interval_id']]);
                if ($state !== null) $db->prepare('INSERT INTO v2_dialer_agent_intervals (campaign_id,agent_user,state,open_slot) VALUES (:c,:u,:s,:slot)')->execute(['c'=>$campaignId,'u'=>$user,'s'=>$state,'slot'=>$slot]);
            }
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    }

    private static function memberLine(array $campaign, string $interface): ?string {
        $output = \Includes\PbxAmi::command(\Includes\PbxAmi::load(),'queue show '.$campaign['queue_name']);
        foreach (explode("\n",$output) as $line) {
            $line=preg_replace('/^Output:\s*/','',$line);
            if (strpos($line,'('.$interface.')') !== false || preg_match('~^\s*'.preg_quote($interface,'~').'\s~',$line)) return $line;
        }
        return null;
    }

    public static function observeAgentSessions(int $campaignId): void {
        $campaign = self::campaign($campaignId);
        $stmt = self::database()->prepare('SELECT DISTINCT agent_user FROM v2_dialer_agent_intervals WHERE campaign_id=:c AND open_slot IS NOT NULL');
        $stmt->execute(['c'=>$campaignId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $user) {
            if (!$campaign['active'] || !in_array($user,self::agentsForCampaign($campaignId),true)) { self::sessionState($user,$campaignId,null); continue; }
            if (!self::agentLoggedIn($user) || self::agentPause($user)!==null) { self::applyAgentState($user,$campaign); continue; }
            [$interface] = self::agentInterface($user);
            $line = self::memberLine($campaign,$interface);
            if ($line === null) { self::sessionState($user,$campaignId,null); continue; }
            if (strpos($line,'DIALER_RESERVED')!==false && !self::agentHasWork($user)) {
                \Includes\PbxAmi::rawAction(\Includes\PbxAmi::load(),'QueuePause',['Queue'=>$campaign['queue_name'],'Interface'=>$interface,'Paused'=>'false']);
                $line=self::memberLine($campaign,$interface);
            }
            if (strpos($line,'DIALER_WRAPUP')!==false && self::pendingDispositionForAgent($user)===null) {
                self::agentSetPause($user,$campaignId,false);
                $line=self::memberLine($campaign,$interface);
            }
            if (self::pendingDispositionForAgent($user) !== null && stripos($line,'paused') === false) self::agentSetPause($user,$campaignId,true,'DIALER_WRAPUP');
            else self::sessionState($user,$campaignId,stripos($line,'paused') === false || strpos($line,'DIALER_RESERVED')!==false ? 'ACTIVE' : (strpos($line,'DIALER_WRAPUP') !== false ? 'WRAPUP' : 'PAUSED'));
        }
    }

    private static function pauseForWrapup(string $user): void {
        foreach (self::campaignsForAgent($user) as $campaign) {
            self::applyAgentState($user,$campaign);
        }
    }

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
            'SELECT c.campaign_id,c.name,c.queue_name,c.active,c.dial_method FROM v2_dialer_agent_campaigns a
             JOIN v2_dialer_campaigns c ON c.campaign_id=a.campaign_id
             WHERE a.agent_user=:u ORDER BY c.campaign_id'
        );
        $stmt->execute(['u' => $user]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function agentPanelState(string $user): array {
        $stmt=self::database()->prepare('SELECT campaign_id,state,last_seen_at FROM v2_dialer_agent_intervals WHERE agent_user=:u AND open_slot IS NOT NULL');
        $stmt->execute(['u'=>$user]);
        return ['pending'=>self::pendingDispositionForAgent($user),'sessions'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'lead'=>self::agentCurrentLead($user),'pause'=>self::agentPause($user)];
    }

    public static function pauseTypes(bool $includeInactive = false): array {
        return self::database()->query('SELECT * FROM v2_dialer_pause_types' . ($includeInactive ? '' : ' WHERE active=1') . ' ORDER BY label,pause_type_id')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function savePauseType(int $id, string $code, string $label, bool $active): void {
        $code=strtoupper(trim($code)); $label=trim($label);
        if ($id<0 || !preg_match('/^[A-Z0-9_]{1,30}$/D',$code) || $label==='' || mb_strlen($label,'UTF-8')>80 || preg_match('/[\x00-\x1f\x7f]/u',$label)) throw new \RuntimeException('Código o nombre de pausa inválido.');
        if ($id) {
            $q=self::database()->prepare('UPDATE v2_dialer_pause_types SET code=?,label=?,active=? WHERE pause_type_id=?'); $q->execute([$code,$label,(int)$active,$id]);
        } else {
            $q=self::database()->prepare('INSERT INTO v2_dialer_pause_types (code,label,active) VALUES (?,?,?)'); $q->execute([$code,$label,(int)$active]);
        }
        self::audit('PAUSE_TYPE_SAVE',(string)$id);
    }

    public static function agentPause(string $user): ?array {
        $q=self::database()->prepare('SELECT pause_id,pause_type_id,label,started_at,TIMESTAMPDIFF(SECOND,started_at,NOW()) duration_seconds FROM v2_dialer_agent_pauses WHERE open_slot=?'); $q->execute([$user]);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function agentLoggedIn(string $user): bool {
        $q=self::database()->prepare('SELECT logged_in FROM v2_dialer_agent_status WHERE agent_user=?'); $q->execute([$user]); $value=$q->fetchColumn();
        return $value!==false && (int)$value===1;
    }

    public static function agentLogin(string $user): void {
        $campaigns=self::campaignsForAgent($user);
        if (!$campaigns) return;
        self::agentInterface($user);
        self::database()->prepare('INSERT INTO v2_dialer_agent_status (agent_user,logged_in) VALUES (?,1) ON DUPLICATE KEY UPDATE logged_in=1,updated_at=NOW()')->execute([$user]);
        foreach ($campaigns as $campaign) if ($campaign['active']) self::agentJoinQueue($user,(int)$campaign['campaign_id']);
    }

    public static function agentLogout(string $user): void {
        self::database()->prepare('INSERT INTO v2_dialer_agent_status (agent_user,logged_in) VALUES (?,0) ON DUPLICATE KEY UPDATE logged_in=0,updated_at=NOW()')->execute([$user]);
        self::database()->prepare('UPDATE v2_dialer_agent_pauses SET ended_at=NOW(),open_slot=NULL WHERE open_slot=?')->execute([$user]);
        $errors=[];
        foreach (self::campaignsForAgent($user) as $campaign) {
            try { self::agentLeaveQueue($user,(int)$campaign['campaign_id']); } catch (\Throwable $error) { $errors[]=$error->getMessage(); }
        }
        if ($errors) throw new \RuntimeException('Salida de colas pendiente: ' . implode('; ',$errors));
    }

    public static function agentPauseAll(string $user, ?int $typeId): void {
        if (!self::agentLoggedIn($user) || !self::campaignsForAgent($user)) throw new \RuntimeException('Agente fuera de sesión operativa.');
        $db=self::database(); $db->beginTransaction();
        try {
            $db->prepare('INSERT IGNORE INTO v2_dialer_agent_status (agent_user,logged_in) VALUES (?,1)')->execute([$user]);
            $q=$db->prepare('SELECT logged_in FROM v2_dialer_agent_status WHERE agent_user=? FOR UPDATE'); $q->execute([$user]);
            if (!(int)$q->fetchColumn()) throw new \RuntimeException('Agente fuera de sesión operativa.');
            $type=null;
            if ($typeId!==null) {
                $q=$db->prepare('SELECT pause_type_id,label FROM v2_dialer_pause_types WHERE pause_type_id=? AND active=1'); $q->execute([$typeId]); $type=$q->fetch(PDO::FETCH_ASSOC);
                if (!$type) throw new \RuntimeException('Selecciona un tipo de pausa activo.');
            } elseif (self::pendingDispositionForAgent($user)!==null) throw new \RuntimeException('Tipifica las llamadas pendientes antes de reanudar.');
            $old=self::agentPause($user);
            if (!$old || (int)$old['pause_type_id']!==$typeId) {
                $db->prepare('UPDATE v2_dialer_agent_pauses SET ended_at=NOW(),open_slot=NULL WHERE open_slot=?')->execute([$user]);
                if ($type) $db->prepare('INSERT INTO v2_dialer_agent_pauses (agent_user,pause_type_id,label,open_slot) VALUES (?,?,?,?)')->execute([$user,$typeId,$type['label'],$user]);
            }
            $db->commit();
        } catch (\Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        $errors=[];
        foreach (self::campaignsForAgent($user) as $campaign) if ($campaign['active']) {
            try { self::applyAgentState($user,$campaign); } catch (\Throwable $error) { $errors[]=$error->getMessage(); }
        }
        if ($errors) throw new \RuntimeException('Estado guardado; sincronización con PBX pendiente: ' . implode('; ',$errors));
    }

    private static function applyAgentState(string $user, array $campaign, bool $preserveLegacyPause = true): void {
        if (!self::agentLoggedIn($user)) { self::agentLeaveQueue($user,(int)$campaign['campaign_id']); return; }
        [$interface]=self::agentInterface($user);
        $line=self::memberLine($campaign,$interface); if ($line===null) return;
        $pause=self::agentPause($user); $pending=self::pendingDispositionForAgent($user);
        if ($preserveLegacyPause && !$pause && stripos($line,'paused')!==false && !preg_match('/DIALER_(PAUSE_|WRAPUP|RESERVED)/',$line)) { self::sessionState($user,(int)$campaign['campaign_id'],'PAUSED'); return; }
        $q=self::database()->prepare('SELECT 1 FROM v2_dialer_call_log WHERE agent_slot=? AND ended_at IS NULL LIMIT 1'); $q->execute([$user]); $reserved=(bool)$q->fetchColumn();
        $reason=$pause ? 'DIALER_PAUSE_' . $pause['pause_type_id'] : ($pending ? 'DIALER_WRAPUP' : ($reserved ? 'DIALER_RESERVED' : ''));
        $ami=\Includes\PbxAmi::load();
        foreach ([$campaign['queue_name'],self::personalQueue((int)$campaign['campaign_id'],$user)] as $index=>$queue) {
            if ($index===1 && $reserved) continue; // Finish an already reserved progressive attempt.
            $member=$index===0 ? $line : self::memberLine(['queue_name'=>$queue],$interface);
            if ($member===null) continue;
            if ($reason!=='' || preg_match('/DIALER_(PAUSE_|WRAPUP|RESERVED)/',$member)) {
                $fields=['Queue'=>$queue,'Interface'=>$interface,'Paused'=>$reason!=='' ? 'true' : 'false'];
                if ($reason!=='') $fields['Reason']=$reason;
                if ($reason==='' || strpos($member,$reason)===false || stripos($member,'paused')===false) \Includes\PbxAmi::rawAction($ami,'QueuePause',$fields);
            }
        }
        $q=self::database()->prepare('SELECT 1 FROM v2_dialer_call_log WHERE ended_at IS NULL AND (agent_slot=:slot OR agent_user=:agent) LIMIT 1'); $q->execute(['slot'=>$user,'agent'=>$user]); $working=(bool)$q->fetchColumn();
        self::sessionState($user,(int)$campaign['campaign_id'],$pause && !$working ? 'PAUSED' : ($pending ? 'WRAPUP' : (stripos($line,'paused')!==false && !preg_match('/DIALER_(PAUSE_|WRAPUP|RESERVED)/',$line) ? 'PAUSED' : 'ACTIVE')));
    }

    public static function agentHangup(string $user, int $callId): void {
        $q=self::database()->prepare('SELECT channel_uniqueid FROM v2_dialer_call_log WHERE call_id=:id AND ended_at IS NULL AND (agent_user=:u OR reserved_agent_user=:reserved)');
        $q->execute(['id'=>$callId,'u'=>$user,'reserved'=>$user]); $uid=$q->fetchColumn();
        if (!$uid || $uid!=='zv2dialer-'.$callId) throw new \RuntimeException('No tienes esa llamada activa.');
        $ami=\Includes\PbxAmi::load();
        foreach (explode("\n",\Includes\PbxAmi::command($ami,'core show channels concise')) as $line) {
            $columns=explode('!',preg_replace('/^Output: /','',$line));
            if (count($columns)>=12 && end($columns)===$uid && preg_match('~^(SIP|PJSIP)/[A-Za-z0-9_-]+-[a-f0-9]+$~D',$columns[0])) {
                \Includes\PbxAmi::rawAction($ami,'Hangup',['Channel'=>$columns[0],'Cause'=>'16']);
                self::audit('AGENT_HANGUP',$user . ':' . $callId); return;
            }
        }
        throw new \RuntimeException('La llamada ya terminó o su canal no está disponible.');
    }

    public static function markBridged(int $callId, string $channel): void {
        if (!preg_match('~^(SIP|PJSIP)/([A-Za-z0-9_-]+)-[a-f0-9]+$~D',$channel,$match)) throw new \RuntimeException('Canal de agente inválido.');
        $user=null;
        foreach (\Includes\Phones::getAll() as $phone) if ($phone['protocol']===$match[1] && $phone['extension']===$match[2]) $user=$phone['login'];
        if ($user===null) throw new \RuntimeException('Agente desconocido.');
        $db=self::database(); $db->beginTransaction();
        try {
            $q=$db->prepare('SELECT campaign_id,reserved_agent_user,agent_user FROM v2_dialer_call_log WHERE call_id=:id AND answered_at IS NOT NULL AND ended_at IS NULL FOR UPDATE'); $q->execute(['id'=>$callId]); $call=$q->fetch(PDO::FETCH_ASSOC);
            if (!$call || !in_array($user,self::agentsForCampaign((int)$call['campaign_id']),true) || ($call['reserved_agent_user']!==null && $call['reserved_agent_user']!==$user)) throw new \RuntimeException('Puente ajeno a la asignación.');
            if ($call['agent_user']!==null && $call['agent_user']!==$user) throw new \RuntimeException('La identidad del puente ya está registrada.');
            $db->prepare('UPDATE v2_dialer_call_log SET agent_user=:u,bridged_at=COALESCE(bridged_at,NOW()) WHERE call_id=:id')->execute(['u'=>$user,'id'=>$callId]);
            $db->commit();
        } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    }

    public static function agentCurrentLead(string $user): ?array {
        $q=self::database()->prepare("SELECT l.call_id,l.campaign_id,c.name campaign_name,l.phone_number,l.ended_at,l.bridged_at,d.first_name,d.last_name,d.custom_fields,d.status
          FROM v2_dialer_call_log l JOIN v2_dialer_campaigns c ON c.campaign_id=l.campaign_id JOIN v2_dialer_leads d ON d.lead_id=l.lead_id
          WHERE (l.ended_at IS NULL AND (l.agent_user=:u OR l.reserved_agent_user=:reserved)) OR (l.ended_at IS NOT NULL AND COALESCE(l.agent_user,l.disposition_agent_user)=:closed AND l.disposition_code IS NULL)
          ORDER BY (l.ended_at IS NULL) DESC,l.call_id DESC LIMIT 1");
        $q->execute(['u'=>$user,'reserved'=>$user,'closed'=>$user]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['variables']=json_decode($row['custom_fields'] ?? '{}',true) ?: [];
        unset($row['custom_fields']);
        return $row;
    }

    public static function recordings(int $campaignId, string $since, string $until, string $phone='', string $agent='', int $beforeId=0): array {
        self::campaign($campaignId); $dates=self::reportDates($since,$until);
        if (!preg_match('/^[0-9+]{0,20}$/D',$phone) || !preg_match('/^[A-Za-z0-9_-]{0,60}$/D',$agent) || $beforeId<0) throw new \InvalidArgumentException('Filtros de grabaciones inválidos.');
        $q=self::database()->prepare("SELECT call_id,phone_number,agent_user,originated_at,ended_at,termination_reason,disposition_code,duration_talk,recording_path
          FROM v2_dialer_call_log WHERE campaign_id=:c AND originated_at>=:since AND originated_at<:before AND ended_at IS NOT NULL AND recording_path IS NOT NULL
          AND phone_number LIKE :phone AND (:all_agents=1 OR agent_user=:agent) AND (:first_page=1 OR call_id<:cursor) ORDER BY call_id DESC LIMIT 100");
        $q->execute(['c'=>$campaignId,'since'=>$dates['since'],'before'=>$dates['before'],'phone'=>'%'.$phone.'%','all_agents'=>$agent==='' ? 1 : 0,'agent'=>$agent,'first_page'=>$beforeId===0 ? 1 : 0,'cursor'=>$beforeId]);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['available']=self::recordingFile((int)$row['call_id'],(string)$row['recording_path'])!==null;
            unset($row['recording_path']);
        }
        return $rows;
    }

    private static function recordingFile(int $callId, string $path): ?string {
        $expected=self::RECORDING_DIRECTORY.'/call-'.$callId.'.wav';
        if ($path!==$expected || is_link($path) || realpath($path)!==$expected || !is_file($path) || !is_readable($path) || filesize($path)<44) return null;
        return $path;
    }

    public static function recording(int $callId): ?array {
        $q=self::database()->prepare('SELECT recording_path FROM v2_dialer_call_log WHERE call_id=:id AND ended_at IS NOT NULL');
        $q->execute(['id'=>$callId]); $path=$q->fetchColumn();
        if (!$path) return null;
        $file=self::recordingFile($callId,$path);
        return $file===null ? null : ['file'=>$file,'size'=>filesize($file)];
    }

    private static function agentInterface(string $user): array {
        $phone = null;
        foreach (\Includes\Phones::getAll() as $row) {
            if ($row['login'] === $user) { $phone = $row; break; }
        }
        if (!$phone) throw new \RuntimeException('No tienes un anexo asignado (Anexos/Teléfonos).');
        if (($phone['active'] ?? '') !== 'Y' || !in_array($phone['protocol'], ['SIP', 'PJSIP'], true) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $phone['extension']) || preg_match('/[\x00-\x1f]/', $phone['fullname'] ?: $user)) throw new \RuntimeException('Anexo inactivo o inválido.');
        return [$phone['protocol'] . '/' . $phone['extension'], $phone['fullname'] ?: $user];
    }

    public static function agentJoinQueue(string $user, int $campaignId): string {
        if (!in_array($user, self::agentsForCampaign($campaignId), true)) throw new \RuntimeException('Campaña no asignada.');
        $campaign = self::campaign($campaignId);
        if (!$campaign['active']) throw new \RuntimeException('Campaña inactiva.');
        [$interface, $name] = self::agentInterface($user);
        self::database()->prepare('INSERT INTO v2_dialer_agent_status (agent_user,logged_in) VALUES (?,1) ON DUPLICATE KEY UPDATE logged_in=1,updated_at=NOW()')->execute([$user]);
        $pause=self::agentPause($user); $pending=self::pendingDispositionForAgent($user);
        $q=self::database()->prepare('SELECT 1 FROM v2_dialer_call_log WHERE agent_slot=? AND ended_at IS NULL LIMIT 1'); $q->execute([$user]); $reserved=(bool)$q->fetchColumn();
        $config = \Includes\PbxAmi::load();
        $msg = 'Agente ya presente.';
        $existingLine=self::memberLine($campaign,$interface);
        if ($existingLine === null) $msg = \Includes\PbxAmi::rawAction($config, 'QueueAdd', [
            'Queue' => $campaign['queue_name'], 'Interface' => $interface,
            'MemberName' => $interface, 'StateInterface' => $interface,
            'Paused' => ($pause || $pending || $reserved) ? 'true' : 'false',
        ]);
        if (self::memberLine(['queue_name'=>self::personalQueue($campaignId,$user)],$interface) === null) \Includes\PbxAmi::rawAction($config, 'QueueAdd', ['Queue'=>self::personalQueue($campaignId,$user),'Interface'=>$interface,'MemberName'=>$interface,'StateInterface'=>$interface,'Paused'=>(($pause || $pending) && !$reserved) ? 'true' : 'false']);
        $line = self::memberLine($campaign,$interface);
        self::sessionState($user,$campaignId,stripos((string)$line,'paused') !== false && strpos((string)$line,'DIALER_RESERVED')===false ? (strpos((string)$line,'DIALER_WRAPUP') !== false ? 'WRAPUP' : 'PAUSED') : 'ACTIVE');
        self::applyAgentState($user,$campaign,$existingLine!==null);
        self::audit('AGENT_QUEUE_JOIN', $user . ':' . $campaign['queue_name']);
        return $msg;
    }

    public static function agentLeaveQueue(string $user, int $campaignId): string {
        if (!in_array($user, self::agentsForCampaign($campaignId), true)) throw new \RuntimeException('Campaña no asignada.');
        $campaign = self::campaign($campaignId);
        [$interface] = self::agentInterface($user);
        $config = \Includes\PbxAmi::load();
        $msg = 'Agente ya fuera de cola.';
        if (self::memberLine($campaign,$interface) !== null) $msg = \Includes\PbxAmi::rawAction($config, 'QueueRemove', [
            'Queue' => $campaign['queue_name'], 'Interface' => $interface,
        ]);
        if (self::memberLine(['queue_name'=>self::personalQueue($campaignId,$user)],$interface) !== null) \Includes\PbxAmi::rawAction($config, 'QueueRemove', ['Queue'=>self::personalQueue($campaignId,$user),'Interface'=>$interface]);
        self::sessionState($user,$campaignId,null);
        self::audit('AGENT_QUEUE_LEAVE', $user . ':' . $campaign['queue_name']);
        return $msg;
    }

    public static function agentSetPause(string $user, int $campaignId, bool $paused, string $reason = ''): string {
        if (!in_array($user, self::agentsForCampaign($campaignId), true)) throw new \RuntimeException('Campaña no asignada.');
        if (strlen($reason) > 80 || preg_match('/[\x00-\x1f]/', $reason)) throw new \RuntimeException('Motivo de pausa inválido.');
        if (!$paused && self::pendingDispositionForAgent($user) !== null) throw new \RuntimeException('Tipifica las llamadas pendientes antes de reanudar.');
        $campaign = self::campaign($campaignId);
        [$interface] = self::agentInterface($user);
        $config = \Includes\PbxAmi::load();
        $fields = ['Queue' => $campaign['queue_name'], 'Interface' => $interface, 'Paused' => $paused ? 'true' : 'false'];
        if ($reason !== '') $fields['Reason'] = $reason;
        $msg = \Includes\PbxAmi::rawAction($config, 'QueuePause', $fields);
        $fields['Queue'] = self::personalQueue($campaignId,$user);
        \Includes\PbxAmi::rawAction($config, 'QueuePause', $fields);
        self::sessionState($user,$campaignId,$paused ? ($reason === 'DIALER_WRAPUP' ? 'WRAPUP' : 'PAUSED') : 'ACTIVE');
        self::audit('AGENT_QUEUE_PAUSE', $user . ':' . $campaign['queue_name'] . ':' . ($paused ? '1' : '0'));
        return $msg;
    }

    // Ultima llamada de este agente sin tipificar todavia (para el panel).
    public static function pendingDispositionForAgent(string $user): ?array {
        $stmt = self::database()->prepare(
            "SELECT l.call_id,l.campaign_id,l.phone_number,l.originated_at,l.termination_reason,c.name campaign_name
             FROM v2_dialer_call_log l JOIN v2_dialer_campaigns c ON c.campaign_id=l.campaign_id
             WHERE COALESCE(l.agent_user,l.disposition_agent_user)=:u AND l.ended_at IS NOT NULL AND l.disposition_code IS NULL ORDER BY l.call_id DESC LIMIT 1"
        );
        $stmt->execute(['u' => $user]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public static function setDisposition(int $callId, string $user, string $code): void {
        $stmt = self::database()->prepare('SELECT campaign_id,agent_user,disposition_agent_user,ended_at,lead_id FROM v2_dialer_call_log WHERE call_id=:id');
        $stmt->execute(['id' => $callId]);
        $call = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$call || ($call['agent_user'] ?? $call['disposition_agent_user']) !== $user) throw new \RuntimeException('Llamada inexistente o de otro agente.');
        if ($call['ended_at'] === null) throw new \RuntimeException('La llamada todavía no ha terminado.');
        $valid = self::database()->prepare('SELECT retryable,retry_delay_minutes FROM v2_dialer_dispositions WHERE campaign_id=:c AND code=:code');
        $valid->execute(['c' => $call['campaign_id'], 'code' => $code]);
        $disposition=$valid->fetch(PDO::FETCH_ASSOC);
        if (!$disposition) throw new \RuntimeException('Disposición inválida para esta campaña.');
        $observed=self::database()->prepare("SELECT SUM(GREATEST(0,TIMESTAMPDIFF(SECOND,GREATEST(started_at,:closed),LEAST(COALESCE(ended_at,LEAST(NOW(),last_seen_at+INTERVAL 5 SECOND)),NOW())))) FROM v2_dialer_agent_intervals WHERE campaign_id=:c AND agent_user=:u AND state='WRAPUP' AND COALESCE(ended_at,NOW())>=:closed2");
        $observed->execute(['closed'=>$call['ended_at'],'closed2'=>$call['ended_at'],'c'=>$call['campaign_id'],'u'=>$user]);
        $wrapup=$observed->fetchColumn();
        // A failed MANUAL attempt needs a disposition, not invented conversational wrapup.
        if ($call['agent_user']===null && $call['disposition_agent_user']!==null) $wrapup=null;
        self::database()->prepare('UPDATE v2_dialer_call_log SET disposition_code=:code,
            duration_wrapup=COALESCE(duration_wrapup,:wrapup),
            ended_at=COALESCE(ended_at,NOW()) WHERE call_id=:id')
            ->execute(['code' => $code, 'id' => $callId,'wrapup'=>$wrapup===false || $wrapup===null ? null : (int)$wrapup]);
        // A correction to an old call must never recycle a newer active attempt.
        self::database()->prepare("UPDATE v2_dialer_leads SET status=:status,next_eligible_at=NOW()+INTERVAL :delay MINUTE WHERE lead_id=:lead AND NOT EXISTS (SELECT 1 FROM v2_dialer_call_log WHERE lead_id=:lead2 AND call_id>:call)")
            ->execute(['status'=>$disposition['retryable'] ? 'NEW' : 'CONTACTED','delay'=>(int)$disposition['retry_delay_minutes'],'lead'=>$call['lead_id'],'lead2'=>$call['lead_id'],'call'=>$callId]);
        self::audit('DISPOSITION_SET', $callId . ':' . $code);
        if (self::pendingDispositionForAgent($user) === null) {
            foreach (self::campaignsForAgent($user) as $campaign) {
                self::applyAgentState($user,$campaign);
            }
        }
    }

    public static function setCampaignAgents(int $campaignId, array $agentUsers): void {
        self::campaign($campaignId);
        $previous = self::agentsForCampaign($campaignId);
        $agentUsers = array_values(array_unique(array_filter(array_map('strval', $agentUsers), static function ($u) {
            return preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $u) === 1;
        })));
        $db = self::database();
        foreach (array_diff($previous,$agentUsers) as $removed) {
            [$interface] = self::agentInterface($removed);
            self::agentLeaveQueue($removed,$campaignId);
        }
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
        self::syncQueues();
        self::audit('AGENTS_ASSIGN', $campaignId . ':' . implode(',', $agentUsers));
    }
}
