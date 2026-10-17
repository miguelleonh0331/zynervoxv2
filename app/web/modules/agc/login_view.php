<?php
// Pantalla de acceso del agente. La incluye crm.php cuando no hay sesión;
// publica en la misma URL, así que tras entrar queda la consola.
// Espera $errorLogin definido por crm.php.
if (!isset($errorLogin)) { http_response_code(403); exit; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#2D2F3B">
<title>Acceso de agente · Zynervox</title>
<link rel="stylesheet" href="crm.css">
</head>
<body class="login-body">

<main class="login-box">
  <header class="login-head">
    <span class="brand"><i></i> ZYNERVOX <b>CRM</b></span>
    <span class="login-sub">Consola de atención</span>
  </header>

  <form class="login-form" method="post" action="crm.php" autocomplete="on">
    <input type="hidden" name="action" value="login">

    <?php if ($errorLogin !== ''): ?>
      <p class="login-error" role="alert"><?php echo crm_h($errorLogin); ?></p>
    <?php endif; ?>

    <label for="username">Usuario</label>
    <input id="username" name="username" type="text" required autofocus
           autocomplete="username" maxlength="60" spellcheck="false">

    <label for="password">Contraseña</label>
    <input id="password" name="password" type="password" required
           autocomplete="current-password" maxlength="200">

    <button type="submit" class="btn btn--primary">Entrar</button>
  </form>

  <footer class="login-foot">Acceso restringido a agentes de Zynervox</footer>
</main>

</body>
</html>
