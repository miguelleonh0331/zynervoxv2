<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || getenv('DIALER_LAB_CONFIRM')!=='APACHE_DIALER_TESTS') exit(1);
ob_start();
require '/srv/www/htdocs/zynervoxv2/includes/Auth.php';
require '/srv/www/htdocs/zynervoxv2/includes/Dialer.php';
use Includes\Auth;
use Includes\Database;
use Includes\Dialer;
function webAssert($ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$db=Database::getCoreInstance();
$admin=$db->query('SELECT user,pass FROM '.\Config\Config::coreTable('users')." WHERE user_level=9 AND active='Y' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
webAssert($admin!==false,'Existing administrator required; never create a public test login.');
session_id(bin2hex(random_bytes(24)));
webAssert(Auth::login($admin['user'],$admin['pass']),'Existing admin login failed.');
$name=session_name(); $id=session_id(); session_write_close();
$cookie=$name.'='.$id;
function requestWeb(string $path,string $cookie='',array $headers=[]): array {
    $curl=curl_init('http://127.0.0.1/zynervoxv2/'.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>$headers]);
    if ($cookie!=='') curl_setopt($curl,CURLOPT_COOKIE,$cookie);
    $response=curl_exec($curl); if ($response===false) throw new RuntimeException(curl_error($curl));
    $status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE); $offset=(int)curl_getinfo($curl,CURLINFO_HEADER_SIZE); curl_close($curl);
    return [$status,substr($response,0,$offset),substr($response,$offset)];
}
try {
    $campaigns=$db->query("SELECT campaign_id FROM v2_dialer_campaigns WHERE carrier_id='DIALER_TEN_SIP' AND name LIKE 'LAB TEN %' AND engine_state='STOPPED' ORDER BY campaign_id DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
    webAssert(count($campaigns)===3,'Three stopped lab campaigns required.');
    foreach (['campaigns.php','dialer_campaign_edit.php','dialer_list_leads.php','dialer_agent.php','dialer_report.php'] as $page) webAssert(requestWeb('modules/admin/'.$page)[0]===302,'Anonymous page blocked by gate instead of Auth: '.$page);
    foreach ($campaigns as $c) {
        $list=$db->query('SELECT list_id FROM v2_dialer_lists WHERE campaign_id='.(int)$c.' LIMIT 1')->fetchColumn();
        foreach (['campaigns.php','dialer_campaign_edit.php?id='.$c,'dialer_list_leads.php?id='.$list.'&campaign_id='.$c,'dialer_agent.php','dialer_report.php?id='.$c,'dialer_report.php?view=recordings&id='.$c] as $page) webAssert(requestWeb('modules/admin/'.$page,$cookie)[0]===200,'Admin page failed: '.$page);
        $call=$db->query('SELECT call_id FROM v2_dialer_call_log WHERE campaign_id='.(int)$c.' AND recording_path IS NOT NULL LIMIT 1')->fetchColumn();
        $path='modules/admin/dialer_report.php?view=recordings&audio='.$call;
        [$status,$headers,$body]=requestWeb($path,$cookie);
        webAssert($status===200 && substr($body,0,4)==='RIFF' && strlen($body)===Dialer::recording((int)$call)['size'],'Apache full WAV failed.');
        [$status,$headers,$body]=requestWeb($path,$cookie,['Range: bytes=0-43']);
        webAssert($status===206 && strlen($body)===44 && strpos($headers,'Content-Range: bytes 0-43/')!==false,'Apache range playback failed.');
        webAssert(requestWeb($path)[0]===302,'Anonymous audio was disclosed.');
    }
    foreach (['dialer_engine_worker.php','dialer_originator_worker.php','dialer_call_tracking_agi.php','dialer_recordings.php'] as $page) webAssert(requestWeb('modules/admin/'.$page,$cookie)[0]===403,'CLI/include page exposed: '.$page);
    webAssert(in_array(requestWeb('modules/vpn/index.php')[0],[302,403,404],true),'VPN anonymous access changed.');
    foreach ([1,7,8] as $role) {
        session_id($id); session_start(); $_SESSION['user_level']=$role; $_SESSION['user']='79970'; session_write_close();
        $c=$campaigns[0];
        webAssert(requestWeb('modules/admin/dialer_report.php?id='.$c,$cookie)[0]===($role===1 ? 302 : 200),'Role report guard failed.');
        webAssert(requestWeb('modules/admin/dialer_agent.php',$cookie)[0]===200,'Agent panel role guard failed.');
        webAssert(in_array(requestWeb('modules/vpn/index.php',$cookie)[0],[302,403,404],true),'VPN inferior role access changed.');
    }
    echo "PASS: real Apache admin pages, anonymous/Auth redirects, roles, WAV/Range playback, CLI gate and VPN restrictions.\n";
} finally { session_id($id); session_start(); session_destroy(); }
ob_end_flush();
