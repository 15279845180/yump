<?php
/**
 * 报表统计 API
 *
 * GET /api/report/overview  — 话务概览（KPI + 趋势 + 节点分布 + 方向占比）
 *
 * 参数：
 *   start_time_from  — 开始日期 (YYYY-MM-DD)，默认今天
 *   start_time_to    — 结束日期 (YYYY-MM-DD)，默认等于 from
 *   node_id          — 节点ID筛选（可选）
 */

require_once __DIR__ . '/../../utils/Database.php';
require_once __DIR__ . '/../../utils/Response.php';

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
$parts = array_values(array_filter(explode('/', trim($path, '/'))));
$action = $parts[2] ?? '';

switch ($method) {
    case 'GET':
        if ($action === 'overview') {
            handleOverview($pdo);
        } elseif ($action === 'traffic') {
            handleTraffic($pdo);
        } elseif ($action === 'quality') {
            handleQuality($pdo);
        } elseif ($action === 'number') {
            handleNumber($pdo);
        } elseif ($action === 'gateway') {
            handleGateway($pdo);
        } elseif ($action === 'cause') {
            handleCause($pdo);
        } elseif ($action === 'node') {
            handleNodeCompare($pdo);
        } elseif ($action === 'finance-customer') {
            handleFinanceCustomer($pdo);
        } elseif ($action === 'finance-line') {
            handleFinanceLine($pdo);
        } elseif ($action === 'finance-profit') {
            handleFinanceProfit($pdo);
        } elseif ($action === 'finance-settlement') {
            handleFinanceSettlement($pdo);
        } elseif ($action === 'finance-payment') {
            handleFinancePayment($pdo);
        } elseif ($action === 'options') {
            reportOptions($pdo);
        } else {
            Response::error('未知的报表类型: ' . $action, 404);
        }
        break;
    default:
        Response::error('不支持的请求方法', 405);
}

/* ============================================================
 * 通用辅助：解析日期范围 → 返回已存在的 CDR 日表
 * ========================================================== */
function reportDayTables($pdo) {
    $from = trim($_GET['start_time_from'] ?? '');
    $to   = trim($_GET['start_time_to'] ?? '');
    $fromDay = $from ? substr($from, 0, 10) : date('Y-m-d');
    $toDay   = $to   ? substr($to, 0, 10)   : $fromDay;
    if ($fromDay > $toDay) { $t = $fromDay; $fromDay = $toDay; $toDay = $t; }

    $maxDays = 92;
    $d = new DateTime($fromDay);
    $end = new DateTime($toDay);
    $span = (int)$end->diff($d)->format('%a');
    if ($span > $maxDays) {
        $fromDay = $end->modify("-{$maxDays} days")->format('Y-m-d');
    }

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
    return [$tables, $fromDay, $toDay];
}

/** 构建 WHERE 条件（支持 node_id + direction + account + gateway_in + gateway_out） */
function reportWhere() {
    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['node_id'])) {
        $where[] = 'c.node_id = ?';
        $params[] = intval($_GET['node_id']);
    }
    if (!empty($_GET['direction']) && in_array($_GET['direction'], ['inbound', 'outbound'])) {
        $where[] = 'c.direction = ?';
        $params[] = $_GET['direction'];
    }
    if (!empty($_GET['account'])) {
        $where[] = 'c.account = ?';
        $params[] = $_GET['account'];
    }
    if (!empty($_GET['gateway_in'])) {
        $where[] = 'c.gateway_in = ?';
        $params[] = $_GET['gateway_in'];
    }
    if (!empty($_GET['gateway_out'])) {
        $where[] = 'c.gateway_out = ?';
        $params[] = $_GET['gateway_out'];
    }
    return [implode(' AND ', $where), $params];
}

/** 预聚合汇总表是否可服务该区间（表存在且区间内有数据） */
function summaryAvailable($pdo, $fromDay, $toDay) {
    try {
        $st = $pdo->query("SELECT 1 FROM y_cdr_hourly WHERE date BETWEEN " . $pdo->quote($fromDay) . " AND " . $pdo->quote($toDay) . " LIMIT 1");
        return (bool)$st->fetchColumn();
    } catch (\Throwable $e) {
        return false; // 表不存在等 → 回退原始扫描
    }
}

/**
 * 时间类汇总(hourly)不支持的筛选：account / gateway_in / gateway_out
 * （hourly 含 node_id / direction 列可服务；维度原始列不在汇总表）
 */
function hasUnsupportedTimeFilter() {
    return !empty($_GET['account']) || !empty($_GET['gateway_in']) || !empty($_GET['gateway_out']);
}

/**
 * 维度类汇总(daily_dim)不支持的筛选：上述三项 + direction
 * （daily_dim 不含 direction 列，direction 筛选须回退原始日表）
 */
function hasUnsupportedDimFilter() {
    return hasUnsupportedTimeFilter() || !empty($_GET['direction']);
}

/** 汇总表适用的 node 过滤条件（无 account/gateway 时） */
function summaryNodeFilter() {
    if (!empty($_GET['node_id'])) {
        return ['node_id = ?', [intval($_GET['node_id'])]];
    }
    return ['', []];
}

/** 从日表名数组推导日期区间（表名升序，首=起，尾=止） */
function cdrTableRange($tables) {
    if (empty($tables)) return [date('Y-m-d'), date('Y-m-d')];
    $fmt = function ($s) { return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2); };
    $f = substr($tables[0], 6);
    $l = substr($tables[count($tables) - 1], 6);
    return [$fmt($f), $fmt($l)];
}

/* ============================================================
 * 预聚合汇总表读取（亿级优化）：读 y_cdr_hourly / y_cdr_daily_dim
 * 仅服务 node_id / direction 筛选；account/gateway/direction(维度类) 回退原始日表
 * ========================================================== */

/** KPI（读 hourly） */
function reportKPISummary($pdo, $fromDay, $toDay) {
    [$ncond, $nparams] = summaryNodeFilter();
    $extra = $ncond;
    if (!empty($_GET['direction'])) { $extra .= ($extra ? ' AND ' : '') . 'direction = ?'; $nparams[] = $_GET['direction']; }
    $where = $extra ? "date BETWEEN ? AND ? AND $extra" : "date BETWEEN ? AND ?";
    $params = array_merge([$fromDay, $toDay], $nparams);
    $sql = "SELECT
        COALESCE(SUM(calls),0) AS total_calls,
        COALESCE(SUM(answered),0) AS answered_calls,
        COALESCE(SUM(total_duration),0) AS total_duration,
        COALESCE(SUM(bill_duration),0) AS total_bill_duration,
        COALESCE(SUM(fee),0) AS total_fee,
        COALESCE(SUM(CASE WHEN direction='outbound' THEN calls ELSE 0 END),0) AS outbound_calls,
        COALESCE(SUM(CASE WHEN direction='inbound' THEN calls ELSE 0 END),0) AS inbound_calls
    FROM y_cdr_hourly WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $total = intval($row['total_calls'] ?? 0);
    $answered = intval($row['answered_calls'] ?? 0);
    $duration = intval($row['total_duration'] ?? 0);
    return [
        'total_calls' => $total, 'answered_calls' => $answered,
        'asr' => $total > 0 ? round($answered / $total * 100, 2) : 0,
        'total_duration' => $duration, 'acd' => $answered > 0 ? round($duration / $answered, 1) : 0,
        'total_bill_duration' => intval($row['total_bill_duration'] ?? 0),
        'total_fee' => round(floatval($row['total_fee'] ?? 0), 4),
        'inbound_calls' => intval($row['inbound_calls'] ?? 0),
        'outbound_calls' => intval($row['outbound_calls'] ?? 0),
    ];
}

