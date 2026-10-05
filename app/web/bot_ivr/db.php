<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/Config.php';

const BOT_IVR_DB_CONFIG = '/etc/asterisk/synervox/secrets/bot_ivr_db.json';

function bot_ivr_db_config(): array {
    if (file_exists(BOT_IVR_DB_CONFIG)) {
        $config = json_decode((string) file_get_contents(BOT_IVR_DB_CONFIG), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config)) throw new RuntimeException('Configuración de Bot IVR inválida.');
        return $config;
    }
    return [
        'server' => \Config\Config::get('VARDB_server', ''),
        'port' => \Config\Config::get('VARDB_port', '3306'),
        'database' => \Config\Config::get('VARDB_database', ''),
        'user' => \Config\Config::get('VARDB_user', ''),
        'password' => \Config\Config::get('VARDB_pass', ''),
    ];
}

function bot_ivr_db_connect(array $config): PDO {
    foreach (['server', 'database', 'user'] as $key) {
        if (!isset($config[$key]) || trim((string) $config[$key]) === '' || preg_match('/[;\x00-\x1f]/', (string) $config[$key])) {
            throw new RuntimeException('Servidor, base de datos y usuario son obligatorios y deben ser válidos.');
        }
    }
    $port = filter_var($config['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if ($port === false) throw new RuntimeException('El puerto debe estar entre 1 y 65535.');
    return new PDO("mysql:host={$config['server']};port=$port;dbname={$config['database']};charset=utf8mb4",
        (string) $config['user'], (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
}

function bot_ivr_db_save(array $config): void {
    // Probar antes de reemplazar la configuración operativa.
    try { bot_ivr_db_connect($config)->query('SELECT 1'); }
    catch (PDOException $e) { throw new RuntimeException('No se pudo conectar. Revisa servidor, puerto, base de datos y credenciales.'); }
    $directory = dirname(BOT_IVR_DB_CONFIG);
    if (!is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('El directorio de secretos de Bot IVR no permite guardar la configuración.');
    }
    $temporary = tempnam($directory, '.bot_ivr_db_');
    if ($temporary === false) throw new RuntimeException('No se pudo crear el archivo de configuración.');
    try {
        if (!chmod($temporary, 0640) || file_put_contents($temporary, json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) === false || !rename($temporary, BOT_IVR_DB_CONFIG)) {
            throw new RuntimeException('No se pudo guardar la configuración.');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}

function carsa_db(): PDO {
    static $connection = null;
    if ($connection === null) $connection = bot_ivr_db_connect(bot_ivr_db_config());
    return $connection;
}
