<?php
/**
 * 一次性回填脚本：将已有 y_cdr_YYYYMMDD 日表聚合进预聚合汇总表
 *   - y_cdr_hourly   （时间趋势 / KPI 数据源）
 *   - y_cdr_daily_dim（维度 TOP-N 数据源）
 *   - y_cdr_counter  （当日节点总数）
 *
 * 用法（CLI）：
 *   php server/database/backfill_cdr_summary.php
 *
 * 说明：
 *   - 增量 UPSERT（ON DUPLICATE KEY UPDATE 累加），可重复运行不重复计数
 *   - 仅回填汇总表，不动原始日表
 *   - 大表较多时可能较慢，建议分批（按天）或低峰期执行
 *   - 依赖 server/.env 中的 YUMP_DB_PASSWORD（同 init.php）
 */

require_once __DIR__ . '/../utils/Database.php';

$pdo = Database::getConnection();

echo "开始回填 CDR 预聚合汇总表...\n";

// 列出所有日表
$tables = [];
$rs = $pdo->query("SHOW TABLES LIKE 'y_cdr_%'");
while ($r = $rs->fetch(PDO::FETCH_NUM)) {
    $t = $r[0];
    if (preg_match('/^y_cdr_\d{8}$/', $t)) $tables[] = $t;
}
echo "发现日表 " . count($tables) . " 张\n";

$totalTables = count($tables);
$idx = 0;
foreach ($tables as $tbl) {
    $idx++;
    echo "[{$idx}/{$totalTables}] 处理 {$tbl} ... ";

    // ---- hourly ----
    $pdo->exec("INSERT INTO y_cdr_hourly
        (date, hour, node_id, direction, calls, answered, total_duration, bill_duration, fee, cost, b1, b2, b3, b4, b5)
        SELECT DATE(start_time), HOUR(start_time), node_id, direction,
            COUNT(*),
            SUM(duration > 0),
            COALESCE(SUM(duration),0),
            COALESCE(SUM(bill_duration),0),
            COALESCE(SUM(fee),0),
            COALESCE(SUM(cost),0),
            SUM(duration <= 10),
            SUM(duration > 10 AND duration <= 30),
            SUM(duration > 30 AND duration <= 60),
            SUM(duration > 60 AND duration <= 180),
            SUM(duration > 180)
        FROM `{$tbl}`
        GROUP BY DATE(start_time), HOUR(start_time), node_id, direction
        ON DUPLICATE KEY UPDATE
            calls = calls + VALUES(calls),
            answered = answered + VALUES(answered),
            total_duration = total_duration + VALUES(total_duration),
            bill_duration = bill_duration + VALUES(bill_duration),
            fee = fee + VALUES(fee),
            cost = cost + VALUES(cost),
            b1 = b1 + VALUES(b1), b2 = b2 + VALUES(b2), b3 = b3 + VALUES(b3), b4 = b4 + VALUES(b4), b5 = b5 + VALUES(b5)");

    // ---- daily_dim: gateway_in / gateway_out / account / disconnect_cause ----
    $dims = [
        'gateway_in'    => 'gateway_in',
        'gateway_out'   => 'gateway_out',
        'account'       => 'account',
        'disconnect_cause' => 'disconnect_cause',
    ];
    foreach ($dims as $dim => $col) {
        $pdo->exec("INSERT INTO y_cdr_daily_dim
            (date, node_id, dim, dim_value, calls, answered, total_duration, fee, cost)
            SELECT DATE(start_time), node_id, '{$dim}', `{$col}`,
                COUNT(*),
                SUM(duration > 0),
                COALESCE(SUM(duration),0),
                COALESCE(SUM(fee),0),
                COALESCE(SUM(cost),0)
            FROM `{$tbl}`
            WHERE `{$col}` != ''
            GROUP BY DATE(start_time), node_id, `{$col}`
            ON DUPLICATE KEY UPDATE
                calls = calls + VALUES(calls),
                answered = answered + VALUES(answered),
                total_duration = total_duration + VALUES(total_duration),
                fee = fee + VALUES(fee),
                cost = cost + VALUES(cost)");
    }

    // ---- daily_dim: caller_prefix (前4位) ----
    $pdo->exec("INSERT INTO y_cdr_daily_dim
        (date, node_id, dim, dim_value, calls, answered, total_duration, fee, cost)
        SELECT DATE(start_time), node_id, 'caller_prefix', LEFT(caller, 4),
            COUNT(*),
            SUM(duration > 0),
            COALESCE(SUM(duration),0),
            COALESCE(SUM(fee),0),
            COALESCE(SUM(cost),0)
        FROM `{$tbl}`
        WHERE caller != '' AND LENGTH(caller) >= 4
        GROUP BY DATE(start_time), node_id, LEFT(caller, 4)
        ON DUPLICATE KEY UPDATE
            calls = calls + VALUES(calls),
            answered = answered + VALUES(answered),
            total_duration = total_duration + VALUES(total_duration),
            fee = fee + VALUES(fee),
            cost = cost + VALUES(cost)");

    // ---- counter: 当日节点总数 ----
    $pdo->exec("INSERT INTO y_cdr_counter (date, node_id, total)
        SELECT DATE(start_time), node_id, COUNT(*)
        FROM `{$tbl}`
        GROUP BY DATE(start_time), node_id
        ON DUPLICATE KEY UPDATE total = total + VALUES(total)");

    echo "OK\n";
}

echo "回填完成。\n";