/** 按小时趋势（读 hourly，单天） */
function trafficHourlySummary($pdo, $day) {
    [$ncond, $nparams] = summaryNodeFilter();
    $extra = $ncond;
    if (!empty($_GET['direction'])) { $extra .= ($extra ? ' AND ' : '') . 'direction = ?'; $nparams[] = $_GET['direction']; }
    $where = $extra ? "date = ? AND $extra" : "date = ?";
    $params = array_merge([$day], $nparams);
    $sql = "SELECT hour,
        COALESCE(SUM(calls),0) AS total_calls,
        COALESCE(SUM(answered),0) AS answered_calls,
        COALESCE(SUM(total_duration),0) AS total_duration,
        COALESCE(SUM(fee),0) AS total_fee,
        COALESCE(SUM(CASE WHEN direction='inbound' THEN calls ELSE 0 END),0) AS inbound_calls,
        COALESCE(SUM(CASE WHEN direction='outbound' THEN calls ELSE 0 END),0) AS outbound_calls,
        COALESCE(SUM(CASE WHEN direction='inbound' THEN total_duration ELSE 0 END),0) AS inbound_duration,
        COALESCE(SUM(CASE WHEN direction='outbound' THEN total_duration ELSE 0 END),0) AS outbound_duration
    FROM y_cdr_hourly WHERE $where GROUP BY hour ORDER BY hour ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[intval($r['hour'])] = $r;
    $result = [];
    for ($h = 0; $h < 24; $h++) {
        $key = sprintf('%02d:00', $h);
        if (isset($map[$h])) $result[] = enrichRow($map[$h], $key);
        else $result[] = emptyHourRow($key);
    }
    return $result;
}

/** 按天趋势（读 hourly，多天） */
function trafficDailySummary($pdo, $fromDay, $toDay) {
    [$ncond, $nparams] = summaryNodeFilter();
    $extra = $ncond;
    if (!empty($_GET['direction'])) { $extra .= ($extra ? ' AND ' : '') . 'direction = ?'; $nparams[] = $_GET['direction']; }
    $where = $extra ? "date BETWEEN ? AND ? AND $extra" : "date BETWEEN ? AND ?";
    $params = array_merge([$fromDay, $toDay], $nparams);
    $sql = "SELECT date AS time_label,
        COALESCE(SUM(calls),0) AS total_calls,
        COALESCE(SUM(answered),0) AS answered_calls,
        COALESCE(SUM(total_duration),0) AS total_duration,
        COALESCE(SUM(fee),0) AS total_fee,
        COALESCE(SUM(CASE WHEN direction='inbound' THEN calls ELSE 0 END),0) AS inbound_calls,
        COALESCE(SUM(CASE WHEN direction='outbound' THEN calls ELSE 0 END),0) AS outbound_calls,
        COALESCE(SUM(CASE WHEN direction='inbound' THEN total_duration ELSE 0 END),0) AS inbound_duration,
        COALESCE(SUM(CASE WHEN direction='outbound' THEN total_duration ELSE 0 END),0) AS outbound_duration
    FROM y_cdr_hourly WHERE $where GROUP BY date ORDER BY date ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) $map[$r['time_label']] = $r;
    $result = [];
    $cur = new DateTime($fromDay);
    $last = new DateTime($toDay);
    while ($cur <= $last) {
        $key = $cur->format('Y-m-d');
        if (isset($map[$key])) $result[] = enrichRow($map[$key], $key);
        else $result[] = emptyHourRow($key);
        $cur->modify('+1 day');
    }
    return $result;
}

/** 节点分布（读 hourly） */
function reportNodeDistSummary($pdo, $fromDay, $toDay) {
    [$ncond, $nparams] = summaryNodeFilter();
    $where = $ncond ? "date BETWEEN ? AND ? AND $ncond" : "date BETWEEN ? AND ?";
    $params = array_merge([$fromDay, $toDay], $nparams);
    $sql = "SELECT h.node_id, COALESCE(n.name,'') AS node_name,
        COALESCE(SUM(h.calls),0) AS calls,
        COALESCE(SUM(h.answered),0) AS answered,
        COALESCE(SUM(h.total_duration),0) AS duration,
        COALESCE(SUM(h.fee),0) AS fee
    FROM y_cdr_hourly h LEFT JOIN y_nodes n ON h.node_id = n.id
    WHERE $where GROUP BY h.node_id ORDER BY calls DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** 呼入呼出占比（读 hourly） */
function reportDirectionSummary($pdo, $fromDay, $toDay) {
    [$ncond, $nparams] = summaryNodeFilter();
    $where = $ncond ? "date BETWEEN ? AND ? AND $ncond" : "date BETWEEN ? AND ?";
    $params = array_merge([$fromDay, $toDay], $nparams);
    $sql = "SELECT direction, COALESCE(SUM(calls),0) AS calls, COALESCE(SUM(total_duration),0) AS duration
        FROM y_cdr_hourly WHERE $where GROUP BY direction";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = ['inbound' => 0, 'outbound' => 0, 'inbound_duration' => 0, 'outbound_duration' => 0];
    foreach ($rows as $r) {
        if ($r['direction'] === 'inbound') {
            $result['inbound'] = intval($r['calls']);
            $result['inbound_duration'] = intval($r['duration']);
        } else {
            $result['outbound'] = intval($r['calls']);
            $result['outbound_duration'] = intval($r['duration']);
        }
    }
    return $result;
}

/** 维度 TOP-N（读 daily_dim）：dim ∈ gateway_in/gateway_out/disconnect_cause */
function qualityByDimSummary($pdo, $fromDay, $toDay, $dim) {
    [$ncond, $nparams] = summaryNodeFilter();
    $where = $ncond ? "date BETWEEN ? AND ? AND dim = ? AND $ncond" : "date BETWEEN ? AND ? AND dim = ?";
    $params = array_merge([$fromDay, $toDay, $dim], $nparams);
    $sql = "SELECT dim_value AS name,
        COALESCE(SUM(calls),0) AS total_calls,
        COALESCE(SUM(answered),0) AS answered_calls,
        COALESCE(SUM(total_duration),0) AS total_duration,
        COALESCE(SUM(fee),0) AS total_fee
    FROM y_cdr_daily_dim WHERE $where GROUP BY dim_value ORDER BY total_calls DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r) {
        $total = intval($r['total_calls']);
        $answered = intval($r['answered_calls']);
        $duration = intval($r['total_duration']);
        return [
            'name' => $r['name'],
            'total_calls' => $total,
            'answered_calls' => $answered,
            'asr' => $total > 0 ? round($answered / $total * 100, 2) : 0,
            'acd' => $answered > 0 ? round($duration / $answered, 1) : 0,
            'total_fee' => round(floatval($r['total_fee']), 4),
        ];
    }, $rows);
}

