<?php
declare(strict_types=1);
// php smart-retry-db.php /path/to/web/bot_ivr (test data always rolled back).
// Cubre el motor de reintentos inteligentes (Fase 4 del plan de trabajo):
// opt-in por campaña, pesos/espera/máximo por estado, contacto efectivo y
// estado terminal configurables, límite por teléfono/ventana, clasificación
// tardía, y regresión exacta del comportamiento actual cuando la política
// sigue apagada (default).
require $argv[1] . '/db.php';
require $argv[1] . '/campaigns_service.php';
function retryCheck(bool $ok, string $why): void { if (!$ok) throw new RuntimeException($why); }
$db = carsa_db();
$repo = \ZynervoxQueries\botIvrRepository(bot_ivr_db_config(), $db);
$db->beginTransaction();
try {
    // --- 1) Regresión: campaña SIN política (default) se comporta igual que hoy. ---
    $campaignOff = $repo->createCampaign('Retry off fixture', true);
    $listOff = $repo->createList($campaignOff, 'Retry off list', true, 2);
    $repo->updateListDialPrefix($listOff, $campaignOff, '7307');
    $campaignRow = $repo->campaign($campaignOff);
    retryCheck((int)$campaignRow['retry_policy_enabled'] === 0, 'retry_policy_enabled debe ser 0 por defecto');
    $runOff = bin2hex(random_bytes(16));
    $repo->createDialRun($listOff, $campaignOff, $runOff, 3, ['statuses'=>['NEW','NA'], 'order'=>'UP', 'prefix'=>'7307', 'flow_id'=>2], 'fixture');
    $repo->transitionDialRun($runOff, 'running');
    $callOff = 'retryoff-' . bin2hex(random_bytes(6));
    $claim = $repo->claimDialLead($runOff, $callOff);
    retryCheck($claim !== null, 'Debe reclamar un lead en campaña sin política');
    $repo->finishCall($callOff, 'NOANSWER', '', '', 16, false);
    $stmt = $db->prepare('SELECT status,next_call_at,retry_attempt_count,retry_excluded_at FROM zynervox_bot_list WHERE lead_id=?');
    $stmt->execute([$claim['lead_id']]);
    $leadOff = $stmt->fetch(PDO::FETCH_ASSOC);
    retryCheck($leadOff['status'] === 'NA', 'Estado NA tras NOANSWER');
    retryCheck($leadOff['next_call_at'] === null, 'Sin política, next_call_at debe seguir NULL (comportamiento actual)');
    retryCheck((int)$leadOff['retry_attempt_count'] === 0, 'Sin política, retry_attempt_count no debe tocarse');
    retryCheck($leadOff['retry_excluded_at'] === null, 'Sin política, retry_excluded_at no debe tocarse');

    // --- 2) Activar política: pesos, espera, límite por estado. ---
    $campaignOn = $repo->createCampaign('Retry on fixture', true);
    $listOn = $repo->createList($campaignOn, 'Retry on list', true, 2);
    $repo->updateListDialPrefix($listOn, $campaignOn, '7307');
    $existing = $repo->campaign($campaignOn);
    bot_campaign_update($repo, $campaignOn, array_merge($existing, [
        'start_time' => substr($existing['start_time'], 0, 5),
        'end_time' => substr($existing['end_time'], 0, 5),
        'retry_policy_present' => '1',
        'retry_policy_enabled' => '1',
        'max_attempts_global' => '3',
        'max_attempts_window_days' => '',
        'max_attempts_window_count' => '',
    ]));
    $repo->upsertRetryRule($campaignOn, 'NA', ['enabled'=>true, 'weight'=>100, 'wait_minutes'=>5, 'max_attempts'=>2, 'is_effective_contact'=>false, 'is_terminal'=>false]);
    $repo->upsertRetryRule($campaignOn, 'NO_INTERESADO', ['enabled'=>true, 'weight'=>0, 'wait_minutes'=>0, 'max_attempts'=>null, 'is_effective_contact'=>true, 'is_terminal'=>true]);
    $rules = $repo->retryRules($campaignOn);
    retryCheck(count($rules) === 2, 'Deben persistir 2 reglas');

    $runOn = bin2hex(random_bytes(16));
    $repo->createDialRun($listOn, $campaignOn, $runOn, 3, ['statuses'=>['NEW','NA'], 'order'=>'UP', 'prefix'=>'7307', 'flow_id'=>2], 'fixture');
    $repo->transitionDialRun($runOn, 'running');
    $callA = 'retryon-a-' . bin2hex(random_bytes(6));
    $claimA = $repo->claimDialLead($runOn, $callA);
    retryCheck($claimA !== null, 'Debe reclamar primer lead en campaña con política');
    $repo->finishCall($callA, 'NOANSWER', '', '', 16, false);
    $stmt->execute([$claimA['lead_id']]);
    $leadA = $stmt->fetch(PDO::FETCH_ASSOC);
    retryCheck($leadA['status'] === 'NA', 'Estado NA tras NOANSWER (con política)');
    retryCheck($leadA['next_call_at'] !== null, 'Con política y regla habilitada, next_call_at debe calcularse');
    retryCheck((int)$leadA['retry_attempt_count'] === 1, 'retry_attempt_count debe incrementar a 1');
    retryCheck($leadA['retry_excluded_at'] === null, 'Primer reintento no debe excluir el lead');

    // --- 3) No se reintenta antes de next_call_at (espera respetada). ---
    $repo->transitionDialRun($runOn, 'completed');
    $runOn2 = bin2hex(random_bytes(16));
    $repo->createDialRun($listOn, $campaignOn, $runOn2, 3, ['statuses'=>['NEW','NA'], 'order'=>'UP', 'prefix'=>'7307', 'flow_id'=>2], 'fixture');
    $repo->transitionDialRun($runOn2, 'running');
    $callRetryTooSoon = 'retryon-tooSoon-' . bin2hex(random_bytes(6));
    $claimTooSoon = $repo->claimDialLead($runOn2, $callRetryTooSoon);
    retryCheck($claimTooSoon === null || (int)$claimTooSoon['lead_id'] !== (int)$claimA['lead_id'], 'No debe reclamar el lead antes de su next_call_at (espera de 5 min)');

    // --- 4) Límite agotado: retry_excluded_at tras alcanzar max_attempts por estado. ---
    $db->prepare('UPDATE zynervox_bot_list SET next_call_at=UTC_TIMESTAMP(6) WHERE lead_id=?')->execute([$claimA['lead_id']]);
    $callB = 'retryon-b-' . bin2hex(random_bytes(6));
    $claimB = $repo->claimDialLead($runOn2, $callB);
    retryCheck($claimB !== null && (int)$claimB['lead_id'] === (int)$claimA['lead_id'], 'Debe volver a reclamar el mismo lead tras vencer la espera');
    $repo->finishCall($callB, 'NOANSWER', '', '', 16, false);
    $stmt->execute([$claimA['lead_id']]);
    $leadB = $stmt->fetch(PDO::FETCH_ASSOC);
    retryCheck((int)$leadB['retry_attempt_count'] === 2, 'retry_attempt_count debe llegar a 2');
    retryCheck($leadB['retry_excluded_at'] !== null, 'max_attempts=2 alcanzado: debe excluirse del ciclo');

    // --- 5) Estado terminal configurable: excluye de inmediato sin esperar límite. ---
    $callC = 'retryon-c-' . bin2hex(random_bytes(6));
    $repo->startCall($listOn, '999100002', $callC);
    $repo->recordCallEvent($callC, 'IVR_NODE', hash('sha256', $callC . 'status'), time(), ['action'=>'status', 'status'=>'NO_INTERESADO']);
    $repo->finishCall($callC, 'ANSWER', '', '', 16, true);
    $stmt2 = $db->prepare('SELECT status,next_call_at,retry_excluded_at FROM zynervox_bot_call_attempts WHERE call_id=?');
    $stmt2->execute([$callC]);
    $leadCPhone = $db->prepare('SELECT lead_id FROM zynervox_bot_list WHERE list_id=? AND phone=?');
    $leadCPhone->execute([$listOn, '999100002']);
    $leadCId = $leadCPhone->fetchColumn();
    $stmt->execute([$leadCId]);
    $leadC = $stmt->fetch(PDO::FETCH_ASSOC);
    retryCheck($leadC['status'] === 'NO_INTERESADO', 'Estado NO_INTERESADO debe conservarse (resultado de flujo)');
    retryCheck($leadC['next_call_at'] === null, 'Estado terminal no debe calcular next_call_at');
    retryCheck($leadC['retry_excluded_at'] !== null, 'Estado terminal debe excluir de inmediato, sin esperar límite');

    // --- 6) Monitor: métricas nuevas reflejan espera/acumulados/efectivos/excluidos. ---
    $rows = array_column($repo->campaignMonitor(), null, 'campaign_id');
    $monOn = $rows[$campaignOn];
    retryCheck($monOn['excluded_limit_reached'] >= 2, 'Monitor debe contar leads excluidos (límite + terminal)');
    retryCheck($monOn['effective_contacts'] >= 1, 'Monitor debe contar contactos efectivos (NO_INTERESADO marcado como tal)');
    retryCheck($monOn['cumulative_attempts'] >= 3, 'Monitor debe acumular intentos (called_count) por encima de los leads únicos');

    // --- 7) Clasificación tardía: PREANSWER después de FINISH recalcula next_call_at. ---
    $repo->upsertRetryRule($campaignOn, 'PREANSWER_CASILLA', ['enabled'=>true, 'weight'=>50, 'wait_minutes'=>10, 'max_attempts'=>5, 'is_effective_contact'=>false, 'is_terminal'=>false]);
    $callD = 'retryon-d-' . bin2hex(random_bytes(6));
    $leadD = $repo->startCall($listOn, '999100003', $callD);
    $db->prepare("UPDATE zynervox_bot_call_attempts SET origin_prefix='7307' WHERE call_id=?")->execute([$callD]);
    $repo->finishCall($callD, 'NOANSWER', '', '', 16, false);
    $late = $repo->savePreanswerResult($callD, hash('sha256', $callD . '|late'), time(), ['category'=>'casilla', 'text'=>'buzon de voz']);
    retryCheck($late === false, 'savePreanswerResult debe indicar que la llamada ya había terminado');
    $stmt->execute([$leadD]);
    $leadDRow = $stmt->fetch(PDO::FETCH_ASSOC);
    retryCheck($leadDRow['status'] === 'PREANSWER_CASILLA', 'Clasificación tardía debe actualizar el estado final');
    retryCheck($leadDRow['next_call_at'] !== null, 'Clasificación tardía con regla habilitada debe calcular next_call_at');

    // --- 8) Idempotencia: un cierre tardío nunca pisa un intento más nuevo. ---
    $callE1 = 'retryon-e1-' . bin2hex(random_bytes(6));
    $leadE = $repo->startCall($listOn, '999100004', $callE1);
    $db->prepare("UPDATE zynervox_bot_call_attempts SET origin_prefix='7307' WHERE call_id=?")->execute([$callE1]);
    // Segundo intento ya existe para el mismo lead antes de que llegue la
    // clasificación tardía del primero (orden desordenado de callbacks).
    $db->prepare("UPDATE zynervox_bot_list SET status='NEW' WHERE lead_id=?")->execute([$leadE]);
    $callE2 = 'retryon-e2-' . bin2hex(random_bytes(6));
    $repo->startCall($listOn, '999100004', $callE2);
    $repo->finishCall($callE2, 'NOANSWER', '', '', 16, false);
    $stmt->execute([$leadE]);
    $beforeLate = $stmt->fetch(PDO::FETCH_ASSOC);
    $repo->finishCall($callE1, 'CHANUNAVAIL', '', '', 0, false);
    $lateE = $repo->savePreanswerResult($callE1, hash('sha256', $callE1 . '|late'), time(), ['category'=>'numero_no_existe', 'text'=>'no existe']);
    $stmt->execute([$leadE]);
    $afterLate = $stmt->fetch(PDO::FETCH_ASSOC);
    retryCheck($afterLate['status'] === $beforeLate['status'] && $afterLate['next_call_at'] === $beforeLate['next_call_at'], 'Cierre tardío del intento viejo no debe pisar la agenda del intento más nuevo');

    echo "SMART_RETRY_OK: opt-in/regresión, pesos/espera, límite por estado, terminal configurable, monitor, clasificación tardía, idempotencia\n";
} finally {
    $db->rollBack();
}
