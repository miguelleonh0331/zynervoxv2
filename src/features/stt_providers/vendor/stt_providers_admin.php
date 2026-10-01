<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_bridge.php';
stt_require_admin();

/*
 * BOOTSTRAP DE SESION (reemplaza al auth.php original del modulo initial_survey).
 * El auth.php de origen hacia session_start() + initial_survey_require_login().
 * Aqui SOLO se inicia la sesion PHP (el CSRF de este admin depende de $_SESSION).
 * IMPORTANTE: el proyecto que integre este modulo DEBE envolver este archivo con
 * su propia autenticacion/sesion antes de exponerlo publicamente.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require __DIR__ . '/lib/db.php';

/*
 * Administrador unificado de cuentas STT.
 *
 * Deepgram conserva su tabla historica deepgram_profiles. ElevenLabs, Groq y
 * Speechmatics comparten carsa_stt_api_keys. La interfaz normaliza ambas
 * estructuras sin migrar datos ni cambiar los consumidores existentes.
 */

const STT_PROVIDERS = [
    'assemblyai' => [
        'label' => 'AssemblyAI',
        'default_model' => '',
        'key_placeholder' => 'API key de AssemblyAI',
        'supports_model' => true,
    ],
    'deepgram' => [
        'label' => 'Deepgram',
        'default_model' => '',
        'key_placeholder' => 'API key de Deepgram',
        'supports_model' => false,
    ],
    'elevenlabs' => [
        'label' => 'ElevenLabs',
        'default_model' => 'scribe_v2',
        'key_placeholder' => 'sk_...',
        'supports_model' => true,
    ],
    'gladia' => [
        'label' => 'Gladia',
        'default_model' => '',
        'key_placeholder' => 'API key de Gladia',
        'supports_model' => true,
    ],
    'groq' => [
        'label' => 'Groq',
        'default_model' => 'whisper-large-v3-turbo',
        'key_placeholder' => 'gsk_...',
        'supports_model' => true,
    ],
    'speechmatics' => [
        'label' => 'Speechmatics',
        'default_model' => 'es',
        'key_placeholder' => 'sm_...',
        'supports_model' => true,
    ],
];

if (!isset($_SESSION['stt_providers_admin_csrf'])) {
    $_SESSION['stt_providers_admin_csrf'] = bin2hex(random_bytes(24));
}
$csrf = (string) $_SESSION['stt_providers_admin_csrf'];

function json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function require_csrf(string $expected): void {
    $sent = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($sent === '' || !hash_equals($expected, $sent)) {
        json_response(['ok' => false, 'error' => 'Token CSRF invalido'], 403);
    }
}

function provider_config(string $provider): array {
    if (!array_key_exists($provider, STT_PROVIDERS)) {
        json_response(['ok' => false, 'error' => 'Proveedor no valido'], 422);
    }
    return STT_PROVIDERS[$provider];
}

function mask_key(string $key): string {
    $length = strlen($key);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }
    return substr($key, 0, 4) . str_repeat('*', $length - 8) . substr($key, -4);
}

function curl_json(string $url, array $headers, string $method = 'GET', ?string $requestBody = null): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($method !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }
    if ($requestBody !== null) {
        $options[CURLOPT_POSTFIELDS] = $requestBody;
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        return ['ok' => false, 'code' => 0, 'data' => null, 'error' => 'Error de conexion: ' . $error];
    }

    $data = json_decode((string) $body, true);
    return [
        'ok' => $code >= 200 && $code < 300,
        'code' => $code,
        'data' => is_array($data) ? $data : null,
        'error' => null,
    ];
}

function check_deepgram(string $apiKey): array {
    $projects = curl_json(
        'https://api.deepgram.com/v1/projects',
        ['Authorization: Token ' . $apiKey, 'Accept: application/json']
    );
    if (!$projects['ok']) {
        return ['ok' => false, 'error' => $projects['error'] ?: 'Deepgram respondio HTTP ' . $projects['code']];
    }

    $projectId = $projects['data']['projects'][0]['project_id'] ?? null;
    if (!$projectId) {
        return ['ok' => false, 'error' => 'Deepgram no devolvio un project_id'];
    }

    $balances = curl_json(
        'https://api.deepgram.com/v1/projects/' . rawurlencode((string) $projectId) . '/balances',
        ['Authorization: Token ' . $apiKey, 'Accept: application/json']
    );
    if (!$balances['ok']) {
        return ['ok' => false, 'error' => $balances['error'] ?: 'Deepgram balances respondio HTTP ' . $balances['code']];
    }

    $balance = $balances['data']['balances'][0] ?? null;
    if (!is_array($balance)) {
        return ['ok' => false, 'error' => 'Deepgram no devolvio saldo'];
    }

    $amount = (float) ($balance['amount'] ?? 0);
    $units = (string) ($balance['units'] ?? 'USD');
    return [
        'ok' => true,
        'status' => 'ok',
        'detail' => rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.') . ' ' . $units,
        'amount' => $amount,
        'units' => $units,
    ];
}

function check_assemblyai(string $apiKey): array {
    $response = curl_json(
        'https://api.assemblyai.com/v2/transcript?limit=1',
        ['Authorization: ' . $apiKey, 'Accept: application/json']
    );
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error'] ?: 'AssemblyAI respondio HTTP ' . $response['code']];
    }

    $count = is_array($response['data']['transcripts'] ?? null)
        ? count($response['data']['transcripts'])
        : 0;
    return [
        'ok' => true,
        'status' => 'ok',
        'detail' => 'Autenticacion valida (' . $count . ' transcripciones visibles)',
    ];
}

function check_elevenlabs(string $apiKey): array {
    $response = curl_json(
        'https://api.elevenlabs.io/v1/user/subscription',
        ['xi-api-key: ' . $apiKey, 'Accept: application/json']
    );
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error'] ?: 'ElevenLabs respondio HTTP ' . $response['code']];
    }

    $used = (int) ($response['data']['character_count'] ?? 0);
    $limit = (int) ($response['data']['character_limit'] ?? 0);
    $remaining = max(0, $limit - $used);
    return ['ok' => true, 'status' => 'ok', 'detail' => number_format($remaining, 0, '.', ',') . ' caracteres restantes'];
}

