<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_auth();
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Centro de control Zyner</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#080d14;color:#eaf2fa;font:14px system-ui,Segoe UI,sans-serif}.muted{color:#91a7bc}.topbar{height:64px;display:flex;align-items:center;gap:14px;padding:0 20px;background:#101a27;border-bottom:1px solid #293c52}.topbar strong{font-size:17px}.tabs{display:flex;gap:8px;margin-left:auto}.tab{padding:9px 14px;border:1px solid #354b63;border-radius:8px;background:#172536;color:#c5d5e5;cursor:pointer}.tab.active{background:#147e87;color:#fff;border-color:#20a6af}.frame{width:100%;height:calc(100vh - 64px);border:0;background:#0e1520;display:none}.frame.active{display:block}@media(max-width:650px){.topbar{height:auto;flex-wrap:wrap;padding:12px}.tabs{order:3;width:100%;margin:0}.tab{flex:1}.frame{height:calc(100vh - 118px)}}
</style></head><body>
<header class="topbar"><strong>Centro de control</strong><span class="muted">Acceso directo temporal</span>
  <nav class="tabs"><button class="tab active" data-target="annexes">Anexos</button><button class="tab" data-target="proxies">Monitor de proxies</button></nav>
</header>
<iframe class="frame active" id="annexes" src="annexes.php" title="Administración de anexos"></iframe>
<iframe class="frame" id="proxies" src="monitor.php" title="Monitor de proxies"></iframe>
<script>document.querySelectorAll('.tab').forEach(b=>b.onclick=()=>{document.querySelectorAll('.tab,.frame').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.getElementById(b.dataset.target).classList.add('active')})</script>
</body></html>
