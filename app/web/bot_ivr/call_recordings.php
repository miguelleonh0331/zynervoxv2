<?php
declare(strict_types=1);
require_once __DIR__.'/../config/Config.php';

function bot_call_recording_path(string $callId): ?string {
    if (!\Config\Config::deployment('isolated',false) || !preg_match('/^[A-Za-z0-9_.-]{1,80}$/D',$callId)) return null;
    $root=realpath(rtrim((string)\Config\Config::deployment('runtime'),'/').'/recordings/bot_ivr');
    if ($root===false) return null;
    // Existing Asterisk FILTER filenames omit hyphens from the tracked call ID.
    foreach (array_unique([$callId,str_replace('-','',$callId)]) as $name) {
        $path=$root.'/'.$name.'.wav';
        clearstatcache(true,$path);
        if (is_link($path)) continue;
        $resolved=realpath($path);
        if ($resolved!==false && dirname($resolved)===$root && is_file($resolved) && is_readable($resolved)) return $resolved;
    }
    return null;
}

function bot_lead_recording_path(array $lead): ?string {
    if (empty($lead['call_id']) || empty($lead['finished_at'])) return null;
    $path=bot_call_recording_path((string)$lead['call_id']);
    return $path!==null && filesize($path)>44 ? $path : null;
}
