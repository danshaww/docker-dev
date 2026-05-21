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
    write_csv(ips_file($id), ['id','ip','hostname','mac','status','note','updated'], $rows);
}

function valid_ip($ip)  { return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4); }
function ip_in_net($ip, $net, $pfx) {
    $mask = ~((1 << (32 - (int)$pfx)) - 1);
    return (ip2long($ip) & $mask) === (ip2long($net) & $mask);
}
function usable($pfx) { $t = 1 << (32 - (int)$pfx); return $t <= 2 ? $t : $t - 2; }

// ─── Handle POST actions ──────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$error  = '';
$active_subnet = $_GET['subnet'] ?? null;

if ($action === 'add_subnet') {
    $net  = trim($_POST['network'] ?? '');
    $pfx  = (int)($_POST['prefix'] ?? 0);
    $name = trim($_POST['name'] ?? '') ?: "$net/$pfx";
    if (!valid_ip($net))               $error = 'Invalid network address.';
    elseif ($pfx < 1 || $pfx > 32)    $error = 'Prefix must be 1–32.';
    else {
        $subnets = read_subnets();
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

if ($action === 'add_ip') {
    $sid = $_POST['subnet_id'] ?? '';
    $ip  = trim($_POST['ip'] ?? '');
    $subnets = read_subnets();
    $subnet  = current(array_filter($subnets, fn($s) => $s['id'] === $sid));
    if (!$subnet)              $error = 'Subnet not found.';
    elseif (!valid_ip($ip))    $error = 'Invalid IP address.';
    elseif (!ip_in_net($ip, $subnet['network'], $subnet['prefix']))
                               $error = "IP is not within {$subnet['network']}/{$subnet['prefix']}.";
    else {
        $ips = read_ips($sid);
        if (array_filter($ips, fn($r) => $r['ip'] === $ip))
            $error = 'IP already allocated.';
        else {
            $ips[] = ['id'=>uid(),'ip'=>$ip,'hostname'=>trim($_POST['hostname']??''),
                      'mac'=>trim($_POST['mac']??''),'status'=>$_POST['status']??'active',
                      'note'=>trim($_POST['note']??''),'updated'=>now_ts()];
            write_ips($sid, $ips);
            header("Location: ipam.php?subnet=$sid"); exit;
        }
    }
    $active_subnet = $_POST['subnet_id'];
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
            $r['mac']      = trim($_POST['mac']??'');
            $r['status']   = $_POST['status']??'active';
            $r['note']     = trim($_POST['note']??'');
            $r['updated']  = now_ts();
        }
    }
    write_ips($sid, $ips);
    header("Location: ipam.php?subnet=$sid"); exit;
}

