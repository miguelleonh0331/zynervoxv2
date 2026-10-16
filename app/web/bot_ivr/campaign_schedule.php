<?php
declare(strict_types=1);

function bot_campaign_schedule_open(array $campaign, ?DateTimeImmutable $now = null): bool {
    if (empty($campaign['scheduled'])) return true;
    $start = (string)($campaign['start_time'] ?? '');
    $end = (string)($campaign['end_time'] ?? '');
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $start)
        || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $end)
        || $start === $end) return false;
    $zone = new DateTimeZone('America/Lima');
    $time = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone)->format('H:i:s');
    return $start < $end ? ($time >= $start && $time < $end) : ($time >= $start || $time < $end);
}

// One attempt per list/window: completed or manually stopped runs are not relaunched.
function bot_campaign_schedule_action(array $campaign, array $list, ?array $run, DateTimeImmutable $now): string {
    if (empty($campaign['scheduled'])) return 'none';
    $busy = $run && in_array($run['state'], ['starting','running','stopping'], true);
    if (empty($campaign['active']) || empty($list['active']) || !bot_campaign_schedule_open($campaign, $now)) {
        return $busy && $run['state'] !== 'stopping' ? 'stop' : 'none';
    }
    if ($busy) return 'none';
    $local = $now->setTimezone(new DateTimeZone('America/Lima'));
    $opening = new DateTimeImmutable($local->format('Y-m-d').' '.$campaign['start_time'], new DateTimeZone('America/Lima'));
    if ($opening > $local) $opening = $opening->modify('-1 day');
    if ($run && new DateTimeImmutable($run['created_at'], new DateTimeZone('UTC')) >= $opening) return 'none';
    return 'start';
}
