<?php
declare(strict_types=1);
require $argv[1].'/list_audio_service.php';
function audio_verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function audio_reject(callable $operation, string $message): void {
    try { $operation(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException($message);
}
$lead = ['lead_id'=>7, 'list_id'=>3, 'phone'=>'999000001', 'customer_name'=>'Ana',
    'extra_json'=>'{"name":"Ana","monto":"0","direccion":"Calle 10","local":"Tienda","campaign_id":"999"}'];
$vars = bot_list_audio_variables($lead, 3, 1);
audio_verify($vars['nombre'] === 'Ana' && $vars['campaign_id'] === '1', 'Canonical name and protected campaign ID');
audio_verify(bot_list_audio_render('Hola {nombre}, monto {monto}, {local}, {direccion}.', $vars) === 'Hola Ana, monto 0, Tienda, Calle 10.', 'All uploaded variables and zero must render');
audio_reject(static function () use ($vars) { bot_list_audio_render('{ausente}', $vars); }, 'Missing variable accepted');
audio_reject(static function () { bot_list_audio_render('{nombre}', ['nombre'=>' ']); }, 'Empty variable accepted');
audio_reject(static function () { bot_list_audio_render('{nombre}', ['nombre'=>'{otro}']); }, 'Nested unresolved variable accepted');
audio_reject(static function () { bot_list_audio_render('{nombre}', ['nombre'=>str_repeat('a',1001)]); }, 'Provider text limit ignored');
foreach (['{bad', '[]', '{"monto":null}', '{"monto":{}}'] as $json) {
    audio_reject(static function () use ($lead, $json) {
        $bad = $lead; $bad['extra_json'] = $json; bot_list_audio_variables($bad, 3, 1);
    }, 'Invalid lead variable map accepted');
}
$flow = ['start'=>'a', 'nodes'=>[
    'a'=>['type'=>'create_audio', 'audio_text'=>'Hola {nombre}', 'next'=>'b', 'fallback'=>'c'],
    'b'=>['type'=>'create_audio_dynamic', 'message'=>'Monto {monto}', 'next'=>'a'],
    'c'=>['type'=>'create_audio_composite', 'segments'=>[['text'=>'Local {local}']]],
    'unreachable'=>['type'=>'create_audio','audio_text'=>'{missing}'],
]];
$templates = bot_list_audio_templates($flow);
audio_verify(count($templates) === 3, 'Reachability, fallback and cycle handling');
$bad = $lead; $bad['lead_id'] = 8; $bad['extra_json'] = '{"monto":"0"}';
$other = $lead; $other['lead_id'] = 9; $other['list_id'] = 4;
$check = bot_list_audio_preflight($flow, [$lead, $bad, $other], 3, 1);
audio_verify($check['ready'] === 1 && $check['rejected'] === 2 && $check['texts'] === 3, 'Per-lead validation and list isolation');
audio_verify(strpos($check['errors'][0], 'local') !== false && strpos($check['errors'][1], 'Lead #9') !== false, 'Errors identify lead and missing variable');
audio_reject(static function () use ($flow) { bot_list_audio_preflight($flow, [], 3, 1); }, 'Empty list accepted');
$flow['nodes']['a']['next'] = 'missing';
audio_reject(static function () use ($flow) { bot_list_audio_templates($flow); }, 'Broken transition accepted');
echo "PASS: variable substitution, aliases, zero, missing/empty values, JSON, protected IDs, reachable prompts and list isolation\n";
