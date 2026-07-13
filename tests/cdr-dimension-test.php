<?php
/**
 * CDR 话单查询 - 多维筛选测试矩阵
 *
 * 设计：每个测试用例都用数据库直接计算"期望 total"（复刻 handleList 的 SQL 逻辑，
 * 含多表 UNION ALL + COLLATE JOIN + 相同 where/params），与 API 返回的 total 交叉比对。
 * 能同时抓出两类问题：
 *   1) 直接 Fatal Error（返回非 JSON / 无 total 字段）
 *   2) 返回不报错但结果错误（total 与库不一致 / 不存在值未返回空）
 *
 * 用法：php tests/cdr-dimension-test.php
 */

$cfg = require 'server/config/db.php';
$base = 'http://localhost';
$apiToken = '';

// ---------- 工具：登录拿 token ----------
function login() {
    global $base;
    $ch = curl_init("$base/api/auth/login");
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['username' => 'admin', 'password' => 'yump']),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['data']['token'] ?? '';
}

// ---------- 工具：调 API ----------
function callApi($path, $query = []) {
    global $base, $apiToken;
    $url = "$base$path?" . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $apiToken"],
    ]);
    $raw = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($raw, true);
    return ['http' => $http, 'raw' => $raw, 'json' => $json];
}

// ---------- 工具：复刻 cdrJoin（带 COLLATE）----------
function cdrJoinSQL() {
    return "LEFT JOIN y_nodes n ON c.node_id = n.id
        LEFT JOIN y_gateway_routings gr ON c.node_id = gr.node_id AND c.gateway_out COLLATE utf8mb4_unicode_ci = gr.name COLLATE utf8mb4_unicode_ci
        LEFT JOIN y_gateway_mappings gm ON c.node_id = gm.node_id AND c.gateway_in COLLATE utf8mb4_unicode_ci = gm.name COLLATE utf8mb4_unicode_ci";
}

// ---------- 工具：构造 where 条件（复刻 handleList）----------
function buildWhere($filters) {
    $where = ['1=1'];
    $params = [];
    foreach ($filters as $k => $v) {
        if ($v === '' || $v === null) continue;
        switch ($k) {
            case 'cdr_id': $where[] = 'c.cdr_id = ?'; $params[] = $v; break;
            case 'node_id': $where[] = 'c.node_id = ?'; $params[] = intval($v); break;
            case 'caller': case 'callee': case 'caller_in': case 'callee_in':
            case 'gateway_in': case 'gateway_out': case 'account':
                $where[] = "c.$k LIKE ?"; $params[] = "%$v%"; break;
            case 'disconnect_cause': $where[] = 'c.disconnect_cause = ?'; $params[] = $v; break;
            case 'duration_min': $where[] = 'c.duration >= ?'; $params[] = intval($v); break;
            case 'duration_max': $where[] = 'c.duration <= ?'; $params[] = intval($v); break;
            case 'start_time_from': $where[] = 'c.start_time >= ?'; $params[] = $v; break;
            case 'start_time_to':
                $to = $v;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to .= ' 23:59:59';
                $where[] = 'c.start_time <= ?'; $params[] = $to; break;
        }
    }
    return [implode(' AND ', $where), $params];
}

// ---------- 工具：复刻 cdrDayTables ----------
function dayTables($pdo, $from, $to) {
    $fromDay = $from ? substr($from, 0, 10) : date('Y-m-d');
    $toDay   = $to   ? substr($to, 0, 10)   : $fromDay;
    if ($fromDay > $toDay) { $t = $fromDay; $fromDay = $toDay; $toDay = $t; }
    $tables = [];
    $cur = new DateTime($fromDay);
    $last = new DateTime($toDay);
    while ($cur <= $last) {
        $name = 'y_cdr_' . $cur->format('Ymd');
        $escaped = str_replace('_', '\\_', $name);
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($escaped));
        if ($st->fetchColumn()) $tables[] = $name;
        $cur->modify('+1 day');
    }
    return $tables;
}

// ---------- 工具：数据库期望 total（复刻 handleList 计数逻辑，含多表参数重复）----------
function dbExpect($pdo, $tables, $whereSQL, $params) {
    if (empty($tables)) return 0;
    if (count($tables) === 1) {
        $sql = "SELECT COUNT(*) FROM `{$tables[0]}` c " . cdrJoinSQL() . " WHERE $whereSQL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return intval($stmt->fetchColumn());
    }
    $multi = [];
    for ($i = 0; $i < count($tables); $i++) $multi = array_merge($multi, $params);
    $parts = [];
    foreach ($tables as $t) $parts[] = "SELECT COUNT(*) cnt FROM `$t` c " . cdrJoinSQL() . " WHERE $whereSQL";
    $sql = "SELECT SUM(cnt) FROM (" . implode(' UNION ALL ', $parts) . ") x";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($multi);
    return intval($stmt->fetchColumn());
}

// ===================== 主流程 =====================
$pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
$apiToken = login();
if (!$apiToken) { echo "登录失败，无法继续\n"; exit(1); }

$pass = 0; $fail = 0;
$results = [];

function check($name, $cond, $detail = '') {
    global $pass, $fail, $results;
    if ($cond) { $pass++; $status = 'PASS'; }
    else { $fail++; $status = 'FAIL'; }
    $results[] = sprintf("  [%s] %s%s", $status, $name, $detail ? "  → $detail" : '');
}