function check_gladia(string $apiKey): array {
    /* Una solicitud vacia valida autenticacion, pero no contiene audio y por
     * tanto Gladia la rechaza antes de crear un trabajo o consumir saldo. */
    $response = curl_json(
        'https://api.gladia.io/v2/pre-recorded',
        ['x-gladia-key: ' . $apiKey, 'Accept: application/json', 'Content-Type: application/json'],
        'POST',
        '{}'
    );
    if ($response['error']) {
        return ['ok' => false, 'error' => $response['error']];
    }
    if ($response['code'] === 401 || $response['code'] === 403) {
        return ['ok' => false, 'error' => 'Gladia rechazo la API key (HTTP ' . $response['code'] . ')'];
    }
    if ($response['ok'] || $response['code'] === 400 || $response['code'] === 422) {
        return ['ok' => true, 'status' => 'ok', 'detail' => 'Autenticacion valida'];
    }
    return ['ok' => false, 'error' => 'Gladia respondio HTTP ' . $response['code']];
}

function check_groq(string $apiKey): array {
    $response = curl_json(
        'https://api.groq.com/openai/v1/models',
        ['Authorization: Bearer ' . $apiKey, 'Accept: application/json']
    );
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error'] ?: 'Groq respondio HTTP ' . $response['code']];
    }

    $models = is_array($response['data']['data'] ?? null) ? count($response['data']['data']) : 0;
    return ['ok' => true, 'status' => 'ok', 'detail' => $models . ' modelos disponibles'];
}

function check_speechmatics(string $apiKey): array {
    $response = curl_json(
        'https://asr.api.speechmatics.com/v2/jobs?limit=1',
        ['Authorization: Bearer ' . $apiKey, 'Accept: application/json']
    );
    if (!$response['ok']) {
        return ['ok' => false, 'error' => $response['error'] ?: 'Speechmatics respondio HTTP ' . $response['code']];
    }

    $jobs = is_array($response['data']['jobs'] ?? null) ? count($response['data']['jobs']) : 0;
    return ['ok' => true, 'status' => 'ok', 'detail' => 'Autenticacion valida (' . $jobs . ' trabajos visibles)'];
}

function check_provider(string $provider, string $apiKey): array {
    if ($provider === 'assemblyai') {
        return check_assemblyai($apiKey);
    }
    if ($provider === 'deepgram') {
        return check_deepgram($apiKey);
    }
    if ($provider === 'elevenlabs') {
        return check_elevenlabs($apiKey);
    }
    if ($provider === 'gladia') {
        return check_gladia($apiKey);
    }
    if ($provider === 'groq') {
        return check_groq($apiKey);
    }
    if ($provider === 'speechmatics') {
        return check_speechmatics($apiKey);
    }
    return ['ok' => false, 'error' => 'Proveedor no valido'];
}

function detect_audio_mime(string $filePath, string $ext): string {
    $extMap = [
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg', 'm4a' => 'audio/mp4', 'webm' => 'audio/webm',
        'flac' => 'audio/flac', 'mp4' => 'audio/mp4',
    ];
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($filePath);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($filePath);
        if (is_string($detected) && $detected !== '') {
            return $detected;
        }
    }
    return $extMap[$ext] ?? 'application/octet-stream';
}

function curl_raw(string $url, array $headers, string $method = 'GET', $body = null, int $timeout = 20): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($method !== 'GET') {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
    }
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $error === '' && $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $responseBody === false ? '' : (string) $responseBody,
        'error' => $error,
    ];
}

function transcribe_deepgram(string $apiKey, string $filePath, string $mime): array {
    $bytes = file_get_contents($filePath);
    if ($bytes === false) {
        return ['ok' => false, 'error' => 'No se pudo leer el archivo temporal'];
    }
    $res = curl_raw(
        'https://api.deepgram.com/v1/listen?model=nova-2&smart_format=true',
        ['Authorization: Token ' . $apiKey, 'Content-Type: ' . ($mime !== '' ? $mime : 'audio/wav')],
        'POST',
        $bytes
    );
    if (!$res['ok']) {
        return ['ok' => false, 'error' => 'Deepgram HTTP ' . $res['code'] . ($res['error'] !== '' ? ' - ' . $res['error'] : '') . ' ' . substr($res['body'], 0, 200)];
    }
    $data = json_decode($res['body'], true);
    $transcript = $data['results']['channels'][0]['alternatives'][0]['transcript'] ?? null;
    if ($transcript === null) {
        return ['ok' => false, 'error' => 'Deepgram no devolvio transcripcion'];
    }
    return ['ok' => true, 'transcript' => (string) $transcript];
}

function transcribe_groq(string $apiKey, string $model, string $filePath, string $mime, string $fileName): array {
    $model = $model !== '' ? $model : 'whisper-large-v3-turbo';
    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', $fileName),
            'model' => $model,
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error !== '') {
        return ['ok' => false, 'error' => 'Error de conexion: ' . $error];
    }
    $data = json_decode((string) $body, true);
    if ($code < 200 || $code >= 300) {
        $detail = $data['error']['message'] ?? substr((string) $body, 0, 200);
        return ['ok' => false, 'error' => 'Groq HTTP ' . $code . ': ' . $detail];
    }
    $text = $data['text'] ?? null;
    if ($text === null) {
        return ['ok' => false, 'error' => 'Groq no devolvio texto'];
    }
    return ['ok' => true, 'transcript' => (string) $text];
}

function transcribe_elevenlabs(string $apiKey, string $model, string $filePath, string $mime, string $fileName): array {
    $model = $model !== '' ? $model : 'scribe_v1';
    $ch = curl_init('https://api.elevenlabs.io/v1/speech-to-text');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['xi-api-key: ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'file' => new CURLFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', $fileName),
            'model_id' => $model,
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error !== '') {
        return ['ok' => false, 'error' => 'Error de conexion: ' . $error];
    }
    $data = json_decode((string) $body, true);
    if ($code < 200 || $code >= 300) {
        $detail = $data['detail']['message'] ?? ($data['detail'] ?? substr((string) $body, 0, 200));
        return ['ok' => false, 'error' => 'ElevenLabs HTTP ' . $code . ': ' . (is_string($detail) ? $detail : json_encode($detail))];
    }
    $text = $data['text'] ?? null;
    if ($text === null) {
        return ['ok' => false, 'error' => 'ElevenLabs no devolvio texto'];
    }
    return ['ok' => true, 'transcript' => (string) $text];
}

