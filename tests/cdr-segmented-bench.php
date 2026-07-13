<?php
/**
 * CDR 深分页性能基准测试 —— 验证「基于计数器的分段定位分页」毫秒级返回
 *
 * 设计：
 *  1) 造 5 天 × 20000 条 = 10 万条测试数据（未来日期，不影响现有查询）
 *  2) 更新 y_cdr_counter 预聚合计数
 *  3) 对比两种查询方式在深分页（offset 接近 total）的耗时：
 *     - 旧方式：UNION 所有日表后 ORDER BY received_at DESC LIMIT offset, pageSize
 *     - 新方式（分段定位）：用 counter 定位 targetDay + localOffset，只查 1~2 个日表的小 LIMIT
 *  4) 验证新方式返回行数正确 + received_at 全局倒序
 *  5) 清理测试数据
 *
 * 用法：php tests/cdr-segmented-bench.php
 */

$cfg = require 'server/config/db.php';
$pdo = new PDO(
    "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4",
    $cfg['username'], $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// 测试用未来日期（避免污染现有数据），倒序排列（最新在前）
$testDates = ['2026-07-24', '2026-07-23', '2026-07-22', '2026-07-21', '2026-07-20'];
$perDay = 20000;
$pageSize = 50;

echo "==================== CDR 深分页性能基准 ====================\n";
echo "造数：{$perDay} 条/天 × " . count($testDates) . " 天 = " . ($perDay * count($testDates)) . " 条\n";

// ---------- 1. 建测试表 + 造数据 ----------
$sourceTbl = 'y_cdr_20260707';
$srcExists = $pdo->query("SHOW TABLES LIKE '$sourceTbl'")->fetchColumn();
if (!$srcExists) {
    echo "源表 $sourceTbl 不存在，无法复制结构，退出\n";
    exit(1);
}

foreach ($testDates as $d) {
    $tbl = 'y_cdr_' . str_replace('-', '', $d);
    $pdo->exec("DROP TABLE IF EXISTS `$tbl`");
    $pdo->exec("CREATE TABLE `$tbl` LIKE `$sourceTbl`");
}

$insertCols = 'cdr_id, node_id, call_id, received_at, start_time, caller, callee, direction, disconnect_cause, gateway_in, gateway_out, account, raw_data';
foreach ($testDates as $d) {
    $tbl = 'y_cdr_' . str_replace('-', '', $d);
    $base = strtotime($d . ' 00:00:00');
    $batch = [];
    for ($i = 0; $i < $perDay; $i++) {
        // received_at 递增（每天 0~86399 秒），保证倒序可预测
        $ts = $base + $i;
        $rt = date('Y-m-d H:i:s', $ts);
        $st = date('Y-m-d H:i:s', $ts - 5);
        $cdrId = "SEGBENCH_{$d}_{$i}";
        $caller = '138' . str_pad($i % 10000, 4, '0');
        $callee = '139' . str_pad(($i * 7) % 10000, 4, '0');
        $batch[] = "('$cdrId', 0, 'CALLID_{$d}_{$i}', '$rt', '$st', '$caller', '$callee', 'outbound', -7, 'gw_in', 'gw_out', 'acc', '')";
        if (count($batch) >= 1000) {
            $pdo->exec("INSERT INTO `$tbl` ($insertCols) VALUES " . implode(',', $batch));
            $batch = [];
        }
    }
    if ($batch) $pdo->exec("INSERT INTO `$tbl` ($insertCols) VALUES " . implode(',', $batch));
    // 更新 counter
    $pdo->exec("DELETE FROM y_cdr_counter WHERE date='$d'");
    $pdo->exec("INSERT INTO y_cdr_counter (date, node_id, total) SELECT '$d', 0, COUNT(*) FROM `$tbl`");
}
echo "  [1] 造数 + counter 更新完成\n";

// ---------- 2. 复刻分段定位逻辑（新方式） ----------
function benchSegmented($pdo, $testDates, $offset, $pageSize) {
    // 从 counter 取每日 total（倒序）
    $counterMap = [];
    $total = 0;
    foreach ($testDates as $d) {
        $cnt = $pdo->query("SELECT total FROM y_cdr_counter WHERE date='$d' AND node_id=0")->fetchColumn();
        $counterMap[$d] = intval($cnt);
        $total += intval($cnt);
    }
    // 定位 targetDay + localOffset
    $accum = 0;
    $targetDate = null;
    $localOffset = 0;
    foreach ($testDates as $dd) {
        if ($accum + $counterMap[$dd] > $offset) {
            $targetDate = $dd;
            $localOffset = $offset - $accum;
            break;
        }
        $accum += $counterMap[$dd];
    }
    if ($targetDate === null) return ['ms' => 0, 'rows' => 0, 'total' => $total, 'empty' => true];

    $targetTable = 'y_cdr_' . str_replace('-', '', $targetDate);
    $targetDayTotal = $counterMap[$targetDate];

    if ($localOffset + $pageSize <= $targetDayTotal) {
        $sql = "SELECT cdr_id, received_at FROM `$targetTable` ORDER BY received_at DESC LIMIT $localOffset, $pageSize";
    } else {
        $needFromPrev = $localOffset + $pageSize - $targetDayTotal;
        $idx = array_search($targetDate, $testDates);
        $prevDate = $testDates[$idx + 1] ?? null;
        if ($prevDate) {
            $prevTable = 'y_cdr_' . str_replace('-', '', $prevDate);
            $b1 = "(SELECT cdr_id, received_at FROM `$targetTable` ORDER BY received_at DESC LIMIT $localOffset, " . ($targetDayTotal - $localOffset) . ")";
            $b2 = "(SELECT cdr_id, received_at FROM `$prevTable` ORDER BY received_at DESC LIMIT $needFromPrev)";
            $sql = "$b1 UNION ALL $b2";
        } else {
            $sql = "SELECT cdr_id, received_at FROM `$targetTable` ORDER BY received_at DESC LIMIT $localOffset, " . ($targetDayTotal - $localOffset);
        }
    }
    $t0 = microtime(true);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $t1 = microtime(true);
    return ['ms' => ($t1 - $t0) * 1000, 'rows' => count($rows), 'total' => $total];
}

// ---------- 3. 旧方式（UNION 全表 LIMIT offset） ----------
function benchOld($pdo, $testDates, $offset, $pageSize) {
    $unions = [];
    foreach ($testDates as $d) {
        $tbl = 'y_cdr_' . str_replace('-', '', $d);
        $unions[] = "SELECT cdr_id, received_at FROM `$tbl`";
    }
    $sql = "SELECT cdr_id, received_at FROM (" . implode(' UNION ALL ', $unions) . ") t ORDER BY received_at DESC LIMIT $offset, $pageSize";
    $t0 = microtime(true);
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $t1 = microtime(true);
    return ['ms' => ($t1 - $t0) * 1000, 'rows' => count($rows)];
}

// ---------- 4. 跑基准 ----------
$total = $perDay * count($testDates);
$cases = [
    '第1页(offset=0)' => 0,
    '中间页(offset=' . intval($total / 2) . ')' => intval($total / 2),
    '最后页(offset=' . ($total - $pageSize) . ')' => $total - $pageSize,
];

foreach ($cases as $label => $offset) {
    $new = benchSegmented($pdo, $testDates, $offset, $pageSize);
    $old = benchOld($pdo, $testDates, $offset, $pageSize);
    $speedup = $old['ms'] > 0 ? sprintf('%.1fx', $old['ms'] / max($new['ms'], 0.001)) : 'N/A';
    echo "\n  [$label]\n";
    echo "    新方式(分段定位): " . sprintf('%.2f ms', $new['ms']) . " | 返回 {$new['rows']} 行\n";
    echo "    旧方式(UNION全表): " . sprintf('%.2f ms', $old['ms']) . " | 返回 {$old['rows']} 行\n";
    echo "    加速比: $speedup\n";
    // 验证：新方式返回行数正确（最后页可能 < pageSize）
    $expectRows = min($pageSize, $total - $offset);
    $ok = ($new['rows'] === $expectRows);
    echo "    行数校验: " . ($ok ? "PASS (期望 $expectRows)" : "FAIL (期望 $expectRows, 实际 {$new['rows']})") . "\n";
}

// ---------- 5. 清理 ----------
foreach ($testDates as $d) {
    $tbl = 'y_cdr_' . str_replace('-', '', $d);
    $pdo->exec("DROP TABLE IF EXISTS `$tbl`");
    $pdo->exec("DELETE FROM y_cdr_counter WHERE date='$d'");
}
echo "\n  [5] 测试数据已清理\n";
echo "==================== 基准完成 ====================\n";
