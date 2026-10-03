<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_auth(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') require_csrf();
$route = (string)($_GET['route'] ?? '');
// proxy-health/test corre una prueba real de conexion (gTTS a traves del proxy);
// test-all las corre TODAS en paralelo (boton "Reintentar todos") y puede
// tardar bastante mas -- ver timeouts especificos mas abajo.
if ($route === 'proxy-health/test') @set_time_limit(30);
if ($route === 'proxy-health/test-all') @set_time_limit(90);
$allowedGet = ['snapshot', 'events'];
$allowedPost = ['fleet/target', 'fleet/start-engine', 'fleet/stop-all', 'fleet/tts-api-url', 'proxy-health/test', 'proxy-health/test-all', 'proxy-health/edit'];
$valid = $method === 'GET' ? in_array($route, $allowedGet, true) : ($method === 'POST' && (in_array($route, $allowedPost, true) || preg_match('#^workers/\d+/(start|stop|pause|resume|drain|restart|rotate-proxy|auto-restart)$#', $route)));
if (!$valid) { http_response_code(404); echo json_encode(['error'=>'Ruta no permitida']); exit; }

$config = farm_config();
$token = trim((string)@file_get_contents((string)$config['control_token']));
if ($token === '') { http_response_code(503); echo json_encode(['error'=>'Control plane no configurado']); exit; }
$url = rtrim((string)$config['control_url'], '/').'/api/'.$route;
if ($route === 'events' && isset($_GET['worker'])) $url .= '?worker='.(int)$_GET['worker'];
$headers = "X-Control-Token: {$token}\r\nContent-Type: application/json\r\nConnection: close\r\n";
$body = $method === 'POST' ? (string)file_get_contents('php://input') : '';
$timeout = $route === 'proxy-health/test-all' ? 80 : ($route === 'proxy-health/test' ? 25 : 12);
$ctx = stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$body,'timeout'=>$timeout,'ignore_errors'=>true]]);
$raw = @file_get_contents($url, false, $ctx);
if ($raw === false) { http_response_code(502); echo json_encode(['error'=>'Orquestador no disponible']); exit; }
$status = 200;
foreach (($http_response_header ?? []) as $line) if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) $status = (int)$m[1];
http_response_code($status);
if ($method === 'POST') audit_event('proxy_action', ['route'=>$route, 'status'=>$status]);
echo $raw;
