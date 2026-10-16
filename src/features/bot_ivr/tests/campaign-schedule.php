<?php
require $argv[1].'/campaign_schedule.php';
function scheduleCheck($expected, $campaign, $instant) {
    if (bot_campaign_schedule_open($campaign, new DateTimeImmutable($instant)) !== $expected) {
        throw new RuntimeException('Schedule mismatch at '.$instant);
    }
}
$c=['scheduled'=>1,'start_time'=>'08:00:00','end_time'=>'16:30:00'];
foreach (['07:59:59'=>false,'08:00:00'=>true,'16:29:59'=>true,'16:30:00'=>false] as $t=>$open) {
    scheduleCheck($open,$c,'2026-10-16T'.$t.'-05:00');
}
scheduleCheck(true,$c,'2026-10-16T13:00:00Z');
scheduleCheck(false,$c,'2026-10-16T21:30:00Z');
$c['scheduled']=0;scheduleCheck(true,$c,'2026-10-16T01:00:00-05:00');
$c=['scheduled'=>1,'start_time'=>'22:00:00','end_time'=>'06:00:00'];
foreach (['21:59:59'=>false,'22:00:00'=>true,'00:00:00'=>true,'05:59:59'=>true,'06:00:00'=>false] as $t=>$open) {
    scheduleCheck($open,$c,'2026-10-16T'.$t.'-05:00');
}
$c['end_time']='22:00:00';scheduleCheck(false,$c,'2026-10-16T23:00:00-05:00');
$c['end_time']='invalid';scheduleCheck(false,$c,'2026-10-16T23:00:00-05:00');
echo "CAMPAIGN_SCHEDULE_OK\n";

function actionCheck($expected, $campaign, $list, $run, $instant) {
    if (bot_campaign_schedule_action($campaign, $list, $run, new DateTimeImmutable($instant)) !== $expected) {
        throw new RuntimeException('Scheduler action mismatch: '.$expected.' at '.$instant);
    }
}
$c=['active'=>1,'scheduled'=>1,'start_time'=>'08:00:00','end_time'=>'16:30:00'];
$l=['active'=>1];
$running=['state'=>'running','created_at'=>'2026-10-16 13:00:00'];
$stopped=['state'=>'stopped','created_at'=>'2026-10-16 13:00:00'];
actionCheck('none',$c,$l,null,'2026-10-16T07:59:59-05:00');
actionCheck('start',$c,$l,null,'2026-10-16T08:00:00-05:00');
actionCheck('none',$c,$l,$running,'2026-10-16T08:01:00-05:00');
actionCheck('stop',$c,$l,$running,'2026-10-16T16:30:00-05:00');
actionCheck('none',$c,$l,$stopped,'2026-10-16T09:00:00-05:00');
actionCheck('start',$c,$l,$stopped,'2026-10-17T08:00:00-05:00');
foreach (['completed','failed','stopped'] as $state) {
    $r=$stopped;$r['state']=$state;
    actionCheck('none',$c,$l,$r,'2026-10-16T09:00:00-05:00');
}
$r=$running;$r['state']='stopping';actionCheck('none',$c,$l,$r,'2026-10-16T17:00:00-05:00');
$c['scheduled']=0;actionCheck('none',$c,$l,$running,'2026-10-16T17:00:00-05:00');
$c['scheduled']=1;$c['active']=0;actionCheck('stop',$c,$l,$running,'2026-10-16T09:00:00-05:00');
$c['active']=1;$l['active']=0;actionCheck('none',$c,$l,null,'2026-10-16T09:00:00-05:00');
actionCheck('stop',$c,$l,$running,'2026-10-16T09:00:00-05:00');
$l['active']=1;$c['start_time']='22:00:00';$c['end_time']='06:00:00';
$r=['state'=>'completed','created_at'=>'2026-10-17 03:00:00'];
actionCheck('none',$c,$l,$r,'2026-10-17T01:00:00-05:00');
actionCheck('start',$c,$l,$r,'2026-10-17T22:00:00-05:00');
echo "CAMPAIGN_SCHEDULER_ACTIONS_OK\n";
