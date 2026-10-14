<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');
require __DIR__.'/baresip_origin.php';
$env=[];while(($line=fgets(STDIN))!==false && trim($line)!==''){$pair=explode(':',trim($line),2);if(count($pair)===2)$env[$pair[0]]=trim($pair[1]);}
function baresip_agi_set(string $name,string $value): void {
    echo 'SET VARIABLE '.$name.' "'.str_replace(['\\','"',"\r","\n"],['\\\\','\\"','',''],$value).'"'."\n";
    fflush(STDOUT);if(fgets(STDIN)===false)throw new RuntimeException('AGI channel closed');
}
try {
    baresip_agi_set('ZV2_ORIGIN_OK','0');
    if(!preg_match('/^7307([0-9]{2,20})\*(zv2-[a-f0-9]{24})$/D',(string)($argv[1]??''),$match))throw new RuntimeException('Invalid SIP dial string');
    $context=bot_baresip_request(['action'=>'bind','call_id'=>$match[2],'phone'=>$match[1],
        'channel'=>$env['agi_channel']??'','unique_id'=>$env['agi_uniqueid']??'']);
    foreach(['ZV2_CALL_ID'=>'call_id','ZV2_LIST_ID'=>'list_id','ZV2_LEAD_ID'=>'lead_id','ZV2_PHONE'=>'phone','ZV2_ORIGIN_CID'=>'caller_id'] as $name=>$key)
        baresip_agi_set('__'.$name,(string)$context[$key]);
    baresip_agi_set('ZV2_ORIGIN_OK','1');
}catch(Throwable $error){fwrite(STDERR,'ZV2 Baresip context rejected: '.get_class($error)."\n");exit(1);}
