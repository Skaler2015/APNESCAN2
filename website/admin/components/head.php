<?php
/**
 * ApneScan Admin — HTML head + global design system (enterprise theme).
 * page_head() opens the document; page_foot() closes it.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

function page_head(string $title, bool $withCharts = false): void
{
    $css = admin_css();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="color-scheme" content="light dark">'
       . '<title>' . h($title) . ' · ApneScan Admin</title>'
       . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">';
    if ($withCharts) {
        echo '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';
        echo '<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>';
    }
    echo '<style>' . $css . '</style></head><body>';
    echo '<script>(function(){try{var t=localStorage.getItem("as_theme");if(t)document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
}

function page_foot(): void { echo '</body></html>'; }

function admin_css(): string
{
    return <<<'CSS'
:root{--bg:#f5f3fb;--bg2:#efeaf8;--surface:#ffffff;--surface2:#f8f6fe;--glass:rgba(255,255,255,.72);
  --ink:#18132a;--muted:#645d7e;--faint:#9891ad;--line:#e8e3f3;--line2:#efecf7;
  --brand:#6d28d9;--brand2:#9333ea;--accent:#8b5cf6;--good:#16a34a;--warn:#d97706;--bad:#dc2626;--info:#2563eb;
  --shadow:0 1px 2px rgba(20,12,45,.04),0 6px 20px rgba(20,12,45,.06);--shadow-lg:0 12px 44px rgba(76,29,149,.14);
  --sb:264px;--r:16px}
@media(prefers-color-scheme:dark){:root{--bg:#0a0810;--bg2:#0d0a16;--surface:#141020;--surface2:#1a1428;--glass:rgba(20,16,32,.72);
  --ink:#f2eff9;--muted:#a49dbb;--faint:#6f6885;--line:#241e34;--line2:#1e1830;
  --brand:#a78bfa;--brand2:#c084fc;--accent:#8b5cf6;--shadow:0 1px 2px rgba(0,0,0,.3),0 6px 20px rgba(0,0,0,.4);--shadow-lg:0 12px 44px rgba(0,0,0,.55)}}
:root[data-theme=light]{color-scheme:light}
:root[data-theme=dark]{--bg:#0a0810;--bg2:#0d0a16;--surface:#141020;--surface2:#1a1428;--glass:rgba(20,16,32,.72);
  --ink:#f2eff9;--muted:#a49dbb;--faint:#6f6885;--line:#241e34;--line2:#1e1830;--brand:#a78bfa;--brand2:#c084fc;--accent:#8b5cf6;
  --shadow:0 1px 2px rgba(0,0,0,.3),0 6px 20px rgba(0,0,0,.4);--shadow-lg:0 12px 44px rgba(0,0,0,.55);color-scheme:dark}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}
body{margin:0;color:var(--ink);font-family:Inter,system-ui,'Segoe UI',Roboto,sans-serif;-webkit-font-smoothing:antialiased;
  background:radial-gradient(1100px 560px at 88% -8%,color-mix(in srgb,var(--brand) 13%,transparent),transparent 60%),linear-gradient(180deg,var(--bg),var(--bg2));min-height:100vh}
h1,h2,h3,h4{font-family:'Bricolage Grotesque',Inter,sans-serif;letter-spacing:-.015em;margin:0}
a{color:var(--brand);text-decoration:none}
:focus-visible{outline:2px solid var(--brand);outline-offset:2px;border-radius:6px}
.skelrow{height:38px;margin:6px 0;border-radius:8px}
.mut{color:var(--muted)}.faint{color:var(--faint)}.tabnum{font-variant-numeric:tabular-nums}
svg.i{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
/* ---- App layout ---- */
.app{display:grid;grid-template-columns:var(--sb) 1fr;min-height:100vh;transition:grid-template-columns .22s ease}
.app.collapsed{--sb:74px}
/* ---- Sidebar ---- */
.side{position:sticky;top:0;height:100vh;border-right:1px solid var(--line);background:var(--glass);backdrop-filter:blur(16px) saturate(1.3);display:flex;flex-direction:column;overflow:hidden;z-index:40}
.side .brand{display:flex;align-items:center;gap:11px;padding:18px 18px 14px;font-family:'Bricolage Grotesque';font-weight:800;font-size:17px;white-space:nowrap}
.side .brand .logo{min-width:34px;width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));display:grid;place-items:center;color:#fff;font-weight:800;box-shadow:0 4px 14px color-mix(in srgb,var(--brand) 45%,transparent)}
.side .brand small{display:block;font-size:10.5px;font-weight:600;color:var(--muted);font-family:Inter}
.nav{flex:1;overflow-y:auto;padding:6px 12px 20px;scrollbar-width:thin}
.nav::-webkit-scrollbar{width:6px}.nav::-webkit-scrollbar-thumb{background:var(--line);border-radius:6px}
.navsec{font-size:10px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;color:var(--faint);padding:16px 12px 6px;white-space:nowrap}
.navlink{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:11px;color:var(--muted);font-size:13.5px;font-weight:600;white-space:nowrap;margin-bottom:2px;transition:background .15s,color .15s}
.navlink:hover{background:var(--surface2);color:var(--ink)}
.navlink.on{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand2));box-shadow:0 4px 14px color-mix(in srgb,var(--brand) 34%,transparent)}
.navlink svg{min-width:18px}
.navlink .txt{overflow:hidden;text-overflow:ellipsis}
.navlink .badge{margin-left:auto;font-size:10.5px;font-weight:800;background:var(--bad);color:#fff;border-radius:20px;padding:1px 7px}
.navlink.on .badge{background:rgba(255,255,255,.25)}
.app.collapsed .side .brand small,.app.collapsed .navsec,.app.collapsed .navlink .txt,.app.collapsed .navlink .badge{display:none}
.app.collapsed .navlink{justify-content:center;padding:11px}
/* ---- Main ---- */
.main{min-width:0;display:flex;flex-direction:column}
.top{position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:14px;padding:12px 24px;border-bottom:1px solid var(--line);background:var(--glass);backdrop-filter:blur(16px) saturate(1.3)}
.burger{display:grid;place-items:center;width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--muted);cursor:pointer}
.burger:hover{color:var(--brand)}
.crumb{font-size:13px;color:var(--muted);font-weight:500}.crumb b{color:var(--ink);font-weight:700}
.gsearch{flex:1;max-width:420px;position:relative}
.gsearch input{width:100%;padding:9px 13px 9px 38px;border:1px solid var(--line);border-radius:11px;background:var(--surface2);color:var(--ink);font:inherit;font-size:13px}
.gsearch input:focus{outline:none;border-color:var(--brand)}
.gsearch svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--faint)}
.tspacer{flex:1}
.tbtn{position:relative;display:grid;place-items:center;width:38px;height:38px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--muted);cursor:pointer;text-decoration:none}
.tbtn:hover{color:var(--brand);border-color:color-mix(in srgb,var(--brand) 40%,var(--line))}
.tbtn .dot{position:absolute;top:8px;right:9px;width:7px;height:7px;border-radius:50%;background:var(--bad)}
.tbtn.live.on{color:#fff;background:linear-gradient(135deg,var(--good),#22c55e);border-color:transparent}
.sync{font-size:11.5px;color:var(--faint);white-space:nowrap}
.online{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:20px;background:color-mix(in srgb,var(--good) 13%,transparent);color:var(--good);font-weight:700;font-size:12px}
.pulse{width:8px;height:8px;border-radius:50%;background:var(--good);animation:pl 2s infinite}
@keyframes pl{0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--good) 55%,transparent)}70%{box-shadow:0 0 0 8px transparent}100%{box-shadow:0 0 0 0 transparent}}
@media(prefers-reduced-motion:reduce){.pulse{animation:none}}
.avatar{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;display:grid;place-items:center;font-weight:800;font-size:14px}
.content{padding:24px;max-width:1320px;width:100%;margin:0 auto}
.phead{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:4px}
.phead h1{font-size:23px}.phead p{margin:4px 0 0;color:var(--muted);font-size:13px}
/* ---- Cards / grids ---- */
.grid{display:grid;gap:16px}
.kpis{grid-template-columns:repeat(auto-fill,minmax(196px,1fr))}
.g2{grid-template-columns:1fr 1fr}.g3{grid-template-columns:repeat(3,1fr)}.g2w{grid-template-columns:1.5fr 1fr}
@media(max-width:1000px){.g2,.g3,.g2w{grid-template-columns:1fr}}
.card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--shadow);overflow:hidden}
.card .pad{padding:18px 20px}
.ctitle{font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:8px;margin:0}
.ctitle svg{color:var(--brand)}
.csub{font-size:12px;color:var(--faint);margin:2px 0 0}
.sec{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:30px 0 12px;display:flex;align-items:center;gap:8px}
.sec svg{width:15px;height:15px;color:var(--brand)}
/* KPI */
.kpi{position:relative;background:var(--surface);border:1px solid var(--line);border-radius:var(--r);padding:16px 17px;box-shadow:var(--shadow);overflow:hidden}
.kpi::after{content:"";position:absolute;inset:0 0 auto 0;height:3px;background:linear-gradient(90deg,var(--brand),var(--brand2))}
.kpi .kr{display:flex;align-items:center;justify-content:space-between}
.kpi .bo{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:color-mix(in srgb,var(--brand) 12%,transparent);color:var(--brand)}
.kpi .n{font-size:27px;font-weight:800;line-height:1.05;margin-top:11px;font-family:'Bricolage Grotesque';font-variant-numeric:tabular-nums}
.kpi .l{color:var(--muted);font-size:12px;margin-top:3px;font-weight:500}
.delta{font-size:11px;font-weight:700;padding:2px 7px;border-radius:20px}
.delta.up{color:var(--good);background:color-mix(in srgb,var(--good) 14%,transparent)}
.delta.down{color:var(--bad);background:color-mix(in srgb,var(--bad) 14%,transparent)}
.delta.flat{color:var(--faint);background:color-mix(in srgb,var(--faint) 14%,transparent)}
/* bars */
.blist{display:flex;flex-direction:column;gap:11px}
.brow{display:grid;grid-template-columns:150px 1fr auto;align-items:center;gap:12px}
.bname{font-size:12.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btrack{height:9px;border-radius:20px;background:color-mix(in srgb,var(--brand) 9%,transparent);overflow:hidden}
.bfill{height:100%;border-radius:20px;background:linear-gradient(90deg,var(--brand),var(--accent))}
.bval{font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--muted);min-width:34px;text-align:right}
.tag{font-size:10.5px;font-weight:700;color:var(--brand);background:color-mix(in srgb,var(--brand) 12%,transparent);padding:1px 6px;border-radius:6px;margin-left:6px}
/* table */
table.tbl{width:100%;border-collapse:collapse;font-size:13px}
.tbl th,.tbl td{text-align:left;padding:10px 14px;border-bottom:1px solid var(--line)}
.tbl th{color:var(--faint);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.tbl tbody tr:last-child td{border-bottom:0}.tbl tbody tr:hover{background:var(--surface2)}
.tbl td.num,.tbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;background:color-mix(in srgb,var(--accent) 14%,transparent);color:var(--brand)}
.pill.g{background:color-mix(in srgb,var(--good) 15%,transparent);color:var(--good)}
.pill.r{background:color-mix(in srgb,var(--bad) 15%,transparent);color:var(--bad)}
.pill.w{background:color-mix(in srgb,var(--warn) 15%,transparent);color:var(--warn)}
.mono{font-family:ui-monospace,'SF Mono',Menlo,monospace;font-size:11.5px}
a.mono{color:var(--brand)}a.mono:hover{text-decoration:underline}
/* chart */
.chartbox{position:relative;height:230px}
.chartbox.sm{height:180px}
.charttools{position:absolute;top:-4px;right:0;display:flex;gap:4px;opacity:0;transition:opacity .15s;z-index:2}
.chartbox:hover .charttools{opacity:1}
.ctool{width:26px;height:26px;border-radius:7px;border:1px solid var(--line);background:var(--surface);color:var(--muted);cursor:pointer;display:grid;place-items:center;padding:0}
.ctool:hover{color:var(--brand);border-color:var(--brand)}
.ctool svg{width:14px;height:14px}
/* heatmap */
.heat{display:grid;grid-template-columns:repeat(24,1fr);gap:4px}
.hc{aspect-ratio:1;border-radius:5px}
.hlabels{display:grid;grid-template-columns:repeat(24,1fr);gap:4px;margin-top:6px;font-size:9px;color:var(--faint);text-align:center}
/* forms */
label.fl{display:block;font-size:12.5px;font-weight:600;margin:14px 0 6px}
.inp,select.inp,textarea.inp{width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font:inherit;font-size:13px;background:var(--surface2);color:var(--ink)}
textarea.inp{min-height:80px;resize:vertical}
.inp:focus{outline:none;border-color:var(--brand)}
.btn{padding:10px 18px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;font-size:13.5px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;text-decoration:none}
.btn.ghost{background:var(--surface2);color:var(--muted);border:1px solid var(--line)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.chk{display:flex;align-items:center;gap:9px;margin-top:14px;font-size:13px;font-weight:600}
.chk input{width:17px;height:17px;accent-color:var(--brand)}
.flash{padding:11px 14px;border-radius:11px;font-size:13px;font-weight:600;margin-bottom:16px}
.flash.ok{background:color-mix(in srgb,var(--good) 13%,transparent);color:var(--good)}
.flash.err{background:color-mix(in srgb,var(--bad) 12%,transparent);color:var(--bad)}
.empty{padding:34px;text-align:center;color:var(--faint);font-size:13px}
.empty svg{width:30px;height:30px;opacity:.5;margin-bottom:8px}
.filters{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.filters .inp{width:auto}
.chips{display:inline-flex;gap:4px;background:var(--surface2);border:1px solid var(--line);border-radius:11px;padding:4px}
.chip{padding:6px 12px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--muted)}
.chip.on{color:#fff;background:linear-gradient(135deg,var(--brand),var(--brand2))}
.pager{display:flex;gap:6px;align-items:center;justify-content:flex-end;padding:12px 14px;font-size:12.5px}
.pager a,.pager span{padding:5px 11px;border-radius:8px;border:1px solid var(--line);color:var(--muted);font-weight:600}
.pager a:hover{color:var(--brand);border-color:var(--brand)}
.pager .cur{background:var(--brand);color:#fff;border-color:transparent}
/* skeleton */
.skel{background:linear-gradient(90deg,var(--line2),var(--surface2),var(--line2));background-size:200% 100%;animation:sk 1.3s infinite;border-radius:8px}
@keyframes sk{0%{background-position:200% 0}100%{background-position:-200% 0}}
/* auth */
.authwrap{min-height:100vh;display:grid;place-items:center;padding:20px}
.authbox{width:100%;max-width:420px;background:var(--surface);border:1px solid var(--line);border-radius:20px;padding:32px;box-shadow:var(--shadow-lg)}
.authbox .brand{display:flex;align-items:center;gap:12px;font-family:'Bricolage Grotesque';font-weight:800;font-size:19px;margin-bottom:6px}
.authbox .brand .logo{width:38px;height:38px;border-radius:11px;background:linear-gradient(135deg,var(--brand),var(--brand2));display:grid;place-items:center;color:#fff}
.authbox .brand small{display:block;font-size:11px;color:var(--muted);font-weight:600;font-family:Inter}
.bigbtn{margin-top:22px;width:100%;padding:12px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--brand),var(--brand2));color:#fff;font-weight:700;font-size:15px;cursor:pointer}
.foot{text-align:center;color:var(--faint);font-size:11.5px;padding:26px 0 8px}
/* notif dropdown */
.dd{position:absolute;top:48px;right:0;width:320px;background:var(--surface);border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow-lg);z-index:60;overflow:hidden;display:none}
.dd.open{display:block}
.dd .h{padding:12px 15px;border-bottom:1px solid var(--line);font-weight:700;font-size:13px}
.dd .it{padding:11px 15px;border-bottom:1px solid var(--line2);font-size:12.5px;display:flex;gap:10px}
.dd .it:last-child{border-bottom:0}
.dd .it . i{width:30px;height:30px;border-radius:9px;display:grid;place-items:center;flex:none}
@media(max-width:720px){.app{grid-template-columns:0 1fr}.side{position:fixed;left:0;top:0;width:var(--sb);--sb:264px;transform:translateX(-100%);transition:transform .2s}.app.mobopen .side{transform:none}.gsearch{display:none}.content{padding:16px}}
CSS;
}
