<?php

namespace Config;

class Config {
    private static $settings = [];
    private static $configFile = '/etc/astguiclient.conf';

    public static function load() {
        if (!empty(self::$settings)) {
            return self::$settings;
        }

        if (!file_exists(self::$configFile)) {
            // Para pruebas en entornos donde el archivo no existe físicamente (como Windows con Z:)
            // Podríamos mockearlo o lanzar una excepción.
            // En producción debe existir.
            error_log("Config file not found: " . self::$configFile);
            return [];
        }

        $lines = file(self::$configFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Ignorar comentarios
            if (strpos(trim($line), '#') === 0) continue;

            if (preg_match('/^([A-Za-z0-9_]+)\s*=>\s*(.*)$/', $line, $matches)) {
                $key = trim($matches[1]);
                $value = trim($matches[2]);
                self::$settings[$key] = $value;
            }
        }

        return self::$settings;
    }

    public static function get($key, $default = null) {
        self::load();
        return self::$settings[$key] ?? $default;
    }
}