// 构造一个查询，返回 [$apiTotal, $apiData, $expectTotal, $http, $isJson]
function runCase($pdo, $query, $filters) {
    global $apiToken;
    $r = callApi('/api/cdr', $query);
    $expect = 0;
    if ($r['json'] && isset($r['json']['success'])) {
        $tables = dayTables($pdo, $query['start_time_from'] ?? '', $query['start_time_to'] ?? '');
        [$whereSQL, $params] = buildWhere($filters);
        $expect = dbExpect($pdo, $tables, $whereSQL, $params);
    }
    $apiTotal = ($r['json']['success'] ?? false) ? ($r['json']['data']['total'] ?? 'N/A') : 'N/A';
    $apiData = ($r['json']['success'] ?? false) ? count($r['json']['data']['data'] ?? []) : 0;
    return [$apiTotal, $apiData, $expect, $r['http'], $r['json'] !== null, $r['raw']];
}

echo "==================== CDR 维度筛选测试 ====================\n";

// ---------- A. 单维度：存在性 ----------
[$at, $ad, $ex, $http, $json, $raw] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07']);
check("A1 单天(07-07)无筛选返回全部", $json && $at == $ex && $ex == 11, "api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123']);
check("A2 caller=123 命中", $at == $ex && $ex > 0, "api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','callee'=>'123','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','callee'=>'123']);
check("A3 callee=123 与库一致(07-07该值实际0行)", $at == $ex, "api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','gateway_in'=>'对接网关-测试','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','gateway_in'=>'对接网关-测试']);
check("A4 gateway_in=对接网关-测试 命中(触发COLLATE JOIN)", $at == $ex && $ex > 0, "api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','account'=>'123','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','account'=>'123']);
check("A5 account=123 命中", $at == $ex && $ex > 0, "api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','disconnect_cause'=>'-7','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','disconnect_cause'=>'-7']);
check("A6 disconnect_cause=-7 命中", $at == $ex && $ex > 0, "api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','duration_min'=>'0','duration_max'=>'3','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','duration_min'=>'0','duration_max'=>'3']);
check("A7 duration 0~3 与库一致", $at == $ex, "api=$at expect=$ex(注:07-07有duration>3的行,非全量)");

// ---------- B. 单维度：不存在性 ----------
[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'zzzzzzzz','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'zzzzzzzz']);
check("B1 caller=不存在 返回空", $at === 0 && $ad === 0, "api=$at data=$ad");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','disconnect_cause'=>'999','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','disconnect_cause'=>'999']);
check("B2 disconnect_cause=999 返回空", $at === 0 && $ad === 0, "api=$at data=$ad");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','gateway_out'=>'不存在的网关','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','gateway_out'=>'不存在的网关']);
check("B3 gateway_out=不存在 返回空", $at === 0 && $ad === 0, "api=$at data=$ad");

// ---------- C. 组合维度（单天）----------
[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123','callee'=>'123','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123','callee'=>'123']);
check("C1 caller+callee 组合", $at == $ex, "api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123','disconnect_cause'=>'-7','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123','disconnect_cause'=>'-7']);
check("C2 caller+disconnect_cause 组合", $at == $ex, "api=$at expect=$ex");

// ---------- D. 跨天 + 维度（多表 UNION + 参数匹配 + COLLATE JOIN）----------
[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-07','gateway_in'=>'对接网关-测试','pageSize'=>50], ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-07','gateway_in'=>'对接网关-测试']);
check("D1 跨天+gateway_in(JOIN+多表参数)", $json && $at == $ex && $at > 0, "http=$http api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-07','disconnect_cause'=>'-7','pageSize'=>50], ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-07','disconnect_cause'=>'-7']);
check("D2 跨天+disconnect_cause 精确(多表参数)", $json && $at == $ex, "http=$http api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-07','pageSize'=>50], ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-07']);
check("D3 跨天无筛选 total=两日之和", $json && $at == $ex && $at == 14, "http=$http api=$at expect=$ex");

[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-13','pageSize'=>50], ['start_time_from'=>'2026-07-06','start_time_to'=>'2026-07-13']);
check("D4 跨天含空表(06~13)", $json && $at == $ex && $at == 15, "http=$http api=$at expect=$ex");

// ---------- E. 日期补全验证 ----------
[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07']);
check("E1 纯日期查询含当天全部(07-07=11行)", $at == 11, "api=$at");

// ---------- F. 通配符 / 边界 ----------
[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'1%2','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'1%2']);
check("F1 caller含LIKE通配符% 不报错", $json, "http=$http api=$at expect=$ex");

[$at, $ad, $ex, $http] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','page'=>200,'pageSize'=>50,'caller'=>'123'], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123']);
check("F2 超大offset(offset=9950>5000) 返回400", $http === 400, "http=$http");

[$at, $ad, $ex, $http] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','pageSize'=>500,'caller'=>'123'], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','caller'=>'123']);
check("F3 pageSize=500 截断到200(返回<=200条)", $json && $ad <= 200, "data=$ad http=$http");

// ---------- G. node_id=0 的 empty 陷阱验证 ----------
// 数据库 CDR 的 node_id 全为 0。代码 if(!empty($_GET['node_id'])) —— PHP empty('0')===true，会被忽略
[$at, $ad, $ex] = runCase($pdo, ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','node_id'=>'0','pageSize'=>50], ['start_time_from'=>'2026-07-07','start_time_to'=>'2026-07-07','node_id'=>'0']);
check("G1 node_id=0 被empty忽略(返回全部而非仅node_id=0)", $at == 11, "api=$at(期望全量11说明node_id=0被忽略)");

// ---------- 输出 ----------
foreach ($results as $r) echo $r . "\n";
echo "\n==================== 结果：$pass PASS / $fail FAIL ====================\n";
exit($fail > 0 ? 1 : 0);
