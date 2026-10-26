<?php
// Internal view: HTTP entry is users.php, protected by IsolatedGate.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__) { http_response_code(403);exit; }
require_once __DIR__.'/../../includes/CoreUsers.php';
\Includes\Auth::checkAccess(9);
$_SESSION['core_users_csrf']=$_SESSION['core_users_csrf'] ?? bin2hex(random_bytes(32));
$message='';$error='';$editing=null;$rows=[];
$values=['user'=>'','full_name'=>'','active'=>'Y','user_level'=>1];
$levels=[1=>'Agente',7=>'GTR',8=>'Supervisor',9=>'Administrador'];
function userHtml($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['core_users_csrf'],$_POST['csrf_token'])) { http_response_code(403);throw new \InvalidArgumentException('Sesión inválida, recarga la página.'); }
        $old=$_POST['existing_user'] ?? '';
        if (!is_string($old)) throw new \InvalidArgumentException('Usuario inválido.');
        \Includes\CoreUsers::saveUser($_POST,$old==='' ? null : $old);
        if ($old!=='') { $message='Cuenta actualizada.'; }
        elseif ((int)($_POST['user_level'] ?? 1)===1) { $message='Agente creado. Crea su anexo SIP y asígnalo a la campaña.'; }
        else { $message='Cuenta creada. Entra al monitor de campañas con su propio login.'; }
    }
    if (isset($_GET['edit'])) {
        if (!is_string($_GET['edit'])) throw new \InvalidArgumentException('Usuario inválido.');
        $candidate=\Includes\CoreUsers::getByUser($_GET['edit']);
        if (!$candidate || !in_array((int)$candidate['user_level'],\Includes\CoreUsers::levels(),true)) throw new \InvalidArgumentException('Solo se pueden editar cuentas de agente, GTR o supervisor.');
        $editing=$candidate;$values=$editing;
    }
} catch (\InvalidArgumentException $exception) { $error=$exception->getMessage(); }
catch (\Throwable $exception) { http_response_code(500);$error='No se pudo guardar el agente. Revisa la conexión de la base de datos.'; }
try { $rows=\Includes\CoreUsers::getAll(); }
catch (\Throwable $exception) { http_response_code(503);$error='No se pudo consultar Usuarios.'; }
?>
<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Usuarios - Zynervox</title><link rel="stylesheet" href="layout.css">
<style>body{font-family:Arial,sans-serif}.users-table{width:100%;border-collapse:collapse}.users-table th,.users-table td{text-align:left;padding:10px;border-bottom:1px solid var(--border)}.users-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.users-grid .field{align-content:start}.hint{margin:4px 0 0;font-size:.8rem;color:var(--text-muted)}.users-table-wrap{overflow-x:auto}@media(max-width:700px){.users-grid{grid-template-columns:1fr}}</style></head>
<body><?php require_once __DIR__.'/sidebar.php';renderSidebar('users'); ?>
<main class="main-content"><header class="top-bar"><h1>Usuarios</h1></header>
<?php if ($message): ?><p role="status"><?php echo userHtml($message); ?></p><?php endif; ?>
<?php if ($error): ?><p role="alert"><?php echo userHtml($error); ?></p><?php endif; ?>
<div class="card"><h2>Cuentas de acceso</h2><div class="users-table-wrap"><table class="users-table"><thead><tr><th>Login</th><th>Nombre</th><th>Nivel</th><th>Activo</th><th>Acciones</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?>
<tr><td><?php echo userHtml($row['user']); ?></td><td><?php echo userHtml($row['full_name']); ?></td><td><?php echo userHtml($levels[(int)$row['user_level']] ?? $row['user_level']); ?></td><td><?php echo $row['active']==='Y' ? 'Sí' : 'No'; ?></td><td><?php if (in_array((int)$row['user_level'],\Includes\CoreUsers::levels(),true)): ?><a href="users.php?edit=<?php echo rawurlencode($row['user']); ?>">Editar</a><?php if ((int)$row['user_level']===1): ?> · <a href="phones.php?search=<?php echo rawurlencode($row['user']); ?>">Anexo SIP</a><?php endif; ?><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2><?php echo $editing ? 'Editar cuenta' : 'Crear cuenta'; ?></h2>
<form method="POST"><input type="hidden" name="csrf_token" value="<?php echo userHtml($_SESSION['core_users_csrf']); ?>"><input type="hidden" name="existing_user" value="<?php echo userHtml($editing['user'] ?? ''); ?>">
<div class="users-grid"><div class="field"><label for="agent-level">Perfil de acceso</label>
<?php if ($editing): ?><input type="hidden" name="user_level" value="<?php echo (int)$values['user_level']; ?>"><input id="agent-level" type="text" readonly value="<?php echo userHtml($levels[(int)$values['user_level']] ?? $values['user_level']); ?>">
<?php else: ?><select id="agent-level" name="user_level"><?php foreach (\Includes\CoreUsers::levels() as $lvl): ?><option value="<?php echo (int)$lvl; ?>" <?php echo (int)$values['user_level']===(int)$lvl ? 'selected' : ''; ?>><?php echo userHtml($levels[$lvl]); ?></option><?php endforeach; ?></select><?php endif; ?></div>
<div class="field"><label for="agent-user">Login</label><input id="agent-user" name="user" maxlength="20" required <?php echo $editing ? 'readonly' : ''; ?> value="<?php echo userHtml($values['user']); ?>"><p class="hint">Agente: los dígitos de su anexo SIP. GTR y supervisor: 3 a 20 caracteres en minúscula.</p></div>
<div class="field"><label for="agent-name">Nombre completo</label><input id="agent-name" name="full_name" maxlength="100" required value="<?php echo userHtml($values['full_name']); ?>"></div>
<div class="field"><label for="agent-password">Contraseña web</label><input id="agent-password" type="password" name="pass" autocomplete="new-password" minlength="8" maxlength="72" <?php echo $editing ? '' : 'required'; ?>><p class="hint"><?php echo $editing ? 'Vacía conserva la actual.' : 'Mínimo 8 caracteres.'; ?></p></div>
<div class="field"><label for="agent-active">Cuenta activa</label><select id="agent-active" name="active"><option value="Y" <?php echo $values['active']==='Y' ? 'selected' : ''; ?>>Sí</option><option value="N" <?php echo $values['active']==='N' ? 'selected' : ''; ?>>No</option></select></div></div>
<button class="btn-primary" type="submit"><?php echo $editing ? 'Guardar cuenta' : 'Crear cuenta'; ?></button> <?php if ($editing): ?><a href="users.php">Nueva cuenta</a><?php endif; ?>
</form></div></main></body></html>
