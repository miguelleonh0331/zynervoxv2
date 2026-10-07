<?php

namespace Includes;

use Config\Config;
use PDO;
use PDOException;

require_once __DIR__ . '/../config/Config.php';

class Database {
    private static $instance = null;
    private static $coreInstance = null;
    private static $botIvrInstance = null;
    private $conn;

    private function __construct($host, $db, $user, $pass, $port, $missingMessage) {
        if (!$host || !$db || !$user || $pass === null) {
            throw new PDOException($missingMessage);
        }

        $dsn = "mysql:host=$host;dbname=$db;port=$port;charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException($e->getMessage(), (int)$e->getCode());
        }
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database(
                Config::get('VARDB_server'),
                Config::get('VARDB_database'),
                Config::get('VARDB_user'),
                Config::get('VARDB_pass'),
                Config::get('VARDB_port', '3306'),
                "Error de configuración: Faltan credenciales de BD en /etc/astguiclient.conf"
            );
        }
        return self::$instance->conn;
    }

    // BD propia de zynervox (zynervox_core / zynervox_users): login admin
    // independiente de vicidial_users y de astguiclient.conf. Ver
    // installer/zynervox-core.sh.
    public static function getCoreInstance() {
        if (self::$coreInstance == null) {
            self::$coreInstance = new Database(
                Config::get('CORE_DB_server'),
                Config::get('CORE_DB_database'),
                Config::get('CORE_DB_user'),
                Config::get('CORE_DB_pass'),
                Config::get('CORE_DB_port', '3306'),
                "Error de configuración: falta /etc/zynervox/zynervox-core.conf"
            );
        }
        return self::$coreInstance->conn;
    }

    // BD propia del IVR Builder (tablas bot_ivr_flow*): vive en "zynervox",
    // no en "asterisk" ni en "zynervox_core". Credenciales en
    // /etc/zynervox/zynervoxv2205-bot_ivr.conf (usuario zynervox_bot_ivr,
    // permisos SELECT/INSERT/UPDATE/DELETE solo sobre esa base).
    public static function getBotIvrInstance() {
        if (self::$botIvrInstance == null) {
            self::$botIvrInstance = new Database(
                Config::get('BOT_IVR_DB_server'),
                Config::get('BOT_IVR_DB_database'),
                Config::get('BOT_IVR_DB_user'),
                Config::get('BOT_IVR_DB_pass'),
                Config::get('BOT_IVR_DB_port', '3306'),
                "Error de configuración: falta /etc/zynervox/zynervoxv2205-bot_ivr.conf"
            );
        }
        return self::$botIvrInstance->conn;
    }
}
