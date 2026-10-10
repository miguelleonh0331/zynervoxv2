<?php
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__.'/auth.php';
require __DIR__.'/list_audio_jobs.php';
initial_survey_require_login();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new RuntimeException('Método inválido.');
    $listId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    $campaignId = filter_var($_GET['campaign_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$listId || !$campaignId) throw new RuntimeException('Lista o campaña inválida.');
    bot_ivr_repository()->list($listId, $campaignId);
    echo json_encode(['ok'=>true, 'job'=>bot_list_audio_job_status($listId)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false, 'error'=>'No se pudo consultar la generación.']);
}
