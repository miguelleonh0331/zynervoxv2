<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/page.php';
require_once __DIR__ . '/campaigns_service.php';
initial_survey_require_login();
if (empty($_SESSION['bot_campaign_csrf'])) $_SESSION['bot_campaign_csrf'] = bin2hex(random_bytes(32));
function bot_campaign_csrf(): void {
    if (!hash_equals($_SESSION['bot_campaign_csrf'], (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Sesión inválida. Recarga la página.');
    }
}
function bot_campaign_token(): void {
    echo '<input type="hidden" name="csrf" value="' . h($_SESSION['bot_campaign_csrf']) . '">';
}
function bot_campaign_redirect(string $target, string $message): void {
    $_SESSION['bot_campaign_message'] = $message;
    header('Location: ' . $target);
    exit;
}
$message = (string)($_SESSION['bot_campaign_message'] ?? '');
unset($_SESSION['bot_campaign_message']);
$error = '';
$dbConfig = ['engine'=>'mysql', 'database'=>'zynervox', 'port'=>'3306'];
try { $dbConfig = bot_ivr_db_config(); } catch (Throwable $e) { $error = $e->getMessage(); }
$dbConfig['database'] = 'zynervox';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_db_config') {
    try {
        bot_campaign_csrf();
        $candidate = ['database'=>'zynervox', 'engine'=>(string)($_POST['db_engine'] ?? ($dbConfig['engine'] ?? 'mysql'))];
        foreach (['server','port','user'] as $key) $candidate[$key] = trim((string)($_POST['db_'.$key] ?? ''));
        $candidate['password'] = (string)($_POST['db_password'] ?? '');
        if ($candidate['password'] === '') $candidate['password'] = (string)($dbConfig['password'] ?? '');
        bot_ivr_db_save($candidate);
        bot_campaign_redirect('index.php', 'Conexión verificada y configuración guardada.');
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
function bot_campaign_header(string $title): void {
    global $message, $error;
    initial_survey_page_start($title);
    echo <<<'CSS'
<style>
.bot-nav{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border)}
.bot-nav a{color:var(--primary-hover);font-size:13px;text-decoration:none;font-weight:600}.bot-nav a:hover{text-decoration:underline}
.bot-nav button{margin-left:auto}.bot-form{max-width:520px}.bot-table-wrap{overflow-x:auto}
dialog{border:1px solid var(--border);border-radius:2px;max-width:520px;width:calc(100% - 48px);padding:18px;background:var(--bg-card);color:var(--text)}
dialog h2{font-size:14px;margin-bottom:14px}dialog::backdrop{background:rgba(45,47,59,.45)}
@media(max-width:600px){.bot-nav button{margin-left:0}}
</style>
CSS;

    echo '<nav class="bot-nav"><a href="index.php">Mostrar campañas</a><a href="index.php?view=create">Crear campaña</a><a href="audio_lab.php">Prueba de audios</a><button type="button" class="carsa-btn secondary" onclick="document.getElementById(\'dbConfigModal\').showModal()">Configurar conexión a base de datos</button></nav>';
    if ($message !== '') echo '<div class="carsa-msg">'.h($message).'</div>';
    if ($error !== '') echo '<div class="carsa-err">'.h($error).'</div>';
}
function bot_campaign_footer(): void {
    global $dbConfig;
    ?>
    <dialog id="dbConfigModal" aria-labelledby="dbConfigTitle">
      <h2 id="dbConfigTitle">Configurar conexión a base de datos</h2>
      <form method="post" class="carsa-form" autocomplete="off">
        <input type="hidden" name="action" value="save_db_config">
        <?php bot_campaign_token(); ?>
        <div class="carsa-field"><label for="db_engine">Motor de base de datos</label>
        <select id="db_engine" name="db_engine" required>
        <?php foreach (['mysql'=>'MySQL', 'mariadb'=>'MariaDB'] as $engine=>$label): ?>
        <option value="<?php echo $engine; ?>" <?php echo ($dbConfig['engine'] ?? 'mysql') === $engine ? 'selected' : ''; ?>><?php echo $label; ?></option>
        <?php endforeach; ?></select></div>
        <?php foreach (['server'=>'Servidor','port'=>'Puerto','database'=>'Base de datos','user'=>'Usuario'] as $key=>$label): ?>
        <div class="carsa-field"><label for="db_<?php echo $key; ?>"><?php echo $label; ?></label>
        <input id="db_<?php echo $key; ?>" name="db_<?php echo $key; ?>" value="<?php echo h($dbConfig[$key] ?? ''); ?>" required <?php echo $key==='database' ? 'readonly' : ''; ?> <?php echo $key==='port' ? 'type="number" min="1" max="65535"' : 'type="text"'; ?>></div>
        <?php endforeach; ?>
        <div class="carsa-field"><label for="db_password">Contraseña</label><input id="db_password" name="db_password" type="password" autocomplete="new-password" placeholder="Vacío: conservar contraseña actual"></div>
        <div class="carsa-actions"><button class="carsa-btn">Probar y guardar</button><button type="button" class="carsa-btn secondary" onclick="document.getElementById('dbConfigModal').close()">Cerrar</button></div>
      </form>
    </dialog>
    <script>document.getElementById('dbConfigModal').addEventListener('close',()=>{document.getElementById('db_password').value='';});</script>
    <?php initial_survey_page_end();
}