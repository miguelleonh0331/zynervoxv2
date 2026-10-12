<?php
declare(strict_types=1);

function bot_tts_endpoint(string $url): string {
    $url = trim($url);
    $parts = parse_url($url);
    if (strlen($url) > 512 || preg_match('/[^\x21-\x7e]/', $url) || !$parts
        || !in_array($parts['scheme'] ?? '', ['ws','wss'], true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
        throw new RuntimeException('Introduce una URL WebSocket válida, por ejemplo ws://172.16.10.26:8900, sin credenciales ni parámetros.');
    }
    return $url;
}

function bot_tts_profile(array $tts): array {
    if (($tts['key'] ?? 'gtts') === 'gtts') {
        return ['provider'=>'gtts','voice'=>'google-es-com','language'=>'es','speed'=>'1.3','format'=>'pcm_s16le-mono-8000','pipeline'=>'v1'];
    }
    if (!preg_match('/^ws:[1-9][0-9]*$/D', (string)($tts['key'] ?? ''))) throw new RuntimeException('Proveedor TTS inválido.');
    return ['provider'=>$tts['key'],'voice'=>bot_tts_endpoint((string)($tts['endpoint'] ?? '')),
            'language'=>'es','speed'=>'1.3','format'=>'pcm_s16le-mono-8000','pipeline'=>'ws-v1'];
}

function bot_tts_snapshot(string $runtime, int $listId, ?int $campaignId = null): array {
    $path = $runtime.'/bot_ivr/audio_jobs/list_'.$listId.'.json';
    if (!is_file($path)) return ['key'=>'gtts'];
    $manifest = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || (int)($manifest['list_id'] ?? 0) !== $listId
        || ($campaignId !== null && (int)($manifest['campaign_id'] ?? 0) !== $campaignId)) throw new RuntimeException('Identidad del trabajo de audios inválida.');
    $tts = $manifest['tts'] ?? ['key'=>'gtts'];
    if (!is_array($tts)) throw new RuntimeException('Proveedor TTS inválido.');
    bot_tts_profile($tts);
    return $tts;
}

function bot_tts_call_snapshot(array $lead, string $runtime): array {
    if (!empty($lead['dial_config_json'])) {
        $config = json_decode($lead['dial_config_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config)) throw new RuntimeException('Configuración de marcación inválida.');
        $tts = $config['tts'] ?? ['key'=>'gtts'];
        bot_tts_profile($tts);
        return $tts;
    }
    return bot_tts_snapshot($runtime, (int)$lead['list_id'], (int)$lead['campaign_id']);
}

function bot_tts_resolve(string $key, array $providers): array {
    if ($key === 'gtts') return ['key'=>'gtts'];
    foreach ($providers as $provider) {
        if ($key === 'ws:'.$provider['provider_id']) return ['key'=>$key, 'endpoint'=>bot_tts_endpoint($provider['endpoint'])];
    }
    throw new RuntimeException('Selecciona un proveedor disponible.');
}
