<?php

namespace Config;

class Config {
    private static $settings = [];
    private static $configFile = null;

    public static function load() {
        if (!empty(self::$settings)) {
            return self::$settings;
        }

        self::$configFile = getenv('ZYNERVOX_CONFIG_FILE') ?: (
            file_exists('/etc/zynervox/astguiclient.conf')
                ? '/etc/zynervox/astguiclient.conf'
                : '/etc/astguiclient.conf'
        );

        if (!file_exists(self::$configFile)) {
            // Para pruebas en entornos donde el archivo no existe físicamente (como Windows con Z:)
            // Podríamos mockearlo o lanzar una excepción.
            // En producción debe existir.
            error_log("Config file not found: " . self::$configFile);
        } else {
            self::loadFile(self::$configFile);
        }

        // BD propia de zynervox (zynervox_core / zynervox_users), separada de
        // astguiclient.conf: claves distintas (CORE_DB_*), sin colisión.
        self::loadFile('/etc/zynervox/zynervox-core.conf');

        return self::$settings;
    }

    private static function loadFile($path) {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) continue;

            if (preg_match('/^([A-Za-z0-9_]+)\s*=>\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                self::$settings[$key] = $value;
            }
        }
    }

    public static function get($key, $default = null) {
        self::load();
        return self::$settings[$key] ?? $default;
    }
}
