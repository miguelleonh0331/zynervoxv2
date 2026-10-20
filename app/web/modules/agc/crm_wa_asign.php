<?php
// Asigna un cliente de WhatsApp a un agente desde un link GET, para apps
// externas que sólo saben construir URLs (la app de escritorio del discador).
//
// Por qué existe: Zynerwaba expone la asignación como POST /api/contacts/:id/assign
// con sesión y con el id interno del contacto. La app antigua llamaba un link con
// el teléfono del cliente y el usuario del agente. Este adaptador traduce lo
// segundo a lo primero sin tocar el módulo WhatsApp: resuelve teléfono -> contacto
// y usuario -> id, y hace el POST servidor contra servidor.
//
// Las credenciales de Zynerwaba nunca viajan en la URL: viven en
// /etc/zynervox/crm-whatsapp.conf (root:www 640), igual que en crm_wa_session.php.
//
// Uso:
//   crm_wa_asign.php?cliente=<telefono>&user=<usuario>&lead_id=<id opcional>
//
// Autorización, en este orden:
//   1. IP de origen incluida en ASIGN_ALLOW_IPS (para la app de escritorio, que
//      no arrastra la cookie de Zynervox). Admite IPs sueltas y CIDR.
//   2. Si no, sesión de Zynervox (para el CRM web y pruebas desde el navegador).
// Sin ASIGN_ALLOW_IPS configurado sólo entra la sesión: cerrado por omisión.

declare(strict_types=1);

const CRM_WA_ASIGN_CONF = '/etc/zynervox/crm-whatsapp.conf';

function asign_salida(int $estado, array $datos): void {
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Coincidencia de IP contra una lista de reglas sueltas o CIDR.
function asign_ip_permitida(string $ip, string $lista): bool {
    $origen = ip2long($ip);
    if ($origen === false) return false;
    $origen &= 0xFFFFFFFF;
    foreach (preg_split('/[\s,]+/', $lista, -1, PREG_SPLIT_NO_EMPTY) as $regla) {
        if (strpos($regla, '/') === false) {
            $suelta = ip2long($regla);
            if ($suelta !== false && ($suelta & 0xFFFFFFFF) === $origen) return true;
            continue;
        }
        [$red, $bits] = explode('/', $regla, 2);
        $base = ip2long($red);
        if ($base === false || !preg_match('/^\d{1,2}$/', $bits)) continue;
        $bits = (int)$bits;
        if ($bits > 32) continue;
        $mascara = $bits === 0 ? 0 : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF);
        if ((($base & 0xFFFFFFFF) & $mascara) === ($origen & $mascara)) return true;
    }
    return false;
}

