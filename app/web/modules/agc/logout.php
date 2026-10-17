<?php
// Salida del agente. Cierra primero la sesión del discador y después la de
// Zynervox, y devuelve a la consola, que exigirá credenciales de nuevo.
//
// No se usa Auth::logout() porque redirige al index.php del panel: el entorno
// del agente es autónomo y nunca lo manda fuera de modules/agc/.
declare(strict_types=1);
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/Dialer.php';
use Includes\Dialer;

if (session_status() === PHP_SESSION_NONE) session_start();

$usuario = (string)($_SESSION['user'] ?? '');

// Sacar al agente de las colas antes de soltar la sesión; si el discador no
// responde la salida continúa igual, para no dejarlo atrapado en la consola.
if ($usuario !== '') {
    try { Dialer::agentLogout($usuario); } catch (Throwable $e) { /* se cierra igual */ }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Cache-Control: no-store, private');
header('Location: crm.php', true, 303);
