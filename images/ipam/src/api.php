<?php
/**
 * IP Address Manager - Backend API
 * Stores subnet and IP allocation data in CSV files
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('DATA_DIR', __DIR__ . '/ipam_data/');

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = isset($_GET['path']) ? trim($_GET['path'], '/') : '';
$parts  = explode('/', $path);
$action = $parts[0] ?? '';

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit();
}

function error($msg, $code = 400) {
    respond(['error' => $msg], $code);
}

/* ---------- SUBNET helpers ---------- */

function subnets_file() {
    return DATA_DIR . 'subnets.csv';
}

function read_subnets() {
    $file = subnets_file();
    if (!file_exists($file)) return [];
    $rows = [];
    if (($fh = fopen($file, 'r')) !== false) {
        $header = fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if ($header && count($row) === count($header)) {
                $rows[] = array_combine($header, $row);
            }
        }
        fclose($fh);
    }
    return $rows;
}

function write_subnets($subnets) {
    $file = subnets_file();
    $fh   = fopen($file, 'w');
    fputcsv($fh, ['id','name','network','prefix','gateway','vlan','description','created']);
    foreach ($subnets as $s) fputcsv($fh, array_values($s));
    fclose($fh);
}

/* ---------- IP helpers ---------- */

function ips_file($subnet_id) {
    return DATA_DIR . 'subnet_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $subnet_id) . '.csv';
}

function read_ips($subnet_id) {
    $file = ips_file($subnet_id);
    if (!file_exists($file)) return [];
    $rows = [];
    if (($fh = fopen($file, 'r')) !== false) {
        $header = fgetcsv($fh);
        while (($row = fgetcsv($fh)) !== false) {
            if ($header && count($row) === count($header)) {
                $rows[] = array_combine($header, $row);
            }
        }
        fclose($fh);
    }
    return $rows;
}

function write_ips($subnet_id, $ips) {
    $file = ips_file($subnet_id);
    $fh   = fopen($file, 'w');
    fputcsv($fh, ['id','ip','hostname','mac','owner','status','description','updated']);
    foreach ($ips as $ip) fputcsv($fh, array_values($ip));
    fclose($fh);
}

function ip_in_subnet($ip, $network, $prefix) {
    $ip_long  = ip2long($ip);
    $net_long = ip2long($network);
    $mask     = ~((1 << (32 - (int)$prefix)) - 1);
    return ($ip_long & $mask) === ($net_long & $mask);
}

/* ===================== ROUTING ===================== */

/* GET /subnets */
if ($action === 'subnets' && $method === 'GET' && !isset($parts[1])) {
    respond(read_subnets());
}

/* POST /subnets */
if ($action === 'subnets' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || empty($body['network']) || empty($body['prefix'])) error('network and prefix required');
    $subnets = read_subnets();
    $id = uniqid('sn_');
    $subnets[] = [
        'id'          => $id,
        'name'        => $body['name']        ?? $body['network'] . '/' . $body['prefix'],
        'network'     => $body['network'],
        'prefix'      => $body['prefix'],
        'gateway'     => $body['gateway']     ?? '',
        'vlan'        => $body['vlan']        ?? '',
        'description' => $body['description'] ?? '',
        'created'     => date('Y-m-d H:i:s'),
    ];
    write_subnets($subnets);
    respond(['id' => $id], 201);
}

/* PUT /subnets/{id} */
if ($action === 'subnets' && $method === 'PUT' && isset($parts[1])) {
    $id   = $parts[1];
    $body = json_decode(file_get_contents('php://input'), true);
    $subnets = read_subnets();
    $found = false;
    foreach ($subnets as &$s) {
        if ($s['id'] === $id) {
            $s['name']        = $body['name']        ?? $s['name'];
            $s['network']     = $body['network']     ?? $s['network'];
            $s['prefix']      = $body['prefix']      ?? $s['prefix'];
            $s['gateway']     = $body['gateway']     ?? $s['gateway'];
            $s['vlan']        = $body['vlan']        ?? $s['vlan'];
            $s['description'] = $body['description'] ?? $s['description'];
            $found = true; break;
        }
    }
    if (!$found) error('Subnet not found', 404);
    write_subnets($subnets);
    respond(['ok' => true]);
}

