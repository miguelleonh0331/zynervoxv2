<?php
declare(strict_types=1);
// Render the actual page with isolated fixtures; authentication remains in campaigns_page.php.
if (($argv[1] ?? '') === '--render') {
    $_GET = ['tab'=>$argv[3]];
    $_SERVER['REQUEST_METHOD'] = $argv[4];
    $_POST = ['action'=>'deactivate_campaign','campaign_id'=>$argv[5],'csrf'=>$argv[6]];
    require $argv[2].'/index.php';
    exit;
}
$dir = sys_get_temp_dir().'/bot-campaign-tabs-'.bin2hex(random_bytes(8));
mkdir($dir,0700);
copy($argv[1].'/index.php',$dir.'/index.php');
$fixture = <<<'PHP'
<?php
$error = ''; $message = '';
function h($s) { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function bot_campaign_csrf() { if (($_POST['csrf'] ?? '') !== 'valid') throw new RuntimeException('CSRF_REJECTED'); }
function bot_campaign_token() { echo '<input name="csrf" value="valid">'; }
function bot_campaign_header($s) { global $error; echo h($s).' '.h($error); }
function bot_campaign_footer() {}
function bot_campaign_redirect($target,$message) { echo 'REDIRECT:'.$target; exit; }
function bot_ivr_repository() { return new class {
    function campaigns() { return [
        ['campaign_id'=>1,'name'=>'Active <script>fixture</script>','active'=>1,'lists_count'=>2,'start_time'=>'08:00:00','end_time'=>'17:00:00','scheduled'=>0],
        ['campaign_id'=>2,'name'=>'Inactive fixture','active'=>0,'lists_count'=>1,'start_time'=>'08:00:00','end_time'=>'17:00:00','scheduled'=>0]
    ]; }
    function deactivateCampaign($id) { if ($id !== 1) throw new RuntimeException('Missing campaign'); }
}; }
PHP;
file_put_contents($dir.'/campaigns_page.php',$fixture);
function renderTabs(string $dir,string $tab,string $method='GET',string $id='1',string $csrf='valid'): string {
    $p = proc_open([PHP_BINARY,__FILE__,'--render',$dir,$tab,$method,$id,$csrf],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($p) !== 0 || $err !== '') throw new RuntimeException('Rendering failed: '.$err);
    return $out;
}
function tabCheck(bool $ok,string $why): void { if (!$ok) throw new LogicException($why); }
try {
    $active = renderTabs($dir,'');
    tabCheck(strpos($active,'Active &lt;script&gt;fixture&lt;/script&gt;') !== false && strpos($active,'Inactive fixture') === false,'Active filter/escaping');
    tabCheck(strpos($active,'Desactivar</button>') !== false && strpos($active,'confirm(') !== false,'Button/confirmation');
    tabCheck(strpos($active,'href="index.php" aria-current="page"') !== false,'Active tab selection');
    $inactive = renderTabs($dir,'inactive');
    tabCheck(strpos($inactive,'Inactive fixture') !== false && strpos($inactive,'Active &lt;') === false,'Inactive filter');
    tabCheck(strpos($inactive,'Desactivar</button>') === false && strpos($inactive,'Modificar</a>') !== false,'Inactive actions');
    tabCheck(strpos($inactive,'href="index.php?tab=inactive" aria-current="page"') !== false,'Inactive tab selection');
    tabCheck(strpos(renderTabs($dir,'','POST','1','bad'),'CSRF_REJECTED') !== false,'CSRF guard');
    foreach (['0','-1','x','1 OR 1=1'] as $id) tabCheck(strpos(renderTabs($dir,'','POST',$id),'REDIRECT:') === false,'Invalid ID accepted');
    tabCheck(renderTabs($dir,'','POST') === 'REDIRECT:index.php?tab=inactive','Successful redirect');
    echo "PASS: campaign tab filters, button, escaped names, confirmation, CSRF, ID validation and redirect\n";
} finally {
    unlink($dir.'/index.php'); unlink($dir.'/campaigns_page.php'); rmdir($dir);
}
