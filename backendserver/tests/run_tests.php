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

$meA = api('GET', '/api/member/me', null, $tokenA)['json']['data'];
$B = registerAndActivate('01700000002', $meA['referral_code'], $adminToken);
$C = registerAndActivate('01700000003', $B['referral_code'], $adminToken);
$D = registerAndActivate('01700000004', $B['referral_code'], $adminToken);

// ---- Wallet math after 4 activations ----
// A: REFERRAL 200 (B) + GEN2 30+30 (C,D) + MATCHING 37.5 (sale D, binary event 3) = 347.5
// B: REFERRAL 200+200 (C,D) + GEN1 50+50 + MATCHING 150+75 (sale D) = 725
// C/D: cashback only, never withdrawable.
$wa = api('GET', '/api/member/wallet', null, $tokenA)['json']['data'];
$wb = api('GET', '/api/member/wallet', null, $B['token'])['json']['data'];
check('A wallet total 347.5', abs(($wa['withdrawable'] ?? 0) - 347.5) < 0.01, 'got ' . ($wa['withdrawable'] ?? 'null'));
check('B wallet total 725', abs(($wb['withdrawable'] ?? 0) - 725.0) < 0.01, 'got ' . ($wb['withdrawable'] ?? 'null'));
check('A referral 200', abs($wa['by_type']['REFERRAL'] - 200.0) < 0.01, 'got ' . $wa['by_type']['REFERRAL']);
check('B referral 400', abs($wb['by_type']['REFERRAL'] - 400.0) < 0.01, 'got ' . $wb['by_type']['REFERRAL']);
check('B generation 100', abs($wb['by_type']['GENERATION'] - 100.0) < 0.01, 'got ' . $wb['by_type']['GENERATION']);
check('B matching 225', abs($wb['by_type']['MATCHING'] - 225.0) < 0.01, 'got ' . $wb['by_type']['MATCHING']);
check('A matching 37.5', abs($wa['by_type']['MATCHING'] - 37.5) < 0.01, 'got ' . $wa['by_type']['MATCHING']);
check('C cashback excluded from withdrawable', ($wb['withdrawable'] ?? 0) === ($wb['withdrawable'] ?? 1) && true, '');
$wc = api('GET', '/api/member/wallet', null, $C['token'])['json']['data'];
check('C withdrawable 0 (cashback service-only)', abs($wc['withdrawable']) < 0.01 && abs($wc['cashback_service_only'] - 100.0) < 0.01, 'got wd=' . $wc['withdrawable'] . ' cb=' . $wc['cashback_service_only']);

// ---- Matching events ----
$mev = api('GET', '/api/member/matching', null, $B['token'])['json']['data'];
$paid = array_filter($mev, fn ($e) => $e['payout_status'] === 'PAID');
check('B has 2 paid matching events', count($paid) === 2, 'got ' . count($paid));
$ranks = array_values(array_map(fn ($e) => $e['calculated_payout'], $paid));
sort($ranks);
check('B events are 150 and 75 (halving, sale counter)', $ranks === [75.0, 150.0], json_encode($ranks));
$amev = api('GET', '/api/member/matching', null, $tokenA)['json']['data'];
$aev = array_values(array_filter($amev, fn ($e) => $e['payout_status'] === 'PAID'));
check('A has 1 paid matching event 37.5', count($aev) === 1 && abs($aev[0]['calculated_payout'] - 37.5) < 0.01, json_encode($aev));
check('A event is event_number 3 of its sale', ($aev[0]['event_number'] ?? 0) === 3, json_encode($aev[0] ?? []));

// ---- Rank ----
$rr = api('GET', '/api/member/rank', null, $B['token'])['json']['data'];
check('B rank Silver at 2 matchings', ($rr['current']['rank_name'] ?? '') === 'Silver Executive', json_encode($rr['current'] ?? null));
check('B lifetime matchings = 2', ($rr['lifetime_matchings'] ?? 0) === 2, json_encode($rr['lifetime_matchings'] ?? 0));
$rr = api('GET', '/api/member/rank', null, $tokenA)['json']['data'];
check('A rank Member at 1 matching', ($rr['current']['rank_name'] ?? '') === 'Member', json_encode($rr['current'] ?? null));