// ─── Load data for display ───────────────────────────────────────────────────
$subnets = read_subnets();
$subnet  = $active_subnet ? current(array_filter($subnets, fn($s) => $s['id'] === $active_subnet)) : null;
$ips     = $subnet ? read_ips($subnet['id']) : [];
$STATUS_COLORS = ['active'=>'#2ecc71','reserved'=>'#f39c12','dhcp'=>'#4f8ef7','inactive'=>'#666'];
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
    --bg:       #f4f6f9;
    --bg2:      #ffffff;
    --bg3:      #f0f2f5;
    --sidebar:  #1a1d2e;
    --sidebar2: #2a2f4a;
    --sidebar3: #2d3150;
    --stext:    #ccd;
    --stext2:   #778;
    --border:   #e8eaf0;
    --border2:  #dde;
    --text:     #222;
    --text2:    #444;
    --text3:    #666;
    --text4:    #888;
    --text5:    #aaa;
    --input-bg: #ffffff;
    --shadow:   0 1px 4px rgba(0,0,0,.08);
    --modal-shadow: 0 8px 40px rgba(0,0,0,.18);
    --accent:   #2563eb;
    --accent2:  #1d4ed8;
    --green:    #2ecc71;
    --red-bg:   #fef2f2;
    --red-bdr:  #fca5a5;
    --red-text: #b91c1c;
    --hover-row:#fafbff;
    --overlay:  rgba(0,0,0,.35);
  }

  @media (prefers-color-scheme: dark) {
    :root {
      --bg:       #0d0f14;
      --bg2:      #13161e;
      --bg3:      #1c202c;
      --sidebar:  #0a0c12;
      --sidebar2: #161926;
      --sidebar3: #1e2235;
      --stext:    #aab;
      --stext2:   #556;
      --border:   #272b38;
      --border2:  #2e3347;
      --text:     #e8eaf2;
      --text2:    #c0c4d8;
      --text3:    #8b90a8;
      --text4:    #666a80;
      --text5:    #444860;
      --input-bg: #1c202c;
      --shadow:   0 1px 4px rgba(0,0,0,.4);
      --modal-shadow: 0 8px 40px rgba(0,0,0,.6);
      --accent:   #4f8ef7;
      --accent2:  #3a6fd4;
      --green:    #3ecf8e;
      --red-bg:   #2a1010;
      --red-bdr:  #7f2020;
      --red-text: #f87171;
      --hover-row:#1a1e2a;
      --overlay:  rgba(0,0,0,.6);
    }
  }

  /* manual overrides */
  [data-theme="light"] { 
    --bg:#f4f6f9;--bg2:#ffffff;--bg3:#f0f2f5;--sidebar:#1a1d2e;--sidebar2:#2a2f4a;--sidebar3:#2d3150;--stext:#ccd;--stext2:#778;--border:#e8eaf0;--border2:#dde;--text:#222;--text2:#444;--text3:#666;--text4:#888;--text5:#aaa;--input-bg:#ffffff;--shadow:0 1px 4px rgba(0,0,0,.08);--modal-shadow:0 8px 40px rgba(0,0,0,.18);--accent:#2563eb;--accent2:#1d4ed8;--green:#2ecc71;--red-bg:#fef2f2;--red-bdr:#fca5a5;--red-text:#b91c1c;--hover-row:#fafbff;--overlay:rgba(0,0,0,.35);
  }
  [data-theme="dark"] {
    --bg:#0d0f14;--bg2:#13161e;--bg3:#1c202c;--sidebar:#0a0c12;--sidebar2:#161926;--sidebar3:#1e2235;--stext:#aab;--stext2:#556;--border:#272b38;--border2:#2e3347;--text:#e8eaf2;--text2:#c0c4d8;--text3:#8b90a8;--text4:#666a80;--text5:#444860;--input-bg:#1c202c;--shadow:0 1px 4px rgba(0,0,0,.4);--modal-shadow:0 8px 40px rgba(0,0,0,.6);--accent:#4f8ef7;--accent2:#3a6fd4;--green:#3ecf8e;--red-bg:#2a1010;--red-bdr:#7f2020;--red-text:#f87171;--hover-row:#1a1e2a;--overlay:rgba(0,0,0,.6);
  }

  body { font-family: system-ui, sans-serif; font-size: 14px; background: var(--bg); color: var(--text); min-height: 100vh; }

  /* ── Layout ── */
  .layout { display: flex; min-height: 100vh; }
  .sidebar { width: 240px; background: var(--sidebar); color: var(--stext); flex-shrink: 0; display: flex; flex-direction: column; }
  .sidebar-top { padding: 16px 16px 12px; border-bottom: 1px solid var(--sidebar3); display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .sidebar-top h1 { font-size: 1rem; font-weight: 700; color: #fff; letter-spacing: .03em; line-height: 1.2; }
  .sidebar-top h1 small { display: block; font-size: .7rem; font-weight: 400; color: var(--stext2); margin-top: 2px; }
  .sidebar nav { flex: 1; padding: 8px; overflow-y: auto; }
  .sidebar a { display: block; padding: 8px 10px; border-radius: 5px; color: var(--stext); text-decoration: none; font-size: .82rem; margin-bottom: 2px; transition: background .15s; }
  .sidebar a:hover { background: var(--sidebar2); color: #fff; }
  .sidebar a.active { background: var(--accent); color: #fff; }
  .sidebar a .cidr { font-family: monospace; font-weight: 700; display: block; }
  .sidebar a .meta { font-size: .7rem; opacity: .7; }
  .sidebar footer { padding: 12px; border-top: 1px solid var(--sidebar3); }
  .main { flex: 1; padding: 24px; overflow-y: auto; }

  /* ── Theme toggle ── */
  .theme-btn { background: var(--sidebar2); border: 1px solid var(--sidebar3); color: var(--stext); border-radius: 5px; padding: 4px 7px; cursor: pointer; font-size: .78rem; white-space: nowrap; flex-shrink: 0; }
  .theme-btn:hover { background: var(--sidebar3); color: #fff; }

  /* ── Cards ── */
  .card { background: var(--bg2); border-radius: 8px; box-shadow: var(--shadow); padding: 20px; margin-bottom: 20px; border: 1px solid var(--border); }

  /* ── Stats ── */
  .stats { display: flex; gap: 12px; margin-bottom: 20px; }
  .stat { flex: 1; background: var(--bg2); border-radius: 8px; box-shadow: var(--shadow); padding: 14px 16px; border: 1px solid var(--border); }
  .stat .val { font-size: 1.6rem; font-weight: 800; font-family: monospace; }
  .stat .lbl { font-size: .7rem; color: var(--text4); text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }

  /* ── Table ── */
  table { width: 100%; border-collapse: collapse; font-size: .82rem; }
  th { text-align: left; padding: 8px 10px; background: var(--bg3); font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: var(--text3); border-bottom: 2px solid var(--border); }
  td { padding: 9px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text2); }
  tr:last-child td { border-bottom: none; }
  tbody tr:hover td { background: var(--hover-row); }
  td.mono { font-family: monospace; font-weight: 600; color: var(--text); }

  /* ── Status dot ── */
  .dot { display: inline-flex; align-items: center; gap: 5px; font-size: .78rem; font-weight: 600; }
  .dot::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

  /* ── Buttons ── */
  .btn { display: inline-flex; align-items: center; gap: 4px; padding: 7px 13px; border-radius: 5px; border: none; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; transition: background .15s; }
  .btn-blue  { background: var(--accent); color: #fff; }
  .btn-blue:hover { background: var(--accent2); }
  .btn-red   { background: transparent; color: var(--red-text); border: 1px solid transparent; }
  .btn-red:hover { background: var(--red-bg); border-color: var(--red-bdr); }
  .btn-grey  { background: var(--bg3); color: var(--text2); border: 1px solid var(--border); }
  .btn-grey:hover { filter: brightness(.95); }
  .btn-sm    { padding: 5px 10px; font-size: .74rem; }

  /* ── Error ── */
  .error { background: var(--red-bg); border: 1px solid var(--red-bdr); color: var(--red-text); padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: .82rem; }

  /* ── Progress bar ── */
  .bar-wrap { background: var(--bg3); border-radius: 3px; height: 6px; overflow: hidden; width: 80px; display: inline-block; vertical-align: middle; }
  .bar-fill  { height: 100%; border-radius: 3px; }

  /* ── Overview grid ── */
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
  .grid-card { background: var(--bg2); border: 2px solid var(--border); border-radius: 8px; box-shadow: var(--shadow); padding: 14px; cursor: pointer; transition: border-color .15s; text-decoration: none; color: inherit; display: block; }
  .grid-card:hover { border-color: var(--accent); }
  .grid-card .cidr { font-family: monospace; font-weight: 700; font-size: .95rem; color: var(--accent); }
  .grid-card .name { font-size: .8rem; color: var(--text3); margin: 3px 0 10px; }

  /* ── Page header row ── */
  .page-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
  .page-hdr h2 { font-size: 1.05rem; font-weight: 700; color: var(--text); }
  .page-hdr .sub { font-family: monospace; font-size: .82rem; color: var(--text3); margin-top: 2px; }

  /* ── Keyboard hint ── */
  kbd { display: inline-block; padding: 1px 5px; border-radius: 3px; border: 1px solid var(--border2); background: var(--bg3); font-size: .72rem; font-family: monospace; color: var(--text3); }

  /* ── Modal overlay ── */
  .modal-overlay {
    display: none;
    position: fixed; inset: 0;
    background: var(--overlay);
    backdrop-filter: blur(3px);
    z-index: 100;
    align-items: center;
    justify-content: center;
  }
  .modal-overlay.open { display: flex; }

  .modal {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: var(--modal-shadow);
    width: 480px;
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 48px);
    overflow-y: auto;
    animation: modal-in .15s ease;
  }
  @keyframes modal-in { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: none; } }

  .modal-hdr {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 18px 14px;
    border-bottom: 1px solid var(--border);
  }
  .modal-hdr h3 { font-size: .95rem; font-weight: 700; }
  .modal-close { background: none; border: none; cursor: pointer; color: var(--text3); font-size: 1.1rem; padding: 2px 6px; border-radius: 4px; line-height: 1; }
  .modal-close:hover { background: var(--bg3); color: var(--text); }
  .modal-body { padding: 18px; }
  .modal-footer { padding: 12px 18px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 8px; }

  /* ── Form fields inside modal ── */
  .field { margin-bottom: 14px; }
  .field label { display: block; font-size: .7rem; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: .05em; margin-bottom: 5px; }
  .field input, .field select {
    width: 100%; padding: 8px 10px;
    border: 1px solid var(--border2); border-radius: 5px;
    font-size: .85rem; font-family: monospace;
    background: var(--input-bg); color: var(--text);
    transition: border-color .15s, box-shadow .15s;
  }
  .field input:focus, .field select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(79,142,247,.15); }
  .field input[readonly] { opacity: .6; cursor: default; }
  .field select option { background: var(--bg2); color: var(--text); }
  .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .field-error { font-size: .75rem; color: var(--red-text); margin-top: 4px; display: none; }
  .field-error.show { display: block; }

  /* sidebar inputs */
  .sidebar .field input, .sidebar .field select {
    background: var(--sidebar2); border-color: var(--sidebar3); color: #fff; font-size: .8rem;
  }
  .sidebar .field input::placeholder { color: var(--stext2); }
  .sidebar .field label { color: var(--stext2); }
</style>
</head>
<body>
<div class="layout">

<!-- ── Sidebar ── -->
<aside class="sidebar">
  <div class="sidebar-top">
    <h1>IPAM <small>IP Address Manager</small></h1>
    <button class="theme-btn" onclick="cycleTheme()" id="themeBtn">⚙</button>
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
  <footer>
    <div style="color:var(--stext2);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px">Add Subnet</div>
    <form method="post" action="ipam.php" style="display:flex;flex-direction:column;gap:8px">
      <input type="hidden" name="action" value="add_subnet">
      <div class="field" style="margin:0"><input type="text" name="name" placeholder="Name (optional)"></div>
      <div style="display:flex;gap:6px">
        <div class="field" style="margin:0;flex:2"><input type="text" name="network" placeholder="192.168.1.0" required></div>
        <div class="field" style="margin:0;width:58px"><input type="number" name="prefix" placeholder="24" min="1" max="32" required></div>
      </div>
      <div style="display:flex;gap:6px">
        <div class="field" style="margin:0;flex:1"><input type="text" name="gateway" placeholder="Gateway"></div>
        <div class="field" style="margin:0;width:58px"><input type="text" name="vlan" placeholder="VLAN"></div>
      </div>
      <button class="btn btn-blue" style="width:100%;justify-content:center">+ Add Subnet</button>
    </form>
  </footer>
</aside>

<!-- ── Main ── -->
<main class="main">
  <?php if ($error): ?><div class="error">⚠ <?= safe($error) ?></div><?php endif; ?>

  <?php if (!$subnet): ?>
  <!-- OVERVIEW -->
  <div class="page-hdr">
    <h2>Overview</h2>
  </div>
  <?php if (!$subnets): ?>
    <div class="card" style="text-align:center;padding:40px;color:var(--text4)">
      <div style="font-size:2rem;margin-bottom:8px">🌐</div>
      <div style="font-weight:600">No subnets yet</div>
      <div style="font-size:.8rem;margin-top:4px">Add your first subnet using the sidebar.</div>
    </div>
  <?php else: ?>
  <div class="grid">
    <?php foreach ($subnets as $s):
        $sips = read_ips($s['id']);
        $u    = usable($s['prefix']);
        $pct  = $u > 0 ? round(count($sips) / $u * 100) : 0;
        $col  = $pct >= 90 ? '#e74c3c' : ($pct >= 70 ? '#f39c12' : '#2ecc71');
    ?>
    <a class="grid-card" href="ipam.php?subnet=<?= $s['id'] ?>">
      <div class="cidr"><?= safe($s['network']) ?>/<?= safe($s['prefix']) ?></div>
      <div class="name"><?= safe($s['name']) ?><?= $s['vlan'] ? " · VLAN {$s['vlan']}" : '' ?></div>
      <div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
      <div style="font-size:.72rem;color:var(--text4);margin-top:5px"><?= count($sips) ?> / <?= $u ?> used (<?= $pct ?>%)</div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- SUBNET DETAIL -->
  <?php
    $u    = usable($subnet['prefix']);
    $used = count($ips);
    $free = max(0, $u - $used);
    $pct  = $u > 0 ? round($used / $u * 100) : 0;
    $col  = $pct >= 90 ? '#e74c3c' : ($pct >= 70 ? '#f39c12' : '#2ecc71');
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
      <button class="btn btn-blue" onclick="openAddIp()">+ Add IP</button>
      <form method="post" onsubmit="return confirm('Delete this subnet and all its IPs?')" style="margin:0">
        <input type="hidden" name="action" value="delete_subnet">
        <input type="hidden" name="id"     value="<?= $subnet['id'] ?>">
        <button class="btn btn-red">🗑 Delete subnet</button>
      </form>
    </div>
  </div>

  <div class="stats">
    <div class="stat"><div class="val" style="color:var(--accent)"><?= $used ?></div><div class="lbl">Allocated</div></div>
    <div class="stat"><div class="val" style="color:var(--green)"><?= $free ?></div><div class="lbl">Available</div></div>
    <div class="stat"><div class="val"><?= $u ?></div><div class="lbl">Usable IPs</div></div>
    <div class="stat"><div class="val" style="color:<?= $col ?>"><?= $pct ?>%</div><div class="lbl">Utilisation</div></div>
  </div>

  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead><tr>
        <th>IP Address</th><th>Hostname</th><th>MAC</th><th>Status</th><th>Note</th><th>Updated</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$ips): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text5);padding:28px">
          No IPs allocated yet — press <kbd>N</kbd> or click <b>+ Add IP</b> to begin.
        </td></tr>
      <?php else: foreach ($ips as $r):
        $sc = $STATUS_COLORS[$r['status']] ?? '#888';
      ?>
        <tr>
          <td class="mono"><?= safe($r['ip']) ?></td>
          <td class="mono"><?= safe($r['hostname']) ?: '—' ?></td>
          <td class="mono" style="font-size:.75rem"><?= safe($r['mac']) ?: '—' ?></td>
          <td><span class="dot" style="color:<?= $sc ?>"><?= safe($r['status']) ?></span></td>
          <td><?= safe($r['note']) ?: '—' ?></td>
          <td style="color:var(--text5);font-size:.75rem"><?= safe($r['updated']) ?></td>
          <td style="white-space:nowrap">
            <button class="btn btn-grey btn-sm"
              onclick="openEditIp(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">Edit</button>
            <form method="post" style="display:inline" onsubmit="return confirm('Release <?= safe($r['ip']) ?>?')">
              <input type="hidden" name="action"    value="delete_ip">
              <input type="hidden" name="subnet_id" value="<?= $subnet['id'] ?>">
              <input type="hidden" name="id"        value="<?= $r['id'] ?>">
              <button class="btn btn-red btn-sm">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</main>
