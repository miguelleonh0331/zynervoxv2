<?php
/**
 * generate_qwen_wav.php — IVR Builder (Zynervox)
 * Integra Alibaba Cloud Qwen3-TTS como proveedor de voz.
 * Misma interfaz que generate_audio.php para compatibilidad total.
 *
 * POST { text, model, voice, language_type, instructions, optimize_instructions }
 * -> { ok, provider, hash, audio, preview, cached, duration_ms, bytes }
 * -> { ok:false, error }
 */
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
ivr_builder_require_login(true);
header('Content-Type: application/json; charset=utf-8');

/* ---- helpers ---- */
function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function qwen_log(string $level, array $data): void {
    // Ruta relativa a este proyecto (antes apuntaba a una ruta fija de
    // mirmidon). Se crea sola si no existe.
    $logFile = __DIR__ . '/qwen_tts.log';
    $line    = date('Y-m-d H:i:s') . " [$level] " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/* ---- servir WAV cacheado directamente (GET ?serve=hash) ---- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['serve'])) {
    $h = (string) $_GET['serve'];
    if (!preg_match('/^[a-f0-9]{64}$/', $h)) { http_response_code(400); exit; }
    $f = '/var/lib/asterisk/sounds/voicebot/cache/ivr_builder/qwen/' . $h . '.wav';
    if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($f));
    header('Cache-Control: no-store');
    readfile($f);
    exit;
}

/* ---- validar método ---- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Metodo invalido', 405);

/* ---- leer payload ---- */
$data   = json_decode((string) file_get_contents('php://input'), true);
$text   = trim((string) ($data['text']   ?? ''));
$model  = trim((string) ($data['model']  ?? 'qwen3-tts-flash'));
$voice  = trim((string) ($data['voice']  ?? 'Cherry'));
$lang   = trim((string) ($data['language_type'] ?? 'Spanish'));
$instr  = trim((string) ($data['instructions']  ?? ''));
$optInstr = !empty($data['optimize_instructions']);

/* ---- validaciones ---- */
$allowedModels = ['qwen3-tts-flash', 'qwen3-tts-instruct-flash'];
$allowedVoices = ['Cherry','Ethan','Chelsie','Momo','Vivian','Moon','Maia',
                  'Kai','Bella','Jennifer','Ryan','Katerina','Bellona','Vincent',
                  'Neil','Elias'];

if ($text === '' || mb_strlen($text, 'UTF-8') > 2000)
    fail('Texto requerido, maximo 2000 caracteres');
if (!in_array($model, $allowedModels, true))
    fail('Modelo invalido: ' . $model);
if (!in_array($voice, $allowedVoices, true))
    fail('Voz invalida: ' . $voice);
if ($model === 'qwen3-tts-instruct-flash' && $instr === '')
    $instr = 'voz natural, clara y amigable'; // default para instruct

/* ---- API key desde archivo de configuración (misma carpeta) ---- */
$keyFile = __DIR__ . '/qwen_key.txt';
if (!is_file($keyFile)) fail('Clave API Qwen no configurada en servidor', 500);
$apiKey  = trim((string) file_get_contents($keyFile));
if ($apiKey === '') fail('Clave API Qwen vacia', 500);

/* ---- caché ---- */
$cacheKey  = hash('sha256', "qwen|$model|$voice|$lang|$instr|$optInstr|" . mb_strtolower($text, 'UTF-8'));
$cacheDir  = '/var/lib/asterisk/sounds/voicebot/cache/ivr_builder/qwen';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0777, true) && !is_dir($cacheDir))
    fail('No se pudo crear directorio de cache', 500);

$wavFile      = "$cacheDir/$cacheKey.wav";
$asteriskPath = "voicebot/cache/ivr_builder/qwen/$cacheKey";

/* ---- cache hit ---- */
if (is_file($wavFile) && filesize($wavFile) > 500) {
    qwen_log('INFO', ['event'=>'cache_hit','hash'=>$cacheKey,'voice'=>$voice,'model'=>$model]);
    echo json_encode([
        'ok' => true, 'provider' => 'qwen', 'label' => "Qwen TTS ($voice)",
        'hash'  => $cacheKey, 'audio' => $asteriskPath,
        'preview'     => 'generate_qwen_wav.php?serve=' . rawurlencode($cacheKey) . '&v=' . time(),
        'cached'      => true,
        'duration_ms' => 0,
        'bytes'       => filesize($wavFile),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- armar payload Qwen ---- */
$inputPayload = [
    'text'          => $text,
    'voice'         => $voice,
    'language_type' => $lang,
];
if ($model === 'qwen3-tts-instruct-flash') {
    $inputPayload['instructions']          = $instr;
    $inputPayload['optimize_instructions'] = $optInstr;
}

$payload = json_encode([
    'model' => $model,
    'input' => $inputPayload,
], JSON_UNESCAPED_UNICODE);

/* ---- llamada a la API Qwen ---- */
$start   = microtime(true);
$ch      = curl_init('https://dashscope-intl.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
    ],
]);
$response = (string) curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);
$apiMs = (int) ((microtime(true) - $start) * 1000);

