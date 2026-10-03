<?php
// Endpoint de control/monitor de Zypad. Ejecuta exactamente los comandos
// sudo whitelisteados por installer/zypad.sh (/etc/sudoers.d/zynervox-zypad)
// contra el contenedor fijo "zynervox-zypad". Nunca interpola variables de
// usuario en el comando: $action solo selecciona cuál de las 4 llamadas
// fijas se ejecuta, nada de concatenar nombres/puertos que vengan del
// request.
require_once __DIR__ . '/../../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

header('Content-Type: application/json; charset=utf-8');

const ZYPAD_INSTANCE = 'zynervox-zypad';
const ZYPAD_CONFIG = '/etc/zynervox/zynervox-zypad.php';

function zypadRespond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_readable(ZYPAD_CONFIG)) {
    zypadRespond(['ok' => false, 'error' => 'Zypad no está instalado en este servidor'], 404);
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Acciones que cambian estado requieren el token CSRF que zypad.php imprime
// en sesión; "status" es de solo lectura y no lo necesita.
if (in_array($action, ['start', 'stop'], true)) {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['zypad_csrf'] ?? '', $token)) {
        zypadRespond(['ok' => false, 'error' => 'token CSRF inválido'], 403);
    }
}

/**
 * Corre exactamente los argv dados bajo sudo -n (nunca con shell=true ni
 * interpolación de strings). Cada elemento ya es una constante fija del
 * propio script, por eso no hace falta validar contenido.
 */
function zypadSudo(array $argv): array
{
    $cmd = 'sudo -n ' . implode(' ', array_map('escapeshellarg', $argv)) . ' 2>&1';
    exec($cmd, $out, $code);
    return [$code === 0, implode("\n", $out)];
}

switch ($action) {
    case 'start':
        [$ok, $out] = zypadSudo(['/usr/bin/docker', 'start', ZYPAD_INSTANCE]);
        zypadRespond(['ok' => $ok, 'detail' => $out]);
        break;

    case 'stop':
        [$ok, $out] = zypadSudo(['/usr/bin/docker', 'stop', ZYPAD_INSTANCE]);
        zypadRespond(['ok' => $ok, 'detail' => $out]);
        break;

    case 'status':
        [$ok, $out] = zypadSudo(['/usr/bin/docker', 'inspect', '-f', '{{.State.Running}}', ZYPAD_INSTANCE]);
        $running = $ok && trim($out) === 'true';
        $stats = null;
        if ($running) {
            [$sok, $sout] = zypadSudo(['/usr/bin/docker', 'stats', '--no-stream', ZYPAD_INSTANCE]);
            if ($sok) {
                // Salida por defecto de `docker stats` (sin --format, para que
                // coincida literal con la regla sudoers): una cabecera y una
                // línea de datos. Las columnas "USAGE / LIMIT" y "NET/BLOCK
                // I/O" traen 3 tokens cada una (valor, "/", valor).
                $lines = array_values(array_filter(explode("\n", trim($sout))));
                $dataLine = end($lines) ?: '';
                if (preg_match(
                    '/^\S+\s+\S+\s+([\d.]+%)\s+(\S+\s*\/\s*\S+)\s+([\d.]+%)\s+(\S+\s*\/\s*\S+)\s+(\S+\s*\/\s*\S+)\s+(\d+)\s*$/',
                    $dataLine,
                    $m
                )) {
                    $stats = [
                        'cpu' => $m[1],
                        'mem' => $m[2],
                        'mem_perc' => $m[3],
                        'net' => $m[4],
                        'block' => $m[5],
                        'pids' => $m[6],
                    ];
                }
            }
        }
        zypadRespond(['ok' => true, 'running' => $running, 'stats' => $stats]);
        break;

    default:
        zypadRespond(['ok' => false, 'error' => 'acción desconocida'], 400);
}
