<?php
declare(strict_types=1);

require_once __DIR__.'/../zynervox_queries/factory.php';

const BOT_IVR_DB_CONFIG = '/etc/asterisk/synervox/secrets/bot_ivr_db.json';

function bot_ivr_db_config(): array {
    if (file_exists(BOT_IVR_DB_CONFIG)) {
        $config = json_decode((string) file_get_contents(BOT_IVR_DB_CONFIG), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config)) throw new RuntimeException('Configuración de Bot IVR inválida.');
        return $config;
    }
    throw new RuntimeException('Configura la conexión mediante el botón Configurar conexión a base de datos.');
}

function bot_ivr_db_connect(array $config): PDO {
    if (($config['database'] ?? '') !== 'zynervox') {
        throw new RuntimeException('Bot IVR requiere la base de datos zynervox.');
    }
    return \ZynervoxQueries\connect($config);
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

function bot_ivr_repository(): \ZynervoxQueries\BotIvrRepository {
    static $repository = null;
    if ($repository === null) {
        $config = bot_ivr_db_config();
        $repository = \ZynervoxQueries\botIvrRepository($config, carsa_db());
    }
    return $repository;
}
