<?php
require_once __DIR__ . '/../../includes/Auth.php';
\Includes\Auth::checkAccess(9);

// Ruta publica real y puerto loopback: los escribe
// installer/zynerdesk.sh install-proxy en /etc/zynervox/zynerdesk.conf.
$zynerdeskBasePath = '/zynerdesk';
$zynerdeskPort = '';
$zynerdeskConfigFile = '/etc/zynervox/zynerdesk.conf';
if (is_readable($zynerdeskConfigFile)) {
    foreach (file($zynerdeskConfigFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos($line, 'ZYNERDESK_BASE_PATH=') === 0) {
            $zynerdeskBasePath = trim(substr($line, strlen('ZYNERDESK_BASE_PATH=')));
        }
        if (strpos($line, 'ZYNERDESK_PORT=') === 0) {
            $zynerdeskPort = trim(substr($line, strlen('ZYNERDESK_PORT=')));
        }
    }
}
$proxyBase = rtrim($zynerdeskBasePath, '/') . '/';

// El upstream (Synervox Remoteo) no soporta SSO ni BASE_PATH: calcula sus
// rutas relativas ("api/...", "login.html", etc.) contra el documento que lo
// sirve. Lo traemos por HTTP server-side (igual que Farm trae su body por
// include) y reescribimos esas rutas para que apunten al proxy publico real,
// asi queda dentro de un solo sidebar/encabezado/scroll, sin iframe.
function fetchZynerdeskPage(string $port, string $path): string
{
    if ($port === '' || !ctype_digit($port)) {
        return '';
    }
    $ch = curl_init("http://127.0.0.1:{$port}/{$path}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    return $out !== false ? (string) $out : '';
}

function renderZynerdeskBody(string $html, string $proxyBase): string
{
    if (!preg_match('~<body[^>]*>(.*)</body>~is', $html, $match)) {
        return '<p class="integration-error">No se pudo cargar el módulo Zynerdesk.</p>';
    }
    $body = $match[1];
    $rewrites = [
        '"api/'                                  => '"' . $proxyBase . 'api/',
        'href="login.html'                       => 'href="' . $proxyBase . 'login.html',
        'href="admin.html'                        => 'href="' . $proxyBase . 'admin.html',
        'href="remoteo.html'                      => 'href="' . $proxyBase . 'remoteo.html',
        'href="supervicion/'                      => 'href="' . $proxyBase . 'supervicion/',
        'location.href = "login.html"'            => 'location.href = "' . $proxyBase . 'login.html"',
        'location.href="login.html"'              => 'location.href="' . $proxyBase . 'login.html"',
        'location.pathname.replace(/[^/]*$/, "")' => '"' . $proxyBase . '"',
    ];
    $body = str_replace(array_keys($rewrites), array_values($rewrites), $body);
    // El agente Windows (.exe) queda expresamente fuera de esta etapa: se
    // retiran los enlaces de descarga en vez de dejarlos rotos (el upstream
    // ni siquiera sirve /downloads en esta imagen).
    $body = preg_replace('~<a[^>]*href="downloads/[^"]*"[^>]*>.*?</a>~is', '', $body);
    // El logo del upstream duplicaria la marca: el shell de Zynervox ya la
    // muestra en el sidebar y en la cabecera de la integracion.
    $body = preg_replace('~<img[^>]*class="brand-logo"[^>]*>~is', '', $body);
    return $body;
}

function extractZynerdeskStyle(string $html): string
{
    if (!preg_match_all('~<style[^>]*>(.*?)</style>~is', $html, $m)) {
        return '';
    }
    $css = implode("\n", $m[1]);
    // El CSS del upstream declara su paleta en `:root` y su tipografia/fondo en
    // `body`. Al encerrarlo en `@scope (.zynerdesk-native)` ninguno de los dos
    // selectores coincide ya con un elemento del scope, asi que las variables
    // (--navy, --ink, --bg...) quedaban SIN definir y la vista se veia lavada:
    // hero transparente, texto invisible y contenedores sin ancho. `:scope` si
    // apunta al elemento raiz del scope, de modo que las variables vuelven a
    // existir y heredan a todo el arbol embebido.
    $css = preg_replace('~(^|[\s,}])\:root\b~', '$1:scope', $css);
    $css = preg_replace('~(^|[\s,}])body\b~', '$1:scope', $css);
    return $css;
}

$zynerdeskHtml = fetchZynerdeskPage($zynerdeskPort, 'index.html');
$zynerdeskBody = $zynerdeskHtml !== ''
    ? renderZynerdeskBody($zynerdeskHtml, $proxyBase)
    : '<p class="integration-error">Zynerdesk no respondió. Verifique el contenedor y el proxy Apache.</p>';
$zynerdeskCss = extractZynerdeskStyle($zynerdeskHtml);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Zynerdesk - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="integration-shell.css">
    <style>
        /* El upstream esperaba ser el documento completo; aqui es un bloque
           dentro del shell. Se fija ancho completo para que su layout interno
           (hero KPI, grid de equipos y mapa Leaflet) calcule bien su medida. */
        .zynerdesk-native { display: block; width: 100%; min-width: 0; }
        .zynerdesk-native header { position: static; }
    </style>
    <?php if ($zynerdeskCss !== ''): ?><style>@scope (.zynerdesk-native) { <?= $zynerdeskCss ?> }</style><?php endif; ?>
</head>
<body>
<?php require_once __DIR__ . '/sidebar.php'; renderSidebar('zynerdesk'); ?>
<div class="integration-shell">
    <header class="integration-head">
        <div><h1>Zynerdesk</h1><p>Supervisión remota (Synervox Remoteo)</p></div>
    </header>
    <section class="integration-native zynerdesk-native">
        <?= $zynerdeskBody ?>
    </section>
</div>
</body>
</html>
