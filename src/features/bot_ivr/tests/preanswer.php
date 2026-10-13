<?php
$sourceDir=$argv[1]??dirname(__DIR__,4).'/app/web/bot_ivr';
require $sourceDir.'/preanswer_service.php';
function paCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
paCheck(strpos(file_get_contents($sourceDir.'/preanswer_agi.php'),"<?php\n")===0,'PHP entry starts without output');
foreach(['El numero marcado no existe'=>'numero_no_existe','Por favor deja tu mensaje de voz'=>'casilla','Temporalmente fuera de servicio'=>'fuera_servicio','Hola buenos dias'=>null,'Sin audio'=>null] as $text=>$category)paCheck(bot_preanswer_classify($text)===$category,'classification: '.$text);
paCheck(bot_preanswer_classify('numeroso')===null,'substring false positive');
$file=tempnam(sys_get_temp_dir(),'zv2-pa-');
try {
 $pcm=str_repeat(pack('v',1000),8000*9);$wav=bot_preanswer_wav($pcm);
 // Real growing WAVs have not finalized RIFF/data sizes yet.
 $wav=substr_replace($wav,pack('V',0),4,4);$wav=substr_replace($wav,pack('V',0),40,4);file_put_contents($file,$wav);
 $sample=bot_preanswer_snapshot($file);paCheck($sample['audio_bytes']===96000 && $sample['total_bytes']===144000 && $sample['window_ms']===6000,'growing WAV sliding window');
 paCheck(strlen($sample['wav'])===96044 && unpack('V',substr($sample['wav'],40,4))[1]===96000,'valid snapshot header');
 $next=bot_preanswer_snapshot($file,$sample['audio_bytes']);paCheck($next['audio_bytes']===144000 && $next['window_ms']===5000,'queued audio advances with overlap');
 file_put_contents($file,bot_preanswer_wav(str_repeat(pack('v',1000),1600)));paCheck(bot_preanswer_snapshot($file)['window_ms']===200,'short finalized announcement');
 file_put_contents($file,bot_preanswer_wav(''));paCheck(bot_preanswer_snapshot($file)===null,'empty audio');
 file_put_contents($file,bot_preanswer_wav($pcm,16000));paCheck(bot_preanswer_snapshot($file)===null,'unsupported sample rate');
 file_put_contents($file,'not a wav');paCheck(bot_preanswer_snapshot($file)===null,'malformed WAV');
}finally{unlink($file);}
echo "PREANSWER_UNIT_OK: categories, word boundaries, growing WAV, bounded window, empty/malformed/rate\n";
