<?php
require $argv[1].'/ivr_builder/forwarding.php';
function forwardCheck(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$node=['type'=>'reenviar','forwarding'=>['mode'=>'trunk','endpoint'=>'carrier1','number'=>'51{numero}','headers'=>[['name'=>'X-Cliente','value'=>'{nombre}'],['name'=>'User-to-User','value'=>'{lead_id}']]]];
$resolved=ivr_forwarding_render($node,['numero'=>'999000111','nombre'=>'Diego','lead_id'=>49]);
forwardCheck($resolved['number']==='51999000111' && $resolved['headers'][0]['value']==='Diego','variable expansion');
forwardCheck(ivr_forwarding_config(['anexo'=>'700'])['mode']==='dialplan','old extension compatibility');
forwardCheck(ivr_forwarding_render($node,['numero'=>'0','nombre'=>'0','lead_id'=>0])['headers'][0]['value']==='0','zero is a value');
foreach ([[],['numero'=>'1&evil','nombre'=>'ok','lead_id'=>1],['numero'=>'1','nombre'=>"bad\r\nX-Evil: yes",'lead_id'=>1],['numero'=>'1','nombre'=>'${SHELL(id)}','lead_id'=>1]] as $variables) {
    try { ivr_forwarding_render($node,$variables);throw new LogicException('unsafe variable accepted'); } catch (RuntimeException $e) {}
}
foreach (['Authorization','Contact','From','P-Asserted-Identity',"X-Bad\nOther"] as $name) {
    $bad=$node;$bad['forwarding']['headers'][0]['name']=$name;
    try { ivr_forwarding_config($bad);throw new LogicException('unsafe header accepted'); } catch (InvalidArgumentException $e) {}
}
$bad=$node;$bad['forwarding']['endpoint']='carrier1&evil';
try { ivr_forwarding_config($bad);throw new LogicException('unsafe endpoint accepted'); } catch (InvalidArgumentException $e) {}
$bad=$node;$bad['forwarding']['headers'][]=['name'=>'x-cliente','value'=>'duplicate'];
try { ivr_forwarding_config($bad);throw new LogicException('duplicate header accepted'); } catch (InvalidArgumentException $e) {}
forwardCheck(ivr_forwarding_agi_quote('a"b\\c')==='"a\\"b\\\\c"','AGI escaping');
echo "PASS: forwarding variables, legacy destination, zero, missing variables and injection rejection\n";

require $argv[1].'/bot_ivr/ivr_graph_service.php';
$lead=['lead_id'=>49,'list_id'=>1,'campaign_id'=>1,'phone'=>'999000111','customer_name'=>'Diego','extra_json'=>'{}'];
$commands=[];
$command=function($text) use(&$commands) {
    $commands[]=$text;
    if (str_starts_with($text,'GET FULL VARIABLE')) return '200 result=1 (carrier1)';
    if ($text==='GET VARIABLE DIALSTATUS') return '200 result=1 (ANSWER)';
    if ($text==='GET VARIABLE CONTEXT') return '200 result=1 (zynervoxv2)';
    return '200 result=1';
};
$flow=['start'=>'forward','nodes'=>['forward'=>$node]];
$runner=new BotIvrGraphRunner($flow,$lead,'forward-test','/runtime',fn()=>true,$command,fn()=>null,fn()=>[],fn()=>true);
forwardCheck($runner->run()==='TRANSFERRED','trunk terminal outcome');
forwardCheck(in_array('EXEC Dial PJSIP/51999000111@carrier1,30,b(zynervoxv2-forward-headers^s^1)',$commands,true),'outbound PJSIP with predial');
forwardCheck(!in_array('EXEC Hangup',$commands,true),'no forced hangup');
$encodedCommand=array_values(array_filter($commands,fn($c)=>str_starts_with($c,'SET VARIABLE __ZV2_FORWARD_HEADERS')))[0];
preg_match('/"([A-Za-z0-9+\/=]+)"$/',$encodedCommand,$match);
$headers=json_decode(base64_decode($match[1]),true,512,JSON_THROW_ON_ERROR);
forwardCheck($headers[0]['value']==='Diego','resolved headers use inherited payload');
$commands=[];$flow['nodes']['forward']=['type'=>'reenviar','anexo'=>'700','contexto'=>'zynervoxv2','prioridad'=>1];
$runner=new BotIvrGraphRunner($flow,$lead,'forward-test','/runtime',fn()=>true,$command,fn()=>null,fn()=>[],fn()=>true);
forwardCheck($runner->run()==='TRANSFERRED' && in_array('EXEC Goto zynervoxv2,700,1',$commands,true),'legacy context transfer');
$flow['nodes']['forward']=$node;$commands=[];
$runner=new BotIvrGraphRunner($flow,$lead,'forward-test','/runtime',fn()=>true,fn($c)=>str_starts_with($c,'GET FULL VARIABLE')?'200 result=0':'200 result=1',fn()=>null,fn()=>[],fn()=>true);
try { $runner->run();throw new LogicException('missing endpoint accepted'); } catch (RuntimeException $e) { forwardCheck($e->getMessage()==='Forward endpoint unavailable','missing endpoint rejected'); }
echo "PASS: terminal forward, PJSIP predial, inherited variables, legacy context and unavailable endpoint\n";

$sip=$node;$sip['forwarding']['protocol']='SIP';
$commands=[];
$added=[];
$command=function($text) use(&$commands,&$added) {
    $commands[]=$text;
    if (str_starts_with($text,'GET FULL VARIABLE')) return '200 result=1 (127.0.0.1)';
    if ($text==='GET VARIABLE SIPADDHEADER01') return '200 result=1 (X-Previous:keep)';
    if (str_starts_with($text,'EXEC SIPAddHeader ')) {
        $added[count($added)+2]=substr($text,19,-1);
        return '200 result=0';
    }
    if (preg_match('/^GET VARIABLE SIPADDHEADER(\d+)$/',$text,$m)) return isset($added[(int)$m[1]])?'200 result=1 ('.$added[(int)$m[1]].')':'200 result=0';
    if ($text==='GET VARIABLE DIALSTATUS') return '200 result=1 (ANSWER)';
    return '200 result=0';
};
$flow['nodes']['forward']=$sip;
$runner=new BotIvrGraphRunner($flow,$lead,'forward-test','/runtime',fn()=>true,$command,fn()=>null,fn()=>[],fn()=>true);
forwardCheck($runner->run()==='TRANSFERRED','SIP terminal outcome');
forwardCheck(in_array('GET FULL VARIABLE "${SIPPEER(carrier1,ip)}"',$commands,true),'SIP peer lookup');
forwardCheck(in_array('EXEC SIPAddHeader "X-Cliente:Diego"',$commands,true) && in_array('EXEC Dial SIP/carrier1/51999000111,30',$commands,true),'SIP headers and trunk dial');
forwardCheck(in_array('SET VARIABLE __SIPADDHEADER02 ""',$commands,true) && !in_array('SET VARIABLE __SIPADDHEADER01 ""',$commands,true),'only created header slots cleared');
forwardCheck(count(array_filter($commands,fn($c)=>str_contains($c,'PJSIP_HEADER') || str_contains($c,'forward-headers')))==0,'SIP does not use PJSIP predial');
try { ivr_forwarding_render($sip,['numero'=>'1','nombre'=>'literal\\r\\nInjected','lead_id'=>1]);throw new LogicException('SIP header escape accepted'); } catch (RuntimeException $e) {}
$bad=$sip;$bad['forwarding']['protocol']='OTHER';
try { ivr_forwarding_config($bad);throw new LogicException('unknown protocol accepted'); } catch (InvalidArgumentException $e) {}
echo "PASS: SIP peer lookup, SIPAddHeader, SIP trunk, scoped cleanup and encoded-control rejection\n";

$full=[];
$command=function($text) use(&$full) {
    $full[]=$text;
    return str_starts_with($text,'GET FULL VARIABLE')?'200 result=1 (127.0.0.1)':'200 result=1 (X-Previous:keep)';
};
$runner=new BotIvrGraphRunner($flow,$lead,'forward-test','/runtime',fn()=>true,$command,fn()=>null,fn()=>[],fn()=>true);
try { $runner->run();throw new LogicException('full SIP header slots accepted'); } catch (RuntimeException $e) { forwardCheck($e->getMessage()==='Forward SIP headers full','full header slots rejected'); }
forwardCheck(!array_filter($full,fn($c)=>str_starts_with($c,'EXEC Dial') || str_starts_with($c,'SET VARIABLE')),'full slots neither dial nor clear old headers');
echo "PASS: full SIP header slots fail before Dial and preserve existing headers\n";
