<?php
// Explicit live provider check with synthetic speech; no phones, CRM or SMS.
require $argv[1].'/config/Config.php';
require $argv[1].'/bot_ivr/ivr_engine_service.php';
require $argv[1].'/bot_ivr/ivr_graph_io.php';
$runtime=rtrim((string)\Config\Config::deployment('runtime'),'/');
$dir=$runtime.'/bot_ivr/ivr_calls/provider-fixture-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
try {
    $source=$runtime.'/sounds/cache/ivr_builder/gtts/'.bot_ivr_audio_hash('hola como estas Prueba').'.wav';
    if (!is_file($source)) throw new RuntimeException('Generate synthetic provider sample before testing');
    copy($source,$dir.'/sample.wav');
    foreach (['amd','menu'] as $role) {
        $out=bot_ivr_graph_io('transcribe',['path'=>$dir.'/sample.wav','provider'=>$role]);
        if (empty($out['ok']) || !bot_list_audio_render('{sample}',['sample'=>$out['text'] ?? ''])) throw new RuntimeException('Live STT failed: '.$role.' '.($out['error'] ?? ''));
        echo 'PASS: live '.($out['provider'] ?? $role).' speech transcription: '.$out['text']."\n";
    }
    $value=bot_ivr_graph_io('capture_value',['text'=>'el once de octubre de 2026','mode'=>'date']);
    if (empty($value['ok']) || ($value['value'] ?? '')!=='2026-10-11') throw new RuntimeException('Live date extraction failed');
    echo "PASS: live date extraction beyond the local parser\n";
    $audio=bot_ivr_graph_io('audio',['text'=>'hola como estas Prueba']);
    if (empty($audio['ok']) || ($audio['state'] ?? '')!=='reused') throw new RuntimeException('Published audio reuse failed');
    echo "PASS: on-demand audio adapter reuses the published hash\n";
} finally {foreach(glob($dir.'/*') as $file)unlink($file);rmdir($dir);}
