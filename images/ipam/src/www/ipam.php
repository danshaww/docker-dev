<?php
// ─── Data directory ───────────────────────────────────────────────────────────
define('DATA', '/data/');
if (!is_dir(DATA)) mkdir(DATA, 0755, true);

// ─── Helpers ──────────────────────────────────────────────────────────────────
function subnets_file()    { return DATA . 'subnets.csv'; }
function ips_file($id)     { return DATA . 'ips_' . preg_replace('/\W/', '', $id) . '.csv'; }
function safe($s)          { return htmlspecialchars($s ?? '', ENT_QUOTES); }
function uid()             { return bin2hex(random_bytes(5)); }
function now_ts()          { return date('Y-m-d H:i'); }

function read_csv($file) {
    if (!file_exists($file)) return [];
    $rows = []; $fh = fopen($file, 'r');
    $headers = fgetcsv($fh);
    while ($row = fgetcsv($fh))
        if (count($row) === count($headers))
            $rows[] = array_combine($headers, $row);
    fclose($fh); return $rows;
}

function write_csv($file, $headers, $rows) {
    $fh = fopen($file, 'w');
    fputcsv($fh, $headers);
    foreach ($rows as $r) fputcsv($fh, array_map(fn($h) => $r[$h] ?? '', $headers));
    fclose($fh);
}

function read_subnets() { return read_csv(subnets_file()); }
function write_subnets($rows) {
    write_csv(subnets_file(), ['id','name','network','prefix','gateway','vlan'], $rows);
}
function read_ips($id) { return read_csv(ips_file($id)); }
function write_ips($id, $rows) {
    usort($rows, fn($a,$b) => ip2long($a['ip']) <=> ip2long($b['ip']));
    write_csv(ips_file($id), ['id','ip','hostname','status','note','updated'], $rows);
}

function valid_ip($ip) { return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4); }
function ip_in_net($ip, $net, $pfx) {
    $mask = ~((1 << (32 - (int)$pfx)) - 1);
    return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
}
function all_ips_in_subnet($network, $prefix) {
    $prefix = (int)$prefix;
    $total  = 1 << (32 - $prefix);
    $base   = ip2long($network);
    $start  = $total > 2 ? 1 : 0;
    $end    = $total > 2 ? $total - 2 : $total - 1;
    $ips    = [];
    for ($i = $start; $i <= $end; $i++) $ips[] = long2ip($base + $i);
    return $ips;
}
function next_available_ip($network, $prefix, $allocated_ips) {
    $all  = all_ips_in_subnet($network, $prefix);
    if (empty($all)) return '';
    if (empty($allocated_ips)) return $all[0];
    $max  = 0;
    foreach ($allocated_ips as $r) { $n = ip2long($r['ip']); if ($n > $max) $max = $n; }
    $used = array_column($allocated_ips, 'ip');
    foreach ($all as $ip) { if (ip2long($ip) > $max && !in_array($ip, $used)) return $ip; }
    return '';
}

// ─── Handle POST actions ──────────────────────────────────────────────────────
$action        = $_POST['action'] ?? '';
$error         = '';
$active_subnet = $_GET['subnet'] ?? null;

if ($action === 'add_subnet') {
    $net  = trim($_POST['network'] ?? '');
    $pfx  = (int)($_POST['prefix'] ?? 0);
    $name = trim($_POST['name'] ?? '') ?: "$net/$pfx";
    if (!valid_ip($net))            $error = 'Invalid network address.';
    elseif ($pfx < 1 || $pfx > 32) $error = 'Prefix must be 1–32.';
    else {
        $subnets   = read_subnets();
        $subnets[] = ['id'=>uid(),'name'=>$name,'network'=>$net,'prefix'=>$pfx,
                      'gateway'=>trim($_POST['gateway']??''),'vlan'=>trim($_POST['vlan']??'')];
        write_subnets($subnets);
        header('Location: ipam.php'); exit;
    }
}

if ($action === 'delete_subnet') {
    $id = $_POST['id'] ?? '';
    write_subnets(array_values(array_filter(read_subnets(), fn($s) => $s['id'] !== $id)));
    if (file_exists(ips_file($id))) unlink(ips_file($id));
    header('Location: ipam.php'); exit;
}

if ($action === 'edit_subnet') {
    $id  = $_POST['id'] ?? '';
    $net = trim($_POST['network'] ?? '');
    $pfx = (int)($_POST['prefix'] ?? 0);
    if (!valid_ip($net))            $error = 'Invalid network address.';
    elseif ($pfx < 1 || $pfx > 32) $error = 'Prefix must be 1–32.';
    else {
        $subnets = read_subnets();
        foreach ($subnets as &$s) {
            if ($s['id'] === $id) {
                $s['name']    = trim($_POST['name'] ?? '') ?: "$net/$pfx";
                $s['network'] = $net;
                $s['prefix']  = $pfx;
                $s['gateway'] = trim($_POST['gateway'] ?? '');
                $s['vlan']    = trim($_POST['vlan'] ?? '');
            }
        }
        write_subnets($subnets);
        header("Location: ipam.php?subnet=$id"); exit;
    }
}

if ($action === 'add_ip') {
    $sid = $_POST['subnet_id'] ?? '';
    $ip  = trim($_POST['ip'] ?? '');
    $subnets = read_subnets();
    $subnet  = current(array_filter($subnets, fn($s) => $s['id'] === $sid));
    if (!$subnet)           $error = 'Subnet not found.';
    elseif (!valid_ip($ip)) $error = 'Invalid IP address.';
    elseif (!ip_in_net($ip, $subnet['network'], $subnet['prefix']))
                            $error = "IP is not within {$subnet['network']}/{$subnet['prefix']}.";
    else {
        $ips = read_ips($sid);
        if (array_filter($ips, fn($r) => $r['ip'] === $ip)) $error = 'IP already allocated.';
        else {
            $ips[] = ['id'=>uid(),'ip'=>$ip,'hostname'=>trim($_POST['hostname']??''),
                      'status'=>$_POST['status']??'active',
                      'note'=>trim($_POST['note']??''),'updated'=>now_ts()];
            write_ips($sid, $ips);
            header("Location: ipam.php?subnet=$sid"); exit;
        }
    }
    $active_subnet = $sid;
}

