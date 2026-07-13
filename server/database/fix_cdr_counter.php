<?php
/**
 * 重建 y_cdr_counter 预聚合计数器，使其与日表实际行数一致。
 *
 * 背景：counter 由 cdr-receiver 在收到话单时增量累加（ON DUPLICATE KEY UPDATE total = total + VALUES(total)）。
 * 若日表被 TRUNCATE/清空而 counter 未同步清，两者就会漂移，导致「无筛选查询的总数」显示错误。
 *
 * 本脚本采取「先清空、再按表名日期重新聚合」的重建策略（非累加），保证与日表现状严格一致。
 * 日期口径与 cdr-receiver 的 updateCounter 保持一致：使用表名日期（y_cdr_YYYYMMDD → YYYY-MM-DD），
 * 而非 start_time 的 DATE()，避免两套口径叠加导致错行。
 *
 * 用法（CLI）：
 *   php server/database/fix_cdr_counter.php
 */

require_once __DIR__ . '/../utils/Database.php';

$pdo = Database::getConnection();

echo "重建 y_cdr_counter 预聚合计数器 ...\n";
$pdo->exec('DELETE FROM y_cdr_counter');
echo "  [1] 已清空旧计数器\n";

$tables = [];
$rs = $pdo->query("SHOW TABLES LIKE 'y_cdr_%'");
while ($r = $rs->fetch(PDO::FETCH_NUM)) {
    $t = $r[0];
    if (preg_match('/^y_cdr_(\d{4})(\d{2})(\d{2})$/', $t)) {
        $tables[] = $t;
    }
}

$rebuilt = 0;
foreach ($tables as $tbl) {
    preg_match('/^y_cdr_(\d{4})(\d{2})(\d{2})$/', $tbl, $m);
    $date = "{$m[1]}-{$m[2]}-{$m[3]}";
    // 按表名日期 + node_id 聚合（node_id 来自日表，COALESCE 防 NULL）
    $pdo->exec("INSERT INTO y_cdr_counter (date, node_id, total)
        SELECT '{$date}', COALESCE(node_id, 0), COUNT(*)
        FROM `{$tbl}`
        GROUP BY node_id");
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    echo "  [2] {$tbl} -> 聚合 {$cnt} 行 (date={$date})\n";
    $rebuilt++;
}

echo "完成：重建 {$rebuilt} 张日表的计数器。\n";
