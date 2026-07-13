<?php
/**
 * 重建 y_cdr_summary 总数汇总表，使其与日表实际行数一致。
 *
 * 背景：summary 由 cdr-receiver 在批量写入时原子累加
 * (ON DUPLICATE KEY UPDATE total_count = total_count + VALUES(total_count))。
 * 若日表被 TRUNCATE/清空而 summary 未同步清，两者就会漂移，导致「无条件查询的总数」显示错误。
 *
 * 本脚本采取「先清空、再按表名日期重新聚合」的重建策略，保证与日表现状严格一致。
 *
 * 用法（CLI）：
 *   php server/database/fix_cdr_summary.php
 */

require_once __DIR__ . '/../utils/Database.php';

$pdo = Database::getConnection();

echo "重建 y_cdr_summary 总数汇总表 ...\n";

// 建表兜底（首次运行或新环境）
$pdo->exec("CREATE TABLE IF NOT EXISTS `y_cdr_summary` (
    `date` DATE NOT NULL COMMENT '日期(YYYY-MM-DD)',
    total_count BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日话单总条数',
    PRIMARY KEY (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CDR按天总数汇总(无条件查询毫秒级取总数)'");
echo "  [0] 确认表存在\n";

$pdo->exec('DELETE FROM y_cdr_summary');
echo "  [1] 已清空旧汇总数据\n";

// 找到所有日表 y_cdr_YYYYMMDD
$tables = [];
$rs = $pdo->query("SHOW TABLES LIKE 'y_cdr\\_%'");
while ($r = $rs->fetch(PDO::FETCH_NUM)) {
    $t = $r[0];
    if (preg_match('/^y_cdr_(\d{4})(\d{2})(\d{2})$/', $t)) {
        $tables[] = $t;
    }
}

$rebuilt = 0;
$grandTotal = 0;
foreach ($tables as $tbl) {
    preg_match('/^y_cdr_(\d{4})(\d{2})(\d{2})$/', $tbl, $m);
    $date = "{$m[1]}-{$m[2]}-{$m[3]}";
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    if ($cnt > 0) {
        $stmt = $pdo->prepare("INSERT INTO y_cdr_summary (`date`, total_count) VALUES (?, ?)");
        $stmt->execute([$date, $cnt]);
    }
    echo "  [2] {$tbl} -> {$cnt} 行 (date={$date})\n";
    $rebuilt++;
    $grandTotal += $cnt;
}

echo "完成：重建 {$rebuilt} 张日表，总计 {$grandTotal} 条。\n";
echo "现在无条件查询的总数将从此汇总表读取，毫秒级返回。\n";
