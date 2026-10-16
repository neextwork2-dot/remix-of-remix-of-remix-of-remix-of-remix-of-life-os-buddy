<?php
declare(strict_types=1);

use LifeOS\Auth;
use LifeOS\DB;
use LifeOS\Http;
use LifeOS\HttpError;
use LifeOS\Engine\Commission;
use LifeOS\Engine\Placement;
use LifeOS\Engine\Rank;

/** Member-facing API. All amounts in BDT. */

// ---- Auth ----
$router->post('/api/member/register', function () {
    $b = Http::body();
    foreach (['phone', 'pin', 'name'] as $f) {
        if (empty($b[$f])) throw new HttpError("$f required");
    }
    if (!preg_match('/^01[3-9]\d{8}$/', (string) $b['phone'])) throw new HttpError('Valid BD phone required');
    if (DB::one('SELECT id FROM users WHERE phone = ?', [$b['phone']])) throw new HttpError('Phone already registered', 409);

    $sponsorId = null;
    if (!empty($b['referral_code'])) {
        $sp = DB::one('SELECT id FROM users WHERE referral_code = ?', [$b['referral_code']]);
        if (!$sp) throw new HttpError('Invalid referral code');
        $sponsorId = (int) $sp['id'];
    }

    $result = DB::tx(function () use ($b, $sponsorId) {
        $lifeId = 'LO' . str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT);
        $refCode = strtoupper(bin2hex(random_bytes(4)));
        $id = DB::insert(
            'INSERT INTO users (life_id, membership_no, referral_code, referred_by, phone, email, pin_hash, status, accepted_terms)
             VALUES (?,?,?,?,?,?,?,"pending",?)',
            [$lifeId, $lifeId, $refCode, $b['referral_code'] ?? null, $b['phone'], $b['email'] ?? null,
             password_hash((string) $b['pin'], PASSWORD_DEFAULT), !empty($b['accepted_terms']) ? 1 : 0]
        );
        DB::insert('INSERT INTO user_wallets (user_id) VALUES (?)', [$id]);
        Placement::ensureNode($id, $sponsorId);
        Placement::place($id, $sponsorId);
        return $id;
    });

    Http::ok(['user_id' => $result, 'token' => Auth::issue('m', $result)]);
});

$router->post('/api/member/login', function () {
    $b = Http::body();
    $u = DB::one('SELECT * FROM users WHERE phone = ? OR life_id = ? OR referral_code = ?', [$b['username'] ?? '', $b['username'] ?? '', $b['username'] ?? '']);
    if (!$u || !password_verify((string) ($b['password'] ?? ''), (string) $u['pin_hash'])) {
        throw new HttpError('Invalid credentials', 401);
    }
    if ($u['status'] === 'suspended') throw new HttpError('Account suspended', 403);
    Http::ok(['token' => Auth::issue('m', (int) $u['id']), 'user' => memberSummary($u)]);
});

// ---- Dashboard ----
$router->get('/api/member/me', function () {
    Http::ok(memberSummary(Auth::member()));
});

$router->get('/api/member/wallet', function () {
    $u = Auth::member();
    $uid = (int) $u['id'];
    $wallet = DB::one('SELECT * FROM user_wallets WHERE user_id = ?', [$uid]);
    $ledger = [];
    foreach (['REFERRAL', 'MATCHING', 'GENERATION', 'RANK', 'ADJUSTMENT'] as $t) {
        $ledger[$t] = (float) DB::val("SELECT COALESCE(SUM(amount),0) FROM lo_ledger WHERE user_id = ? AND ledger_type = ? AND status = 'PAID'", [$uid, $t]);
    }
    $withdrawn = (float) DB::val("SELECT COALESCE(SUM(amount),0) FROM lo_withdrawals WHERE user_id = ? AND status = 'APPROVED'", [$uid]);
    $pendingWd = (float) DB::val("SELECT COALESCE(SUM(amount),0) FROM lo_withdrawals WHERE user_id = ? AND status = 'PENDING'", [$uid]);
    $cashback = (float) DB::val("SELECT COALESCE(SUM(amount),0) FROM lo_ledger WHERE user_id = ? AND ledger_type = 'CASHBACK' AND status = 'PAID'", [$uid]);
    $earned = array_sum($ledger);
    Http::ok([
        'withdrawable' => round($earned - $withdrawn - $pendingWd, 3),
        'pending_withdrawal' => $pendingWd,
        'withdrawn' => $withdrawn,
        'cashback_service_only' => $cashback,
        'by_type' => $ledger,
        'legacy_wallet' => $wallet,
    ]);
});

$router->get('/api/member/ledger', function () {
    $u = Auth::member();
    $type = $_GET['type'] ?? null;
    $sql = 'SELECT payout_code, ledger_type, amount, level, status, note, created_at FROM lo_ledger WHERE user_id = ?';
    $p = [(int) $u['id']];
    if ($type) { $sql .= ' AND ledger_type = ?'; $p[] = $type; }
    $sql .= ' ORDER BY id DESC LIMIT 200';
    Http::ok(DB::all($sql, $p));
});