function transcribe_assemblyai(string $apiKey, string $filePath): array {
    $bytes = file_get_contents($filePath);
    if ($bytes === false) {
        return ['ok' => false, 'error' => 'No se pudo leer el archivo temporal'];
    }
    $upload = curl_raw('https://api.assemblyai.com/v2/upload', ['Authorization: ' . $apiKey], 'POST', $bytes);
    if (!$upload['ok']) {
        return ['ok' => false, 'error' => 'AssemblyAI upload HTTP ' . $upload['code'] . ' ' . substr($upload['body'], 0, 200)];
    }
    $uploadData = json_decode($upload['body'], true);
    $audioUrl = $uploadData['upload_url'] ?? null;
    if (!$audioUrl) {
        return ['ok' => false, 'error' => 'AssemblyAI no devolvio upload_url'];
    }

    $create = curl_raw(
        'https://api.assemblyai.com/v2/transcript',
        ['Authorization: ' . $apiKey, 'Content-Type: application/json'],
        'POST',
        json_encode(['audio_url' => $audioUrl])
    );
    if (!$create['ok']) {
        return ['ok' => false, 'error' => 'AssemblyAI transcript HTTP ' . $create['code'] . ' ' . substr($create['body'], 0, 200)];
    }
    $createData = json_decode($create['body'], true);
    $transcriptId = $createData['id'] ?? null;
    if (!$transcriptId) {
        return ['ok' => false, 'error' => 'AssemblyAI no devolvio id de transcripcion'];
    }

    for ($i = 0; $i < 15; $i++) {
        usleep(1200000);
        $poll = curl_raw('https://api.assemblyai.com/v2/transcript/' . rawurlencode((string) $transcriptId), ['Authorization: ' . $apiKey], 'GET');
        if (!$poll['ok']) {
            continue;
        }
        $pollData = json_decode($poll['body'], true);
        $status = $pollData['status'] ?? '';
        if ($status === 'completed') {
            return ['ok' => true, 'transcript' => (string) ($pollData['text'] ?? '')];
        }
        if ($status === 'error') {
            return ['ok' => false, 'error' => 'AssemblyAI error: ' . (string) ($pollData['error'] ?? 'desconocido')];
        }
    }
    return ['ok' => false, 'error' => 'AssemblyAI no termino a tiempo (timeout de prueba)'];
}

function transcribe_gladia(string $apiKey, string $filePath, string $mime, string $fileName): array {
    $ch = curl_init('https://api.gladia.io/v2/upload');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-gladia-key: ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'audio' => new CURLFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', $fileName),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error !== '' || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'Gladia upload HTTP ' . $code . ($error !== '' ? ' ' . $error : '') . ' ' . substr((string) $body, 0, 200)];
    }
    $uploadData = json_decode((string) $body, true);
    $audioUrl = $uploadData['audio_url'] ?? null;
    if (!$audioUrl) {
        return ['ok' => false, 'error' => 'Gladia no devolvio audio_url'];
    }

    $create = curl_raw(
        'https://api.gladia.io/v2/pre-recorded',
        ['x-gladia-key: ' . $apiKey, 'Content-Type: application/json'],
        'POST',
        json_encode(['audio_url' => $audioUrl])
    );
    if (!$create['ok']) {
        return ['ok' => false, 'error' => 'Gladia pre-recorded HTTP ' . $create['code'] . ' ' . substr($create['body'], 0, 200)];
    }
    $createData = json_decode($create['body'], true);
    $resultUrl = $createData['result_url'] ?? null;
    if (!$resultUrl) {
        return ['ok' => false, 'error' => 'Gladia no devolvio result_url'];
    }

    for ($i = 0; $i < 15; $i++) {
        usleep(1200000);
        $poll = curl_raw((string) $resultUrl, ['x-gladia-key: ' . $apiKey], 'GET');
        if (!$poll['ok']) {
            continue;
        }
        $pollData = json_decode($poll['body'], true);
        $status = $pollData['status'] ?? '';
        if ($status === 'done') {
            $text = $pollData['result']['transcription']['full_transcript'] ?? '';
            return ['ok' => true, 'transcript' => (string) $text];
        }
        if ($status === 'error') {
            return ['ok' => false, 'error' => 'Gladia error: ' . json_encode($pollData['error'] ?? 'desconocido')];
        }
    }
    return ['ok' => false, 'error' => 'Gladia no termino a tiempo (timeout de prueba)'];
}

function transcribe_speechmatics(string $apiKey, string $model, string $filePath, string $mime, string $fileName): array {
    $language = $model !== '' ? $model : 'es';
    $config = json_encode(['type' => 'transcription', 'transcription_config' => ['language' => $language]]);
    $ch = curl_init('https://asr.api.speechmatics.com/v2/jobs');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'config' => $config,
            'data_file' => new CURLFile($filePath, $mime !== '' ? $mime : 'application/octet-stream', $fileName),
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error !== '' || $code < 200 || $code >= 300) {
        return ['ok' => false, 'error' => 'Speechmatics jobs HTTP ' . $code . ($error !== '' ? ' ' . $error : '') . ' ' . substr((string) $body, 0, 200)];
    }
    $jobData = json_decode((string) $body, true);
    $jobId = $jobData['id'] ?? null;
    if (!$jobId) {
        return ['ok' => false, 'error' => 'Speechmatics no devolvio id de job'];
    }

    for ($i = 0; $i < 15; $i++) {
        usleep(1200000);
        $poll = curl_raw('https://asr.api.speechmatics.com/v2/jobs/' . rawurlencode((string) $jobId), ['Authorization: Bearer ' . $apiKey], 'GET');
        if (!$poll['ok']) {
            continue;
        }
        $pollData = json_decode($poll['body'], true);
        $status = $pollData['job']['status'] ?? '';
        if ($status === 'done') {
            $transcriptRes = curl_raw(
                'https://asr.api.speechmatics.com/v2/jobs/' . rawurlencode((string) $jobId) . '/transcript?format=txt',
                ['Authorization: Bearer ' . $apiKey],
                'GET'
            );
            if (!$transcriptRes['ok']) {
                return ['ok' => false, 'error' => 'Speechmatics transcript HTTP ' . $transcriptRes['code']];
            }
            return ['ok' => true, 'transcript' => trim($transcriptRes['body'])];
        }
        if ($status === 'rejected') {
            return ['ok' => false, 'error' => 'Speechmatics rechazo el job'];
        }
    }
    return ['ok' => false, 'error' => 'Speechmatics no termino a tiempo (timeout de prueba)'];
}

