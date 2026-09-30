<?php

namespace Includes;

use Config\Config;
use PDO;
use PDOException;

require_once __DIR__ . '/../config/Config.php';

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $host = Config::get('VARDB_server');
        $db   = Config::get('VARDB_database');
        $user = Config::get('VARDB_user');
        $pass = Config::get('VARDB_pass');
        $port = Config::get('VARDB_port', '3306');

        if (!$host || !$db || !$user || $pass === null) {
            throw new PDOException("Error de configuración: Faltan credenciales de BD en /etc/astguiclient.conf");
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
            self::$instance = new Database();
        }
        return self::$instance->conn;
    }
}
