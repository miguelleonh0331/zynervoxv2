<?php
declare(strict_types=1);
require $argv[1].'/db.php';
require $argv[1].'/campaigns_service.php';
function flow_verify(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$db = carsa_db();
$repo = \ZynervoxQueries\botIvrRepository(bot_ivr_db_config(), $db);
$db->beginTransaction();
try {
    $campaign = bot_campaign_create($repo, '__flow_test__', false);
    $id = bot_campaign_list_create($repo, $campaign, 'Lista con flujo', true, '12');
    flow_verify((int)$repo->list($id, $campaign)['id_flujo'] === 12, 'Creation flow ID');
    bot_campaign_list_update($repo, $campaign, $id, 'Otro flujo', false, '34');
    flow_verify((int)$repo->list($id, $campaign)['id_flujo'] === 34, 'Flow update');
    bot_campaign_list_update($repo, $campaign, $id, 'Formulario anterior', true);
    flow_verify((int)$repo->list($id, $campaign)['id_flujo'] === 34, 'Old form must preserve flow');
    foreach (['', '0', '-1', '1.2', '1e2', 'abc', '9223372036854775808', []] as $invalid) {
        try {
            bot_campaign_list_update($repo, $campaign, $id, 'No guardar', false, $invalid);
            throw new LogicException('Invalid flow accepted');
        } catch (RuntimeException $e) {}
    }
    flow_verify($repo->list($id, $campaign)['name'] === 'Formulario anterior', 'Invalid input partially saved');
    $old = bot_campaign_list_create($repo, $campaign, 'Sin asignar', false);
    flow_verify($repo->list($old, $campaign)['id_flujo'] === null, 'Nullable compatibility');
    bot_campaign_list_update($repo, $campaign, $old, 'Asignado', true, '56');
    flow_verify((int)$repo->list($old, $campaign)['id_flujo'] === 56, 'Assign old list');
    echo "PASS: flow creation/update, old-form preservation, invalid IDs and nullable compatibility\n";
} finally { $db->rollBack(); }