function transcribe_provider(string $provider, string $apiKey, string $model, string $filePath, string $mime, string $fileName): array {
    try {
        if ($provider === 'deepgram') {
            return transcribe_deepgram($apiKey, $filePath, $mime);
        }
        if ($provider === 'groq') {
            return transcribe_groq($apiKey, $model, $filePath, $mime, $fileName);
        }
        if ($provider === 'elevenlabs') {
            return transcribe_elevenlabs($apiKey, $model, $filePath, $mime, $fileName);
        }
        if ($provider === 'assemblyai') {
            return transcribe_assemblyai($apiKey, $filePath);
        }
        if ($provider === 'gladia') {
            return transcribe_gladia($apiKey, $filePath, $mime, $fileName);
        }
        if ($provider === 'speechmatics') {
            return transcribe_speechmatics($apiKey, $model, $filePath, $mime, $fileName);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Excepcion al transcribir: ' . $e->getMessage()];
    }
    return ['ok' => false, 'error' => 'Proveedor no soportado para test'];
}

function list_accounts(PDO $db): array {
    $rows = $db->query(
        'SELECT a.id, a.email, a.label, a.active, a.notes, a.created_at, a.updated_at,
                COUNT(m.id) AS api_key_count
         FROM carsa_stt_api_keys_account a
         LEFT JOIN carsa_stt_api_keys_account_map m ON m.account_id=a.id
         GROUP BY a.id, a.email, a.label, a.active, a.notes, a.created_at, a.updated_at
         ORDER BY a.email ASC, a.id ASC'
    )->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) $row['id'];
        $row['active'] = (int) $row['active'];
        $row['api_key_count'] = (int) $row['api_key_count'];
    }
    return $rows;
}