/** 主叫号码段 TOP-N（读 daily_dim dim=caller_prefix） */
function qualityByPrefixSummary($pdo, $fromDay, $toDay) {
    [$ncond, $nparams] = summaryNodeFilter();
    $where = $ncond ? "date BETWEEN ? AND ? AND dim = 'caller_prefix' AND $ncond" : "date BETWEEN ? AND ? AND dim = 'caller_prefix'";
    $params = array_merge([$fromDay, $toDay], $nparams);
    $sql = "SELECT dim_value AS prefix,
        COALESCE(SUM(calls),0) AS total_calls,
        COALESCE(SUM(answered),0) AS answered_calls
    FROM y_cdr_daily_dim WHERE $where GROUP BY dim_value ORDER BY total_calls DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r) {
        $total = intval($r['total_calls']);
        $answered = intval($r['answered_calls']);
        return [
            'prefix' => $r['prefix'],
            'total_calls' => $total,
            'answered_calls' => $answered,
            'asr' => $total > 0 ? round($answered / $total * 100, 2) : 0,
        ];
    }, $rows);
}

/** ACD 时长分布（读 hourly b1-b5） */
function qualityACDSummary($pdo, $fromDay, $toDay) {
    [$ncond, $nparams] = summaryNodeFilter();
    $where = $ncond ? "date BETWEEN ? AND ? AND $ncond" : "date BETWEEN ? AND ?";
    $params = array_merge([$fromDay, $toDay], $nparams);
    $sql = "SELECT COALESCE(SUM(b1),0) b1, COALESCE(SUM(b2),0) b2, COALESCE(SUM(b3),0) b3,
        COALESCE(SUM(b4),0) b4, COALESCE(SUM(b5),0) b5
        FROM y_cdr_hourly WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $buckets = ['0-10秒', '10-30秒', '30-60秒', '1-3分钟', '3分钟+'];
    $result = [];
    for ($i = 0; $i < 5; $i++) {
        $result[] = ['label' => $buckets[$i], 'count' => intval($row['b' . ($i + 1)] ?? 0)];
    }
    return $result;
}

/** 返回筛选下拉选项（从 CDR 日表提取 distinct 值） */
function reportOptions($pdo) {
    [$tables] = reportDayTables($pdo);
    $nodes = $pdo->query("SELECT id, name FROM y_nodes WHERE status = 1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($tables)) {
        Response::success([
            'nodes' => $nodes,
            'accounts' => [], 'gateways_in' => [], 'gateways_out' => [],
        ]);
        return;
    }
    $distinctCol = function ($col) use ($tables, $pdo) {
        $branches = array_map(function ($t) use ($col) {
            return "SELECT c.{$col} FROM `{$t}` c WHERE c.{$col} != '' AND c.{$col} IS NOT NULL";
        }, $tables);
        $union = implode(' UNION ALL ', $branches);
        return $pdo->query("SELECT DISTINCT {$col} FROM ({$union}) x ORDER BY {$col}")->fetchAll(PDO::FETCH_COLUMN);
    };
    Response::success([
        'nodes' => $nodes,
        'accounts' => $distinctCol('account'),
        'gateways_in' => $distinctCol('gateway_in'),
        'gateways_out' => $distinctCol('gateway_out'),
    ]);
}

/** 成功通话判定：duration > 0 (直接使用字符串，避免常量定义顺序问题) */

/* ============================================================
 * 话务概览
 * ========================================================== */