// Petición a la API de Zynerwaba. Devuelve [estado, cabeceras, cuerpo].
function asign_pedir(string $url, ?string $cuerpo, array $galletas, bool $conCabeceras = false): array {
    $cabeceras = ['Accept: application/json'];
    if ($cuerpo !== null) $cabeceras[] = 'Content-Type: application/json';
    if ($galletas) $cabeceras[] = 'Cookie: ' . implode('; ', $galletas);

    $ch = curl_init($url);
    $opciones = [
        CURLOPT_HTTPHEADER => $cabeceras,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => $conCabeceras,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($cuerpo !== null) {
        $opciones[CURLOPT_POST] = true;
        $opciones[CURLOPT_POSTFIELDS] = $cuerpo;
    }
    curl_setopt_array($ch, $opciones);

    $respuesta = curl_exec($ch);
    if ($respuesta === false) {
        $error = curl_error($ch);
        curl_close($ch);
        asign_salida(502, ['error' => 'No se pudo contactar el servicio de WhatsApp: ' . $error]);
    }
    $estado = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $corte = $conCabeceras ? (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE) : 0;
    curl_close($ch);

    return [$estado, substr($respuesta, 0, $corte), substr($respuesta, $corte)];
}

// ------------------------------------------------------------------ configuración
$cfg = [
    'WA_BASE' => 'http://127.0.0.1:3022/zynerwabav2',
    'WA_USER' => '',
    'WA_PASS' => '',
    'ASIGN_ALLOW_IPS' => '',
];
if (!is_readable(CRM_WA_ASIGN_CONF)) {
    asign_salida(503, ['error' => 'Falta la configuración del chat (' . CRM_WA_ASIGN_CONF . ').']);
}
foreach (file(CRM_WA_ASIGN_CONF, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
    if ($linea === '' || $linea[0] === '#') continue;
    $partes = explode('=', $linea, 2);
    if (count($partes) === 2 && array_key_exists($partes[0], $cfg)) {
        $cfg[$partes[0]] = trim($partes[1]);
    }
}
if ($cfg['WA_USER'] === '' || $cfg['WA_PASS'] === '') {
    asign_salida(503, ['error' => 'La cuenta de WhatsApp del CRM no está configurada.']);
}
// El destino se fija aquí: sólo rutas internas, nunca un valor de la petición.
$base = rtrim($cfg['WA_BASE'], '/');
if (!preg_match('#^https?://127\.0\.0\.1(:\d+)?/[A-Za-z0-9._/-]+$#', $base)) {
    asign_salida(503, ['error' => 'La dirección interna del servicio de WhatsApp no es válida.']);
}

// ------------------------------------------------------------------ autorización
$ipOrigen = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$porIp = $cfg['ASIGN_ALLOW_IPS'] !== '' && asign_ip_permitida($ipOrigen, $cfg['ASIGN_ALLOW_IPS']);
if (!$porIp) {
    // checkAccess redirige al login si no hay sesión; para un cliente que espera
    // JSON eso es ruido, así que se mira la sesión antes y se responde 403.
    // Auth::startSession() es privada y sólo hace session_start(): se replica.
    require_once __DIR__ . '/../../includes/Auth.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['user'])) {
        asign_salida(403, ['error' => 'Sin sesión de Zynervox y la IP de origen no está autorizada.', 'ip' => $ipOrigen]);
    }
    \Includes\Auth::checkAccess([1, 2, 3, 4, 5, 6, 7, 8, 9]);
}

// ------------------------------------------------------------------ parámetros
$telefono = preg_replace('/\D/', '', (string)($_GET['cliente'] ?? ''));
if ($telefono === '' || strlen($telefono) > 20) {
    asign_salida(400, ['error' => 'Parámetro cliente inválido: se espera un teléfono.']);
}
$usuario = trim((string)($_GET['user'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $usuario)) {
    asign_salida(400, ['error' => 'Parámetro user inválido.']);
}
$lead = preg_replace('/\D/', '', (string)($_GET['lead_id'] ?? ''));
if (strlen($lead) > 20) $lead = '';

// ------------------------------------------------------------------ sesión interna
$login = json_encode(['username' => $cfg['WA_USER'], 'password' => $cfg['WA_PASS']], JSON_UNESCAPED_UNICODE);
[$estado, $cabeceras, ] = asign_pedir($base . '/api/login', $login, [], true);
if ($estado !== 200) {
    asign_salida(502, ['error' => 'El servicio de WhatsApp rechazó el inicio de sesión (HTTP ' . $estado . ').']);
}
$galletas = [];
foreach (preg_split('/\r\n|\n/', $cabeceras) as $linea) {
    if (stripos($linea, 'Set-Cookie:') !== 0) continue;
    $galletas[] = trim(explode(';', substr($linea, strlen('Set-Cookie:')), 2)[0]);
}
if (!$galletas) {
    asign_salida(502, ['error' => 'El servicio de WhatsApp no devolvió una sesión utilizable.']);
}

// ------------------------------------------------- teléfono -> contacto
// Mismo criterio que chat.html: igualdad o coincidencia por sufijo, para
// tolerar que uno de los dos traiga el prefijo de país.
[$estado, , $cuerpo] = asign_pedir($base . '/api/contacts', null, $galletas);
if ($estado !== 200) {
    asign_salida(502, ['error' => 'No se pudo consultar las conversaciones (HTTP ' . $estado . ').']);
}
$contactos = json_decode($cuerpo, true);
if (!is_array($contactos)) {
    asign_salida(502, ['error' => 'Respuesta inesperada al listar conversaciones.']);
}
$contacto = null;
foreach ($contactos as $fila) {
    $n = preg_replace('/\D/', '', (string)($fila['phone'] ?? ''));
    if ($n === '') continue;
    if ($n === $telefono
        || substr($n, -strlen($telefono)) === $telefono
        || substr($telefono, -strlen($n)) === $n) {
        $contacto = $fila;
        break;
    }
}
if ($contacto === null) {
    // La conversación la crea el entrante de WhatsApp, no este adaptador: sin
    // mensaje previo no hay contacto que asignar y se dice tal cual.
    asign_salida(404, ['error' => 'El cliente no tiene conversación de WhatsApp todavía.', 'cliente' => $telefono]);
}

// ------------------------------------------------- usuario -> id de agente
// Se admite el usuario tal cual y la forma zv_<usuario> que crea el SSO.
[$estado, , $cuerpo] = asign_pedir($base . '/api/users', null, $galletas);
if ($estado !== 200) {
    asign_salida(502, ['error' => 'No se pudo consultar los usuarios de WhatsApp (HTTP ' . $estado . ').']);
}
$usuarios = json_decode($cuerpo, true);
if (!is_array($usuarios)) {
    asign_salida(502, ['error' => 'Respuesta inesperada al listar usuarios.']);
}
$buscados = [$usuario, 'zv_' . $usuario];
$agente = null;
foreach ($usuarios as $fila) {
    if (!in_array((string)($fila['username'] ?? ''), $buscados, true)) continue;
    if ((int)($fila['active'] ?? 0) !== 1) continue;
    if (!in_array((string)($fila['role'] ?? ''), ['agent', 'supervisor'], true)) continue;
    $agente = $fila;
    break;
}
if ($agente === null) {
    asign_salida(404, ['error' => 'No existe un agente activo con ese usuario en WhatsApp.', 'user' => $usuario]);
}

// ------------------------------------------------------------------ asignación
$duenoAnterior = isset($contacto['owner_user_id']) ? (int)$contacto['owner_user_id'] : 0;
[$estado, , $cuerpo] = asign_pedir(
    $base . '/api/contacts/' . (int)$contacto['id'] . '/assign',
    json_encode(['userId' => (int)$agente['id']]),
    $galletas
);
if ($estado !== 200) {
    asign_salida(502, ['error' => 'La asignación fue rechazada (HTTP ' . $estado . ').', 'detalle' => $cuerpo]);
}

// lead_id no existe como campo en Zynerwaba; se deja como nota interna para no
// alterar su esquema ni su contrato. Si falla, la asignación ya quedó hecha.
$nota = null;
if ($lead !== '') {
    $texto = 'Asignado desde Zynervox · lead_id=' . $lead . ' · agente=' . $usuario;
    [$estadoNota, , ] = asign_pedir(
        $base . '/api/contacts/' . (int)$contacto['id'] . '/notes',
        json_encode(['body' => $texto], JSON_UNESCAPED_UNICODE),
        $galletas
    );
    $nota = $estadoNota === 201;
}

asign_salida(200, [
    'ok' => true,
    'cliente' => $telefono,
    'contact_id' => (int)$contacto['id'],
    'user' => $usuario,
    'owner_user_id' => (int)$agente['id'],
    'owner_anterior' => $duenoAnterior ?: null,
    'lead_id' => $lead !== '' ? $lead : null,
    'lead_id_en_nota' => $nota,
]);
