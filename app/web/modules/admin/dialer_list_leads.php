<?php
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Auth;
use Includes\Dialer;

Auth::checkAccess([7, 8, 9]);
if (empty($_SESSION['dialer_csrf'])) $_SESSION['dialer_csrf'] = bin2hex(random_bytes(32));

function h($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

$listId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$msg = '';
$error = '';

try {
    if (!$listId || !$campaignId) throw new RuntimeException('ID de lista o campaña inválido.');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['dialer_csrf'], $_POST['csrf_token'])) {
            throw new RuntimeException('Sesión inválida, recarga la página.');
        }
        $upload = $_FILES['leads_txt'] ?? [];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecciona un archivo TXT.');
        if (strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) !== 'txt') throw new RuntimeException('El archivo debe tener extensión .txt.');
        if (!is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) throw new RuntimeException('Archivo de carga inválido.');
        $contents = file_get_contents((string) $upload['tmp_name']);
        if ($contents === false) throw new RuntimeException('No se pudo leer el archivo.');
        $parsed = Dialer::parseLeadsTxt($contents);
        $result = Dialer::importLeads($listId, $campaignId, $parsed);
        $msg = 'Base reemplazada. Cargados: ' . $result['saved'] . '. Duplicados: ' . $result['duplicates'] . '. Rechazados: ' . $result['rejected'] . '.';
    }

    $list = Dialer::list($listId, $campaignId);
    $count = Dialer::leadCount($listId);
    $preview = Dialer::leadsPreview($listId, 50);
} catch (Throwable $e) {
    http_response_code($listId && $campaignId ? 404 : 400);
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lista #<?php echo h($listId); ?> - Zynervox</title>
    <link rel="stylesheet" href="layout.css">
    <style>.dialer-table{width:100%;font-size:12px}.dialer-table th,.dialer-table td{padding:5px 8px;border-bottom:1px solid var(--border);text-align:left}</style>
</head>
<body>
    <?php
    if (($_SESSION['user_level'] ?? 0) == 9) {
        require_once __DIR__ . '/sidebar.php'; renderSidebar('campaigns');
    } else {
        require_once __DIR__ . '/../sup/sidebar.php'; renderSupSidebar('campaigns');
    }
    ?>
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 1rem;">
            <div>
                <h1 style="font-size: 1.25rem; font-weight: 700;"><?php echo !empty($list) ? h('Lista #' . $listId . ' — ' . $list['name']) : 'Lista'; ?></h1>
                <?php if ($campaignId): ?><a href="dialer_campaign_edit.php?id=<?php echo (int) $campaignId; ?>" style="font-size:12px;color:var(--text-muted)">&larr; Volver a la campaña</a><?php endif; ?>
            </div>
        </header>

        <?php if ($msg !== ''): ?><p style="color:#10b981;margin-bottom:1rem;">✅ <?php echo h($msg); ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p style="color:#fca5a5;margin-bottom:1rem;">❌ <?php echo h($error); ?></p><?php endif; ?>

        <?php if (!empty($list)): ?>
        <div class="card" style="margin-bottom:16px;">
            <h2>Cargar base de clientes (<?php echo (int) $count; ?> leads actuales)</h2>
            <p style="font-size:12px;color:var(--text-muted);">
                TXT separado por comas, UTF-8, con cabecera. Columna obligatoria <code>numero</code>;
                opcional <code>nombre</code>. El resto de columnas se guarda como datos extra.
                Reemplaza toda la base de la lista. Máximo 10 MB.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION['dialer_csrf']); ?>">
                <input type="file" name="leads_txt" accept=".txt" required>
                <button type="submit" class="carsa-btn" style="margin-top:8px;">Reemplazar base</button>
            </form>
        </div>

        <div class="card">
            <h2>Últimos leads cargados</h2>
            <table class="dialer-table">
                <thead><tr><th>ID</th><th>Teléfono</th><th>Nombre</th><th>Estado</th></tr></thead>
                <tbody>
                <?php foreach ($preview as $lead): ?>
                    <tr>
                        <td><?php echo (int) $lead['lead_id']; ?></td>
                        <td><?php echo h($lead['phone_number']); ?></td>
                        <td><?php echo h($lead['first_name']); ?></td>
                        <td><?php echo h($lead['status']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$preview): ?><tr><td colspan="4">Sin leads todavía.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