/* ---- validar respuesta API ---- */
if ($curlErr !== '') {
    qwen_log('ERROR', ['event'=>'curl_error','error'=>$curlErr,'text'=>mb_substr($text,0,80,'UTF-8')]);
    fail('Error de red al conectar con Qwen API: ' . $curlErr, 502);
}
if ($httpCode !== 200) {
    $detail = json_decode($response, true)['message'] ?? $response;
    qwen_log('ERROR', ['event'=>'api_error','http'=>$httpCode,'detail'=>substr($detail,0,200),'model'=>$model,'voice'=>$voice]);
    fail("Qwen API error HTTP $httpCode: " . substr($detail, 0, 200), 502);
}

$result   = json_decode($response, true);
$audioUrl = $result['output']['audio']['url'] ?? '';
if ($audioUrl === '') {
    qwen_log('ERROR', ['event'=>'no_audio_url','response'=>substr($response,0,300)]);
    fail('Qwen no devolvio URL de audio en la respuesta', 502);
}

/* ---- descargar WAV desde URL (expira pronto) ---- */
$tmpWav = sys_get_temp_dir() . "/qwen_$cacheKey.wav";
$chDl   = curl_init($audioUrl);
curl_setopt_array($chDl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_FOLLOWLOCATION => true,
]);
$wavData = curl_exec($chDl);
$dlCode  = (int) curl_getinfo($chDl, CURLINFO_HTTP_CODE);
curl_close($chDl);

if ($wavData === false || strlen($wavData) < 100) {
    qwen_log('ERROR', ['event'=>'download_error','http'=>$dlCode,'url'=>$audioUrl]);
    fail('No se pudo descargar el audio desde Qwen (URL expirada o error de red)', 502);
}

/* ---- convertir a WAV 8000Hz mono 16bit con ffmpeg ---- */
file_put_contents($tmpWav, $wavData);
$ffmpeg = '/usr/bin/ffmpeg';
if (!is_executable($ffmpeg)) {
    $found = trim((string) shell_exec('which ffmpeg 2>/dev/null'));
    if ($found && is_executable($found)) $ffmpeg = $found;
    else fail('ffmpeg no disponible en el servidor', 500);
}

$tmpOut = sys_get_temp_dir() . "/qwen_conv_$cacheKey.wav";
$cmd    = sprintf('%s -y -i %s -ar 8000 -ac 1 -sample_fmt s16 %s 2>&1',
    escapeshellarg($ffmpeg), escapeshellarg($tmpWav), escapeshellarg($tmpOut));
$ffOut  = (string) shell_exec($cmd);

@unlink($tmpWav);

if (!is_file($tmpOut) || filesize($tmpOut) < 100) {
    $detail = implode(' | ', array_slice(explode("\n", trim($ffOut)), -3));
    qwen_log('ERROR', ['event'=>'ffmpeg_error','detail'=>$detail]);
    fail("ffmpeg fallo al convertir audio: $detail", 500);
}

/* ---- mover al caché definitivo ---- */
if (!rename($tmpOut, $wavFile)) {
    copy($tmpOut, $wavFile);
    @unlink($tmpOut);
}
chmod($wavFile, 0666);

$totalMs = (int) ((microtime(true) - $start) * 1000);
$bytes   = (int) filesize($wavFile);

qwen_log('INFO', [
    'event'   => 'generated',
    'hash'    => $cacheKey,
    'model'   => $model,
    'voice'   => $voice,
    'lang'    => $lang,
    'instr'   => mb_substr($instr, 0, 80, 'UTF-8'),
    'text'    => mb_substr($text, 0, 80, 'UTF-8'),
    'api_ms'  => $apiMs,
    'total_ms'=> $totalMs,
    'bytes'   => $bytes,
]);

echo json_encode([
    'ok'          => true,
    'provider'    => 'qwen',
    'label'       => "Qwen TTS ($voice)",
    'hash'        => $cacheKey,
    'audio'       => $asteriskPath,
    'preview'     => 'generate_qwen_wav.php?serve=' . rawurlencode($cacheKey) . '&v=' . time(),
    'cached'      => false,
    'duration_ms' => $totalMs,
    'bytes'       => $bytes,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
