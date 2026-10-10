<?php
$web=$argv[1];
function renderRoute($web,$route,$logged=true){
 $code='$_SERVER["REQUEST_METHOD"]="GET";require $argv[1]."/includes/Auth.php";session_start();';
 if($logged)$code.='$_SESSION["user"]="monitor-fixture";$_SESSION["user_level"]=9;';
 $code.='$_SERVER["SCRIPT_FILENAME"]=$argv[1]."/".$argv[2];require $argv[1]."/includes/IsolatedGate.php";require $_SERVER["SCRIPT_FILENAME"];';
 $p=proc_open(['/usr/bin/php','-r',$code,$web,$route],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($p);
 if($status!==0 || $err!=='')throw new RuntimeException('Route execution failed: '.$route.' '.$err);return $out;
}
foreach(['modules/admin/index.php','modules/admin/campaign_monitor.php'] as $route){$html=renderRoute($web,$route);if(strpos($html,'id="monitor-campaigns"')===false)throw new RuntimeException('Monitor not rendered');}
$data=json_decode(renderRoute($web,'modules/admin/campaign_monitor_api.php'),true);if(empty($data['ok']) || !is_array($data['campaigns']))throw new RuntimeException('API snapshot missing');
$data=json_decode(renderRoute($web,'modules/admin/campaign_monitor_api.php',false),true);if($data['ok']!==false)throw new RuntimeException('Unauthenticated access');
echo "ADMIN_MONITOR_ROUTES_OK: dashboard/monitor render, isolated gate, authenticated API and anonymous rejection\n";