if ($action === 'add_dhcp_scope') {
    $sid   = $_POST['subnet_id'] ?? '';
    $start = trim($_POST['dhcp_start'] ?? '');
    $end   = trim($_POST['dhcp_end']   ?? '');
    $note  = trim($_POST['dhcp_note']  ?? 'DHCP');
    $subnets = read_subnets();
    $subnet  = current(array_filter($subnets, fn($s) => $s['id'] === $sid));
    if (!$subnet)                $error = 'Subnet not found.';
    elseif (!valid_ip($start))   $error = 'Invalid start IP.';
    elseif (!valid_ip($end))     $error = 'Invalid end IP.';
    elseif (ip2long($start) > ip2long($end)) $error = 'Start IP must be before end IP.';
    elseif (!ip_in_net($start, $subnet['network'], $subnet['prefix'])) $error = 'Start IP not in subnet.';
    elseif (!ip_in_net($end,   $subnet['network'], $subnet['prefix'])) $error = 'End IP not in subnet.';
    else {
        $ips      = read_ips($sid);
        $used_map = array_column($ips, 'ip');
        $added    = 0;
        for ($n = ip2long($start); $n <= ip2long($end); $n++) {
            $ip = long2ip($n);
            if (!in_array($ip, $used_map)) {
                $ips[] = ['id'=>uid(),'ip'=>$ip,'hostname'=>'',
                          'status'=>'dhcp','note'=>$note,'updated'=>now_ts()];
                $added++;
            }
        }
        write_ips($sid, $ips);
        header("Location: ipam.php?subnet=$sid"); exit;
    }
    $active_subnet = $sid;
}

if ($action === 'delete_ip') {
    $sid = $_POST['subnet_id'] ?? '';
    $iid = $_POST['id'] ?? '';
    write_ips($sid, array_values(array_filter(read_ips($sid), fn($r) => $r['id'] !== $iid)));
    header("Location: ipam.php?subnet=$sid"); exit;
}

if ($action === 'edit_ip') {
    $sid = $_POST['subnet_id'] ?? '';
    $iid = $_POST['id'] ?? '';
    $ips = read_ips($sid);
    foreach ($ips as &$r) {
        if ($r['id'] === $iid) {
            $r['hostname'] = trim($_POST['hostname']??'');
            $r['status']   = $_POST['status']??'active';
            $r['note']     = trim($_POST['note']??'');
            $r['updated']  = now_ts();
        }
    }
    write_ips($sid, $ips);
    header("Location: ipam.php?subnet=$sid"); exit;
}