function assign_profile_account(PDO $db, string $provider, int $apiKeyId, int $accountId): void {
    $sourceType = $provider === 'deepgram' ? 'deepgram' : 'carsa';
    if ($accountId <= 0) {
        $stmt = $db->prepare(
            'DELETE FROM carsa_stt_api_keys_account_map
             WHERE source_type=:source_type AND api_key_id=:api_key_id'
        );
        $stmt->execute([':source_type' => $sourceType, ':api_key_id' => $apiKeyId]);
        return;
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM carsa_stt_api_keys_account WHERE id=:id');
    $stmt->execute([':id' => $accountId]);
    if ((int) $stmt->fetchColumn() !== 1) {
        json_response(['ok' => false, 'error' => 'La cuenta principal no existe'], 422);
    }

    $stmt = $db->prepare(
        'INSERT INTO carsa_stt_api_keys_account_map (account_id, source_type, api_key_id)
         VALUES (:account_id, :source_type, :api_key_id)
         ON DUPLICATE KEY UPDATE account_id=VALUES(account_id)'
    );
    $stmt->execute([
        ':account_id' => $accountId,
        ':source_type' => $sourceType,
        ':api_key_id' => $apiKeyId,
    ]);
}

function list_profiles(PDO $db): array {
    $profiles = [];

    $rows = $db->query(
        'SELECT id, name, api_key, active, usage_count, generated_chars, last_used_at,
                last_balance_amount, last_balance_units, last_balance_checked_at, last_balance_error
         FROM deepgram_profiles ORDER BY id'
    )->fetchAll();
    foreach ($rows as $row) {
        $error = (string) ($row['last_balance_error'] ?? '');
        $amount = $row['last_balance_amount'];
        $detail = $amount !== null
            ? rtrim(rtrim(number_format((float) $amount, 8, '.', ''), '0'), '.') . ' ' . ($row['last_balance_units'] ?: 'USD')
            : '';
        $profiles[] = [
            'provider' => 'deepgram',
            'provider_label' => STT_PROVIDERS['deepgram']['label'],
            'id' => (int) $row['id'],
            'label' => (string) $row['name'],
            'api_key_masked' => mask_key((string) $row['api_key']),
            'model' => '',
            'active' => (int) $row['active'],
            'priority' => null,
            'notes' => '',
            'usage_count' => (int) ($row['usage_count'] ?? 0),
            'generated_chars' => (int) ($row['generated_chars'] ?? 0),
            'last_used_at' => $row['last_used_at'],
            'last_checked_at' => $row['last_balance_checked_at'],
            'last_status' => $error !== '' ? 'error' : ($amount !== null ? 'ok' : ''),
            'status_detail' => $error !== '' ? $error : $detail,
        ];
    }

    $stmt = $db->prepare(
        'SELECT id, provider, label, api_key, model, active, priority, rpm_limit, notes,
                last_used_at, last_status, last_error
         FROM carsa_stt_api_keys
         WHERE provider IN (\'assemblyai\', \'elevenlabs\', \'gladia\', \'groq\', \'speechmatics\')
         ORDER BY provider, priority, id'
    );
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $provider = (string) $row['provider'];
        $error = (string) ($row['last_error'] ?? '');
        $status = (string) ($row['last_status'] ?? '');
        $profiles[] = [
            'provider' => $provider,
            'provider_label' => STT_PROVIDERS[$provider]['label'],
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'api_key_masked' => mask_key((string) $row['api_key']),
            'model' => (string) ($row['model'] ?? ''),
            'active' => (int) $row['active'],
            'priority' => (int) ($row['priority'] ?? 100),
            'notes' => (string) ($row['notes'] ?? ''),
            'usage_count' => null,
            'generated_chars' => null,
            'last_used_at' => $row['last_used_at'],
            'last_checked_at' => $row['last_used_at'],
            'last_status' => $error !== '' ? 'error' : $status,
            'status_detail' => $error !== '' ? $error : $status,
        ];
    }

    $accountMap = [];
    $accountRows = $db->query(
        'SELECT m.source_type, m.api_key_id, a.id AS account_id, a.email AS account_email,
                a.label AS account_label, a.active AS account_active
         FROM carsa_stt_api_keys_account_map m
         INNER JOIN carsa_stt_api_keys_account a ON a.id=m.account_id'
    )->fetchAll();
    foreach ($accountRows as $row) {
        $accountMap[$row['source_type'] . ':' . $row['api_key_id']] = $row;
    }
    foreach ($profiles as &$profile) {
        $sourceType = $profile['provider'] === 'deepgram' ? 'deepgram' : 'carsa';
        $account = $accountMap[$sourceType . ':' . $profile['id']] ?? null;
        $profile['account_id'] = $account ? (int) $account['account_id'] : null;
        $profile['account_email'] = $account ? (string) $account['account_email'] : '';
        $profile['account_label'] = $account ? (string) $account['account_label'] : '';
        $profile['account_active'] = $account ? (int) $account['account_active'] : null;
    }
    unset($profile);

    usort($profiles, static function (array $a, array $b): int {
        $providerOrder = strcmp($a['provider_label'], $b['provider_label']);
        if ($providerOrder !== 0) {
            return $providerOrder;
        }
        $priorityA = $a['priority'] ?? 0;
        $priorityB = $b['priority'] ?? 0;
        if ($priorityA !== $priorityB) {
            return $priorityA <=> $priorityB;
        }
        return $a['id'] <=> $b['id'];
    });

    return $profiles;
}

$action = (string) ($_GET['action'] ?? ($_POST['action'] ?? ''));
if ($action !== '') {
    $db = carsa_db();

    try {
        if ($action === 'list') {
            json_response([
                'ok' => true,
                'profiles' => list_profiles($db),
                'accounts' => list_accounts($db),
                'providers' => STT_PROVIDERS,
            ]);
        }

        require_csrf($csrf);

        if ($action === 'account_save') {
            $accountId = (int) ($_POST['id'] ?? 0);
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));
            $label = trim((string) ($_POST['label'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                json_response(['ok' => false, 'error' => 'Correo no valido'], 422);
            }
            if ($label === '') {
                $label = $email;
            }
            $stmt = $db->prepare(
                'SELECT id FROM carsa_stt_api_keys_account WHERE email=:email AND id<>:id LIMIT 1'
            );
            $stmt->execute([':email' => $email, ':id' => $accountId]);
            if ($stmt->fetchColumn()) {
                json_response(['ok' => false, 'error' => 'Ese correo ya esta registrado'], 409);
            }
            if ($accountId > 0) {
                $stmt = $db->prepare(
                    'UPDATE carsa_stt_api_keys_account
                     SET email=:email, label=:label, notes=:notes WHERE id=:id'
                );
                $stmt->execute([
                    ':email' => $email, ':label' => $label, ':notes' => $notes, ':id' => $accountId,
                ]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO carsa_stt_api_keys_account (email, label, notes)
                     VALUES (:email, :label, :notes)'
                );
                $stmt->execute([':email' => $email, ':label' => $label, ':notes' => $notes]);
                $accountId = (int) $db->lastInsertId();
            }
            json_response(['ok' => true, 'id' => $accountId]);
        }

        if ($action === 'account_toggle') {
            $accountId = (int) ($_POST['id'] ?? 0);
            if ($accountId <= 0) {
                json_response(['ok' => false, 'error' => 'Cuenta no valida'], 422);
            }
            $stmt = $db->prepare(
                'UPDATE carsa_stt_api_keys_account SET active=1-active WHERE id=:id'
            );
            $stmt->execute([':id' => $accountId]);
            json_response(['ok' => true]);
        }

        if ($action === 'account_delete') {
            $accountId = (int) ($_POST['id'] ?? 0);
            if ($accountId <= 0) {
                json_response(['ok' => false, 'error' => 'Cuenta no valida'], 422);
            }
            $stmt = $db->prepare('DELETE FROM carsa_stt_api_keys_account WHERE id=:id');
            $stmt->execute([':id' => $accountId]);
            json_response(['ok' => true]);
        }

        if ($action === 'account_assign') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $provider = (string) ($_POST['provider'] ?? '');
            $id = (int) ($_POST['id'] ?? 0);
            provider_config($provider);
            if ($accountId <= 0 || $id <= 0) {
                json_response(['ok' => false, 'error' => 'Cuenta o lead no valido'], 422);
            }
            if ($provider === 'deepgram') {
                $stmt = $db->prepare('SELECT COUNT(*) FROM deepgram_profiles WHERE id=:id');
                $stmt->execute([':id' => $id]);
            } else {
                $stmt = $db->prepare(
                    'SELECT COUNT(*) FROM carsa_stt_api_keys WHERE id=:id AND provider=:provider'
                );
                $stmt->execute([':id' => $id, ':provider' => $provider]);
            }
            if ((int) $stmt->fetchColumn() !== 1) {
                json_response(['ok' => false, 'error' => 'El lead seleccionado no existe'], 404);
            }
            assign_profile_account($db, $provider, $id, $accountId);
            json_response(['ok' => true]);
        }

        if ($action === 'account_unassign') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $provider = (string) ($_POST['provider'] ?? '');
            $id = (int) ($_POST['id'] ?? 0);
            provider_config($provider);
            if ($accountId <= 0 || $id <= 0) {
                json_response(['ok' => false, 'error' => 'Cuenta o lead no valido'], 422);
            }
            $stmt = $db->prepare(
                'DELETE FROM carsa_stt_api_keys_account_map
                 WHERE account_id=:account_id AND source_type=:source_type AND api_key_id=:api_key_id'
            );
            $stmt->execute([
                ':account_id' => $accountId,
                ':source_type' => $provider === 'deepgram' ? 'deepgram' : 'carsa',
                ':api_key_id' => $id,
            ]);
            json_response(['ok' => true]);
        }

        $provider = (string) ($_POST['provider'] ?? '');
        $config = provider_config($provider);
        $id = (int) ($_POST['id'] ?? 0);

        if ($action === 'save') {
            $label = trim((string) ($_POST['label'] ?? ''));
            $apiKey = trim((string) ($_POST['api_key'] ?? ''));
            $model = trim((string) ($_POST['model'] ?? ''));
            $priority = (int) ($_POST['priority'] ?? 100);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $accountId = (int) ($_POST['account_id'] ?? 0);

            if ($label === '') {
                json_response(['ok' => false, 'error' => 'Falta el nombre o etiqueta'], 422);
            }
            if ($id <= 0 && $apiKey === '') {
                json_response(['ok' => false, 'error' => 'Falta la API key'], 422);
            }
            if ($accountId > 0) {
                $stmt = $db->prepare('SELECT COUNT(*) FROM carsa_stt_api_keys_account WHERE id=:id');
                $stmt->execute([':id' => $accountId]);
                if ((int) $stmt->fetchColumn() !== 1) {
                    json_response(['ok' => false, 'error' => 'La cuenta principal no existe'], 422);
                }
            }

            if ($provider === 'deepgram') {
                if ($id > 0) {
                    if ($apiKey !== '') {
                        $stmt = $db->prepare('UPDATE deepgram_profiles SET name=:name, api_key=:api_key WHERE id=:id');
                        $stmt->execute([':name' => $label, ':api_key' => $apiKey, ':id' => $id]);
                    } else {
                        $stmt = $db->prepare('UPDATE deepgram_profiles SET name=:name WHERE id=:id');
                        $stmt->execute([':name' => $label, ':id' => $id]);
                    }
                } else {
                    $stmt = $db->prepare('INSERT INTO deepgram_profiles (name, api_key, active) VALUES (:name, :api_key, 1)');
                    $stmt->execute([':name' => $label, ':api_key' => $apiKey]);
                    $id = (int) $db->lastInsertId();
                }
            } else {
                if ($model === '') {
                    $model = (string) $config['default_model'];
                }
                if ($id > 0) {
                    if ($apiKey !== '') {
                        $stmt = $db->prepare(
                            'UPDATE carsa_stt_api_keys
                             SET label=:label, api_key=:api_key, model=:model, priority=:priority, notes=:notes
                             WHERE id=:id AND provider=:provider'
                        );
                        $stmt->execute([
                            ':label' => $label, ':api_key' => $apiKey, ':model' => $model,
                            ':priority' => $priority, ':notes' => $notes, ':id' => $id, ':provider' => $provider,
                        ]);
                    } else {
                        $stmt = $db->prepare(
                            'UPDATE carsa_stt_api_keys
                             SET label=:label, model=:model, priority=:priority, notes=:notes
                             WHERE id=:id AND provider=:provider'
                        );
                        $stmt->execute([
                            ':label' => $label, ':model' => $model, ':priority' => $priority,
                            ':notes' => $notes, ':id' => $id, ':provider' => $provider,
                        ]);
                    }
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO carsa_stt_api_keys
                            (provider, label, api_key, model, active, priority, notes)
                         VALUES (:provider, :label, :api_key, :model, 1, :priority, :notes)'
                    );
                    $stmt->execute([
                        ':provider' => $provider, ':label' => $label, ':api_key' => $apiKey,
                        ':model' => $model, ':priority' => $priority, ':notes' => $notes,
                    ]);
                    $id = (int) $db->lastInsertId();
                }
            }
            assign_profile_account($db, $provider, $id, $accountId);
            json_response(['ok' => true, 'id' => $id]);
        }

        if ($id <= 0) {
            json_response(['ok' => false, 'error' => 'Perfil no valido'], 422);
        }

        if ($action === 'toggle') {
            if ($provider === 'deepgram') {
                $stmt = $db->prepare('UPDATE deepgram_profiles SET active=1-active WHERE id=:id');
                $stmt->execute([':id' => $id]);
            } else {
                $stmt = $db->prepare('UPDATE carsa_stt_api_keys SET active=1-active WHERE id=:id AND provider=:provider');
                $stmt->execute([':id' => $id, ':provider' => $provider]);
            }
            json_response(['ok' => true]);
        }

        if ($action === 'delete') {
            $sourceType = $provider === 'deepgram' ? 'deepgram' : 'carsa';
            if ($provider === 'deepgram') {
                $stmt = $db->prepare('DELETE FROM deepgram_profiles WHERE id=:id');
                $stmt->execute([':id' => $id]);
            } else {
                $stmt = $db->prepare('DELETE FROM carsa_stt_api_keys WHERE id=:id AND provider=:provider');
                $stmt->execute([':id' => $id, ':provider' => $provider]);
            }
            $stmt = $db->prepare(
                'DELETE FROM carsa_stt_api_keys_account_map
                 WHERE source_type=:source_type AND api_key_id=:api_key_id'
            );
            $stmt->execute([':source_type' => $sourceType, ':api_key_id' => $id]);
            json_response(['ok' => true]);
        }

        if ($action === 'refresh') {
            if ($provider === 'deepgram') {
                $stmt = $db->prepare('SELECT api_key FROM deepgram_profiles WHERE id=:id');
                $stmt->execute([':id' => $id]);
            } else {
                $stmt = $db->prepare('SELECT api_key FROM carsa_stt_api_keys WHERE id=:id AND provider=:provider');
                $stmt->execute([':id' => $id, ':provider' => $provider]);
            }
            $apiKey = $stmt->fetchColumn();
            if (!$apiKey) {
                json_response(['ok' => false, 'error' => 'Perfil inexistente'], 404);
            }

            $check = check_provider($provider, (string) $apiKey);
            if ($provider === 'deepgram') {
                if ($check['ok']) {
                    $stmt = $db->prepare(
                        'UPDATE deepgram_profiles
                         SET last_balance_amount=:amount, last_balance_units=:units,
                             last_balance_checked_at=NOW(), last_balance_error=NULL
                         WHERE id=:id'
                    );
                    $stmt->execute([
                        ':amount' => $check['amount'], ':units' => $check['units'], ':id' => $id,
                    ]);
                } else {
                    $stmt = $db->prepare(
                        'UPDATE deepgram_profiles
                         SET last_balance_checked_at=NOW(), last_balance_error=:error
                         WHERE id=:id'
                    );
                    $stmt->execute([':error' => $check['error'] ?? 'Error desconocido', ':id' => $id]);
                }
            } else {
                $status = $check['ok']
                    ? 'ok: ' . (string) ($check['detail'] ?? 'Credencial valida')
                    : 'error';
                $stmt = $db->prepare(
                    'UPDATE carsa_stt_api_keys
                     SET last_status=:status, last_error=:error, last_used_at=NOW()
                     WHERE id=:id AND provider=:provider'
                );
                $stmt->execute([
                    ':status' => $status,
                    ':error' => $check['ok'] ? null : ($check['error'] ?? 'Error desconocido'),
                    ':id' => $id,
                    ':provider' => $provider,
                ]);
            }
            json_response($check);
        }

        if ($action === 'test_audio') {
            if (!isset($_FILES['audio']) || !is_array($_FILES['audio'])) {
                json_response(['ok' => false, 'error' => 'Falta el archivo de audio'], 422);
            }
            $file = $_FILES['audio'];
            if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                json_response(['ok' => false, 'error' => 'Error al subir el archivo (codigo ' . $file['error'] . ')'], 422);
            }
            $maxBytes = 2 * 1024 * 1024;
            if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxBytes) {
                json_response(['ok' => false, 'error' => 'El audio debe pesar entre 1 byte y 2 MB'], 422);
            }
            $allowedExt = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'webm', 'flac', 'mp4'];
            $originalName = (string) ($file['name'] ?? 'audio');
            $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                json_response(['ok' => false, 'error' => 'Formato no soportado: ' . $ext], 422);
            }
            $mime = detect_audio_mime($file['tmp_name'], $ext);
            if (strpos($mime, 'audio') !== 0 && strpos($mime, 'video/mp4') !== 0 && $mime !== 'application/octet-stream') {
                json_response(['ok' => false, 'error' => 'El archivo no parece ser audio (' . $mime . ')'], 422);
            }

            if ($provider === 'deepgram') {
                $stmt = $db->prepare('SELECT api_key FROM deepgram_profiles WHERE id=:id');
                $stmt->execute([':id' => $id]);
                $row = $stmt->fetch();
                $model = '';
            } else {
                $stmt = $db->prepare('SELECT api_key, model FROM carsa_stt_api_keys WHERE id=:id AND provider=:provider');
                $stmt->execute([':id' => $id, ':provider' => $provider]);
                $row = $stmt->fetch();
                $model = (string) ($row['model'] ?? '');
            }
            if (!$row || empty($row['api_key'])) {
                json_response(['ok' => false, 'error' => 'Perfil inexistente'], 404);
            }

            $result = transcribe_provider($provider, (string) $row['api_key'], $model, $file['tmp_name'], $mime, $originalName);
            json_response($result);
        }

        json_response(['ok' => false, 'error' => 'Accion invalida'], 404);
    } catch (Throwable $e) {
        error_log('stt_providers_admin: ' . $e->getMessage());
        json_response(['ok' => false, 'error' => 'Error interno al procesar la solicitud'], 500);
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<title>Administrar proveedores STT</title>
<link rel="stylesheet" href="assets/stt_providers_admin.css">
<style>
  :root{--bg:#0f1115;--card:#1a1d24;--card2:#11141a;--border:#2a2e37;--ink:#e6e6e6;
    --muted:#94a3b8;--accent:#3998d5;--ok:#4caf7d;--err:#d45c5c;--warn:#e5a93d}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,sans-serif;padding:24px}
  .top{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:18px}
  h1{font-size:1.25rem;margin:0 0 4px}.sub{color:var(--muted);font-size:.8rem}
  button{border:0;border-radius:7px;background:var(--accent);color:#fff;padding:8px 12px;cursor:pointer;font-size:.78rem}
  button:hover{opacity:.86}button:disabled{opacity:.45;cursor:not-allowed}button.sec{background:var(--border);color:var(--ink)}
  button.danger{background:var(--err)}button.small{padding:5px 8px;margin:2px;font-size:.72rem}
  .summary{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.summary span,.pill{border-radius:999px;padding:4px 10px;font-size:.72rem;font-weight:700}
  .summary span{background:var(--card);border:1px solid var(--border)}
  .table-wrap{overflow:auto;border:1px solid var(--border);border-radius:11px;background:var(--card)}
  table{width:100%;border-collapse:collapse;min-width:1050px}th,td{padding:10px 11px;text-align:left;border-bottom:1px solid var(--border);font-size:.8rem;vertical-align:middle}
  th{position:sticky;top:0;background:#171a20;color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:.4px}
  tr:last-child td{border-bottom:0}.provider{font-weight:750}.key{font-family:ui-monospace,monospace;color:#cbd5e1}
  .pill{display:inline-block}.pill.on,.health.ok{background:rgba(76,175,125,.14);color:var(--ok)}
  .pill.off{background:rgba(148,163,184,.13);color:var(--muted)}.health.err{color:var(--err)}.health.none{color:var(--muted)}
  .health{font-size:.75rem;max-width:240px;overflow-wrap:anywhere}.date{color:var(--muted);white-space:nowrap;font-size:.72rem}
  .form-card{background:var(--card);border:1px solid var(--border);border-radius:11px;padding:16px;margin-top:18px}
  .form-title{font-weight:750;margin-bottom:12px}.fields{display:grid;grid-template-columns:repeat(6,minmax(130px,1fr));gap:10px}
  .account-fields{display:grid;grid-template-columns:1.2fr 1fr 1.4fr auto;gap:10px;align-items:end}
  .account-list{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
  .account-card{background:var(--card2);border:1px solid var(--border);border-radius:9px;padding:9px 11px;min-width:230px}
  .account-card.off{opacity:.6}.account-email{font-size:.78rem;font-weight:700}.account-meta{font-size:.7rem;color:var(--muted);margin:3px 0 7px}
  .modal{position:fixed;inset:0;z-index:50;background:rgba(0,0,0,.72);display:flex;align-items:center;justify-content:center;padding:18px}
  .modal[hidden]{display:none}.modal-card{width:min(920px,100%);max-height:90vh;overflow:auto;background:var(--card);border:1px solid var(--border);border-radius:12px;padding:17px;box-shadow:0 18px 55px rgba(0,0,0,.5)}
  .modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.modal-head h2{font-size:1rem;margin:0 0 4px}
  .assign-fields{display:grid;grid-template-columns:1fr 1.5fr;gap:10px}.lead-preview{margin-top:12px;padding:12px;border:1px solid var(--border);border-radius:9px;background:var(--card2);font-size:.78rem;line-height:1.55}
  .assigned-list{margin-top:16px}.assigned-row{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;align-items:center;padding:9px;border-top:1px solid var(--border);font-size:.77rem}
  .filter-bar{display:flex;align-items:center;gap:8px;margin:14px 0 10px}.filter-bar label{margin:0}.filter-bar select{width:auto;min-width:260px}
  label{display:block;color:var(--muted);font-size:.68rem;text-transform:uppercase;margin-bottom:4px}
  input,select{width:100%;border:1px solid var(--border);border-radius:7px;background:var(--card2);color:var(--ink);padding:8px 9px;font-size:.82rem}
  .form-actions{display:flex;align-items:center;gap:8px;margin-top:13px}.msg{font-size:.78rem;min-height:1em}.msg.ok{color:var(--ok)}.msg.err{color:var(--err)}
  @media(max-width:1000px){.fields{grid-template-columns:repeat(2,minmax(150px,1fr))}.account-fields{grid-template-columns:repeat(2,minmax(180px,1fr))}}
  @media(max-width:620px){body{padding:14px}.top{display:block}.top button{margin-top:12px}.fields,.account-fields,.assign-fields{grid-template-columns:1fr}.assigned-row{grid-template-columns:1fr}.filter-bar{display:block}.filter-bar select{width:100%;margin-top:5px}}
</style>
</head>
<body class="stt-native">
  <div class="top">
    <div>
      <h1>Proveedores STT</h1>
      <div class="sub">Administración unificada de cuentas, credenciales y estado.</div>
    </div>
    <button id="refresh-all" type="button">Actualizar estados</button>
  </div>

  <div class="form-card form-card--first">
    <div class="form-title" id="account-form-title">Cuentas principales</div>
    <input type="hidden" id="account-edit-id" value="0">
    <div class="account-fields">
      <div><label for="account-email">Correo</label><input id="account-email" type="email" placeholder="cuenta@gmail.com"></div>
      <div><label for="account-label">Nombre / alias</label><input id="account-label" type="text" placeholder="opcional"></div>
      <div><label for="account-notes">Notas</label><input id="account-notes" type="text" placeholder="sin contraseñas"></div>
      <div>
        <button type="button" id="save-account-button">Guardar cuenta</button>
        <button type="button" class="sec" id="cancel-account-edit" hidden>Cancelar</button>
      </div>
    </div>
    <div id="account-msg" class="msg"></div>
    <div id="account-list" class="account-list"></div>
  </div>

  <div class="summary" id="summary"></div>
  <div class="filter-bar">
    <label for="account-filter">Filtrar por cuenta</label>
    <select id="account-filter"><option value="all">Todas las cuentas</option></select>
  </div>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Proveedor</th><th>Cuenta principal</th><th>Perfil</th><th>API key</th><th>Modelo</th><th>Estado</th>
          <th>Comprobación</th><th>Última actividad</th><th>Prioridad</th><th>Acciones</th>
        </tr>
      </thead>
      <tbody id="profiles-body"><tr><td colspan="10" class="muted-cell">Cargando…</td></tr></tbody>
    </table>
  </div>

  <div class="form-card">
    <div class="form-title" id="form-title">Nueva API Key</div>
    <input type="hidden" id="edit-id" value="0">
    <div class="fields">
      <div><label for="f-account">Cuenta principal</label><select id="f-account"><option value="0">Sin asignar</option></select></div>
      <div><label for="f-provider">Proveedor</label><select id="f-provider"></select></div>
      <div><label for="f-label">Nombre / etiqueta</label><input id="f-label" type="text" placeholder="ej. cuenta-01"></div>
      <div><label for="f-key">API key</label><input id="f-key" type="password" autocomplete="new-password"></div>
      <div id="model-field"><label for="f-model">Modelo / idioma</label><input id="f-model" type="text"></div>
      <div id="priority-field"><label for="f-priority">Prioridad</label><input id="f-priority" type="number" value="100"></div>
      <div id="notes-field"><label for="f-notes">Notas</label><input id="f-notes" type="text" placeholder="opcional"></div>
    </div>
    <div class="form-actions">
      <button type="button" id="save-profile-button">Guardar</button>
      <button type="button" class="sec" id="cancel-edit" hidden>Cancelar</button>
      <span id="msg" class="msg"></span>
    </div>
  </div>

  <div id="account-manager" class="modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="manager-title">
      <div class="modal-head">
        <div><h2 id="manager-title">Proveedores de la cuenta</h2><div id="manager-account" class="sub"></div></div>
        <button type="button" class="sec" id="close-account-manager">Cerrar</button>
      </div>
      <div class="assign-fields">
        <div><label for="manager-provider">Proveedor</label><select id="manager-provider"></select></div>
        <div><label for="manager-lead">Lead / API key existente</label><select id="manager-lead"></select></div>
      </div>
      <div id="lead-preview" class="lead-preview"></div>
      <div class="form-actions">
        <button type="button" id="assign-lead-button">Asignar este lead</button>
        <span id="manager-msg" class="msg"></span>
      </div>
      <div class="assigned-list">
        <div class="form-title">Leads asignados a esta cuenta</div>
        <div id="assigned-leads"></div>
      </div>
    </div>
  </div>

  <div id="test-modal" class="modal" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="test-title">
      <div class="modal-head">
        <div><h2 id="test-title">Probar STT</h2><div id="test-sub" class="sub"></div></div>
        <button type="button" class="sec" id="close-test-modal">Cerrar</button>
      </div>
      <div>
        <label for="test-audio-file">Audio corto (mp3, wav, ogg, m4a... maximo 2 MB)</label>
        <input type="file" id="test-audio-file" accept="audio/*,.m4a,.oga">
      </div>
      <div class="form-actions">
        <button type="button" id="test-send-button">Enviar y transcribir</button>
        <span id="test-msg" class="msg"></span>
      </div>
      <div id="test-result" class="lead-preview" hidden></div>
    </div>
  </div>

<script type="application/json" id="stt-providers-config"><?php echo json_encode(STT_PROVIDERS, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?></script>
<script src="assets/stt_providers_admin.js" defer></script>
</body>
</html>