// ---- Trees ----
$router->get('/api/member/tree/binary', function () {
    $u = Auth::member();
    $uid = (int) $u['id'];
    $node = DB::one('SELECT * FROM lo_tree WHERE user_id = ?', [$uid]);
    $build = function (int $id, int $depth) use (&$build): ?array {
        if ($depth > 6) return null;
        $n = DB::one('SELECT t.*, u.life_id, u.phone FROM lo_tree t JOIN users u ON u.id = t.user_id WHERE t.user_id = ?', [$id]);
        if (!$n) return null;
        return [
            'user_id' => $id,
            'life_id' => $n['life_id'],
            'active' => (bool) $n['is_active'],
            'left_carry' => (float) $n['left_carry'],
            'right_carry' => (float) $n['right_carry'],
            'left' => $n['left_child_id'] ? $build((int) $n['left_child_id'], $depth + 1) : null,
            'right' => $n['right_child_id'] ? $build((int) $n['right_child_id'], $depth + 1) : null,
        ];
    };
    Http::ok($build($uid, 0));
});

$router->get('/api/member/tree/enrollment', function () {
    $u = Auth::member();
    $uid = (int) $u['id'];
    $directs = DB::all(
        'SELECT u.id, u.life_id, u.phone, u.created_at, t.is_active FROM lo_tree t JOIN users u ON u.id = t.user_id WHERE t.sponsor_id = ? ORDER BY u.id',
        [$uid]
    );
    $legs = DB::all('SELECT leg_user_id, carry, consumed FROM lo_enroll_legs WHERE upline_id = ?', [$uid]);
    Http::ok(['directs' => $directs, 'legs' => $legs]);
});

// ---- Activation (payment-verified only; gateway callback calls /api/admin/activations/{id}/confirm) ----
$router->post('/api/member/activations', function () {
    $u = Auth::member();
    $uid = (int) $u['id'];
    if (DB::one("SELECT id FROM lo_activations WHERE user_id = ? AND payment_status = 'SUCCESS'", [$uid])) {
        throw new HttpError('Already activated', 409);
    }
    $id = DB::insert(
        'INSERT INTO lo_activations (activation_code, user_id, payment_ref) VALUES (?, ?, ?)',
        ['ACT-' . strtoupper(bin2hex(random_bytes(8))), $uid, 'PENDING-' . bin2hex(random_bytes(8))]
    );
    Http::ok(['activation_id' => $id, 'fee' => 1000, 'status' => 'PENDING']);
});

$router->get('/api/member/activations', function () {
    $u = Auth::member();
    Http::ok(DB::all('SELECT id, activation_code, payment_status, fee, processed, created_at FROM lo_activations WHERE user_id = ? ORDER BY id DESC', [(int) $u['id']]));
});

// ---- Matching & rank ----
$router->get('/api/member/matching', function () {
    $u = Auth::member();
    Http::ok(DB::all(
        'SELECT event_code, sale_id, event_number, selected_type, calculated_payout, actual_payout, payout_status, created_at
         FROM lo_matching_events WHERE beneficiary_id = ? ORDER BY id DESC LIMIT 200',
        [(int) $u['id']]
    ));
});

$router->get('/api/member/rank', function () {
    $u = Auth::member();
    $uid = (int) $u['id'];
    $matchings = (int) DB::val('SELECT lifetime_matchings FROM lo_tree WHERE user_id = ?', [$uid]);
    $current = Rank::current($uid);
    $next = DB::one('SELECT * FROM lo_ranks WHERE required_matchings > ? ORDER BY required_matchings LIMIT 1', [$matchings]);
    Http::ok(['lifetime_matchings' => $matchings, 'current' => $current, 'next' => $next]);
});

// ---- Withdrawals ----
$router->post('/api/member/withdrawals', function () {
    $u = Auth::member();
    $b = Http::body();
    $amount = (float) ($b['amount'] ?? 0);
    $min = (float) (DB::val("SELECT v FROM lo_settings WHERE k = 'withdraw_min'") ?: 500);
    if ($amount < $min) throw new HttpError("Minimum withdrawal is $min");
    if (empty($b['method']) || empty($b['account_number'])) throw new HttpError('method and account_number required');
    $chargePct = (float) (DB::val("SELECT v FROM lo_settings WHERE k = 'withdraw_charge_pct'") ?: 0);
    $id = DB::insert(
        'INSERT INTO lo_withdrawals (user_id, method, account_number, amount, charge) VALUES (?,?,?,?,?)',
        [(int) $u['id'], $b['method'], $b['account_number'], $amount, round($amount * $chargePct / 100, 3)]
    );
    Http::ok(['withdrawal_id' => $id, 'status' => 'PENDING']);
});

$router->get('/api/member/withdrawals', function () {
    $u = Auth::member();
    Http::ok(DB::all('SELECT id, method, account_number, amount, charge, status, admin_note, created_at FROM lo_withdrawals WHERE user_id = ? ORDER BY id DESC', [(int) $u['id']]));
});

function memberSummary(array $u): array
{
    $uid = (int) $u['id'];
    $tree = DB::one('SELECT * FROM lo_tree WHERE user_id = ?', [$uid]);
    return [
        'id' => $uid,
        'life_id' => $u['life_id'],
        'referral_code' => $u['referral_code'],
        'phone' => $u['phone'],
        'email' => $u['email'],
        'status' => $u['status'],
        'active' => (bool) ($tree['is_active'] ?? false),
        'lifetime_matchings' => (int) ($tree['lifetime_matchings'] ?? 0),
        'rank' => Rank::current($uid),
    ];
}