function handleOverview($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'kpi' => [
                'total_calls' => 0, 'answered_calls' => 0, 'asr' => 0,
                'total_duration' => 0, 'acd' => 0, 'total_fee' => 0,
                'inbound_calls' => 0, 'outbound_calls' => 0,
            ],
            'hourly_trend' => [],
            'node_distribution' => [],
            'direction_breakdown' => ['inbound' => 0, 'outbound' => 0],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    // ---- KPI 汇总 ----
    $kpi = reportKPI($pdo, $tables, $whereSQL, $params);

    // ---- 按小时趋势 ----
    $hourly = reportHourlyTrend($pdo, $tables, $whereSQL, $params);

    // ---- 节点分布 ----
    $nodeDist = reportNodeDist($pdo, $tables, $whereSQL, $params);

    // ---- 呼入呼出占比 ----
    $direction = reportDirection($pdo, $tables, $whereSQL, $params);

    Response::success([
        'kpi' => $kpi,
        'hourly_trend' => $hourly,
        'node_distribution' => $nodeDist,
        'direction_breakdown' => $direction,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/** KPI 汇总 */
function reportKPI($pdo, $tables, $whereSQL, $params) {
    if (!empty($tables)) {
        [$fromDay, $toDay] = cdrTableRange($tables);
        if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedTimeFilter()) {
            return reportKPISummary($pdo, $fromDay, $toDay);
        }
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered_calls,
            COALESCE(SUM(c.duration), 0) AS total_duration,
            COALESCE(SUM(c.bill_duration), 0) AS total_bill_duration,
            COALESCE(SUM(c.fee), 0) AS total_fee,
            COUNT(CASE WHEN c.direction='outbound' THEN 1 END) AS outbound_calls,
            COUNT(CASE WHEN c.direction='inbound' THEN 1 END) AS inbound_calls
        FROM `{$tbl}` c WHERE {$whereSQL}";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT
            SUM(total_calls) AS total_calls,
            SUM(answered_calls) AS answered_calls,
            SUM(total_duration) AS total_duration,
            SUM(total_bill_duration) AS total_bill_duration,
            SUM(total_fee) AS total_fee,
            SUM(outbound_calls) AS outbound_calls,
            SUM(inbound_calls) AS inbound_calls
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $total = intval($row['total_calls'] ?? 0);
    $answered = intval($row['answered_calls'] ?? 0);
    $duration = intval($row['total_duration'] ?? 0);

    return [
        'total_calls' => $total,
        'answered_calls' => $answered,
        'asr' => $total > 0 ? round($answered / $total * 100, 2) : 0,
        'total_duration' => $duration,
        'acd' => $answered > 0 ? round($duration / $answered, 1) : 0,
        'total_bill_duration' => intval($row['total_bill_duration'] ?? 0),
        'total_fee' => round(floatval($row['total_fee'] ?? 0), 4),
        'inbound_calls' => intval($row['inbound_calls'] ?? 0),
        'outbound_calls' => intval($row['outbound_calls'] ?? 0),
    ];
}

/** 按小时趋势 */
function reportHourlyTrend($pdo, $tables, $whereSQL, $params) {
    if (!empty($tables)) {
        [$fromDay, $toDay] = cdrTableRange($tables);
        if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedTimeFilter()) {
            return trafficHourlySummary($pdo, $fromDay);
        }
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            DATE_FORMAT(c.start_time, '%H:00') AS hour,
            COUNT(*) AS calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered,
            COALESCE(SUM(c.duration), 0) AS duration
        FROM `{$tbl}` c WHERE {$whereSQL}
        GROUP BY DATE_FORMAT(c.start_time, '%H:00')";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT hour,
            SUM(calls) AS calls,
            SUM(answered) AS answered,
            SUM(duration) AS duration
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x
        GROUP BY hour";
    }

    $sql .= " ORDER BY hour ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 填充 00-23 完整小时
    $map = [];
    foreach ($rows as $r) {
        $map[$r['hour']] = $r;
    }
    $result = [];
    for ($h = 0; $h < 24; $h++) {
        $key = sprintf('%02d:00', $h);
        if (isset($map[$key])) {
            $result[] = $map[$key];
        } else {
            $result[] = ['hour' => $key, 'calls' => 0, 'answered' => 0, 'duration' => 0];
        }
    }
    return $result;
}

/** 节点分布 */
function reportNodeDist($pdo, $tables, $whereSQL, $params) {
    if (!empty($tables)) {
        [$fromDay, $toDay] = cdrTableRange($tables);
        // 节点分布汇总不含 direction 列，direction 筛选须回退原始
        if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedDimFilter()) {
            return reportNodeDistSummary($pdo, $fromDay, $toDay);
        }
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            c.node_id,
            n.name AS node_name,
            COUNT(*) AS calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered,
            COALESCE(SUM(c.duration), 0) AS duration,
            COALESCE(SUM(c.fee), 0) AS fee
        FROM `{$tbl}` c
        LEFT JOIN y_nodes n ON c.node_id = n.id
        WHERE {$whereSQL}
        GROUP BY c.node_id, n.name";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT node_id, node_name,
            SUM(calls) AS calls,
            SUM(answered) AS answered,
            SUM(duration) AS duration,
            SUM(fee) AS fee
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x
        GROUP BY node_id, node_name";
    }

    $sql .= " ORDER BY calls DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** 呼入呼出占比 */
function reportDirection($pdo, $tables, $whereSQL, $params) {
    if (!empty($tables)) {
        [$fromDay, $toDay] = cdrTableRange($tables);
        // 方向占比汇总不含 direction 列，direction 筛选须回退原始
        if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedDimFilter()) {
            return reportDirectionSummary($pdo, $fromDay, $toDay);
        }
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            c.direction,
            COUNT(*) AS calls,
            COALESCE(SUM(c.duration), 0) AS duration
        FROM `{$tbl}` c WHERE {$whereSQL}
        GROUP BY c.direction";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT direction,
            SUM(calls) AS calls,
            SUM(duration) AS duration
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x
        GROUP BY direction";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = ['inbound' => 0, 'outbound' => 0, 'inbound_duration' => 0, 'outbound_duration' => 0];
    foreach ($rows as $r) {
        if ($r['direction'] === 'inbound') {
            $result['inbound'] = intval($r['calls']);
            $result['inbound_duration'] = intval($r['duration']);
        } else {
            $result['outbound'] = intval($r['calls']);
            $result['outbound_duration'] = intval($r['duration']);
        }
    }
    return $result;
}

/* ============================================================
 * 话务量统计
 *
 * 额外参数：
 *   granularity — hourly | daily（默认自动：单天→小时，多天→天）
 *   direction   — inbound | outbound（可选筛选）
 *
 * 返回：
 *   summary    — 区间汇总 KPI
 *   trend      — 趋势数据（按小时或按天）
 *   table      — 明细表格行（含呼入呼出拆分）
 * ========================================================== */
function handleTraffic($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    // 粒度自动判断
    $granularity = $_GET['granularity'] ?? '';
    if (!in_array($granularity, ['hourly', 'daily'])) {
        $span = (int)(new DateTime($toDay))->diff(new DateTime($fromDay))->format('%a');
        $granularity = ($span === 0) ? 'hourly' : 'daily';
    }

    if (empty($tables)) {
        Response::success([
            'summary' => [
                'total_calls' => 0, 'answered_calls' => 0, 'asr' => 0,
                'total_duration' => 0, 'acd' => 0, 'total_fee' => 0,
                'inbound_calls' => 0, 'outbound_calls' => 0,
                'inbound_duration' => 0, 'outbound_duration' => 0,
            ],
            'trend' => [],
            'table' => [],
            'granularity' => $granularity,
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    // ---- 汇总 KPI ----
    $summary = reportKPI($pdo, $tables, $whereSQL, $params);
    // 补充呼入呼出时长
    $dirData = reportDirection($pdo, $tables, $whereSQL, $params);
    $summary['inbound_duration'] = $dirData['inbound_duration'];
    $summary['outbound_duration'] = $dirData['outbound_duration'];

    // ---- 趋势 + 明细 ----
    if ($granularity === 'hourly') {
        $trend = trafficHourly($pdo, $tables, $whereSQL, $params, $fromDay);
        $table = $trend; // 小时粒度，表格直接用趋势数据
    } else {
        $trend = trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay);
        $table = $trend;
    }

    Response::success([
        'summary' => $summary,
        'trend' => $trend,
        'table' => $table,
        'granularity' => $granularity,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/** 按小时统计（单天） */
function trafficHourly($pdo, $tables, $whereSQL, $params, $day) {
    if (summaryAvailable($pdo, $day, $day) && !hasUnsupportedTimeFilter()) {
        return trafficHourlySummary($pdo, $day);
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            DATE_FORMAT(c.start_time, '%H:00') AS time_label,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered_calls,
            COALESCE(SUM(c.duration), 0) AS total_duration,
            COALESCE(SUM(c.fee), 0) AS total_fee,
            COUNT(CASE WHEN c.direction='inbound' THEN 1 END) AS inbound_calls,
            COUNT(CASE WHEN c.direction='outbound' THEN 1 END) AS outbound_calls,
            COALESCE(SUM(CASE WHEN c.direction='inbound' THEN c.duration ELSE 0 END), 0) AS inbound_duration,
            COALESCE(SUM(CASE WHEN c.direction='outbound' THEN c.duration ELSE 0 END), 0) AS outbound_duration
        FROM `{$tbl}` c WHERE {$whereSQL}
        GROUP BY DATE_FORMAT(c.start_time, '%H:00')";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT time_label,
            SUM(total_calls) AS total_calls,
            SUM(answered_calls) AS answered_calls,
            SUM(total_duration) AS total_duration,
            SUM(total_fee) AS total_fee,
            SUM(inbound_calls) AS inbound_calls,
            SUM(outbound_calls) AS outbound_calls,
            SUM(inbound_duration) AS inbound_duration,
            SUM(outbound_duration) AS outbound_duration
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x
        GROUP BY time_label";
    }

    $sql .= " ORDER BY time_label ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 填充 00-23
    $map = [];
    foreach ($rows as $r) $map[$r['time_label']] = $r;
    $result = [];
    for ($h = 0; $h < 24; $h++) {
        $key = sprintf('%02d:00', $h);
        if (isset($map[$key])) {
            $result[] = enrichRow($map[$key], $key);
        } else {
            $result[] = emptyHourRow($key);
        }
    }
    return $result;
}

/** 按天统计（多天） */
function trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay) {
    if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedTimeFilter()) {
        return trafficDailySummary($pdo, $fromDay, $toDay);
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            DATE(c.start_time) AS time_label,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered_calls,
            COALESCE(SUM(c.duration), 0) AS total_duration,
            COALESCE(SUM(c.fee), 0) AS total_fee,
            COUNT(CASE WHEN c.direction='inbound' THEN 1 END) AS inbound_calls,
            COUNT(CASE WHEN c.direction='outbound' THEN 1 END) AS outbound_calls,
            COALESCE(SUM(CASE WHEN c.direction='inbound' THEN c.duration ELSE 0 END), 0) AS inbound_duration,
            COALESCE(SUM(CASE WHEN c.direction='outbound' THEN c.duration ELSE 0 END), 0) AS outbound_duration
        FROM `{$tbl}` c WHERE {$whereSQL}
        GROUP BY DATE(c.start_time)";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT time_label,
            SUM(total_calls) AS total_calls,
            SUM(answered_calls) AS answered_calls,
            SUM(total_duration) AS total_duration,
            SUM(total_fee) AS total_fee,
            SUM(inbound_calls) AS inbound_calls,
            SUM(outbound_calls) AS outbound_calls,
            SUM(inbound_duration) AS inbound_duration,
            SUM(outbound_duration) AS outbound_duration
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x
        GROUP BY time_label";
    }

    $sql .= " ORDER BY time_label ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 填充日期空隙
    $map = [];
    foreach ($rows as $r) $map[$r['time_label']] = $r;
    $result = [];
    $cur = new DateTime($fromDay);
    $last = new DateTime($toDay);
    while ($cur <= $last) {
        $key = $cur->format('Y-m-d');
        if (isset($map[$key])) {
            $result[] = enrichRow($map[$key], $key);
        } else {
            $result[] = emptyHourRow($key);
        }
        $cur->modify('+1 day');
    }
    return $result;
}

/** 行数据 enrichment：计算 ASR / ACD */
function enrichRow($r, $label) {
    $total = intval($r['total_calls'] ?? 0);
    $answered = intval($r['answered_calls'] ?? 0);
    $duration = intval($r['total_duration'] ?? 0);
    return [
        'time_label' => $label,
        'total_calls' => $total,
        'answered_calls' => $answered,
        'asr' => $total > 0 ? round($answered / $total * 100, 2) : 0,
        'total_duration' => $duration,
        'acd' => $answered > 0 ? round($duration / $answered, 1) : 0,
        'total_fee' => round(floatval($r['total_fee'] ?? 0), 4),
        'inbound_calls' => intval($r['inbound_calls'] ?? 0),
        'outbound_calls' => intval($r['outbound_calls'] ?? 0),
        'inbound_duration' => intval($r['inbound_duration'] ?? 0),
        'outbound_duration' => intval($r['outbound_duration'] ?? 0),
    ];
}

/** 空行模板 */
function emptyHourRow($label) {
    return [
        'time_label' => $label,
        'total_calls' => 0, 'answered_calls' => 0, 'asr' => 0,
        'total_duration' => 0, 'acd' => 0, 'total_fee' => 0,
        'inbound_calls' => 0, 'outbound_calls' => 0,
        'inbound_duration' => 0, 'outbound_duration' => 0,
    ];
}

/* ============================================================
 * 接通率分析
 *
 * 返回：
 *   summary         — 总体 ASR / ACD / 通话数
 *   asr_trend       — ASR 趋势（按小时或按天）
 *   gateway_in_asr  — 对接网关接通率 TOP 20
 *   gateway_out_asr — 落地网关接通率 TOP 20
 *   prefix_asr      — 主叫号码段(前4位)接通率 TOP 20
 *   acd_distribution— 通话时长分布(5档)
 * ========================================================== */
function handleQuality($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    $granularity = $_GET['granularity'] ?? '';
    if (!in_array($granularity, ['hourly', 'daily'])) {
        $span = (int)(new DateTime($toDay))->diff(new DateTime($fromDay))->format('%a');
        $granularity = ($span === 0) ? 'hourly' : 'daily';
    }

    if (empty($tables)) {
        Response::success([
            'summary' => ['total_calls' => 0, 'answered_calls' => 0, 'asr' => 0, 'acd' => 0],
            'asr_trend' => [],
            'gateway_in_asr' => [],
            'gateway_out_asr' => [],
            'prefix_asr' => [],
            'acd_distribution' => [],
            'granularity' => $granularity,
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    $summary = reportKPI($pdo, $tables, $whereSQL, $params);

    // ASR 趋势
    if ($granularity === 'hourly') {
        $asrTrend = trafficHourly($pdo, $tables, $whereSQL, $params, $fromDay);
    } else {
        $asrTrend = trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay);
    }

    // 网关接通率
    $gwIn = qualityByDimension($pdo, $tables, $whereSQL, $params, 'gateway_in');
    $gwOut = qualityByDimension($pdo, $tables, $whereSQL, $params, 'gateway_out');

    // 号码段接通率
    $prefix = qualityByPrefix($pdo, $tables, $whereSQL, $params);

    // ACD 分布
    $acdDist = qualityACDDistribution($pdo, $tables, $whereSQL, $params);

    Response::success([
        'summary' => $summary,
        'asr_trend' => $asrTrend,
        'gateway_in_asr' => $gwIn,
        'gateway_out_asr' => $gwOut,
        'prefix_asr' => $prefix,
        'acd_distribution' => $acdDist,
        'granularity' => $granularity,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/** 按维度(网关)统计接通率 TOP 20 */
function qualityByDimension($pdo, $tables, $whereSQL, $params, $dim) {
    if (!empty($tables)) {
        [$fromDay, $toDay] = cdrTableRange($tables);
        // 维度类汇总表不含 direction 列 → direction 筛选也回退原始
        if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedDimFilter()) {
            return qualityByDimSummary($pdo, $fromDay, $toDay, $dim);
        }
    }
    $mk = function ($tbl) use ($whereSQL, $dim) {
        return "SELECT
            c.{$dim} AS name,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered_calls,
            COALESCE(SUM(c.duration), 0) AS total_duration,
            COALESCE(SUM(c.fee), 0) AS total_fee
        FROM `{$tbl}` c WHERE {$whereSQL} AND c.{$dim} != ''
        GROUP BY c.{$dim}";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT name,
            SUM(total_calls) AS total_calls,
            SUM(answered_calls) AS answered_calls,
            SUM(total_duration) AS total_duration,
            SUM(total_fee) AS total_fee
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x
        GROUP BY name";
    }

    $sql .= " ORDER BY total_calls DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(function($r) {
        $total = intval($r['total_calls']);
        $answered = intval($r['answered_calls']);
        $duration = intval($r['total_duration']);
        return [
            'name' => $r['name'],
            'total_calls' => $total,
            'answered_calls' => $answered,
            'asr' => $total > 0 ? round($answered / $total * 100, 2) : 0,
            'acd' => $answered > 0 ? round($duration / $answered, 1) : 0,
            'total_fee' => round(floatval($r['total_fee']), 4),
        ];
    }, $rows);
}

/** 号码段(前4位)接通率 TOP 20 */
function qualityByPrefix($pdo, $tables, $whereSQL, $params) {
    if (!empty($tables)) {
        [$fromDay, $toDay] = cdrTableRange($tables);
        if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedDimFilter()) {
            return qualityByPrefixSummary($pdo, $fromDay, $toDay);
        }
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            LEFT(c.caller, 4) AS prefix,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered_calls
        FROM `{$tbl}` c WHERE {$whereSQL} AND c.caller != '' AND LENGTH(c.caller) >= 4
        GROUP BY LEFT(c.caller, 4)";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT prefix,
            SUM(total_calls) AS total_calls,
            SUM(answered_calls) AS answered_calls
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x
        GROUP BY prefix";
    }

    $sql .= " ORDER BY total_calls DESC LIMIT 20";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(function($r) {
        $total = intval($r['total_calls']);
        $answered = intval($r['answered_calls']);
        return [
            'prefix' => $r['prefix'],
            'total_calls' => $total,
            'answered_calls' => $answered,
            'asr' => $total > 0 ? round($answered / $total * 100, 2) : 0,
        ];
    }, $rows);
}

/** ACD 分布：5档 */
function qualityACDDistribution($pdo, $tables, $whereSQL, $params) {
    if (!empty($tables)) {
        [$fromDay, $toDay] = cdrTableRange($tables);
        if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedDimFilter()) {
            return qualityACDSummary($pdo, $fromDay, $toDay);
        }
    }
    $buckets = [
        ['label' => '0-10秒', 'min' => 0, 'max' => 10],
        ['label' => '10-30秒', 'min' => 10, 'max' => 30],
        ['label' => '30-60秒', 'min' => 30, 'max' => 60],
        ['label' => '1-3分钟', 'min' => 60, 'max' => 180],
        ['label' => '3分钟+', 'min' => 180, 'max' => 999999],
    ];

    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            COUNT(CASE WHEN c.duration > 0 AND c.duration <= 10 THEN 1 END) AS b1,
            COUNT(CASE WHEN c.duration > 10 AND c.duration <= 30 THEN 1 END) AS b2,
            COUNT(CASE WHEN c.duration > 30 AND c.duration <= 60 THEN 1 END) AS b3,
            COUNT(CASE WHEN c.duration > 60 AND c.duration <= 180 THEN 1 END) AS b4,
            COUNT(CASE WHEN c.duration > 180 THEN 1 END) AS b5
        FROM `{$tbl}` c WHERE {$whereSQL}";
    };

    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT
            SUM(b1) AS b1, SUM(b2) AS b2, SUM(b3) AS b3, SUM(b4) AS b4, SUM(b5) AS b5
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $result = [];
    for ($i = 0; $i < 5; $i++) {
        $key = 'b' . ($i + 1);
        $result[] = [
            'label' => $buckets[$i]['label'],
            'count' => intval($row[$key] ?? 0),
        ];
    }
    return $result;
}

/* ============================================================
 * 号码分析
 *
 * 返回：caller_top / callee_top / prefix_dist
 * ========================================================== */
function handleNumber($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'caller_top' => [], 'callee_top' => [], 'prefix_dist' => [],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    $limit = intval($_GET['limit'] ?? 50);
    if ($limit < 1 || $limit > 200) $limit = 50;

    // 主叫 TOP N
    $callerTop = numberTopN($pdo, $tables, $whereSQL, $params, 'caller', $limit);
    // 被叫 TOP N
    $calleeTop = numberTopN($pdo, $tables, $whereSQL, $params, 'callee', $limit);
    // 号码段分布
    $prefixDist = numberPrefixDist($pdo, $tables, $whereSQL, $params);

    Response::success([
        'caller_top' => $callerTop,
        'callee_top' => $calleeTop,
        'prefix_dist' => $prefixDist,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

function numberTopN($pdo, $tables, $whereSQL, $params, $field, $limit) {
    $mk = function ($tbl) use ($whereSQL, $field) {
        return "SELECT
            c.{$field} AS number,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered,
            COALESCE(SUM(c.duration), 0) AS duration,
            COALESCE(SUM(c.fee), 0) AS fee
        FROM `{$tbl}` c WHERE {$whereSQL} AND c.{$field} != ''
        GROUP BY c.{$field}";
    };
    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT number, SUM(total_calls) AS total_calls, SUM(answered) AS answered,
            SUM(duration) AS duration, SUM(fee) AS fee
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x GROUP BY number";
    }
    $sql .= " ORDER BY total_calls DESC LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r) {
        $t = intval($r['total_calls']); $a = intval($r['answered']);
        return [
            'number' => $r['number'], 'total_calls' => $t, 'answered' => $a,
            'asr' => $t > 0 ? round($a / $t * 100, 2) : 0,
            'duration' => intval($r['duration']), 'fee' => round(floatval($r['fee']), 4),
        ];
    }, $rows);
}

function numberPrefixDist($pdo, $tables, $whereSQL, $params) {
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            LEFT(c.caller, 4) AS prefix,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered
        FROM `{$tbl}` c WHERE {$whereSQL} AND c.caller != '' AND LENGTH(c.caller) >= 4
        GROUP BY LEFT(c.caller, 4)";
    };
    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT prefix, SUM(total_calls) AS total_calls, SUM(answered) AS answered
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x GROUP BY prefix";
    }
    $sql .= " ORDER BY total_calls DESC LIMIT 30";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r) {
        $t = intval($r['total_calls']); $a = intval($r['answered']);
        return [
            'prefix' => $r['prefix'], 'total_calls' => $t, 'answered' => $a,
            'asr' => $t > 0 ? round($a / $t * 100, 2) : 0,
        ];
    }, $rows);
}

