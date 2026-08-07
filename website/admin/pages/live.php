<?php
/** Live Users — real-time KPIs + event feed via AJAX polling (no reload). */
declare(strict_types=1);
$m = metrics_overview(resolve_range('1'));
echo '<div class="phead"><div><h1>' . h(t('Live Users')) . '</h1><p>' . h(t('sub_live')) . '</p></div>'
   . '<span class="online"><span class="pulse"></span><span id="lvOnline">' . nf($m['online']) . '</span> online now</span></div>';

echo '<div class="grid kpis" id="lvKpis" style="margin-top:16px">'
   . '<div class="kpi"><div class="kr"><span class="bo">' . icon('activity') . '</span></div><div class="n" data-k="online">' . nf($m['online']) . '</div><div class="l">Online now</div></div>'
   . '<div class="kpi"><div class="kr"><span class="bo">' . icon('pulse') . '</span></div><div class="n" data-k="sessions">' . nf($m['sessions']) . '</div><div class="l">Active sessions (30m)</div></div>'
   . '<div class="kpi"><div class="kr"><span class="bo">' . icon('user') . '</span></div><div class="n" data-k="active_today">' . nf($m['active_today']) . '</div><div class="l">Users today</div></div>'
   . '<div class="kpi"><div class="kr"><span class="bo">' . icon('layers') . '</span></div><div class="n" data-k="events">' . nf($m['events']) . '</div><div class="l">Events today</div></div>'
   . '</div>';

echo '<div class="card pad" style="margin-top:18px"><div class="ctitle">' . icon('activity') . 'Events per minute</div><div class="csub">Rolling last 30 minutes · updates live</div>'
   . '<div class="chartbox"><canvas id="lvChart"></canvas></div></div>';

echo '<div class="card" style="margin-top:16px"><div class="pad" style="padding-bottom:6px"><div class="ctitle">' . icon('pulse') . 'Live event feed</div><div class="csub">Newest actions across all installs</div></div>'
   . '<table class="tbl"><thead><tr><th>When (UTC)</th><th>Feature</th><th>Version</th><th>Install</th></tr></thead><tbody id="lvFeed"><tr><td colspan="4"><div class="skel skelrow"></div><div class="skel skelrow"></div><div class="skel skelrow"></div></td></tr></tbody></table></div>';
?>
<script>
(function(){
  var chart=null;
  function ensureChart(series){
    if(!window.Chart) return;
    if(chart){ chart.data.labels=series.labels; chart.data.datasets[0].data=series.values; chart.update('none'); return; }
    var el=document.getElementById('lvChart'); if(!el) return;
    if(window.ASchart){ window.ASchart('lvChart',{type:'bar',data:{labels:series.labels,datasets:[{label:'Events/min',data:series.values,backgroundColor:'#8b5cf6',borderRadius:4,maxBarThickness:14}]}}); chart=window._charts['lvChart']; }
  }
  window.__lvChartUpdate=ensureChart;
})();
</script>
<script>
(function(){
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function tick(){
    fetch('admin/api/api.php?action=live',{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
      if(!d.ok) return;
      var on=document.getElementById('lvOnline'); if(on) on.textContent=d.kpis.online;
      document.querySelectorAll('#lvKpis .n[data-k]').forEach(function(el){ var k=el.getAttribute('data-k'); if(d.kpis[k]!=null) el.textContent=Number(d.kpis[k]).toLocaleString(); });
      var st=document.getElementById('syncTime'); if(st) st.textContent='synced '+new Date().toISOString().substr(11,5)+' UTC';
      if(d.series && window.__lvChartUpdate) window.__lvChartUpdate(d.series);
      var tb=document.getElementById('lvFeed');
      if(tb && d.events){ tb.innerHTML = d.events.length? d.events.map(function(e){
        return '<tr><td class="mut">'+esc(e.when)+'</td><td><b>'+esc(e.event)+'</b></td><td class="mut">'+esc(e.version)+'</td><td><a class="mono" href="?page=install&install='+encodeURIComponent(e.install)+'">'+esc(e.install.substr(0,8))+'</a></td></tr>';
      }).join('') : '<tr><td colspan="4" class="empty">No recent events.</td></tr>'; }
    }).catch(function(){});
  }
  tick(); var iv=setInterval(tick,5000);
  window.addEventListener('beforeunload',function(){ clearInterval(iv); });
})();
</script>
<?php
