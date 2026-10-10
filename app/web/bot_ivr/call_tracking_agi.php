#!/usr/bin/php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
while (($line=fgets(STDIN))!==false && trim($line)!=='') {}
function agi_set(string $name, string $value): void {
    echo 'SET VARIABLE '.$name.' "'.str_replace(['\\','"',"\r","\n"],['\\\\','\\"','',''],$value).'"'."\n";
    fflush(STDOUT);
    fgets(STDIN);
}
try {
    require __DIR__.'/db.php';
    if (!\Config\Config::deployment('isolated',false) || carsa_db()->query('SELECT DATABASE()')->fetchColumn()!=='zynervox_core') throw new RuntimeException('Invalid tracking database');
    $repo=bot_ivr_repository();
    if (($argv[1]??'')==='start') {
        agi_set('ZV2_TRACK_OK','0');
        $list=filter_var($argv[2]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$list) throw new RuntimeException('Invalid list');
        $lead=$repo->startCall($list,(string)($argv[3]??''),(string)($argv[4]??''));
        agi_set('ZV2_LEAD_ID',(string)$lead);
        agi_set('ZV2_TRACK_OK','1');
    } elseif (($argv[1]??'')==='finish') {
        $repo->finishCall((string)($argv[2]??''),(string)($argv[3]??''),(string)($argv[4]??''),(string)($argv[5]??''),(int)($argv[6]??0),($argv[7]??'')==='1');
        agi_set('ZV2_FINISH_OK','1');
    } else throw new RuntimeException('Invalid action');
} catch (Throwable $e) {
    // Never log database credentials or lead data through the AGI channel.
    fwrite(STDERR,"ZV2 call tracking failed: ".get_class($e)."\n");
    exit(1);
}
