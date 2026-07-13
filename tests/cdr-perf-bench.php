<?php
/**
 * CDR 查询性能压测：对比「旧逻辑（精确 COUNT + 物化全表）」vs「方案A（counter + 每表 LIMIT）」。
 * 造一张 N 行压测表 y_cdr_perf（格式不匹配 y_cdr_YYYYMMDD，不进 API 路由，安全隔离），
 * 测两种「数据查询」与两种「total 计算」的耗时差异。
 *
 * 用法：php tests/cdr-perf-bench.php
 */
$cfg = require __DIR__ . '/../server/config/db.php';
$pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4", $cfg['username'], $cfg['password']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$N = 50000;
$BENCH = 'y_cdr_perf';

echo "=== 准备压测表 $BENCH (克隆 y_cdr_20260711 结构+索引) ===\n";
$pdo->exec("DROP TABLE IF EXISTS `$BENCH`");
$pdo->exec("CREATE TABLE `$BENCH` LIKE y_cdr_20260711");

echo "插入 $N 行（事务批量，每批 5000）...\n";
$cols = "cdr_id,node_id,node_ip,call_id,caller,callee,caller_out,callee_out,caller_in,callee_in,caller_ip,callee_ip,start_time,end_time,duration,continuous_duration,bill_duration,fee_rate,fee,direction,disconnect_cause,gateway_in,gateway_out,account,fee_rate_group,raw_data,received_at";
$sql = "INSERT INTO `$BENCH` ($cols) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
$stmt = $pdo->prepare($sql);
$batch = 5000;
$inserted = 0;
$pdo->beginTransaction();
for ($n = 1; $n <= $N; $n++) {
    $daysAgo = $n % 30;
    $ts = time() - $daysAgo * 86400 - ($n % 86400);
    $recv = date('Y-m-d H:i:s', $ts);
    $start = date('Y-m-d H:i:s', $ts - 10);
    $stmt->execute([
        "cdr-$n", 1, '127.0.0.1', "call-$n",
        '13' . str_pad($n % 1000000000, 10, '0'),
        '13' . str_pad(($n * 7) % 1000000000, 10, '0'),
        '', '', '', '',
        '127.0.0.1', '127.0.0.2',
        $start, $recv,
        $n % 600, $n % 600, $n % 600,
        round(rand(0, 100) / 1000, 4), round(rand(0, 100) / 1000, 4),
        ($n % 2 == 0) ? 'outbound' : 'inbound',
        [-7, -8, -10, -11, 16][$n % 5],
        "gw-in-" . $n % 20, "gw-out-" . $n % 20,
        "acc-" . $n % 50, "frg-" . $n % 10, '{}',
        $recv
    ]);
    $inserted++;
    if ($inserted % $batch === 0) { $pdo->commit(); $pdo->beginTransaction(); }
}
$pdo->commit();
echo "  插入完成：$inserted 行\n";

function timed($pdo, $sql, $params = []) {
    $t0 = microtime(true);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $st->fetchAll(PDO::FETCH_ASSOC);
    return microtime(true) - $t0;
}

echo "\n=== 数据查询耗时（取最新 50 条）===\n";
// 旧逻辑：UNION 后物化全表再排序取 50（这里用单表 $N 行模拟「一天 N 万」的物化代价）
$tOld = timed($pdo, "SELECT * FROM (SELECT $cols FROM `$BENCH` c WHERE 1=1 ORDER BY c.received_at DESC LIMIT $N) t ORDER BY received_at DESC LIMIT 50");
// 方案A：每表先 LIMIT 50（走 idx_received_at），再 UNION 取 top 50
$tNew = timed($pdo, "SELECT * FROM (SELECT $cols FROM `$BENCH` c WHERE 1=1 ORDER BY c.received_at DESC LIMIT 50) t ORDER BY received_at DESC LIMIT 50");
printf("  旧(物化全表 %d 行): %.3fs\n", $N, $tOld);
printf("  新(每表 LIMIT 50):   %.3fs\n", $tNew);
printf("  => 数据查询提速: %.1fx\n", $tOld / max($tNew, 0.0001));

echo "\n=== total 计算耗时 ===\n";
// 旧逻辑：COUNT(*) 全表（跨天多表时 ×表数，这里单表模拟）
$tOldT = timed($pdo, "SELECT COUNT(*) FROM `$BENCH` c WHERE 1=1");
// 方案A：counter 预聚合 O(天数)
$pdo->exec("INSERT INTO y_cdr_counter (date, node_id, total) VALUES ('2000-01-01', 0, $N) ON DUPLICATE KEY UPDATE total=$N");
$tNewT = timed($pdo, "SELECT COALESCE(SUM(total),0) FROM y_cdr_counter WHERE date IN ('2000-01-01')");
printf("  旧(COUNT(*) 全表): %.3fs\n", $tOldT);
printf("  新(counter SUM):   %.3fs\n", $tNewT);
printf("  => total 提速: %.1fx\n", $tOldT / max($tNewT, 0.0001));

echo "\n=== 估算跨天（如 30 天 × 每天 $N 行 = " . ($N * 30) . " 行）===\n";
printf("  旧 total ≈ %.2fs（30 表各 COUNT）\n", $tOldT * 30);
printf("  新 total ≈ %.3fs（counter 30 行 SUM）\n", $tNewT);

echo "\n=== 清理 ===\n";
$pdo->exec("DROP TABLE IF EXISTS `$BENCH`");
$pdo->exec("DELETE FROM y_cdr_counter WHERE date='2000-01-01'");
echo "done\n";
