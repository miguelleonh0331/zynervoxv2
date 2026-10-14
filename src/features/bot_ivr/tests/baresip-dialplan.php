<?php
require $argv[1].'/includes/DialplanOrigins.php';
$common=\Includes\DialplanOrigins::COMMON;
function phoneCheck($condition,$message){if(!$condition)throw new RuntimeException($message);}
preg_match('/\[zynervoxv2-baresip\](.*?)(?=\n\[)/s',$common,$origin);
phoneCheck(strpos($origin[1],'Answer(')===false,'origin answered before destination');
phoneCheck(strpos($origin[1],'b(zynervoxv2-outbound^s^1)G(zynervoxv2-baresip-answer^answered^1)')!==false,'missing predial/split');
phoneCheck(strpos($common,'CHANNEL(hangup_handler_pop)')!==false,'caller closes callee tracking');
phoneCheck(strpos($common,'exten => callee,1,Set(__ZV2_ANSWERED=1)')!==false,'callee lacks independent answer state');
phoneCheck(strpos($common,'Set(DIALSTATUS=ANSWER)')!==false,'callee loses answer disposition');
phoneCheck(strpos($common,'SIP/carrier1/881851${ZV2_PHONE}')!==false,'carrier changed');
$body="exten => _7307X.,1,NoOp(existing)\n";
$rendered=\Includes\DialplanOrigins::render([['carrier_id'=>'fixture','dialplan_entry'=>$body]]);
phoneCheck(\Includes\DialplanOrigins::prefixes($rendered)===['7307'],'helper prefix leaked into origin catalog');
phoneCheck(strpos(\Includes\DialplanOrigins::body($rendered),'existing')!==false,'render roundtrip failed');
echo "BARESIP_DIALPLAN_OK\n";
