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

// Vistas del upstream que se muestran dentro del shell de Zynervox. Cualquier
// enlace del upstream que apunte a una de estas rutas se reescribe hacia
// zynerdesk.php para no salir del panel; el resto se manda al proxy publico.
$ZYNERDESK_VIEWS = [
    'panel'       => ['path' => 'index.html',            'title' => 'Panel'],
    'supervicion' => ['path' => 'supervicion/index.html','title' => 'Supervisión múltiple'],
    'usuarios'    => ['path' => 'admin.html',            'title' => 'Usuarios'],
];
$view = isset($_GET['view']) && isset($ZYNERDESK_VIEWS[$_GET['view']]) ? $_GET['view'] : 'panel';
$viewPath = $ZYNERDESK_VIEWS[$view]['path'];
// Carpeta del documento dentro del upstream, para resolver sus rutas relativas.
// Se calcula con corte de cadena y no con dirname(), que en Windows devuelve
// separadores "\" y corromperia la URL.
$viewSlash = strrpos($viewPath, '/');
$viewDir = $viewSlash === false ? $proxyBase : $proxyBase . substr($viewPath, 0, $viewSlash + 1);

// El upstream (Synervox Remoteo) no soporta SSO ni BASE_PATH: sirve rutas
// relativas al documento que las contiene. Se trae su HTML por HTTP
// server-side (igual que Farm incluye su propio body) y se reescriben esas
// rutas hacia el proxy publico real, de modo que todo queda dentro de un solo
// sidebar, encabezado y scroll, sin iframe.
function fetchZynerdesk(string $port, string $path): string
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

// Resuelve una ruta relativa del upstream contra la carpeta del documento,
// normalizando "./" y "../" como lo haria el navegador.
function zynerdeskResolve(string $relative, string $baseDir): string
{
    $parts = [];
    foreach (explode('/', $baseDir . $relative) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }
    $resolved = '/' . implode('/', $parts);
    if (substr($relative, -1) === '/') {
        $resolved .= '/';
    }
    return $resolved;
}

// Las vistas embebidas se enlazan al propio shell; todo lo demas al proxy.
function zynerdeskShellUrl(string $absolute, string $proxyBase, array $views): ?string
{
    foreach ($views as $key => $meta) {
        $candidates = [$proxyBase . $meta['path']];
        // "supervicion/" y "supervicion/index.html" son el mismo destino.
        if (substr($meta['path'], -11) === '/index.html') {
            $candidates[] = $proxyBase . substr($meta['path'], 0, -10);
        }
        if (in_array($absolute, $candidates, true)) {
            return 'zynerdesk.php?view=' . $key;
        }
    }
    return null;
}

function renderZynerdeskBody(string $html, string $baseDir, string $proxyBase, array $views): string
{
    if (!preg_match('~<body[^>]*>(.*)</body>~is', $html, $match)) {
        return '<p class="integration-error">No se pudo cargar el módulo Zynerdesk.</p>';
    }
    $body = $match[1];

    // Rutas relativas en atributos href/src -> absolutas del proxy, salvo que
    // correspondan a una vista embebida, que apunta de vuelta al shell.
    $body = preg_replace_callback(
        '~\b(href|src)=(["\'])([^"\']+)\2~i',
        function (array $m) use ($baseDir, $proxyBase, $views): string {
            $url = $m[3];
            if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/|#)~i', $url)) {
                return $m[0];
            }
            $absolute = zynerdeskResolve($url, $baseDir);
            $target = zynerdeskShellUrl($absolute, $proxyBase, $views) ?? $absolute;
            return $m[1] . '=' . $m[2] . $target . $m[2];
        },
        $body
    );

    // Rutas que el upstream construye dentro de su JavaScript (no son atributos).
    $body = str_replace(
        ['"api/', "'api/", '"login.html"', "'login.html'"],
        ['"' . $proxyBase . 'api/', "'" . $proxyBase . 'api/', '"' . $proxyBase . 'login.html"', "'" . $proxyBase . "login.html'"],
        $body
    );
    $body = str_replace(
        'location.pathname.replace(/[^/]*$/, "")',
        '"' . $proxyBase . '"',
        $body
    );

    // El agente Windows (.exe) queda expresamente fuera de esta etapa y el
    // upstream ni siquiera sirve /downloads en esta imagen.
    $body = preg_replace('~<a[^>]*href="[^"]*downloads/[^"]*"[^>]*>.*?</a>~is', '', $body);
    // El logo del upstream duplicaria la marca que ya muestran el sidebar y la
    // cabecera de la integracion.
    $body = preg_replace('~<img[^>]*class="brand-logo"[^>]*>~is', '', $body);
    return $body;
}

