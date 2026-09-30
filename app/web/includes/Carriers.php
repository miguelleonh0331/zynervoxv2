<?php

namespace Includes;

use Includes\Database;
use Includes\Audit;
use PDO;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Audit.php';

class Carriers {

    const CONF_PATH = '/etc/asterisk/synervox/modules/asterisk/pjsip-zynervox.conf';
    const DIALPLAN_PATH = '/etc/asterisk/synervox/modules/asterisk/extensions-zynervox.conf';
    // Wrapper minimo con sudoers dedicado (www-data solo puede correr este
    // script exacto, no tiene acceso libre al CLI/AMI de Asterisk). El
    // wrapper hace "pjsip reload" y "dialplan reload" en un solo paso.
    const RELOAD_CMD = 'sudo /usr/local/sbin/zynervox-pjsip-reload.sh 2>&1';

    public static function getAll() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT * FROM vicidial_server_carriers ORDER BY carrier_id ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function getById($carrier_id) {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM vicidial_server_carriers WHERE carrier_id = :id LIMIT 1");
        $stmt->execute(['id' => $carrier_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public static function create($data) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare(
                "INSERT INTO vicidial_server_carriers
                 (carrier_id, carrier_name, template_id, protocol, account_entry, dialplan_entry, server_ip, active, carrier_description)
                 VALUES (:carrier_id, :carrier_name, 'CUSTOM', 'PJSIP', :account_entry, :dialplan_entry, :server_ip, :active, :carrier_description)"
            );
            $stmt->execute([
                'carrier_id' => $data['carrier_id'],
                'carrier_name' => $data['carrier_name'],
                'account_entry' => $data['account_entry'] ?? '',
                'dialplan_entry' => $data['dialplan_entry'] ?? '',
                'server_ip' => $data['server_ip'],
                'active' => $data['active'] ?? 'Y',
                'carrier_description' => $data['carrier_description'] ?? '',
            ]);
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'CREATE', 'vicidial_server_carriers', 'carrier_id', $data['carrier_id']);
            return self::regenerateAndReload();
        } catch (\PDOException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    public static function update($carrier_id, $data) {
        $db = Database::getInstance();
        try {
            $stmt = $db->prepare(
                "UPDATE vicidial_server_carriers SET
                 carrier_name = :carrier_name, account_entry = :account_entry, dialplan_entry = :dialplan_entry,
                 server_ip = :server_ip, active = :active, carrier_description = :carrier_description
                 WHERE carrier_id = :id"
            );
            $stmt->execute([
                'carrier_name' => $data['carrier_name'],
                'account_entry' => $data['account_entry'] ?? '',
                'dialplan_entry' => $data['dialplan_entry'] ?? '',
                'server_ip' => $data['server_ip'],
                'active' => $data['active'] ?? 'Y',
                'carrier_description' => $data['carrier_description'] ?? '',
                'id' => $carrier_id,
            ]);
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'UPDATE', 'vicidial_server_carriers', 'carrier_id', $carrier_id);
            return self::regenerateAndReload();
        } catch (\PDOException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    public static function delete($carrier_id) {
        $db = Database::getInstance();
        try {
            Audit::logTableChange($_SESSION['user'] ?? 'system', 'DELETE', 'vicidial_server_carriers', 'carrier_id', $carrier_id);
            $stmt = $db->prepare("DELETE FROM vicidial_server_carriers WHERE carrier_id = :id");
            $stmt->execute(['id' => $carrier_id]);
            return self::regenerateAndReload();
        } catch (\PDOException $e) { return ['ok' => false, 'error' => $e->getMessage()]; }
    }

    // Reconstruye modules/asterisk/pjsip-zynervox.conf (account_entry) y
    // modules/asterisk/extensions-zynervox.conf
    // (dialplan_entry) con las troncales PJSIP activas, y dispara
    // "pjsip reload" + "dialplan reload" via el wrapper sudo restringido.
    public static function regenerateAndReload() {
        $db = Database::getInstance();
        $stmt = $db->query("SELECT carrier_id, carrier_name, account_entry, dialplan_entry FROM vicidial_server_carriers WHERE active = 'Y' AND protocol = 'PJSIP' ORDER BY carrier_id ASC");
        $carriers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $confOut = "; Generado por Zynervox (carriers.php) - " . date('Y-m-d H:i:s') . "\n";
        $confOut .= "; NO EDITAR A MANO, se sobreescribe desde el panel de administracion.\n\n";

        $dialOut = "; Generado por Zynervox (carriers.php) - " . date('Y-m-d H:i:s') . "\n";
        $dialOut .= "; NO EDITAR A MANO, se sobreescribe desde el panel de administracion.\n\n";

        foreach ($carriers as $c) {
            $confOut .= "; ---- Carrier: {$c['carrier_id']} ({$c['carrier_name']}) ----\n";
            $confOut .= rtrim($c['account_entry']) . "\n\n";

            if (trim($c['dialplan_entry']) !== '') {
                $dialOut .= "; ---- Carrier: {$c['carrier_id']} ({$c['carrier_name']}) ----\n";
                $dialOut .= rtrim($c['dialplan_entry']) . "\n\n";
            }
        }

        $w1 = @file_put_contents(self::CONF_PATH, $confOut);
        $w2 = @file_put_contents(self::DIALPLAN_PATH, $dialOut);
        if ($w1 === false || $w2 === false) {
            return ['ok' => false, 'error' => 'No se pudo escribir ' . self::CONF_PATH . ' o ' . self::DIALPLAN_PATH . ' (revisar permisos).'];
        }

        $reloadOutput = shell_exec(self::RELOAD_CMD);
        return ['ok' => true, 'reload_output' => trim($reloadOutput ?? '')];
    }
}
