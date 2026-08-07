<?php
/**
 * ApneScan Admin — top navigation bar: burger, breadcrumb, global search,
 * online-now, refresh, live toggle, notifications, theme, profile.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

function render_topbar(string $title, int $online, array $notifs): void
{
    $u = current_user();
    $initials = strtoupper(substr($u['username'] ?? 'A', 0, 1));
    $unread = 0; foreach ($notifs as $x) if (($x['sev'] ?? '') !== 'good') $unread++;
    $q = h($_GET['q'] ?? '');

    $lang = current_lang();
    echo '<header class="top">'
       . '<div class="burger" id="burger" title="Toggle sidebar" tabindex="0" aria-label="Toggle sidebar">' . icon('menu') . '</div>'
       . '<div class="crumb">' . h(t('nav_dashboard')) . ' <span class="faint">/</span> <b>' . h($title) . '</b></div>';

    echo '<form class="gsearch" method="get" role="search"><input type="hidden" name="page" value="search">'
       . icon('search') . '<input name="q" value="' . $q . '" placeholder="' . h(t('search_ph')) . '" aria-label="Global search"></form>';

    echo '<div class="tspacer"></div>';
    if ($online > 0) echo '<span class="online"><span class="pulse"></span>' . nf($online) . ' ' . h(t('online')) . '</span>';
    echo '<span class="sync" id="syncTime">' . h(t('synced')) . ' ' . dt(time(), 'H:i') . ' IST</span>';
    // language toggle
    echo '<div class="chips" style="padding:3px"><a class="chip' . ($lang === 'en' ? ' on' : '') . '" href="?lang=en" style="padding:5px 9px" title="English">EN</a>'
       . '<a class="chip' . ($lang === 'hi' ? ' on' : '') . '" href="?lang=hi" style="padding:5px 9px" title="हिन्दी">हि</a></div>';
    echo '<a class="tbtn live" id="liveBtn" href="#" title="Live auto-refresh">' . icon('refresh') . '</a>';

    // Notifications dropdown
    echo '<div style="position:relative"><a class="tbtn" id="bellBtn" href="#" title="Notifications" aria-label="Notifications">' . icon('bell')
       . ($unread > 0 ? '<span class="dot"></span>' : '') . '</a><div class="dd" id="bellDD"><div class="h">Notifications</div>';
    foreach ($notifs as $x) {
        $cmap = ['bad' => 'r', 'warn' => 'w', 'good' => 'g', 'info' => ''];
        $pc = $cmap[$x['sev']] ?? '';
        echo '<a class="it" href="' . h($x['link']) . '" style="color:inherit"><span class="i pill ' . $pc . '" style="width:30px;height:30px;border-radius:9px">' . icon($x['sev'] === 'good' ? 'check' : 'alert') . '</span>'
           . '<span><b style="font-size:12.5px">' . h($x['title']) . '</b><br><span class="faint" style="font-size:11.5px">' . h($x['sub']) . '</span></span></a>';
    }
    echo '</div></div>';

    echo '<a class="tbtn" id="themeBtn" href="#" title="Toggle theme">' . icon('moon') . '</a>';
    // Profile
    echo '<div style="position:relative"><a class="tbtn" id="profBtn" href="#" title="Profile" style="width:auto;padding:0 6px 0 0;gap:8px;display:flex;align-items:center"><span class="avatar">' . h($initials) . '</span></a>'
       . '<div class="dd" id="profDD" style="width:210px"><div class="h">' . h($u['username'] ?? 'admin') . '<br><span class="faint" style="font-weight:500;font-size:11px">' . h($GLOBALS['ROLE_LABELS'][$u['role']] ?? $u['role']) . '</span></div>'
       . '<a class="it" href="?page=settings" style="color:inherit">' . icon('settings') . ' ' . h(t('Settings')) . '</a>'
       . '<a class="it" href="?page=audit" style="color:inherit">' . icon('shield') . ' ' . h(t('Audit Log')) . '</a>'
       . '<a class="it" href="?logout=1" style="color:var(--bad)">' . icon('logout') . ' ' . h(t('log_out')) . '</a></div></div>';

    echo '</header>';
}
