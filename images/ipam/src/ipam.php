<?php
// ─── Data directory ───────────────────────────────────────────────────────────
define('DATA', __DIR__ . '/data/');
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
    if (!valid_ip($net))         $error = 'Invalid network address.';
    elseif ($pfx < 1 || $pfx > 32) $error = 'Prefix must be 1–32.';
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

$STATUS_COLORS = ['active'=>'#2ecc71','reserved'=>'#f39c12','dhcp'=>'#3498db','inactive'=>'#888'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IPAM</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: system-ui, sans-serif; font-size: 14px; background: #f4f6f9; color: #222; min-height: 100vh; }

  /* Layout */
  .layout { display: flex; min-height: 100vh; }
  .sidebar { width: 240px; background: #1a1d2e; color: #ccd; flex-shrink: 0; display: flex; flex-direction: column; }
  .sidebar h1 { padding: 18px 16px 14px; font-size: 1rem; font-weight: 700; color: #fff; border-bottom: 1px solid #2d3150; letter-spacing: .03em; }
  .sidebar h1 small { display: block; font-size: .7rem; font-weight: 400; color: #778; margin-top: 2px; }
  .sidebar nav { flex: 1; padding: 8px; overflow-y: auto; }
  .sidebar a { display: block; padding: 8px 10px; border-radius: 5px; color: #aab; text-decoration: none; font-size: .82rem; margin-bottom: 2px; }
  .sidebar a:hover { background: #2a2f4a; color: #fff; }
  .sidebar a.active { background: #2563eb; color: #fff; }
  .sidebar a .cidr { font-family: monospace; font-weight: 700; display: block; color: inherit; }
  .sidebar a .meta { font-size: .7rem; opacity: .7; }
  .sidebar footer { padding: 12px 16px; border-top: 1px solid #2d3150; }
  .main { flex: 1; padding: 24px; overflow-y: auto; }

  /* Cards & panels */
  .card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 20px; margin-bottom: 20px; }
  .card h2 { font-size: .9rem; font-weight: 700; margin-bottom: 14px; color: #444; text-transform: uppercase; letter-spacing: .05em; }

  /* Stats row */
  .stats { display: flex; gap: 12px; margin-bottom: 20px; }
  .stat { flex: 1; background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 14px 16px; }
  .stat .val { font-size: 1.6rem; font-weight: 800; font-family: monospace; }
  .stat .lbl { font-size: .7rem; color: #888; text-transform: uppercase; letter-spacing: .05em; margin-top: 2px; }

  /* Table */
  table { width: 100%; border-collapse: collapse; font-size: .82rem; }
  th { text-align: left; padding: 8px 10px; background: #f4f6f9; font-size: .7rem; text-transform: uppercase; letter-spacing: .05em; color: #666; border-bottom: 2px solid #e8eaf0; }
  td { padding: 9px 10px; border-bottom: 1px solid #f0f2f5; vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #fafbff; }
  td.mono { font-family: monospace; font-weight: 600; }

  /* Status dot */
  .dot { display: inline-flex; align-items: center; gap: 5px; font-size: .78rem; font-weight: 600; }
  .dot::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

  /* Forms */
  .form-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  .form-group { display: flex; flex-direction: column; gap: 4px; min-width: 120px; }
  .form-group label { font-size: .7rem; font-weight: 700; color: #666; text-transform: uppercase; letter-spacing: .05em; }
  input[type=text], input[type=number], select { padding: 7px 9px; border: 1px solid #dde; border-radius: 5px; font-size: .82rem; font-family: monospace; width: 100%; background: #fff; }
  input:focus, select:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.15); }

  /* Buttons */
  .btn { display: inline-flex; align-items: center; gap: 4px; padding: 7px 13px; border-radius: 5px; border: none; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; }
  .btn-blue   { background: #2563eb; color: #fff; }
  .btn-blue:hover { background: #1d4ed8; }
  .btn-red    { background: transparent; color: #dc2626; border: 1px solid transparent; }
  .btn-red:hover { background: #fef2f2; border-color: #dc2626; }
  .btn-grey   { background: #f0f2f5; color: #444; border: 1px solid #dde; }
  .btn-grey:hover { background: #e4e6eb; }

  /* Error */
  .error { background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: .82rem; }

  /* Progress bar */
  .bar-wrap { background: #eee; border-radius: 3px; height: 6px; overflow: hidden; width: 80px; display: inline-block; vertical-align: middle; }
  .bar-fill  { height: 100%; border-radius: 3px; }

  /* Overview grid */
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
  .grid-card { background: #fff; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); padding: 14px; cursor: pointer; border: 2px solid transparent; transition: border-color .15s; text-decoration: none; color: inherit; display: block; }
  .grid-card:hover { border-color: #2563eb; }
  .grid-card .cidr { font-family: monospace; font-weight: 700; font-size: .95rem; color: #2563eb; }
  .grid-card .name { font-size: .8rem; color: #666; margin: 3px 0 10px; }
</style>
</head>
<body>
<div class="layout">

<!-- ── Sidebar ──────────────────────────────────────────────────────────────── -->
<aside class="sidebar">
  <h1>IPAM <small>IP Address Manager</small></h1>
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
      <div style="color:#778;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:2px">Add Subnet</div>
      <input type="text"   name="name"    placeholder="Name (optional)" style="background:#2a2f4a;border:1px solid #3d4470;color:#fff;font-size:.78rem">
      <div style="display:flex;gap:6px">
        <input type="text"   name="network"  placeholder="192.168.1.0"  style="background:#2a2f4a;border:1px solid #3d4470;color:#fff;font-size:.78rem;flex:2">
        <input type="number" name="prefix"   placeholder="24" min="1" max="32" style="background:#2a2f4a;border:1px solid #3d4470;color:#fff;font-size:.78rem;flex:1;width:50px">
      </div>
      <div style="display:flex;gap:6px">
        <input type="text" name="gateway" placeholder="Gateway" style="background:#2a2f4a;border:1px solid #3d4470;color:#fff;font-size:.78rem;flex:1">
        <input type="text" name="vlan"    placeholder="VLAN"    style="background:#2a2f4a;border:1px solid #3d4470;color:#fff;font-size:.78rem;width:54px">
      </div>
      <button class="btn btn-blue" style="width:100%;justify-content:center">+ Add Subnet</button>
    </form>
  </footer>
</aside>

<!-- ── Main content ──────────────────────────────────────────────────────────── -->
<main class="main">
  <?php if ($error): ?><div class="error">⚠ <?= safe($error) ?></div><?php endif; ?>

  <?php if (!$subnet): ?>
  <!-- OVERVIEW -->
  <h2 style="margin-bottom:16px;font-size:1rem;color:#444">Overview</h2>
  <?php if (!$subnets): ?>
    <div class="card" style="text-align:center;padding:40px;color:#888">
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
      <div style="font-size:.72rem;color:#666;margin-top:5px"><?= count($sips) ?> / <?= $u ?> used (<?= $pct ?>%)</div>
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

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px">
    <div>
      <h2 style="font-size:1.05rem;font-weight:700"><?= safe($subnet['name']) ?></h2>
      <div style="font-family:monospace;font-size:.82rem;color:#666"><?= safe($subnet['network']) ?>/<?= safe($subnet['prefix']) ?>
        <?= $subnet['gateway'] ? " · GW {$subnet['gateway']}" : '' ?>
        <?= $subnet['vlan'] ? " · VLAN {$subnet['vlan']}" : '' ?>
      </div>
    </div>
    <form method="post" onsubmit="return confirm('Delete this subnet and all its IPs?')">
      <input type="hidden" name="action" value="delete_subnet">
      <input type="hidden" name="id"     value="<?= $subnet['id'] ?>">
      <button class="btn btn-red">🗑 Delete subnet</button>
    </form>
  </div>

  <!-- Stats -->
  <div class="stats">
    <div class="stat"><div class="val" style="color:#2563eb"><?= $used ?></div><div class="lbl">Allocated</div></div>
    <div class="stat"><div class="val" style="color:#2ecc71"><?= $free ?></div><div class="lbl">Available</div></div>
    <div class="stat"><div class="val"><?= $u ?></div><div class="lbl">Usable IPs</div></div>
    <div class="stat"><div class="val" style="color:<?= $col ?>"><?= $pct ?>%</div><div class="lbl">Utilisation</div></div>
  </div>

  <!-- Add / Edit IP form -->
  <div class="card">
    <h2><?= $edit_ip ? 'Edit IP' : 'Add IP Address' ?></h2>
    <form method="post" action="ipam.php?subnet=<?= $subnet['id'] ?>">
      <input type="hidden" name="action"    value="<?= $edit_ip ? 'edit_ip' : 'add_ip' ?>">
      <input type="hidden" name="subnet_id" value="<?= $subnet['id'] ?>">
      <?php if ($edit_ip): ?><input type="hidden" name="id" value="<?= $edit_ip['id'] ?>"><?php endif; ?>
      <div class="form-row">
        <div class="form-group" style="min-width:150px">
          <label>IP Address</label>
          <input type="text" name="ip" placeholder="<?= safe($subnet['network']) ?>" value="<?= $edit_ip ? safe($edit_ip['ip']) : '' ?>" <?= $edit_ip ? 'readonly' : '' ?> required>
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

  <!-- IP Table -->
  <div class="card" style="padding:0;overflow:hidden">
    <table>
      <thead><tr>
        <th>IP Address</th><th>Hostname</th><th>MAC</th><th>Status</th><th>Note</th><th>Updated</th><th></th>
      </tr></thead>
      <tbody>
      <?php if (!$ips): ?>
        <tr><td colspan="7" style="text-align:center;color:#aaa;padding:28px">No IPs allocated yet.</td></tr>
      <?php else: foreach ($ips as $r):
        $col = $STATUS_COLORS[$r['status']] ?? '#888';
      ?>
        <tr>
          <td class="mono"><?= safe($r['ip']) ?></td>
          <td class="mono"><?= safe($r['hostname']) ?: '—' ?></td>
          <td class="mono" style="font-size:.75rem"><?= safe($r['mac']) ?: '—' ?></td>
          <td><span class="dot" style="color:<?= $col ?>"><?= safe($r['status']) ?></span></td>
          <td><?= safe($r['note']) ?: '—' ?></td>
          <td style="color:#aaa;font-size:.75rem"><?= safe($r['updated']) ?></td>
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
</body>
</html>