// ─── Load data for display ────────────────────────────────────────────────────
$subnets       = read_subnets();
$subnet        = $active_subnet ? current(array_filter($subnets, fn($s) => $s['id'] === $active_subnet)) : null;
$ips           = $subnet ? read_ips($subnet['id']) : [];
$next_ip       = $subnet ? next_available_ip($subnet['network'], $subnet['prefix'], $ips) : '';
$STATUS_COLORS = ['active'=>'#2ecc71','reserved'=>'#f39c12','dhcp'=>'#4f8ef7','inactive'=>'#888'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IPAM</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:#f4f6f9; --bg2:#ffffff; --bg3:#f0f2f5;
    --sidebar:#1a1d2e; --sidebar2:#2a2f4a; --sidebar3:#2d3150;
    --stext:#ccd; --stext2:#778;
    --border:#e8eaf0; --border2:#dde;
    --text:#222; --text2:#444; --text3:#666; --text4:#888; --text5:#bbb;
    --input-bg:#ffffff;
    --shadow:0 1px 4px rgba(0,0,0,.08);
    --modal-shadow:0 8px 40px rgba(0,0,0,.18);
    --accent:#2563eb; --accent2:#1d4ed8;
    --green:#2ecc71;
    --red-bg:#fef2f2; --red-bdr:#fca5a5; --red-text:#b91c1c;
    --hover-row:#f5f7ff;
    --free-text:#bcc0cc;
    --overlay:rgba(0,0,0,.35);
  }
  @media (prefers-color-scheme: dark) { :root {
    --bg:#0d0f14; --bg2:#13161e; --bg3:#1c202c;
    --sidebar:#0a0c12; --sidebar2:#161926; --sidebar3:#1e2235;
    --stext:#aab; --stext2:#556;
    --border:#272b38; --border2:#2e3347;
    --text:#e8eaf2; --text2:#c0c4d8; --text3:#8b90a8; --text4:#666a80; --text5:#3a3e52;
    --input-bg:#1c202c;
    --shadow:0 1px 4px rgba(0,0,0,.4);
    --modal-shadow:0 8px 40px rgba(0,0,0,.6);
    --accent:#4f8ef7; --accent2:#3a6fd4;
    --green:#3ecf8e;
    --red-bg:#2a1010; --red-bdr:#7f2020; --red-text:#f87171;
    --hover-row:#1a1e2a;
    --free-text:#383c50;
    --overlay:rgba(0,0,0,.6);
  }}
  [data-theme="light"] { --bg:#f4f6f9;--bg2:#ffffff;--bg3:#f0f2f5;--sidebar:#1a1d2e;--sidebar2:#2a2f4a;--sidebar3:#2d3150;--stext:#ccd;--stext2:#778;--border:#e8eaf0;--border2:#dde;--text:#222;--text2:#444;--text3:#666;--text4:#888;--text5:#bbb;--input-bg:#ffffff;--shadow:0 1px 4px rgba(0,0,0,.08);--modal-shadow:0 8px 40px rgba(0,0,0,.18);--accent:#2563eb;--accent2:#1d4ed8;--green:#2ecc71;--red-bg:#fef2f2;--red-bdr:#fca5a5;--red-text:#b91c1c;--hover-row:#f5f7ff;--free-text:#bcc0cc;--overlay:rgba(0,0,0,.35); }
  [data-theme="dark"]  { --bg:#0d0f14;--bg2:#13161e;--bg3:#1c202c;--sidebar:#0a0c12;--sidebar2:#161926;--sidebar3:#1e2235;--stext:#aab;--stext2:#556;--border:#272b38;--border2:#2e3347;--text:#e8eaf2;--text2:#c0c4d8;--text3:#8b90a8;--text4:#666a80;--text5:#3a3e52;--input-bg:#1c202c;--shadow:0 1px 4px rgba(0,0,0,.4);--modal-shadow:0 8px 40px rgba(0,0,0,.6);--accent:#4f8ef7;--accent2:#3a6fd4;--green:#3ecf8e;--red-bg:#2a1010;--red-bdr:#7f2020;--red-text:#f87171;--hover-row:#1a1e2a;--free-text:#383c50;--overlay:rgba(0,0,0,.6); }

  body { font-family: system-ui, sans-serif; font-size: 16px; background: var(--bg); color: var(--text); }

  /* ── Layout ── */
  html, body { height: 100%; overflow: hidden; }
  .layout  { display: flex; height: 100vh; }
  .sidebar { width: 240px; background: var(--sidebar); color: var(--stext); flex-shrink: 0; display: flex; flex-direction: column; transition: transform .25s ease, width .25s ease; z-index: 50; }
  .sidebar.collapsed { width: 0; overflow: hidden; }

  /* Mobile: sidebar overlays content instead of pushing it */
  @media (max-width: 768px) {
    .sidebar { position: fixed; top: 0; left: 0; height: 100vh; transform: translateX(0); }
    .sidebar.collapsed { transform: translateX(-100%); width: 240px; overflow: visible; }
    .main { padding-top: 56px; }
  }

  /* Backdrop — only shown via JS adding .open, never automatically */
  .sidebar-backdrop { display: none; position: fixed; inset: 0; background: var(--overlay); z-index: 40; pointer-events: none; }
  .sidebar-backdrop.open { display: block; pointer-events: all; }

  /* Top bar shown on all screens — contains hamburger */
  .topbar { display: flex; align-items: center; gap: 10px; padding: 0 16px; height: 48px; background: var(--bg2); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 30; flex-shrink: 0; }
  .hamburger { background: none; border: 1px solid var(--border); border-radius: 5px; cursor: pointer; padding: 5px 8px; color: var(--text3); font-size: 1rem; line-height: 1; flex-shrink: 0; transition: background .15s; }
  .hamburger:hover { background: var(--bg3); color: var(--text); }
  .topbar-title { font-size: .85rem; font-weight: 600; color: var(--text2); }
  @media (min-width: 769px) {
    .topbar { display: none; }
    .main { padding-top: 0; }
    /* topbar shown via JS when sidebar is collapsed on desktop */
    .topbar.desktop-visible { display: flex; position: fixed; top: 0; left: 0; right: 0; z-index: 30; }
    .layout { padding-top: 0; }
    .main.topbar-shown { padding-top: 48px; }
  }
  .sidebar-top { padding: 14px 12px 10px; border-bottom: 1px solid var(--sidebar3); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .sidebar-top h1 { font-size: .95rem; font-weight: 700; color: #fff; letter-spacing: .03em; line-height: 1.2; }
  .sidebar-top h1 small { display: block; font-size: .68rem; font-weight: 400; color: var(--stext2); margin-top: 1px; }
  .sidebar-actions { padding: 8px; border-bottom: 1px solid var(--sidebar3); }
  .sidebar nav { flex: 1; padding: 8px; overflow-y: auto; }
  .sidebar a { display: block; padding: 7px 10px; border-radius: 5px; color: var(--stext); text-decoration: none; font-size: .82rem; margin-bottom: 2px; transition: background .15s; }
  .sidebar a:hover { background: var(--sidebar2); color: #fff; }
  .sidebar a.active { background: var(--accent); color: #fff; }
  .sidebar a .cidr { font-family: monospace; font-weight: 700; display: block; }
  .sidebar a .meta { font-size: .7rem; opacity: .7; }
  .main { flex: 1; padding: 24px; overflow-y: auto; height: 100vh; }
  .main-inner { max-width: 1400px; margin: 0 auto; }

  /* ── Sidebar buttons ── */
  .theme-btn { background: var(--sidebar2); border: 1px solid var(--sidebar3); color: var(--stext); border-radius: 5px; padding: 4px 7px; cursor: pointer; font-size: .75rem; white-space: nowrap; flex-shrink: 0; }
  .theme-btn:hover { background: var(--sidebar3); color: #fff; }
  .add-subnet-btn { display: flex; align-items: center; justify-content: center; gap: 5px; padding: 7px; border-radius: 5px; background: var(--sidebar2); border: 1px dashed var(--sidebar3); color: var(--stext); font-size: .78rem; font-weight: 600; cursor: pointer; transition: background .15s; width: 100%; font-family: inherit; }
  .add-subnet-btn:hover { background: var(--accent); border-color: var(--accent); color: #fff; }

  /* ── Stats ── */
  .stats { display: flex; gap: 10px; margin-bottom: 16px; }
  .stat  { flex: 1; background: var(--bg2); border-radius: 6px; box-shadow: var(--shadow); padding: 8px 12px; border: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
  .stat .val { font-size: 1.1rem; font-weight: 800; font-family: monospace; }
  .stat .lbl { font-size: .65rem; color: var(--text4); text-transform: uppercase; letter-spacing: .04em; line-height: 1.2; }

  /* ── Table ── */
  .card { background: var(--bg2); border-radius: 8px; box-shadow: var(--shadow); margin-bottom: 20px; border: 1px solid var(--border); overflow: hidden; }
  table { width: 100%; border-collapse: collapse; font-size: .8rem; }
  th { text-align: left; padding: 6px 10px; background: var(--bg3); font-size: .67rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); border-bottom: 2px solid var(--border); white-space: nowrap; }
  td { padding: 5px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr.allocated td { color: var(--text2); }
  tr.allocated:hover td { background: var(--hover-row); }
  tr.allocated td.mono { font-family: monospace; font-weight: 600; color: var(--text); }
  tr.free td { color: var(--free-text); }
  tr.free td.mono { font-family: monospace; color: var(--free-text); }
  tr.free:hover td { background: var(--hover-row); cursor: pointer; }

  .dot { display: inline-flex; align-items: center; gap: 4px; font-size: .76rem; font-weight: 600; }
  .dot::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

  /* ── Buttons ── */
  .btn { display: inline-flex; align-items: center; gap: 4px; padding: 5px 11px; border-radius: 5px; border: none; font-size: .75rem; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; transition: background .15s; }
  .btn-blue  { background: var(--accent); color: #fff; }
  .btn-blue:hover  { background: var(--accent2); }
  .btn-red   { background: transparent; color: var(--red-text); border: 1px solid transparent; }
  .btn-red:hover   { background: var(--red-bg); border-color: var(--red-bdr); }
  .btn-grey  { background: var(--bg3); color: var(--text2); border: 1px solid var(--border); }
  .btn-grey:hover  { filter: brightness(.95); }
  .btn-ghost { background: transparent; color: var(--accent); border: none; padding: 3px 6px; font-size: .73rem; font-weight: 600; cursor: pointer; font-family: inherit; border-radius: 4px; }
  .btn-ghost:hover { background: var(--bg3); }

  .error { background: var(--red-bg); border: 1px solid var(--red-bdr); color: var(--red-text); padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: .82rem; }

  .bar-wrap { background: var(--bg3); border-radius: 3px; height: 5px; overflow: hidden; width: 70px; display: inline-block; vertical-align: middle; }
  .bar-fill  { height: 100%; border-radius: 3px; }

  /* ── Overview grid ── */
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
  .grid-card { background: var(--bg2); border: 2px solid var(--border); border-radius: 8px; box-shadow: var(--shadow); padding: 14px; transition: border-color .15s; text-decoration: none; color: inherit; display: block; }
  .grid-card:hover { border-color: var(--accent); }
  .grid-card .cidr { font-family: monospace; font-weight: 700; font-size: .95rem; color: var(--accent); }
  .grid-card .name { font-size: .8rem; color: var(--text3); margin: 3px 0 10px; }

  /* ── Page header ── */
  .page-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; gap: 12px; flex-wrap: wrap; }
  .page-hdr h2 { font-size: 1.05rem; font-weight: 700; }
  .page-hdr .sub { font-family: monospace; font-size: .78rem; color: var(--text3); margin-top: 2px; }
  kbd { display: inline-block; padding: 1px 5px; border-radius: 3px; border: 1px solid var(--border2); background: var(--bg3); font-size: .7rem; font-family: monospace; color: var(--text3); }

  /* ── Modals ── */
  .modal-overlay { display: none; position: fixed; inset: 0; background: var(--overlay); backdrop-filter: blur(3px); z-index: 100; align-items: center; justify-content: center; }
  .modal-overlay.open { display: flex; }
  .modal { background: var(--bg2); border: 1px solid var(--border); border-radius: 10px; box-shadow: var(--modal-shadow); width: 440px; max-width: calc(100vw - 32px); max-height: calc(100vh - 48px); overflow-y: auto; animation: modal-in .15s ease; }
  @keyframes modal-in { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:none; } }
  .modal-hdr    { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px 12px; border-bottom: 1px solid var(--border); }
  .modal-hdr h3 { font-size: .9rem; font-weight: 700; }
  .modal-close  { background: none; border: none; cursor: pointer; color: var(--text3); font-size: 1.1rem; padding: 2px 6px; border-radius: 4px; line-height: 1; }
  .modal-close:hover { background: var(--bg3); color: var(--text); }
  .modal-body   { padding: 16px; }
  .modal-footer { padding: 10px 16px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; }

  /* ── Context menu ── */
  .ctx-menu { position: fixed; background: var(--bg2); border: 1px solid var(--border); border-radius: 7px; box-shadow: var(--modal-shadow); padding: 4px; z-index: 500; min-width: 150px; display: none; }
  .ctx-menu.open { display: block; }
  .ctx-item { display: flex; align-items: center; gap: 8px; padding: 7px 12px; border-radius: 5px; font-size: .82rem; cursor: pointer; color: var(--text2); white-space: nowrap; }
  .ctx-item:hover { background: var(--bg3); color: var(--text); }
  .ctx-item.danger { color: var(--red-text); }
  .ctx-item.danger:hover { background: var(--red-bg); }
  .ctx-sep { height: 1px; background: var(--border); margin: 3px 4px; }
  .field { margin-bottom: 12px; }
  .field:last-child { margin-bottom: 0; }
  .field label { display: block; font-size: .68rem; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
  .field input, .field select { width: 100%; padding: 7px 10px; border: 1px solid var(--border2); border-radius: 5px; font-size: .83rem; font-family: monospace; background: var(--input-bg); color: var(--text); transition: border-color .15s, box-shadow .15s; }
  .field input:focus, .field select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(79,142,247,.15); }
  .field input[readonly] { opacity: .55; cursor: default; }
  .field select option { background: var(--bg2); color: var(--text); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .field-hint { font-size: .7rem; color: var(--text4); margin-top: 3px; }
</style>
</head>
<body>
<div class="layout">

<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-top">
    <h1>IPAM <small>IP Address Manager</small></h1>
    <div style="display:flex;gap:6px;align-items:center">
      <button class="theme-btn" onclick="cycleTheme()" id="themeBtn">⚙</button>
      <button class="theme-btn" onclick="toggleSidebar()" title="Hide sidebar">◀</button>
    </div>
  </div>
  <div class="sidebar-actions">
    <button class="add-subnet-btn" onclick="openSubnetModal()">+ Add Subnet</button>
  </div>
  <nav>
    <a href="ipam.php" <?= !$subnet ? 'class="active"' : '' ?>>🌐 Overview</a>
    <?php foreach ($subnets as $s): ?>
      <a href="ipam.php?subnet=<?= $s['id'] ?>" <?= ($subnet && $subnet['id']===$s['id']) ? 'class="active"' : '' ?>>
        <span class="cidr"><?= safe($s['network']) ?>/<?= safe($s['prefix']) ?></span>
        <span class="meta"><?= safe($s['name']) ?><?= $s['vlan'] ? " · VLAN {$s['vlan']}" : '' ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>

<!-- __ Sidebar backdrop (mobile) __ -->
<div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

<!-- __ Top bar (mobile + collapsed desktop) __ -->
<div class="topbar" id="topbar">
  <button class="hamburger" onclick="toggleSidebar()">☰</button>
  <span class="topbar-title">IPAM<?= $subnet ? ' — ' . safe($subnet['name']) : '' ?></span>
</div>

<!-- __ Main __ -->
<main class="main" id="mainPanel">
<div class="main-inner">
  <?php if ($error): ?><div class="error">&#9888; <?= safe($error) ?></div><?php endif; ?>

  <?php if (!$subnet): ?>
  <div class="page-hdr"><h2>Overview</h2></div>
  <?php if (!$subnets): ?>
    <div class="card" style="padding:40px;text-align:center;color:var(--text4)">
      <div style="font-size:2rem;margin-bottom:8px">🌐</div>
      <div style="font-weight:600">No subnets yet</div>
      <div style="font-size:.8rem;margin-top:4px">Click <b>+ Add Subnet</b> in the sidebar to get started.</div>
    </div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($subnets as $s):
        $sips = read_ips($s['id']);
        $all  = all_ips_in_subnet($s['network'], $s['prefix']);
        $u    = count($all); $used = count($sips);
        $pct  = $u > 0 ? round($used / $u * 100) : 0;
        $col  = $pct >= 90 ? '#e74c3c' : ($pct >= 70 ? '#f39c12' : '#2ecc71');
    ?>
    <a class="grid-card" href="ipam.php?subnet=<?= $s['id'] ?>">
      <div class="cidr"><?= safe($s['network']) ?>/<?= safe($s['prefix']) ?></div>
      <div class="name"><?= safe($s['name']) ?><?= $s['vlan'] ? " · VLAN {$s['vlan']}" : '' ?></div>
      <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
      <div style="font-size:.72rem;color:var(--text4);margin-top:5px"><?= $used ?> / <?= $u ?> used (<?= $pct ?>%)</div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php else:
    $all_ips  = all_ips_in_subnet($subnet['network'], $subnet['prefix']);
    $used_map = [];
    foreach ($ips as $r) $used_map[$r['ip']] = $r;
    $total = count($all_ips); $used = count($ips); $free = $total - $used;
    $pct   = $total > 0 ? round($used / $total * 100) : 0;
    $col   = $pct >= 90 ? '#e74c3c' : ($pct >= 70 ? '#f39c12' : '#2ecc71');
  ?>

  <div class="page-hdr">
    <div>
      <h2><?= safe($subnet['name']) ?></h2>
      <div class="sub"><?= safe($subnet['network']) ?>/<?= safe($subnet['prefix']) ?>
        <?= $subnet['gateway'] ? " · GW {$subnet['gateway']}" : '' ?>
        <?= $subnet['vlan']    ? " · VLAN {$subnet['vlan']}"  : '' ?>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span style="font-size:.75rem;color:var(--text4)">Press <kbd>N</kbd> to add</span>
      <button class="btn btn-grey" onclick="openEditSubnetModal()">✏️ Edit</button>
      <button class="btn btn-grey" onclick="openDhcpModal()">⚡ DHCP Scope</button>
      <button class="btn btn-blue" onclick="openAddIp()">+ Add IP</button>
      <form method="post" onsubmit="return confirm('Delete this subnet and all its IPs?')" style="margin:0">
        <input type="hidden" name="action" value="delete_subnet">
        <input type="hidden" name="id"     value="<?= $subnet['id'] ?>">
        <button class="btn btn-red">🗑 Delete</button>
      </form>
    </div>
  </div>

  <div class="stats">
    <div class="stat">
      <div class="val" style="color:var(--accent)"><?= $used ?></div>
      <div class="lbl">Allocated</div>
    </div>
    <div class="stat">
      <div class="val" style="color:var(--green)"><?= $free ?></div>
      <div class="lbl">Available</div>
    </div>
    <div class="stat">
      <div class="val"><?= $total ?></div>
      <div class="lbl">Total IPs</div>
    </div>
    <div class="stat">
      <div class="val" style="color:<?= $col ?>"><?= $pct ?>%</div>
      <div class="lbl">Utilisation</div>
    </div>
  </div>

  <div class="card">
    <table>
      <thead><tr>
        <th>IP Address</th><th>Hostname</th><th>Status</th><th>Note</th><th>Updated</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($all_ips as $ip):
        if (isset($used_map[$ip])):
          $r  = $used_map[$ip];
          $sc = $STATUS_COLORS[$r['status']] ?? '#888';
      ?>
        <tr class="allocated" oncontextmenu="showCtx(event, <?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
          <td class="mono"><?= safe($ip) ?></td>
          <td class="mono"><?= safe($r['hostname']) ?: '—' ?></td>
          <td><span class="dot" style="color:<?= $sc ?>"><?= safe($r['status']) ?></span></td>
          <td><?= safe($r['note']) ?: '—' ?></td>
          <td style="font-size:.73rem;color:var(--text4)"><?= safe($r['updated']) ?></td>
          <td style="white-space:nowrap;text-align:right">
            <button class="btn-ghost" onclick="openEditIp(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Release <?= safe($ip) ?>?')">
              <input type="hidden" name="action"    value="delete_ip">
              <input type="hidden" name="subnet_id" value="<?= $subnet['id'] ?>">
              <input type="hidden" name="id"        value="<?= $r['id'] ?>">
              <button class="btn-ghost" style="color:var(--red-text)">Delete</button>
            </form>
          </td>
        </tr>
      <?php else: ?>
        <tr class="free" onclick="openAddIpPrefilled('<?= safe($ip) ?>')" oncontextmenu="showCtxFree(event, '<?= safe($ip) ?>')">
          <td class="mono"><?= safe($ip) ?></td>
          <td colspan="4" style="font-size:.75rem;font-style:italic">free</td>
          <td style="text-align:right">
            <button class="btn-ghost" onclick="event.stopPropagation();openAddIpPrefilled('<?= safe($ip) ?>')">+ Assign</button>
          </td>
        </tr>
      <?php endif; endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div><!-- /.main-inner -->
</main>
</div>

<!-- ── Context menu ── -->
<div class="ctx-menu" id="ctxMenu">
  <div class="ctx-item" id="ctxEdit"   onclick="ctxAction('edit')">✏️ Edit</div>
  <div class="ctx-item" id="ctxAssign" onclick="ctxAction('assign')">＋ Assign</div>
  <div class="ctx-sep"></div>
  <div class="ctx-item danger" id="ctxDelete" onclick="ctxAction('delete')">🗑 Delete</div>
</div>

<!-- ── Edit Subnet Modal ── -->
<div class="modal-overlay" id="editSubnetModal">
  <div class="modal">
    <div class="modal-hdr">
      <h3>Edit Subnet</h3>
      <button class="modal-close" onclick="closeModal('editSubnetModal')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" action="ipam.php?subnet=<?= $subnet ? safe($subnet['id']) : '' ?>" id="editSubnetForm">
        <input type="hidden" name="action" value="edit_subnet">
        <input type="hidden" name="id"     value="<?= $subnet ? safe($subnet['id']) : '' ?>">
        <div class="field">
          <label>Name</label>
          <input type="text" name="name" id="esn_name" placeholder="e.g. Office LAN">
        </div>
        <div class="field-row">
          <div class="field">
            <label>Network Address *</label>
            <input type="text" name="network" id="esn_network" placeholder="192.168.1.0" required>
          </div>
          <div class="field">
            <label>Prefix Length *</label>
            <input type="number" name="prefix" id="esn_prefix" placeholder="24" min="1" max="32" required>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Gateway</label>
            <input type="text" name="gateway" id="esn_gateway" placeholder="192.168.1.1">
          </div>
          <div class="field">
            <label>VLAN</label>
            <input type="text" name="vlan" id="esn_vlan" placeholder="100">
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-grey" onclick="closeModal('editSubnetModal')">Cancel</button>
      <button class="btn btn-blue" onclick="submitForm('editSubnetForm')">Save Changes</button>
    </div>
  </div>
</div>

<!-- ── IP Modal ── -->
<div class="modal-overlay" id="ipModal">
  <div class="modal">
    <div class="modal-hdr">
      <h3 id="ipModalTitle">Add IP Address</h3>
      <button class="modal-close" onclick="closeModal('ipModal')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" action="ipam.php?subnet=<?= $subnet ? safe($subnet['id']) : '' ?>" id="ipForm">
        <input type="hidden" name="action"    id="f_action"    value="add_ip">
        <input type="hidden" name="subnet_id" id="f_subnet_id" value="<?= $subnet ? safe($subnet['id']) : '' ?>">
        <input type="hidden" name="id"        id="f_id"        value="">
        <div class="field-row">
          <div class="field">
            <label>IP Address *</label>
            <input type="text" name="ip" id="f_ip" placeholder="<?= $subnet ? safe($subnet['network']) : '' ?>" required>
          </div>
          <div class="field">
            <label>Status</label>
            <select name="status" id="f_status">
              <option value="active">Active</option>
              <option value="reserved">Reserved</option>
              <option value="dhcp">DHCP</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
        <div class="field">
          <label>Hostname</label>
          <input type="text" name="hostname" id="f_hostname" placeholder="server-01.local">
        </div>
        <div class="field">
          <label>Note</label>
          <input type="text" name="note" id="f_note" placeholder="Optional note">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-grey" onclick="closeModal('ipModal')">Cancel</button>
      <button class="btn btn-blue" id="ipModalSubmit" onclick="submitForm('ipForm')">Add IP</button>
    </div>
  </div>
</div>

<!-- ── DHCP Scope Modal ── -->
<div class="modal-overlay" id="dhcpModal">
  <div class="modal">
    <div class="modal-hdr">
      <h3>Assign DHCP Scope</h3>
      <button class="modal-close" onclick="closeModal('dhcpModal')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" action="ipam.php?subnet=<?= $subnet ? safe($subnet['id']) : '' ?>" id="dhcpForm">
        <input type="hidden" name="action"    value="add_dhcp_scope">
        <input type="hidden" name="subnet_id" value="<?= $subnet ? safe($subnet['id']) : '' ?>">
        <div class="field-row">
          <div class="field">
            <label>Start IP *</label>
            <input type="text" name="dhcp_start" id="dhcp_start" placeholder="<?= $subnet ? safe($subnet['network']) : '192.168.1.100' ?>" required>
          </div>
          <div class="field">
            <label>End IP *</label>
            <input type="text" name="dhcp_end" id="dhcp_end" placeholder="<?= $subnet ? safe($subnet['network']) : '192.168.1.200' ?>" required>
          </div>
        </div>
        <div class="field">
          <label>Note</label>
          <input type="text" name="dhcp_note" id="dhcp_note" value="DHCP" placeholder="DHCP scope label">
          <div class="field-hint">All IPs in the range will be marked as DHCP with this note. Already-allocated IPs are skipped.</div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-grey" onclick="closeModal('dhcpModal')">Cancel</button>
      <button class="btn btn-blue" onclick="submitForm('dhcpForm')">Assign Scope</button>
    </div>
  </div>
</div>

<!-- ── Add Subnet Modal ── -->
<div class="modal-overlay" id="subnetModal">
  <div class="modal">
    <div class="modal-hdr">
      <h3>Add Subnet</h3>
      <button class="modal-close" onclick="closeModal('subnetModal')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" action="ipam.php" id="subnetForm">
        <input type="hidden" name="action" value="add_subnet">
        <div class="field">
          <label>Name</label>
          <input type="text" name="name" id="sn_name" placeholder="e.g. Office LAN">
        </div>
        <div class="field-row">
          <div class="field">
            <label>Network Address *</label>
            <input type="text" name="network" id="sn_network" placeholder="192.168.1.0" required>
          </div>
          <div class="field">
            <label>Prefix Length *</label>
            <input type="number" name="prefix" id="sn_prefix" placeholder="24" min="1" max="32" required>
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Gateway</label>
            <input type="text" name="gateway" id="sn_gateway" placeholder="192.168.1.1">
          </div>
          <div class="field">
            <label>VLAN</label>
            <input type="text" name="vlan" id="sn_vlan" placeholder="100">
          </div>
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-grey" onclick="closeModal('subnetModal')">Cancel</button>
      <button class="btn btn-blue" onclick="submitForm('subnetForm')">Add Subnet</button>
    </div>
  </div>
</div>

<script>
  // ── Sidebar toggle ────────────────────────────────────────────────────────
  const sidebar         = document.getElementById('sidebar');
  const backdrop        = document.getElementById('sidebarBackdrop');
  const topbar          = document.getElementById('topbar');
  const isMobile        = () => window.innerWidth <= 768;
  const SIDEBAR_KEY     = 'ipam-sidebar';

  function setSidebar(open) {
    const main = document.getElementById('mainPanel');
    if (open) {
      sidebar.classList.remove('collapsed');
      backdrop.classList.remove('open');
      topbar.classList.remove('desktop-visible');
      main.classList.remove('topbar-shown');
      topbar.style.display = '';
    } else {
      sidebar.classList.add('collapsed');
      if (isMobile()) {
        backdrop.classList.add('open');
      } else {
        backdrop.classList.remove('open');
        topbar.classList.add('desktop-visible');
        main.classList.add('topbar-shown');
        topbar.style.display = 'flex';
      }
    }
    if (!isMobile()) localStorage.setItem(SIDEBAR_KEY, open ? '1' : '0');
  }

  function toggleSidebar() {
    setSidebar(sidebar.classList.contains('collapsed'));
  }

  // Restore desktop preference; default open
  if (!isMobile()) {
    const pref = localStorage.getItem(SIDEBAR_KEY);
    if (pref === '0') setSidebar(false);
    else setSidebar(true);
  } else {
    // Mobile: start collapsed
    setSidebar(false);
  }

  // Auto-collapse/expand on resize
  window.addEventListener('resize', () => {
    if (isMobile()) {
      setSidebar(false);
    } else {
      const pref = localStorage.getItem(SIDEBAR_KEY);
      setSidebar(pref !== '0');
    }
  });

  // Close sidebar on mobile when navigating
  if (isMobile()) {
    document.querySelectorAll('.sidebar a').forEach(a => {
      a.addEventListener('click', () => setSidebar(false));
    });
  }
  const html  = document.documentElement;
  const tBtn  = document.getElementById('themeBtn');
  const MODES = ['system','light','dark'];
  const ICONS = { system:'⚙ Auto', light:'☀️ Light', dark:'🌙 Dark' };
  let mode    = localStorage.getItem('ipam-theme') || 'system';
  function applyTheme() {
    if (mode === 'system') html.removeAttribute('data-theme');
    else html.dataset.theme = mode;
    tBtn.textContent = ICONS[mode];
  }
  function cycleTheme() {
    mode = MODES[(MODES.indexOf(mode) + 1) % MODES.length];
    localStorage.setItem('ipam-theme', mode);
    applyTheme();
  }
  applyTheme();

  // ── Modal helpers ──────────────────────────────────────────────────────────
  let activeModal = null;

  function openModal(id) {
    if (activeModal) closeModal(activeModal);
    activeModal = id;
    document.getElementById(id).classList.add('open');
  }
  function closeModal(id) {
    document.getElementById(id).classList.remove('open');
    if (activeModal === id) activeModal = null;
  }

  // Close on backdrop click
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('mousedown', e => {
      if (e.target === overlay) closeModal(overlay.id);
    });
  });

  // ── Subnet modal ───────────────────────────────────────────────────────────
  function openSubnetModal() {
    document.getElementById('sn_name').value    = '';
    document.getElementById('sn_network').value = '';
    document.getElementById('sn_prefix').value  = '';
    document.getElementById('sn_gateway').value = '';
    document.getElementById('sn_vlan').value    = '';
    openModal('subnetModal');
    setTimeout(() => document.getElementById('sn_name').focus(), 50);
  }

  // ── DHCP scope modal ───────────────────────────────────────────────────────
  function openDhcpModal() {
    document.getElementById('dhcp_start').value = '';
    document.getElementById('dhcp_end').value   = '';
    document.getElementById('dhcp_note').value  = 'DHCP';
    openModal('dhcpModal');
    setTimeout(() => document.getElementById('dhcp_start').focus(), 50);
  }

  // ── Edit subnet modal ─────────────────────────────────────────────────────
  <?php if ($subnet): ?>
  const SUBNET_DATA = <?= json_encode($subnet) ?>;
  <?php else: ?>
  const SUBNET_DATA = null;
  <?php endif; ?>

  function openEditSubnetModal() {
    if (!SUBNET_DATA) return;
    document.getElementById('esn_name').value    = SUBNET_DATA.name    || '';
    document.getElementById('esn_network').value = SUBNET_DATA.network || '';
    document.getElementById('esn_prefix').value  = SUBNET_DATA.prefix  || '';
    document.getElementById('esn_gateway').value = SUBNET_DATA.gateway || '';
    document.getElementById('esn_vlan').value    = SUBNET_DATA.vlan    || '';
    openModal('editSubnetModal');
    setTimeout(() => document.getElementById('esn_name').focus(), 50);
  }

  // ── Context menu ──────────────────────────────────────────────────────────
  const ctxMenu = document.getElementById('ctxMenu');
  let ctxRow    = null;

  function showCtx(e, r) {
    e.preventDefault();
    ctxRow = { type: 'allocated', data: r };
    document.getElementById('ctxEdit').style.display   = '';
    document.getElementById('ctxAssign').style.display = 'none';
    document.getElementById('ctxDelete').style.display = '';
    positionCtx(e);
  }

  function showCtxFree(e, ip) {
    e.preventDefault();
    e.stopPropagation();
    ctxRow = { type: 'free', data: ip };
    document.getElementById('ctxEdit').style.display   = 'none';
    document.getElementById('ctxAssign').style.display = '';
    document.getElementById('ctxDelete').style.display = 'none';
    positionCtx(e);
  }

  function positionCtx(e) {
    ctxMenu.classList.add('open');
    // Must be visible to measure; position off-screen first
    ctxMenu.style.left = '-9999px';
    ctxMenu.style.top  = '-9999px';
    requestAnimationFrame(() => {
      const mw = ctxMenu.offsetWidth;
      const mh = ctxMenu.offsetHeight;
      ctxMenu.style.left = (e.clientX + mw > window.innerWidth  ? e.clientX - mw : e.clientX) + 'px';
      ctxMenu.style.top  = (e.clientY + mh > window.innerHeight ? e.clientY - mh : e.clientY) + 'px';
    });
  }

  function ctxAction(action) {
    ctxMenu.classList.remove('open');
    if (!ctxRow) return;
    if (action === 'edit'   && ctxRow.type === 'allocated') openEditIp(ctxRow.data);
    if (action === 'assign' && ctxRow.type === 'free')      openAddIpPrefilled(ctxRow.data);
    if (action === 'delete' && ctxRow.type === 'allocated') {
      if (confirm('Release ' + ctxRow.data.ip + '?')) {
        sessionStorage.setItem(SCROLL_KEY, mainEl.scrollTop);
        const f = document.createElement('form');
        f.method = 'post';
        f.innerHTML = '<input name="action" value="delete_ip">'
          + '<input name="subnet_id" value="<?= $subnet ? safe($subnet['id']) : '' ?>">'
          + '<input name="id" value="' + ctxRow.data.id + '">';
        document.body.appendChild(f);
        f.submit();
      }
    }
    ctxRow = null;
  }

  document.addEventListener('click',  () => ctxMenu.classList.remove('open'));
  document.addEventListener('scroll', () => ctxMenu.classList.remove('open'), true);

  // ── IP modal ───────────────────────────────────────────────────────────────
  const NEXT_IP = <?= json_encode($next_ip) ?>;

  function openAddIp() {
    document.getElementById('ipModalTitle').textContent  = 'Add IP Address';
    document.getElementById('ipModalSubmit').textContent = 'Add IP';
    document.getElementById('f_action').value            = 'add_ip';
    document.getElementById('f_id').value                = '';
    document.getElementById('f_ip').value                = NEXT_IP;
    document.getElementById('f_ip').readOnly             = false;
    document.getElementById('f_hostname').value          = '';
    document.getElementById('f_status').value            = 'active';
    document.getElementById('f_note').value              = '';
    openModal('ipModal');
    setTimeout(() => {
      (NEXT_IP ? document.getElementById('f_hostname') : document.getElementById('f_ip')).focus();
    }, 50);
  }

  function openAddIpPrefilled(ip) {
    openAddIp();
    document.getElementById('f_ip').value    = ip;
    document.getElementById('f_ip').readOnly = true;
    setTimeout(() => document.getElementById('f_hostname').focus(), 50);
  }

  function openEditIp(r) {
    document.getElementById('ipModalTitle').textContent  = 'Edit IP Address';
    document.getElementById('ipModalSubmit').textContent = 'Save Changes';
    document.getElementById('f_action').value            = 'edit_ip';
    document.getElementById('f_id').value                = r.id;
    document.getElementById('f_ip').value                = r.ip;
    document.getElementById('f_ip').readOnly             = true;
    document.getElementById('f_hostname').value          = r.hostname || '';
    document.getElementById('f_status').value            = r.status   || 'active';
    document.getElementById('f_note').value              = r.note     || '';
    openModal('ipModal');
    setTimeout(() => document.getElementById('f_hostname').focus(), 50);
  }

  // ── Scroll preservation ───────────────────────────────────────────────────
  const mainEl    = document.querySelector('.main');
  const SCROLL_KEY = 'ipam-scroll-<?= $subnet ? safe($subnet['id']) : 'overview' ?>';

  // Restore immediately so there's no visible jump
  const savedScroll = sessionStorage.getItem(SCROLL_KEY);
  if (savedScroll !== null) {
    mainEl.scrollTop = parseInt(savedScroll, 10);
    sessionStorage.removeItem(SCROLL_KEY);
  }

  // Wrap submit so scroll is always saved, even when called programmatically
  function submitForm(id) {
    sessionStorage.setItem(SCROLL_KEY, mainEl.scrollTop);
    document.getElementById(id).submit();
  }

  // Catch regular form submits (delete buttons etc.)
  document.querySelectorAll('form').forEach(f => {
    f.addEventListener('submit', () => sessionStorage.setItem(SCROLL_KEY, mainEl.scrollTop));
  });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && activeModal) {
      e.preventDefault();
      closeModal(activeModal);
      return;
    }

    // Enter inside a modal input/select submits that modal's form
    if (e.key === 'Enter' && activeModal) {
      const tag = e.target.tagName;
      if (tag === 'INPUT' || tag === 'SELECT') {
        e.preventDefault();
        if (activeModal === 'ipModal')          submitForm('ipForm');
        if (activeModal === 'dhcpModal')        submitForm('dhcpForm');
        if (activeModal === 'subnetModal')      submitForm('subnetForm');
        if (activeModal === 'editSubnetModal')  submitForm('editSubnetForm');
      }
      return;
    }

    // Global shortcut — only when not focused in an input and no modal open
    if (!activeModal && !['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) {
      <?php if ($subnet): ?>
      if (e.key === 'n' || e.key === 'N') { e.preventDefault(); openAddIp(); }
      <?php endif; ?>
    }
  });
</script>
</body>
</html>