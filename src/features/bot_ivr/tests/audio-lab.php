<?php
declare(strict_types=1);
require $argv[1].'/audio_lab_service.php';
function lab_verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$_SESSION = [];
foreach (['', str_repeat('a',1001), "abc\0def", "\xff"] as $bad) {
    try { bot_audio_lab_validate($bad,'gtts'); throw new LogicException('Invalid text accepted'); }
    catch (RuntimeException $e) {}
}
try { bot_audio_lab_validate('Hola','https://example.invalid'); throw new LogicException('Unknown provider accepted'); }
catch (RuntimeException $e) {}
foreach (['../secret', str_repeat('a',32)] as $id) {
    try { bot_audio_lab_file($id); throw new LogicException('Foreign audio accepted'); }
    catch (RuntimeException $e) {}
}
$id = bot_audio_lab_generate('Hola, esta es una prueba de audio de Zynervox. Muchas gracias.', 'gtts');
$path = bot_audio_lab_file($id);
try {
    $bytes = file_get_contents($path);
    lab_verify(substr($bytes,0,4)==='RIFF' && substr($bytes,8,4)==='WAVE', 'Generated WAV');
    $history = $_SESSION['bot_audio_lab'];
    $_SESSION['bot_audio_lab'] = [];
    try { bot_audio_lab_file($id); throw new LogicException('Other session can access audio'); }
    catch (RuntimeException $e) {}
    $_SESSION['bot_audio_lab'] = $history;
    lab_verify(is_readable(bot_audio_lab_file($id)), 'Session playback');
    echo "PASS: text/provider validation, owned playback, isolation and real gTTS generation\n";
} finally { unlink($path); }