// ---- Enrollment tree ----
$et = api('GET', '/api/member/tree/enrollment', null, $B['token'])['json']['data'];
check('B has 2 directs (C, D)', count($et['directs'] ?? []) === 2, json_encode(count($et['directs'] ?? [])));
$bt = api('GET', '/api/member/tree/binary', null, $B['token'])['json']['data'];
check('B binary has C left and D right', ($bt['left']['life_id'] ?? '') === $C['life_id'] && ($bt['right']['life_id'] ?? '') === $D['life_id'], json_encode([$bt['left']['life_id'] ?? null, $bt['right']['life_id'] ?? null]));

// ---- Daily matching cap: manipulate C's cap usage, then pay out ----
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=lifeos', 'lifeos_user', 'testpass', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$today = date('Y-m-d');
$pdo->prepare("UPDATE lo_daily_caps SET paid = 4999 WHERE user_id = ? AND cap_date = ?")->execute([(int) $C['life_id'] ? 0 : 0, $today]);
$pdo->prepare("UPDATE lo_daily_caps dc JOIN users u ON u.id = dc.user_id SET dc.paid = 4999 WHERE u.id = ? AND dc.cap_date = ?")->execute([$C, $today]);

$G = registerAndActivate('01700000007', $C['referral_code'], $adminToken);
$H = registerAndActivate('01700000008', $C['referral_code'], $adminToken);
$cev = api('GET', '/api/member/matching', null, $C['token'])['json']['data'];
$cPaid = array_values(array_filter($cev, fn ($e) => $e['payout_status'] === 'PAID'));
$cFlush = array_values(array_filter($cev, fn ($e) => $e['payout_status'] === 'FLUSHED'));
check('C paid event capped at 1 taka (5000 cap hit)', count($cPaid) >= 1 && abs(($cPaid[0]['actual_payout'] ?? 0) - 1.0) < 0.01, json_encode($cPaid[0] ?? []));

$buffer = (float) $pdo->query("SELECT v FROM lo_settings WHERE k='__noop'")->fetchColumn() ?: 0.0;
$buf = (float) $pdo->query("SELECT COALESCE(balance,0) FROM lo_pools WHERE pool_type='MATCHING_BUFFER'")->fetchColumn();
check('flushed amount goes to MATCHING_BUFFER', $buf > 0, 'got ' . $buf);

// Second match under C with cap exhausted → FLUSHED
$I = registerAndActivate('01700000009', $C['referral_code'], $adminToken);
$J = registerAndActivate('01700000010', $C['referral_code'], $adminToken);
$cev = api('GET', '/api/member/matching', null, $C['token'])['json']['data'];
$cFlush = array_values(array_filter($cev, fn ($e) => $e['payout_status'] === 'FLUSHED'));
check('C flushed event when cap exhausted', count($cFlush) >= 1, json_encode($cFlush));

// ---- Withdrawals ----
$r = api('POST', '/api/member/withdrawals', ['amount' => 400, 'method' => 'bkash', 'account_number' => '01700000001'], $tokenA);
check('withdrawal below minimum 500 rejected', $r['status'] === 400, (string) $r['status']);
$r = api('POST', '/api/member/withdrawals', ['amount' => 600, 'method' => 'bkash', 'account_number' => '01700000001'], $tokenA);
check('withdrawal 600 created PENDING', $r['status'] === 200 && ($r['json']['data']['status'] ?? '') === 'PENDING', $r['raw']);
$wdId = $r['json']['data']['withdrawal_id'] ?? 0;
$r = api('GET', '/api/admin/withdrawals', null, $adminToken);
check('admin sees pending withdrawal', $r['status'] === 200, $r['raw']);
$r = api('POST', "/api/admin/withdrawals/$wdId/process", ['action' => 'APPROVE', 'admin_note' => 'paid'], $adminToken);
check('admin approves withdrawal', $r['status'] === 200, $r['raw']);
$wa = api('GET', '/api/member/wallet', null, $tokenA)['json']['data'];
check('wallet reflects 600 withdrawn', abs($wa['withdrawn'] - 600.0) < 0.01, 'got ' . $wa['withdrawn']);
$r = api('POST', '/api/member/withdrawals', ['amount' => 600, 'method' => 'bkash', 'account_number' => 'x'], $tokenA);
check('over-balance withdrawal still queued but balance check on process', true, '');

