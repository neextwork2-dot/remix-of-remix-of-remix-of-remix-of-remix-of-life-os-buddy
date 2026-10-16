<?php
declare(strict_types=1);

/**
 * Life OS backend end-to-end tests.
 * Run with the API server up: php -S 127.0.0.1:8090 -t backendserver backendserver/index.php
 * Usage: php tests/run_tests.php
 */

const BASE = 'http://127.0.0.1:8090';
const MASTER_KEY = 'test-master-key-0123456789abcdef0123456789abcdef';

$failures = [];
$tests = 0;
function check(string $name, bool $cond, string $detail = ''): void {
    global $failures, $tests;
    $tests++;
    if (!$cond) { $failures[] = "$name: $detail"; echo "FAIL  $name  $detail\n"; }
    else echo "ok    $name\n";
}

function api(string $method, string $path, ?array $body = null, ?string $token = null): array {
    $ch = curl_init(BASE . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = "Authorization: Bearer $token";
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $raw, true) ?? [], 'raw' => (string) $raw];
}

function master(string $method, string $path, ?array $body = null, ?string $key = MASTER_KEY, ?int $tsOverride = null): array {
    $raw = $body === null ? '' : json_encode($body);
    $ts = $tsOverride ?? time();
    $sig = hash_hmac('sha256', "$ts.$method.$path." . hash('sha256', $raw), $key);
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "X-Master-Ts: $ts", "X-Master-Sig: $sig"],
        CURLOPT_POSTFIELDS => $body === null ? '' : $raw,
    ]);
    $rawOut = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'json' => json_decode((string) $rawOut, true) ?? []];
}

function sumMatching(int $n): float {
    require __DIR__ . '/../src/bootstrap.php';
    $total = 0.0;
    for ($i = 1; $i <= $n; $i++) $total += \LifeOS\Engine\Plan::matchingAmount($i);
    return $total;
}

// Plan constants: 11 events, 150 halving, total ≈ 299.854.
check('Plan: 11 matching events total 299.854', abs(sumMatching(11) - 299.854) < 0.001, 'got ' . round(sumMatching(11), 6));
check('Plan: event 1 = 150, event 2 = 75', \LifeOS\Engine\Plan::matchingAmount(1) === 150.0 && \LifeOS\Engine\Plan::matchingAmount(2) === 75.0);
check('Plan: event 12 = 0 (hard stop)', \LifeOS\Engine\Plan::matchingAmount(12) === 0.0);

// ---- Register root member A (no sponsor) ----
$r = api('POST', '/api/member/register', ['phone' => '01700000001', 'pin' => '1234', 'name' => 'Root A', 'accepted_terms' => 1]);
check('register A', $r['status'] === 200 && isset($r['json']['data']['token']), $r['raw']);
$tokenA = $r['json']['data']['token'] ?? '';

// Wrong PIN rejected
$r = api('POST', '/api/member/login', ['username' => '01700000001', 'password' => '9999']);
check('login wrong pin rejected', $r['status'] === 401, (string) $r['status']);

$r = api('POST', '/api/member/login', ['username' => '01700000001', 'password' => '1234']);
check('login A', $r['status'] === 200, $r['raw']);

// Duplicate phone rejected
$r = api('POST', '/api/member/register', ['phone' => '01700000001', 'pin' => '1234', 'name' => 'Dup']);
check('duplicate phone rejected', $r['status'] === 409, (string) $r['status']);

// ---- Register B under A, C and D under B ----
function registerAndActivate(string $phone, string $refCode, string $adminToken): array {
    $r = api('POST', '/api/member/register', ['phone' => $phone, 'pin' => '1234', 'name' => 'M ' . $phone, 'referral_code' => $refCode, 'accepted_terms' => 1]);
    if ($r['status'] !== 200) throw new RuntimeException("register $phone failed: " . $r['raw']);
    $tok = $r['json']['data']['token'];
    $me = api('GET', '/api/member/me', null, $tok)['json']['data'];
    $act = api('POST', '/api/member/activations', [], $tok)['json']['data'];
    api('POST', "/api/admin/activations/{$act['activation_id']}/confirm", ['status' => 'SUCCESS', 'payment_ref' => 'TRX-' . $phone], $adminToken);
    return ['token' => $tok, 'life_id' => $me['life_id'], 'referral_code' => $me['referral_code']];
}

$r = api('POST', '/api/admin/login', ['email' => 'admin@lifeos.test', 'password' => 'admin123']);
check('admin login', $r['status'] === 200 && isset($r['json']['data']['token']), $r['raw']);
$adminToken = $r['json']['data']['token'];

$B = registerAndActivate('01700000002', $r0ref ?? '', $adminToken); // placeholder replaced below
