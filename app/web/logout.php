<?php
require_once __DIR__ . '/includes/Auth.php';
use Includes\Auth;

if (session_status()===PHP_SESSION_NONE) session_start();
if (\Config\Config::deployment('isolated',false) && !empty($_SESSION['dialer_entered']) && !empty($_SESSION['user'])) {
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST' || !is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)($_SESSION['dialer_csrf'] ?? ''),$_POST['csrf_token'])) {
        http_response_code(403); exit('Cierra sesión desde el botón del panel.');
    }
    require_once __DIR__.'/includes/Dialer.php';
    try { \Includes\Dialer::agentLogout((string)$_SESSION['user']); }
    catch (\Throwable $error) {
        http_response_code(503);
        echo '<p>No se pudo completar la salida de colas. Reintenta cerrar sesión.</p><form method="POST"><input type="hidden" name="csrf_token" value="'.htmlspecialchars($_SESSION['dialer_csrf'],ENT_QUOTES,'UTF-8').'"><button type="submit">Reintentar cerrar sesión</button></form>'; exit;
    }
}
Auth::logout();