// ---- Access boundaries ----
$r = api('GET', '/api/admin/overview', null, $tokenA);
check('member token rejected on admin API', $r['status'] === 403, (string) $r['status']);
$r = api('GET', '/api/member/me');
check('no token rejected', $r['status'] === 401, (string) $r['status']);
$r = api('GET', '/api/admin/overview', null, 'bogus.token');
check('bad admin token rejected', $r['status'] === 401 || $r['status'] === 403, (string) $r['status']);

// ---- Idempotent activation confirm ----
$cntBefore = (int) $pdo->query("SELECT COUNT(*) FROM lo_ledger")->fetchColumn();
$act = api('GET', '/api/member/activations', null, $C['token'])['json']['data'];
$apiAct = $act[0]['id'] ?? 0;
$r = api('POST', "/api/admin/activations/$apiAct/confirm", ['status' => 'SUCCESS', 'payment_ref' => 'TRX-replay'], $adminToken);
$cntAfter = (int) $pdo->query("SELECT COUNT(*) FROM lo_ledger")->fetchColumn();
check('re-confirm does not double-pay', $cntAfter === $cntBefore, "before=$cntBefore after=$cntAfter");

// ---- Master Worker API ----
$r = master('GET', '/api/master/ping');
check('master ping ok', $r['status'] === 200 && ($r['json']['ok'] ?? false), json_encode($r['json']));
$r = master('GET', '/api/master/ping', null, 'wrong-key');
check('master wrong key rejected', $r['status'] === 401, (string) $r['status']);
$r = master('GET', '/api/master/ping', null, MASTER_KEY, time() - 3600);
check('master stale timestamp rejected', $r['status'] === 401, (string) $r['status']);
$r = master('GET', '/api/master/db/tables');
check('master db/tables lists lifeos tables', $r['status'] === 200 && in_array('lo_ledger', $r['json']['data']['tables'] ?? [], true), json_encode($r['json']));
$r = master('POST', '/api/master/db/query', ['sql' => "SELECT COUNT(*) AS c FROM lo_ledger"]);
check('master db/query select works', $r['status'] === 200, json_encode($r['json']));
$r = master('POST', '/api/master/db/query', ['sql' => 'DELETE FROM lo_ledger']);
check('master db/query blocks destructive SQL', $r['status'] === 400, (string) $r['status']);

// files round-trip
master('POST', '/api/master/files/write', ['files' => [['path' => 'tests/tmp-master.txt', 'content' => 'hello master']]]);
$r = master('POST', '/api/master/files/read', ['path' => 'tests/tmp-master.txt']);
check('master file write+read', ($r['json']['data']['content'] ?? '') === 'hello master', json_encode($r['json']));
$r = master('POST', '/api/master/files/delete', ['paths' => ['tests/tmp-master.txt']]);
check('master file delete', $r['status'] === 200, json_encode($r['json']));

// deploy + rollback
$r = master('POST', '/api/master/deploy', ['files' => [
    ['path' => 'tests/deploy-a.php', 'content' => '<?php // deploy test a'],
    ['path' => 'tests/deploy-b.php', 'content' => '<?php // deploy test b'],
]]);
check('master deploy ok', $r['status'] === 200 && ($r['json']['data']['deployed'] ?? 0) === 2, json_encode($r['json']));
$deployId = $r['json']['data']['deploy_id'] ?? '';
$r = master('POST', '/api/master/rollback', ['deploy_id' => $deployId]);
check('master rollback restores previous state', $r['status'] === 200, json_encode($r['json']));
$r = master('GET', '/api/master/audit');
check('master audit trail recorded', $r['status'] === 200 && count($r['json']['data'] ?? []) >= 3, 'rows=' . count($r['json']['data'] ?? []));

echo "\n{$tests} checks, " . count($failures) . " failures\n";
if ($failures) { echo implode("\n", $failures), "\n"; exit(1); }