/* DELETE /subnets/{id} */
if ($action === 'subnets' && $method === 'DELETE' && isset($parts[1])) {
    $id      = $parts[1];
    $subnets = array_filter(read_subnets(), fn($s) => $s['id'] !== $id);
    write_subnets(array_values($subnets));
    $f = ips_file($id);
    if (file_exists($f)) unlink($f);
    respond(['ok' => true]);
}

/* GET /subnets/{id}/ips */
if ($action === 'subnets' && $method === 'GET' && isset($parts[1]) && ($parts[2] ?? '') === 'ips') {
    respond(read_ips($parts[1]));
}

/* POST /subnets/{id}/ips */
if ($action === 'subnets' && $method === 'POST' && isset($parts[1]) && ($parts[2] ?? '') === 'ips') {
    $subnet_id = $parts[1];
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body || empty($body['ip'])) error('ip required');

    // Validate IP
    if (!filter_var($body['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) error('Invalid IPv4 address');

    // Check subnet membership
    $subnets = read_subnets();
    $subnet  = null;
    foreach ($subnets as $s) { if ($s['id'] === $subnet_id) { $subnet = $s; break; } }
    if (!$subnet) error('Subnet not found', 404);
    if (!ip_in_subnet($body['ip'], $subnet['network'], $subnet['prefix']))
        error('IP address does not belong to this subnet');

    $ips = read_ips($subnet_id);

    // Check duplicate
    foreach ($ips as $existing) {
        if ($existing['ip'] === $body['ip']) error('IP address already allocated');
    }

    $id    = uniqid('ip_');
    $ips[] = [
        'id'          => $id,
        'ip'          => $body['ip'],
        'hostname'    => $body['hostname']    ?? '',
        'mac'         => $body['mac']         ?? '',
        'owner'       => $body['owner']       ?? '',
        'status'      => $body['status']      ?? 'active',
        'description' => $body['description'] ?? '',
        'updated'     => date('Y-m-d H:i:s'),
    ];

    // Sort by IP
    usort($ips, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
    write_ips($subnet_id, $ips);
    respond(['id' => $id], 201);
}

/* PUT /subnets/{subnet_id}/ips/{ip_id} */
if ($action === 'subnets' && $method === 'PUT' && isset($parts[3]) && ($parts[2] ?? '') === 'ips') {
    $subnet_id = $parts[1];
    $ip_id     = $parts[3];
    $body = json_decode(file_get_contents('php://input'), true);
    $ips   = read_ips($subnet_id);
    $found = false;
    foreach ($ips as &$ip) {
        if ($ip['id'] === $ip_id) {
            $ip['hostname']    = $body['hostname']    ?? $ip['hostname'];
            $ip['mac']         = $body['mac']         ?? $ip['mac'];
            $ip['owner']       = $body['owner']       ?? $ip['owner'];
            $ip['status']      = $body['status']      ?? $ip['status'];
            $ip['description'] = $body['description'] ?? $ip['description'];
            $ip['updated']     = date('Y-m-d H:i:s');
            $found = true; break;
        }
    }
    if (!$found) error('IP not found', 404);
    write_ips($subnet_id, $ips);
    respond(['ok' => true]);
}

/* DELETE /subnets/{subnet_id}/ips/{ip_id} */
if ($action === 'subnets' && $method === 'DELETE' && isset($parts[3]) && ($parts[2] ?? '') === 'ips') {
    $subnet_id = $parts[1];
    $ip_id     = $parts[3];
    $ips = array_filter(read_ips($subnet_id), fn($ip) => $ip['id'] !== $ip_id);
    write_ips($subnet_id, array_values($ips));
    respond(['ok' => true]);
}

error('Not found', 404);
