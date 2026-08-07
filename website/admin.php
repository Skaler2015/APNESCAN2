<?php
/**
 * ApneScan Enterprise Admin — front controller.
 * Bootstraps, guards auth, and routes ?page= to a modular page view rendered
 * inside the sidebar/topbar shell. All business logic lives in admin/includes;
 * all presentation in admin/components + admin/pages.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

require __DIR__ . '/admin/includes/init.php';       // session, db, config, csrf, helpers
require __DIR__ . '/admin/includes/metrics.php';
require __DIR__ . '/admin/includes/ui.php';
require __DIR__ . '/admin/components/head.php';
require __DIR__ . '/admin/components/sidebar.php';
require __DIR__ . '/admin/components/topbar.php';

// ---- Logout ---------------------------------------------------------------
if (isset($_GET['logout'])) { audit('logout'); session_destroy(); header('Location: admin.php'); exit; }

// ---- Auth gate ------------------------------------------------------------
if (!is_logged_in()) { require __DIR__ . '/admin/auth/login.php'; exit; }

// ---- Routing --------------------------------------------------------------
$pages = [
    'dashboard' => ['Overview', 'view'], 'analytics' => ['Analytics', 'view'], 'live' => ['Live Users', 'view'],
    'scanner' => ['Scanner Analytics', 'view'], 'ocr' => ['OCR Analytics', 'view'], 'events' => ['Events & Feedback', 'view'],
    'reports' => ['Reports', 'reports'], 'versions' => ['Versions', 'view'], 'devices' => ['Devices', 'view'],
    'os' => ['Operating Systems', 'view'], 'users' => ['Admins & Roles', 'users'], 'notifications' => ['Notifications', 'view'],
    'backup' => ['Backup', 'backup'], 'export' => ['Export', 'export'], 'audit' => ['Audit Log', 'audit'],
    'health' => ['System Health', 'view'], 'settings' => ['Settings', 'settings'], 'help' => ['Help', 'view'],
    'install' => ['Install detail', 'view'],
];
$page = $_GET['page'] ?? 'dashboard';
if (!isset($pages[$page])) $page = 'dashboard';
[$title, $cap] = $pages[$page];
if (!can($cap)) { $page = 'dashboard'; [$title, $cap] = $pages['dashboard']; }

// ---- Central POST actions (redirect before any output) --------------------
require __DIR__ . '/admin/includes/actions.php';

// ---- Early handlers that emit non-HTML (streamed downloads) ---------------
if (isset($_GET['do'])) { require __DIR__ . '/admin/pages/export_stream.php'; exit; }

// ---- Shell data -----------------------------------------------------------
$ov = metrics_overview(resolve_range($_GET['r'] ?? '30'));
$notifs = notifications();
$badges = ['events' => (int)$ov['unread_fb'], 'notifications' => count(array_filter($notifs, fn($n) => ($n['sev'] ?? '') !== 'good'))];

// ---- Render shell ---------------------------------------------------------
page_head($title, true);
echo '<div class="app" id="app">';
render_sidebar($page, $badges);
echo '<div class="main">';
render_topbar($title, (int)$ov['online'], $notifs);
echo '<div class="content">';

try {
    require __DIR__ . '/admin/pages/' . $page . '.php';
} catch (Throwable $e) {
    echo '<div class="flash err">This section hit an error: ' . h($e->getMessage()) . '</div>';
}

echo '</div></div></div>'; // content, main, app

// ---- Global shell JS ------------------------------------------------------
?>
<script>
(function(){
  var root=document.documentElement, app=document.getElementById('app');
  // sidebar collapse (persisted)
  try{ if(localStorage.getItem('as_collapsed')==='1') app.classList.add('collapsed'); }catch(e){}
  var burger=document.getElementById('burger');
  if(burger) burger.onclick=function(){
    if(window.innerWidth<=720){ app.classList.toggle('mobopen'); return; }
    app.classList.toggle('collapsed');
    try{ localStorage.setItem('as_collapsed', app.classList.contains('collapsed')?'1':'0'); }catch(e){}
  };
  // theme
  var tb=document.getElementById('themeBtn');
  function applyChartTheme(){
    if(!window.Chart) return;
    var cs=getComputedStyle(root);
    Chart.defaults.color=cs.getPropertyValue('--muted').trim()||'#645d7e';
    Chart.defaults.borderColor=cs.getPropertyValue('--line').trim()||'#e8e3f3';
    Chart.defaults.font.family="Inter,system-ui,sans-serif";
  }
  if(tb) tb.onclick=function(e){ e.preventDefault();
    var cur=root.getAttribute('data-theme')||(matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');
    var nx=cur==='dark'?'light':'dark'; root.setAttribute('data-theme',nx);
    try{ localStorage.setItem('as_theme',nx); }catch(e){}
    applyChartTheme(); Object.values(window._charts||{}).forEach(function(c){ c.update(); });
  };
  // dropdowns
  function dd(btnId, ddId){ var b=document.getElementById(btnId), d=document.getElementById(ddId); if(!b||!d) return;
    b.onclick=function(e){ e.preventDefault(); e.stopPropagation(); document.querySelectorAll('.dd.open').forEach(function(x){ if(x!==d)x.classList.remove('open'); }); d.classList.toggle('open'); };
  }
  dd('bellBtn','bellDD'); dd('profBtn','profDD');
  document.addEventListener('click', function(){ document.querySelectorAll('.dd.open').forEach(function(x){ x.classList.remove('open'); }); });
  // live auto-refresh
  var lb=document.getElementById('liveBtn'), timer=null;
  function setLive(on){ try{localStorage.setItem('as_live',on?'1':'0');}catch(e){} if(lb)lb.classList.toggle('on',on); if(on){ timer=setTimeout(function(){location.reload();},20000);} else if(timer){clearTimeout(timer);} }
  var live=false; try{ live=localStorage.getItem('as_live')==='1'; }catch(e){} setLive(live);
  if(lb) lb.onclick=function(e){ e.preventDefault(); live=!live; setLive(live); };
  // Chart.js helper
  window._charts=window._charts||{};
  window.ASchart=function(id,cfg){
    var el=document.getElementById(id); if(!el||!window.Chart) return;
    applyChartTheme();
    var area=cfg.options&&cfg.options.as_area;
    if(area){ var ds=cfg.data.datasets[0]; var ctx=el.getContext('2d');
      var g=ctx.createLinearGradient(0,0,0,220); g.addColorStop(0,hexA(ds.borderColor,.28)); g.addColorStop(1,hexA(ds.borderColor,0)); ds.backgroundColor=g; }
    cfg.options=Object.assign({responsive:true,maintainAspectRatio:false,
      plugins:{legend:{display:(cfg.data.datasets.length>1)},tooltip:{mode:'index',intersect:false}},
      interaction:{mode:'nearest',intersect:false},
      scales:cfg.type==='doughnut'?{}:{x:{grid:{display:false},ticks:{maxTicksLimit:8}},y:{beginAtZero:true,grid:{color:Chart.defaults.borderColor},ticks:{maxTicksLimit:5}}}
    },cfg.options||{});
    if(cfg.type==='doughnut'){ cfg.options.scales={}; cfg.options.plugins.legend={position:'right'}; }
    window._charts[id]=new Chart(el,cfg);
  };
  function hexA(hex,a){ hex=(hex||'#8b5cf6').replace('#',''); if(hex.length===3)hex=hex.split('').map(function(c){return c+c;}).join(''); var n=parseInt(hex,16); return 'rgba('+((n>>16)&255)+','+((n>>8)&255)+','+(n&255)+','+a+')'; }
})();
</script>
<?php
page_foot();
