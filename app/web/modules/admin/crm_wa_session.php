<?php
// Abre sesión en Zynerwaba para el panel de chat del CRM y redirige a la
// bandeja, de modo que el iframe cargue ya autenticado.
//
// Por qué existe: Zynerwaba sólo acepta credenciales por POST /api/login, no
// admite pasarlas en la URL (quedarían en los logs de Apache, el historial y
// la cabecera Referer). Aquí el inicio de sesión ocurre servidor contra
// servidor y al navegador sólo le llega la cookie de sesión resultante.
//
// No modifica nada del módulo WhatsApp ni del resto del sistema: sólo
// reutiliza su API pública. Las credenciales viven en
// /etc/zynervox/crm-whatsapp.conf (root:www 640), fuera del repositorio.

declare(strict_types=1);
require_once __DIR__ . '/../../includes/Auth.php';
use Includes\Auth;

// Exige sesión de Zynervox: sin ella no se entrega la sesión de Zynerwaba.
Auth::checkAccess([1, 2, 3, 4, 5, 6, 7, 8, 9]);

const CRM_WA_CONF = '/etc/zynervox/crm-whatsapp.conf';

function crm_wa_fail(int $status, string $mensaje): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Chat no disponible</title>'
       . '<div style="font:13px Arial,sans-serif;color:#2D2F3B;padding:18px;line-height:1.5">'
       . '<strong>No se pudo abrir el chat.</strong><br>'
       . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8')
       . '</div>';
    exit;
}

$cfg = ['WA_BASE' => 'http://127.0.0.1:3022/zynerwabav2', 'WA_USER' => '', 'WA_PASS' => ''];
if (!is_readable(CRM_WA_CONF)) {
    crm_wa_fail(503, 'Falta la configuración del chat (' . CRM_WA_CONF . ').');
}
foreach (file(CRM_WA_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
    if ($linea === '' || $linea[0] === '#') continue;
    $partes = explode('=', $linea, 2);
    if (count($partes) === 2 && array_key_exists($partes[0], $cfg)) {
        $cfg[$partes[0]] = trim($partes[1]);
    }
}
if ($cfg['WA_USER'] === '' || $cfg['WA_PASS'] === '') {
    crm_wa_fail(503, 'La cuenta de WhatsApp del CRM no está configurada.');
}
// El destino se fija aquí: sólo rutas internas de Zynerwaba, nunca un valor
// que llegue por la petición.
$base = rtrim($cfg['WA_BASE'], '/');
if (!preg_match('#^https?://127\.0\.0\.1(:\d+)?/[A-Za-z0-9._/-]+$#', $base)) {
    crm_wa_fail(503, 'La dirección interna del servicio de WhatsApp no es válida.');
}

$cuerpo = json_encode(['username' => $cfg['WA_USER'], 'password' => $cfg['WA_PASS']], JSON_UNESCAPED_UNICODE);
$ch = curl_init($base . '/api/login');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $cuerpo,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_FOLLOWLOCATION => false,
]);
$respuesta = curl_exec($ch);
if ($respuesta === false) {
    $error = curl_error($ch);
    curl_close($ch);
    crm_wa_fail(502, 'No se pudo contactar el servicio de WhatsApp: ' . $error);
}
$estado = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$tamCabeceras = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

if ($estado !== 200) {
    crm_wa_fail(502, 'El servicio de WhatsApp rechazó el inicio de sesión (HTTP ' . $estado . ').');
}

// Reemitir al navegador la cookie de sesión que devolvió Zynerwaba. Se
// conservan sus atributos (Path, HttpOnly, SameSite) y se añade Secure
// cuando la petición llega por HTTPS.
$cabeceras = substr($respuesta, 0, $tamCabeceras);
$entregadas = 0;
$https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
foreach (preg_split('/\r\n|\n/', $cabeceras) as $linea) {
    if (stripos($linea, 'Set-Cookie:') !== 0) continue;
    $valor = trim(substr($linea, strlen('Set-Cookie:')));
    if ($valor === '') continue;
    if ($https && stripos($valor, 'secure') === false) $valor .= '; Secure';
    header('Set-Cookie: ' . $valor, $entregadas === 0);
    $entregadas++;
}
if ($entregadas === 0) {
    crm_wa_fail(502, 'El servicio de WhatsApp no devolvió una sesión utilizable.');
}

// Ruta pública equivalente a WA_BASE, para que el navegador siga el proxy.
$publico = '/' . ltrim(parse_url($base, PHP_URL_PATH) ?: '/zynerwabav2', '/');

// Con un número se abre esa conversación (chat.html lo busca por teléfono);
// sin él, la bandeja completa. El valor llega del navegador, así que se
// reduce a dígitos y se acota: así no puede torcer el destino del redirect.
$numero = preg_replace('/\D/', '', (string)($_GET['id'] ?? ''));
$destino = ($numero !== '' && strlen($numero) <= 20)
    ? $publico . '/chat.html?id=' . $numero
    : $publico . '/';

header('Cache-Control: no-store, private');
header('Location: ' . $destino, true, 303);
