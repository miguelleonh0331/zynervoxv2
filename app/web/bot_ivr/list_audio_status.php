<?php
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__.'/auth.php';
require __DIR__.'/list_audio_jobs.php';
require_once __DIR__.'/../ivr_builder/published_flow.php';
initial_survey_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, ['GET','POST'], true)) throw new RuntimeException('Método inválido.');
    $listId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$listId || !$campaignId) throw new RuntimeException('Lista o campaña inválida.');
    $db = bot_ivr_repository();
    $list = $db->list($listId, $campaignId);
    if ($method === 'POST') {
        $source = file_get_contents('php://input');
        if (strlen($source) > 4096) throw new RuntimeException('Solicitud demasiado grande.');
        $data = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_string($data['csrf'] ?? null) || empty($_SESSION['bot_campaign_csrf']) || !hash_equals($_SESSION['bot_campaign_csrf'], $data['csrf'])) {
            http_response_code(403);
            throw new RuntimeException('Sesión inválida. Recarga la página.');
        }
        if (($data['action'] ?? '') !== 'stop' || !is_string($data['job_id'] ?? null)) throw new RuntimeException('Generación inválida.');
        // Release session lock so other tabs can keep polling during the emergency stop.
        session_write_close();
        bot_list_audio_stop($listId, $campaignId, $data['job_id']);
    }
    $job = bot_list_audio_job_status($listId);
    $ready = false;
    try {
        if ($method === 'GET' || ($job['state'] ?? '') === 'ready') {
            $payload = bot_list_audio_payload(ivr_builder_published_flow((int)$list['id_flujo']), $db->audioLeads($listId, $campaignId), $listId, $campaignId, 25);
            $ready = bot_list_audio_ready($job, $payload);
        }
    } catch (Throwable $e) { /* Invalid or changed inputs must keep Play blocked. */ }
    echo json_encode(['ok'=>true, 'job'=>$job, 'ready'=>$ready], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    $error = ($method ?? '') === 'POST' && get_class($e) === 'RuntimeException' ? $e->getMessage() : 'No se pudo consultar la generación.';
    echo json_encode(['ok'=>false, 'error'=>$error], JSON_UNESCAPED_UNICODE);
}