// El upstream carga hojas externas con <link> en su <head>. Como aqui solo se
// embebe su <body>, hay que reemitirlas o se pierden: sin leaflet.css, por
// ejemplo, los tiles del mapa quedan sin position:absolute y se descuadran.
function extractZynerdeskHeadLinks(string $html, string $baseDir): string
{
    if (!preg_match('~<head[^>]*>(.*?)</head>~is', $html, $head)) {
        return '';
    }
    if (!preg_match_all('~<link[^>]*rel=["\']stylesheet["\'][^>]*>~i', $head[1], $links)) {
        return '';
    }
    $out = '';
    foreach ($links[0] as $link) {
        if (!preg_match('~href=["\']([^"\']+)["\']~i', $link, $href)) {
            continue;
        }
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:|//|/)~i', $href[1])) {
            $out .= $link . "\n";
            continue;
        }
        $out .= str_replace($href[1], zynerdeskResolve($href[1], $baseDir), $link) . "\n";
    }
    return $out;
}

function extractZynerdeskStyle(string $html): string
{
    if (!preg_match_all('~<style[^>]*>(.*?)</style>~is', $html, $m)) {
        return '';
    }
    $css = implode("\n", $m[1]);
    // El CSS del upstream declara su paleta en `:root` y su tipografia/fondo en
    // `body`. Dentro de `@scope (.zynerdesk-native)` ninguno de los dos
    // coincide con un elemento del scope, asi que las variables (--navy,
    // --ink, --bg...) quedaban SIN definir y la vista se veia lavada. `:scope`
    // si apunta al elemento raiz del scope, de modo que vuelven a heredar.
    $css = preg_replace('~(^|[\s,}])\:root\b~', '$1:scope', $css);
    $css = preg_replace('~(^|[\s,}])body\b~', '$1:scope', $css);
    return $css;
}

// supervicion/app.js fija su base con location.pathname.split("/supervicion"),
// que aqui no existe porque la URL es la del shell. Se trae el script y se
// reescriben sus dos constantes a la ruta real del proxy, para que su API y su
// WebSocket sigan funcionando embebidos.
function zynerdeskInlineScripts(string $body, string $port, string $baseDir, string $proxyBase): string
{
    return preg_replace_callback(
        '~<script[^>]*\bsrc=(["\'])([^"\']+)\1[^>]*></script>~i',
        function (array $m) use ($port, $baseDir, $proxyBase): string {
            $src = $m[2];
            if (strpos($src, $proxyBase) !== 0) {
                return $m[0];
            }
            $code = fetchZynerdesk($port, ltrim(substr($src, strlen($proxyBase)), '/'));
            if ($code === '') {
                return $m[0];
            }
            $code = str_replace(
                [
                    'const API_BASE = "../";',
                    'const APP_BASE = location.pathname.split("/supervicion")[0].replace(/\/$/, "") + "/";',
                ],
                [
                    'const API_BASE = "' . $proxyBase . '";',
                    'const APP_BASE = "' . $proxyBase . '";',
                ],
                $code
            );
            return '<script>' . $code . '</script>';
        },
        $body
    );
}

$zynerdeskHtml = fetchZynerdesk($zynerdeskPort, $viewPath);
if ($zynerdeskHtml !== '') {
    $zynerdeskBody = renderZynerdeskBody($zynerdeskHtml, $viewDir, $proxyBase, $ZYNERDESK_VIEWS);
    $zynerdeskBody = zynerdeskInlineScripts($zynerdeskBody, $zynerdeskPort, $viewDir, $proxyBase);
} else {
    $zynerdeskBody = '<p class="integration-error">Zynerdesk no respondió. Verifique el contenedor y el proxy Apache.</p>';
}
$zynerdeskCss = extractZynerdeskStyle($zynerdeskHtml);
$zynerdeskHeadLinks = extractZynerdeskHeadLinks($zynerdeskHtml, $viewDir);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Zynerdesk - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <link rel="stylesheet" href="integration-shell.css">
    <?= $zynerdeskHeadLinks ?>
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
        <nav class="integration-tabs" aria-label="Secciones de Zynerdesk">
            <?php foreach ($ZYNERDESK_VIEWS as $key => $meta): ?>
            <a class="<?= $view === $key ? 'active' : '' ?>" href="zynerdesk.php?view=<?= $key ?>"><?= htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <section class="integration-native zynerdesk-native">
        <?= $zynerdeskBody ?>
    </section>
    <script>
    // El upstream solo recalcula el mapa al mostrarlo/ocultarlo. Aqui vive en
    // un contenedor flex que fija su ancho despues de que Leaflet se inicializa,
    // asi que se emite un resize (Leaflet lo escucha y hace invalidateSize) una
    // vez que terminaron de cargar hoja de estilos e imagenes.
    window.addEventListener('load', function () {
        window.dispatchEvent(new Event('resize'));
    });
    </script>
</div>
</body>
</html>
