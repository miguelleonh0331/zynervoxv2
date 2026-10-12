<?php
require $argv[1].'/../zynervox_queries/factory.php';
require ($argv[2] ?? $argv[1]).'/../includes/Database.php';
if (!\Config\Config::deployment('isolated',false) || \Includes\Database::getBotIvrConfig()['database']!=='zynervox_core') throw new RuntimeException('Isolated configuration required');
$db=\Includes\Database::getBotIvrInstance();
$repo=\ZynervoxQueries\botIvrRepository(\Includes\Database::getBotIvrConfig(),$db);
$db->beginTransaction();
try {
    $url='ws://127.0.0.1:8900/fixture-'.bin2hex(random_bytes(6));
    $id=$repo->createTtsProvider('Fixture <TTS>',$url);
    $found=array_values(array_filter($repo->ttsProviders(),fn($row)=>(int)$row['provider_id']===$id));
    if (count($found)!==1 || $found[0]['name']!=='Fixture <TTS>' || $found[0]['endpoint']!==$url) throw new RuntimeException('Catalog roundtrip failed');
    $duplicate=false;
    try {$repo->createTtsProvider('Duplicate',$url);} catch(RuntimeException $e) {$duplicate=$e->getMessage()==='Esta URL ya está registrada.';}
    if (!$duplicate) throw new RuntimeException('Duplicate URL accepted');
    echo "PASS: scoped TTS catalog create/list and duplicate guard; transaction rolled back\n";
} finally {$db->rollBack();}
