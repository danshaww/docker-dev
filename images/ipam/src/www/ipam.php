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
$edit_ip = isset($_GET['edit']) ? current(array_filter($ips, fn($r) => $r['id'] === $_GET['edit'])) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IPAM</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  /* ── Themes ── */
  :root {
    --bg:        #f4f6f9;
    --bg2:       #ffffff;
    --bg3:       #f0f2f5;
    --sidebar:   #1a1d2e;
    --sidebar2:  #2a2f4a;
    --sidebar3:  #2d3150;
    --stext:     #ccd;
    --stext2:    #778;
    --border:    #e8eaf0;
    --border2:   #dde;
    --text:      #222;
    --text2:     #444;
    --text3:     #666;
    --text4:     #888;
    --text5:     #aaa;
    --input-bg:  #ffffff;
    --shadow:    0 1px 4px rgba(0,0,0,.08);
    --accent:    #2563eb;
    --accent2:   #1d4ed8;
    --green:     #2ecc71;
    --orange:    #f39c12;
    --red-bg:    #fef2f2;
    --red-bdr:   #fca5a5;
    --red-text:  #b91c1c;
    --hover-row: #fafbff;
  }

  [data-theme="dark"] {
    --bg:        #0d0f14;
    --bg2:       #13161e;
    --bg3:       #1c202c;
    --sidebar:   #0a0c12;
    --sidebar2:  #161926;
    --sidebar3:  #1e2235;
    --stext:     #aab;
    --stext2:    #556;
    --border:    #272b38;
    --border2:   #2e3347;
    --text:      #e8eaf2;
    --text2:     #c0c4d8;
    --text3:     #8b90a8;
    --text4:     #666a80;
    --text5:     #444860;
    --input-bg:  #1c202c;
    --shadow:    0 1px 4px rgba(0,0,0,.4);
    --accent:    #4f8ef7;
    --accent2:   #3a6fd4;
    --green:     #3ecf8e;
    --orange:    #f7a94f;
    --red-bg:    #2a1010;
    --red-bdr:   #7f2020;
    --red-text:  #f87171;
    --hover-row: #1a1e2a;
  }

  body { font-family: system-ui, sans-serif; font-size: 14px; background: var(--bg); color: var(--text); min-height: 100vh; transition: background .2s, color .2s; }

  /* ── Layout ── */
  .layout { display: flex; min-height: 100vh; }
  .sidebar { width: 240px; background: var(--sidebar); color: var(--stext); flex-shrink: 0; display: flex; flex-direction: column; }
  .sidebar-top { padding: 16px 16px 12px; border-bottom: 1px solid var(--sidebar3); display: flex; align-items: center; justify-content: space-between; }
  .sidebar-top h1 { font-size: 1rem; font-weight: 700; color: #fff; letter-spacing: .03em; }
  .sidebar-top h1 small { display: block; font-size: .7rem; font-weight: 400; color: var(--stext2); margin-top: 2px; }
  .sidebar nav { flex: 1; padding: 8px; overflow-y: auto; }
  .sidebar a { display: block; padding: 8px 10px; border-radius: 5px; color: var(--stext); text-decoration: none; font-size: .82rem; margin-bottom: 2px; }
  .sidebar a:hover { background: var(--sidebar2); color: #fff; }
  .sidebar a.active { background: var(--accent); color: #fff; }
  .sidebar a .cidr { font-family: monospace; font-weight: 700; display: block; }
  .sidebar a .meta { font-size: .7rem; opacity: .7; }
  .sidebar footer { padding: 12px; border-top: 1px solid var(--sidebar3); }
  .main { flex: 1; padding: 24px; overflow-y: auto; }

  /* ── Theme toggle ── */
  .theme-btn { background: var(--sidebar2); border: 1px solid var(--sidebar3); color: var(--stext); border-radius: 5px; padding: 4px 8px; cursor: pointer; font-size: .8rem; white-space: nowrap; }
  .theme-btn:hover { background: var(--sidebar3); color: #fff; }

  /* ── Cards ── */
  .card { background: var(--bg2); border-radius: 8px; box-shadow: var(--shadow); padding: 20px; margin-bottom: 20px; border: 1px solid var(--border); }
  .card h2 { font-size: .82rem; font-weight: 700; margin-bottom: 14px; color: var(--text3); text-transform: uppercase; letter-spacing: .05em; }

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

  /* ── Forms ── */
  .form-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  .form-group { display: flex; flex-direction: column; gap: 4px; min-width: 120px; }
  .form-group label { font-size: .7rem; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: .05em; }
  input[type=text], input[type=number], select {
    padding: 7px 9px; border: 1px solid var(--border2); border-radius: 5px;
    font-size: .82rem; font-family: monospace; width: 100%;
    background: var(--input-bg); color: var(--text);
    transition: border-color .15s, box-shadow .15s;
  }
  input:focus, select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 2px rgba(79,142,247,.15); }
  select option { background: var(--bg2); color: var(--text); }

  /* sidebar inputs */
  .sidebar input[type=text], .sidebar input[type=number] {
    background: var(--sidebar2); border-color: var(--sidebar3); color: #fff; font-size: .78rem;
  }
  .sidebar input::placeholder { color: var(--stext2); }

  /* ── Buttons ── */
  .btn { display: inline-flex; align-items: center; gap: 4px; padding: 7px 13px; border-radius: 5px; border: none; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; transition: background .15s; }
  .btn-blue  { background: var(--accent); color: #fff; }
  .btn-blue:hover { background: var(--accent2); }
  .btn-red   { background: transparent; color: var(--red-text); border: 1px solid transparent; }
  .btn-red:hover { background: var(--red-bg); border-color: var(--red-bdr); }
  .btn-grey  { background: var(--bg3); color: var(--text2); border: 1px solid var(--border); }
  .btn-grey:hover { background: var(--border); }

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
</style>
</head>
<body>
<div class="layout">

<!-- ── Sidebar ── -->
<aside class="sidebar">
  <div class="sidebar-top">
    <h1>IPAM <small>IP Address Manager</small></h1>
    <button class="theme-btn" onclick="toggleTheme()" id="themeBtn">🌙 Dark</button>
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
    <form method="post" action="ipam.php" style="display:flex;flex-direction:column;gap:8px">
      <input type="hidden" name="action" value="add_subnet">
      <div style="color:var(--stext2);font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Add Subnet</div>
      <input type="text"   name="name"    placeholder="Name (optional)">
      <div style="display:flex;gap:6px">
        <input type="text"   name="network"  placeholder="192.168.1.0" style="flex:2">
        <input type="number" name="prefix"   placeholder="24" min="1" max="32" style="flex:1;width:50px">
      </div>
      <div style="display:flex;gap:6px">
        <input type="text" name="gateway" placeholder="Gateway" style="flex:1">
        <input type="text" name="vlan"    placeholder="VLAN"    style="width:54px">
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
  <h2 style="margin-bottom:16px;font-size:1rem;color:var(--text2)">Overview</h2>
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
    $STATUS_COLORS = ['active'=>'#2ecc71','reserved'=>'#f39c12','dhcp'=>'#4f8ef7','inactive'=>'#666'];
  ?>
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap">
    <div>
      <h2 style="font-size:1.05rem;font-weight:700;color:var(--text)"><?= safe($subnet['name']) ?></h2>
      <div style="font-family:monospace;font-size:.82rem;color:var(--text3)">
        <?= safe($subnet['network']) ?>/<?= safe($subnet['prefix']) ?>
        <?= $subnet['gateway'] ? " · GW {$subnet['gateway']}" : '' ?>
        <?= $subnet['vlan']    ? " · VLAN {$subnet['vlan']}"  : '' ?>
      </div>
    </div>
    <form method="post" onsubmit="return confirm('Delete this subnet and all its IPs?')">
      <input type="hidden" name="action" value="delete_subnet">
      <input type="hidden" name="id"     value="<?= $subnet['id'] ?>">
      <button class="btn btn-red">🗑 Delete subnet</button>
    </form>
  </div>

  <div class="stats">
    <div class="stat"><div class="val" style="color:var(--accent)"><?= $used ?></div><div class="lbl">Allocated</div></div>
    <div class="stat"><div class="val" style="color:var(--green)"><?= $free ?></div><div class="lbl">Available</div></div>
    <div class="stat"><div class="val"><?= $u ?></div><div class="lbl">Usable IPs</div></div>
    <div class="stat"><div class="val" style="color:<?= $col ?>"><?= $pct ?>%</div><div class="lbl">Utilisation</div></div>
  </div>

  <div class="card">
    <h2><?= $edit_ip ? 'Edit IP' : 'Add IP Address' ?></h2>
    <form method="post" action="ipam.php?subnet=<?= $subnet['id'] ?>">
      <input type="hidden" name="action"    value="<?= $edit_ip ? 'edit_ip' : 'add_ip' ?>">
      <input type="hidden" name="subnet_id" value="<?= $subnet['id'] ?>">
      <?php if ($edit_ip): ?><input type="hidden" name="id" value="<?= $edit_ip['id'] ?>"><?php endif; ?>
      <div class="form-row">
        <div class="form-group" style="min-width:150px">
          <label>IP Address</label>
          <input type="text" name="ip" placeholder="<?= safe($subnet['network']) ?>" value="<?= $edit_ip ? safe($edit_ip['ip']) : '' ?>" <?= $edit_ip ? 'readonly style="opacity:.6"' : '' ?> required>
        </div>
        <div class="form-group" style="min-width:160px">
          <label>Hostname</label>
          <input type="text" name="hostname" placeholder="server-01.local" value="<?= $edit_ip ? safe($edit_ip['hostname']) : '' ?>">
        </div>
        <div class="form-group" style="min-width:150px">
          <label>MAC Address</label>
          <input type="text" name="mac" placeholder="AA:BB:CC:DD:EE:FF" value="<?= $edit_ip ? safe($edit_ip['mac']) : '' ?>">
        </div>
        <div class="form-group" style="min-width:110px">
          <label>Status</label>
          <select name="status">
            <?php foreach (['active','reserved','dhcp','inactive'] as $st): ?>
              <option value="<?= $st ?>" <?= ($edit_ip && $edit_ip['status']===$st) ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="flex:1;min-width:160px">
          <label>Note</label>
          <input type="text" name="note" placeholder="Optional note" value="<?= $edit_ip ? safe($edit_ip['note']) : '' ?>">
        </div>
        <div class="form-group">
          <label>&nbsp;</label>
          <button class="btn btn-blue"><?= $edit_ip ? 'Save Changes' : '+ Add IP' ?></button>
        </div>
        <?php if ($edit_ip): ?>
        <div class="form-group">
          <label>&nbsp;</label>
          <a href="ipam.php?subnet=<?= $subnet['id'] ?>" class="btn btn-grey">Cancel</a>
        </div>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead><tr>
        <th>IP Address</th><th>Hostname</th><th>MAC</th><th>Status</th><th>Note</th><th>Updated</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$ips): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text5);padding:28px">No IPs allocated yet.</td></tr>
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
            <a href="ipam.php?subnet=<?= $subnet['id'] ?>&edit=<?= $r['id'] ?>" class="btn btn-grey" style="font-size:.72rem">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Release <?= safe($r['ip']) ?>?')">
              <input type="hidden" name="action"    value="delete_ip">
              <input type="hidden" name="subnet_id" value="<?= $subnet['id'] ?>">
              <input type="hidden" name="id"        value="<?= $r['id'] ?>">
              <button class="btn btn-red" style="font-size:.72rem">Delete</button>
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
<script>
  const btn = document.getElementById('themeBtn');
  const html = document.documentElement;
  const saved = localStorage.getItem('ipam-theme') || 'light';
  setTheme(saved);

  function setTheme(t) {
    html.dataset.theme = t;
    btn.textContent = t === 'dark' ? '☀️ Light' : '🌙 Dark';
    localStorage.setItem('ipam-theme', t);
  }
  function toggleTheme() {
    setTheme(html.dataset.theme === 'dark' ? 'light' : 'dark');
  }
</script>
</body>
</html>