</div>

<!-- ── IP Modal ── -->
<div class="modal-overlay" id="ipModal" onclick="if(event.target===this)closeIpModal()">
  <div class="modal">
    <div class="modal-hdr">
      <h3 id="modalTitle">Add IP Address</h3>
      <button class="modal-close" onclick="closeIpModal()">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" action="ipam.php?subnet=<?= $subnet ? safe($subnet['id']) : '' ?>" id="ipForm">
        <input type="hidden" name="action"    id="f_action"    value="add_ip">
        <input type="hidden" name="subnet_id" id="f_subnet_id" value="<?= $subnet ? safe($subnet['id']) : '' ?>">
        <input type="hidden" name="id"        id="f_id"        value="">

        <div class="field">
          <label>IP Address *</label>
          <input type="text" name="ip" id="f_ip" placeholder="<?= $subnet ? safe($subnet['network']) : '0.0.0.0' ?>" required>
          <div class="field-error" id="f_ip_err"></div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>Hostname</label>
            <input type="text" name="hostname" id="f_hostname" placeholder="server-01.local">
          </div>
          <div class="field">
            <label>Owner</label>
            <input type="text" name="owner" id="f_owner" placeholder="Alice">
          </div>
        </div>
        <div class="field-row">
          <div class="field">
            <label>MAC Address</label>
            <input type="text" name="mac" id="f_mac" placeholder="AA:BB:CC:DD:EE:FF">
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
        <div class="field" style="margin-bottom:0">
          <label>Note</label>
          <input type="text" name="note" id="f_note" placeholder="Optional note">
        </div>
      </form>
    </div>
    <div class="modal-footer">
      <button class="btn btn-grey" onclick="closeIpModal()">Cancel</button>
      <button class="btn btn-blue" onclick="document.getElementById('ipForm').submit()" id="modalSubmit">Add IP</button>
    </div>
  </div>