/* ============================================================
 * 网关质量分析
 *
 * 返回：gateway_in_stats / gateway_out_stats
 * ========================================================== */
function handleGateway($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'gateway_in_stats' => [], 'gateway_out_stats' => [],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    $gwIn = qualityByDimension($pdo, $tables, $whereSQL, $params, 'gateway_in');
    $gwOut = qualityByDimension($pdo, $tables, $whereSQL, $params, 'gateway_out');

    // 补充呼入呼出拆分
    foreach ($gwIn as &$g) {
        $g['type'] = '对接网关';
    }
    foreach ($gwOut as &$g) {
        $g['type'] = '落地网关';
    }

    Response::success([
        'gateway_in_stats' => $gwIn,
        'gateway_out_stats' => $gwOut,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/* ============================================================
 * 挂断原因分析
 *
 * 返回：cause_dist / cause_by_gateway / cause_trend
 * ========================================================== */
function handleCause($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'cause_dist' => [], 'cause_by_gateway' => [],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    // 挂断原因分布
    $causeDist = null;
    if (summaryAvailable($pdo, $fromDay, $toDay) && !hasUnsupportedDimFilter()) {
        $causeDist = qualityByDimSummary($pdo, $fromDay, $toDay, 'disconnect_cause');
        $totalAll = array_sum(array_map(fn($r) => intval($r['total_calls']), $causeDist));
        $causeDist = array_map(function($r) use ($totalAll) {
            $t = intval($r['total_calls']);
            return [
                'cause' => $r['name'], 'total_calls' => $t,
                'answered' => intval($r['answered_calls']),
                'percentage' => $totalAll > 0 ? round($t / $totalAll * 100, 2) : 0,
            ];
        }, $causeDist);
    }
    if ($causeDist === null) {
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT
            COALESCE(NULLIF(c.disconnect_cause, ''), 'unknown') AS cause,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered
        FROM `{$tbl}` c WHERE {$whereSQL}
        GROUP BY COALESCE(NULLIF(c.disconnect_cause, ''), 'unknown')";
    };
    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT cause, SUM(total_calls) AS total_calls, SUM(answered) AS answered
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x GROUP BY cause";
    }
    $sql .= " ORDER BY total_calls DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $causeDist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $totalAll = array_sum(array_map(fn($r) => intval($r['total_calls']), $causeDist));
    $causeDist = array_map(function($r) use ($totalAll) {
        $t = intval($r['total_calls']);
        return [
            'cause' => $r['cause'], 'total_calls' => $t,
            'answered' => intval($r['answered']),
            'percentage' => $totalAll > 0 ? round($t / $totalAll * 100, 2) : 0,
        ];
    }, $causeDist);

    // 挂断原因 × 落地网关 交叉分析 (TOP 15 网关)
    $causeByGw = qualityByDimension($pdo, $tables, $whereSQL, $params, 'gateway_out');

    Response::success([
        'cause_dist' => $causeDist,
        'cause_by_gateway' => $causeByGw,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/* ============================================================
 * 节点对比分析
 *
 * 返回：node_stats / node_trend
 * ========================================================== */
function handleNodeCompare($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'node_stats' => [], 'node_trend' => [],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    // 节点统计
    $nodeStats = reportNodeDist($pdo, $tables, $whereSQL, $params);
    // 补充 ASR / ACD
    $nodeStats = array_map(function($r) {
        $t = intval($r['calls']); $a = intval($r['answered']); $d = intval($r['duration']);
        return [
            'node_id' => intval($r['node_id']),
            'node_name' => $r['node_name'] ?: ('节点' . $r['node_id']),
            'total_calls' => $t, 'answered' => $a,
            'asr' => $t > 0 ? round($a / $t * 100, 2) : 0,
            'acd' => $a > 0 ? round($d / $a, 1) : 0,
            'total_duration' => $d,
            'total_fee' => round(floatval($r['fee']), 4),
        ];
    }, $nodeStats);

    // 节点按天趋势
    $span = (int)(new DateTime($toDay))->diff(new DateTime($fromDay))->format('%a');
    if ($span === 0) {
        $nodeTrend = trafficHourly($pdo, $tables, $whereSQL, $params, $fromDay);
    } else {
        $nodeTrend = trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay);
    }

    Response::success([
        'node_stats' => $nodeStats,
        'node_trend' => $nodeTrend,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/* ============================================================
 * 客户消费报表
 *
 * 返回：summary / account_stats / daily_trend
 * ========================================================== */
function handleFinanceCustomer($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'summary' => ['total_calls' => 0, 'total_duration' => 0, 'total_fee' => 0],
            'account_stats' => [], 'daily_trend' => [],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    $summary = reportKPI($pdo, $tables, $whereSQL, $params);
    $accountStats = financeByDimension($pdo, $tables, $whereSQL, $params, 'account');
    $dailyTrend = trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay);

    Response::success([
        'summary' => [
            'total_calls' => $summary['total_calls'],
            'total_duration' => $summary['total_duration'],
            'total_fee' => $summary['total_fee'],
        ],
        'account_stats' => $accountStats,
        'daily_trend' => $dailyTrend,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/** 财务按维度汇总 */
function financeByDimension($pdo, $tables, $whereSQL, $params, $dim) {
    $mk = function ($tbl) use ($whereSQL, $dim) {
        return "SELECT
            c.{$dim} AS name,
            COUNT(*) AS total_calls,
            COUNT(CASE WHEN c.duration > 0 THEN 1 END) AS answered,
            COALESCE(SUM(c.duration), 0) AS total_duration,
            COALESCE(SUM(c.bill_duration), 0) AS total_bill_duration,
            COALESCE(SUM(c.fee), 0) AS total_fee,
            COALESCE(SUM(c.cost), 0) AS total_cost
        FROM `{$tbl}` c WHERE {$whereSQL} AND c.{$dim} != ''
        GROUP BY c.{$dim}";
    };
    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT name, SUM(total_calls) AS total_calls, SUM(answered) AS answered,
            SUM(total_duration) AS total_duration, SUM(total_bill_duration) AS total_bill_duration,
            SUM(total_fee) AS total_fee, SUM(total_cost) AS total_cost
        FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x GROUP BY name";
    }
    $sql .= " ORDER BY total_fee DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function($r) {
        $t = intval($r['total_calls']); $a = intval($r['answered']);
        $fee = round(floatval($r['total_fee']), 4);
        $cost = round(floatval($r['total_cost']), 4);
        return [
            'name' => $r['name'],
            'total_calls' => $t, 'answered' => $a,
            'asr' => $t > 0 ? round($a / $t * 100, 2) : 0,
            'total_duration' => intval($r['total_duration']),
            'total_bill_duration' => intval($r['total_bill_duration']),
            'total_fee' => $fee, 'total_cost' => $cost,
            'profit' => round($fee - $cost, 4),
        ];
    }, $rows);
}

/* ============================================================
 * 线路消费报表
 *
 * 返回：summary / gateway_stats / daily_trend
 * ========================================================== */
function handleFinanceLine($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'summary' => ['total_calls' => 0, 'total_duration' => 0, 'total_fee' => 0, 'total_cost' => 0],
            'gateway_stats' => [], 'daily_trend' => [],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    $summary = reportKPI($pdo, $tables, $whereSQL, $params);
    $gwStats = financeByDimension($pdo, $tables, $whereSQL, $params, 'gateway_out');
    $dailyTrend = trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay);

    Response::success([
        'summary' => [
            'total_calls' => $summary['total_calls'],
            'total_duration' => $summary['total_duration'],
            'total_fee' => $summary['total_fee'],
            'total_cost' => 0,
        ],
        'gateway_stats' => $gwStats,
        'daily_trend' => $dailyTrend,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/* ============================================================
 * 利润分析
 *
 * 返回：summary / dimension_stats / daily_trend
 *   dimension: account(默认) / gateway_out
 * ========================================================== */
function handleFinanceProfit($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    $dim = $_GET['dimension'] ?? 'account';
    if (!in_array($dim, ['account', 'gateway_out', 'gateway_in', 'node_id'])) {
        $dim = 'account';
    }

    if (empty($tables)) {
        Response::success([
            'summary' => ['total_fee' => 0, 'total_cost' => 0, 'total_profit' => 0, 'margin' => 0],
            'dimension_stats' => [], 'daily_trend' => [],
            'dimension' => $dim,
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    $summary = reportKPI($pdo, $tables, $whereSQL, $params);
    $dimStats = financeByDimension($pdo, $tables, $whereSQL, $params, $dim);
    $dailyTrend = trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay);

    $totalFee = $summary['total_fee'];
    // 从 dimension_stats 汇总 cost
    $totalCost = array_sum(array_map(fn($r) => $r['total_cost'], $dimStats));

    Response::success([
        'summary' => [
            'total_fee' => $totalFee,
            'total_cost' => round($totalCost, 4),
            'total_profit' => round($totalFee - $totalCost, 4),
            'margin' => $totalFee > 0 ? round(($totalFee - $totalCost) / $totalFee * 100, 2) : 0,
        ],
        'dimension_stats' => $dimStats,
        'daily_trend' => $dailyTrend,
        'dimension' => $dim,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/* ============================================================
 * 账期结算
 *
 * 返回：summary / daily_stats / account_stats
 * ========================================================== */
function handleFinanceSettlement($pdo) {
    [$tables, $fromDay, $toDay] = reportDayTables($pdo);
    [$whereSQL, $params] = reportWhere();

    if (empty($tables)) {
        Response::success([
            'summary' => ['total_calls' => 0, 'total_duration' => 0, 'total_fee' => 0, 'days' => 0],
            'daily_stats' => [], 'account_stats' => [],
            'date_range' => ['from' => $fromDay, 'to' => $toDay],
        ]);
        return;
    }

    $summary = reportKPI($pdo, $tables, $whereSQL, $params);
    $dailyStats = trafficDaily($pdo, $tables, $whereSQL, $params, $fromDay, $toDay);
    $accountStats = financeByDimension($pdo, $tables, $whereSQL, $params, 'account');

    $span = (int)(new DateTime($toDay))->diff(new DateTime($fromDay))->format('%a') + 1;

    Response::success([
        'summary' => [
            'total_calls' => $summary['total_calls'],
            'total_duration' => $summary['total_duration'],
            'total_fee' => $summary['total_fee'],
            'days' => $span,
        ],
        'daily_stats' => $dailyStats,
        'account_stats' => $accountStats,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}

/* ============================================================
 * 缴费记录 — 查询 y_payment_records
 * 支持: 日期范围、节点、账户、类型筛选
 * ========================================================== */
function handleFinancePayment($pdo) {
    $from = trim($_GET['start_time_from'] ?? '');
    $to   = trim($_GET['start_time_to'] ?? '');
    $fromDay = $from ? substr($from, 0, 10) : date('Y-m-01');
    $toDay   = $to   ? substr($to, 0, 10)   : date('Y-m-d');
    if ($fromDay > $toDay) { $t = $fromDay; $fromDay = $toDay; $toDay = $t; }

    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['node_id'])) {
        $where[] = 'node_id = ?';
        $params[] = intval($_GET['node_id']);
    }
    if (!empty($_GET['account'])) {
        $where[] = 'account LIKE ?';
        $params[] = '%' . $_GET['account'] . '%';
    }
    if (!empty($_GET['type']) && in_array($_GET['type'], ['recharge', 'deduct', 'reset', 'overdraft'])) {
        $where[] = 'type = ?';
        $params[] = $_GET['type'];
    }

    $whereSQL = implode(' AND ', $where);

    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = min(200, max(10, intval($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM y_payment_records WHERE {$whereSQL}");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare("SELECT * FROM y_payment_records WHERE {$whereSQL} ORDER BY created_at DESC LIMIT {$pageSize} OFFSET {$offset}");
    $listStmt->execute($params);
    $rows = $listStmt->fetchAll();

    $records = [];
    foreach ($rows as $r) {
        $records[] = [
            'id'           => (int)$r['id'],
            'node_id'      => (int)$r['node_id'],
            'account'      => $r['account'],
            'account_name' => $r['account_name'],
            'type'         => $r['type'],
            'amount'       => (float)$r['amount'],
            'old_value'    => (float)$r['old_value'],
            'new_value'    => (float)$r['new_value'],
            'memo'         => $r['memo'],
            'created_at'   => $r['created_at'],
        ];
    }

    $sumStmt = $pdo->prepare("SELECT
        type,
        COUNT(*) AS cnt,
        COALESCE(SUM(amount), 0) AS total_amount
        FROM y_payment_records WHERE {$whereSQL}
        GROUP BY type");
    $sumStmt->execute($params);
    $sumRows = $sumStmt->fetchAll();

    $summary = [
        'recharge'  => ['count' => 0, 'total' => 0],
        'deduct'    => ['count' => 0, 'total' => 0],
        'reset'     => ['count' => 0, 'total' => 0],
        'overdraft' => ['count' => 0, 'total' => 0],
    ];
    foreach ($sumRows as $s) {
        $summary[$s['type']] = [
            'count' => (int)$s['cnt'],
            'total' => (float)$s['total_amount'],
        ];
    }

    Response::success([
        'records'    => $records,
        'total'      => $total,
        'page'       => $page,
        'page_size'  => $pageSize,
        'summary'    => $summary,
        'date_range' => ['from' => $fromDay, 'to' => $toDay],
    ]);
}
