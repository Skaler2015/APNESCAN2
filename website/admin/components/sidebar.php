<?php
/**
 * ApneScan Admin — collapsible sidebar navigation.
 * Expects $active (current page key) and optional $badges map.
 *
 * @package ApneScan\Admin
 */
declare(strict_types=1);

function render_sidebar(string $active, array $badges = []): void
{
    // [key, label, icon, capability]
    $nav = [
        ['__', 'Dashboard'],
        ['dashboard', 'Overview', 'grid', 'view'],
        ['analytics', 'Analytics', 'chart', 'view'],
        ['live', 'Live Users', 'activity', 'view'],
        ['__', 'Product'],
        ['scanner', 'Scanner Analytics', 'scan', 'view'],
        ['ocr', 'OCR Analytics', 'text', 'view'],
        ['events', 'Events & Feedback', 'pulse', 'view'],
        ['reports', 'Reports', 'report', 'reports'],
        ['__', 'Audience'],
        ['versions', 'Versions', 'layers', 'view'],
        ['devices', 'Devices', 'monitor', 'view'],
        ['os', 'Operating Systems', 'monitor', 'view'],
        ['users', 'Admins & Roles', 'role', 'users'],
        ['__', 'System'],
        ['notifications', 'Notifications', 'bell', 'view'],
        ['backup', 'Backup', 'backup', 'backup'],
        ['export', 'Export', 'download', 'export'],
        ['audit', 'Audit Log', 'shield', 'audit'],
        ['health', 'System Health', 'heart', 'view'],
        ['settings', 'Settings', 'settings', 'settings'],
        ['help', 'Help', 'help', 'view'],
    ];
    echo '<aside class="side"><div class="brand"><span class="logo">A</span><span>ApneScan<small>Admin Console</small></span></div><nav class="nav">';
    foreach ($nav as $it) {
        if ($it[0] === '__') { echo '<div class="navsec">' . h($it[1]) . '</div>'; continue; }
        [$key, $label, $ic, $cap] = $it;
        if (!can($cap)) continue;
        $on = $key === $active ? ' on' : '';
        $bd = isset($badges[$key]) && $badges[$key] > 0 ? '<span class="badge">' . nf($badges[$key]) . '</span>' : '';
        echo '<a class="navlink' . $on . '" href="?page=' . h($key) . '" title="' . h($label) . '">'
           . icon($ic) . '<span class="txt">' . h($label) . '</span>' . $bd . '</a>';
    }
    echo '</nav></aside>';
}