</div>

<script>
  // ── Theme ──────────────────────────────────────────────────────────────────
  // Modes: 'system' | 'light' | 'dark'
  const html = document.documentElement;
  const btn  = document.getElementById('themeBtn');
  const MODES = ['system','light','dark'];
  const ICONS = { system:'⚙ Auto', light:'☀️ Light', dark:'🌙 Dark' };

  let mode = localStorage.getItem('ipam-theme') || 'system';

  function applyTheme() {
    if (mode === 'system') {
      html.removeAttribute('data-theme');
    } else {
      html.dataset.theme = mode;
    }
    btn.textContent = ICONS[mode];
  }

  function cycleTheme() {
    mode = MODES[(MODES.indexOf(mode) + 1) % MODES.length];
    localStorage.setItem('ipam-theme', mode);
    applyTheme();
  }

  applyTheme();

  // ── IP Modal ───────────────────────────────────────────────────────────────
  const modal = document.getElementById('ipModal');

  function openAddIp() {
    document.getElementById('modalTitle').textContent   = 'Add IP Address';
    document.getElementById('modalSubmit').textContent  = 'Add IP';
    document.getElementById('f_action').value           = 'add_ip';
    document.getElementById('f_id').value               = '';
    document.getElementById('f_ip').value               = '';
    document.getElementById('f_ip').readOnly            = false;
    document.getElementById('f_hostname').value         = '';
    document.getElementById('f_owner').value            = '';
    document.getElementById('f_mac').value              = '';
    document.getElementById('f_status').value           = 'active';
    document.getElementById('f_note').value             = '';
    modal.classList.add('open');
    setTimeout(() => document.getElementById('f_ip').focus(), 50);
  }

  function openEditIp(r) {
    document.getElementById('modalTitle').textContent   = 'Edit IP Address';
    document.getElementById('modalSubmit').textContent  = 'Save Changes';
    document.getElementById('f_action').value           = 'edit_ip';
    document.getElementById('f_id').value               = r.id;
    document.getElementById('f_ip').value               = r.ip;
    document.getElementById('f_ip').readOnly            = true;
    document.getElementById('f_hostname').value         = r.hostname  || '';
    document.getElementById('f_owner').value            = r.owner     || '';
    document.getElementById('f_mac').value              = r.mac       || '';
    document.getElementById('f_status').value           = r.status    || 'active';
    document.getElementById('f_note').value             = r.note      || '';
    modal.classList.add('open');
    setTimeout(() => document.getElementById('f_hostname').focus(), 50);
  }

  function closeIpModal() {
    modal.classList.remove('open');
  }

  // ── Keyboard shortcuts ─────────────────────────────────────────────────────
  document.addEventListener('keydown', e => {
    // ignore when typing in an input
    if (['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;

    if (e.key === 'n' || e.key === 'N') {
      <?php if ($subnet): ?>
      e.preventDefault();
      openAddIp();
      <?php endif; ?>
    }

    if (e.key === 'Escape') {
      closeIpModal();
    }
  });
</script>
</body>
</html>