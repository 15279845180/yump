<?php
/**
 * CDR（通话记录）查询 API  —  按天分表版 (y_cdr_YYYYMMDD)
 *
 * GET /api/cdr           — 查询通话记录列表（分页+筛选，按日期范围路由到日表）
 * GET /api/cdr/stats     — 统计概览（总通话数、总时长、总费用等）
 * GET /api/cdr/options   — 返回筛选下拉数据（节点、账户、网关、状态码）
 * GET /api/cdr/export    — 导出通话记录（CSV，字段选择，行数上限10万）
 * DELETE /api/cdr        — 清空话单（需 confirm=yes）
 *
 * 筛选参数（GET）：
 *   node_id          — 关联节点ID（精确）
 *   caller           — 主叫号码（中间模糊 LIKE %xxx%）
 *   callee           — 被叫号码（中间模糊 LIKE %xxx%）
 *   caller_in        — 呼入主叫号码（中间模糊）
 *   callee_in        — 呼入被叫号码（中间模糊）
 *   gateway_in       — 对接网关（模糊）
 *   gateway_out      — 落地网关（模糊）
 *   account          — 普通账户（模糊）
 *   disconnect_cause — 挂断原因码（精确）
 *   start_time_from  — 开始时间范围起 (YYYY-MM-DD 或 YYYY-MM-DD HH:mm:ss)，用于定位日表
 *   start_time_to    — 开始时间范围止
 *   duration_min     — 最短通话时长(秒)
 *   duration_max     — 最长通话时长(秒)
 *   page             — 页码(默认1)
 *   pageSize         — 每页条数(默认20)
 */

require_once __DIR__ . '/../../utils/Database.php';
require_once __DIR__ . '/../../utils/Response.php';
require_once __DIR__ . '/../../utils/Logger.php';

$pdo    = Database::getConnection();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = '';

// 解析子路径: /api/cdr/stats → action=stats
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '';
$parts = array_values(array_filter(explode('/', trim($path, '/'))));
// parts: [api, cdr, stats?]
if (isset($parts[2])) {
    $action = $parts[2];
}

switch ($method) {
    case 'GET':
        if ($action === 'stats') {
            handleStats($pdo);
        } elseif ($action === 'options') {
            handleOptions($pdo);
        } elseif ($action === 'export') {
            handleExport($pdo);
        } else {
            handleList($pdo);
        }
        break;
    case 'DELETE':
        handleClear($pdo);
        break;
    default:
        Response::error('不支持的请求方法', 405);
}

/* ============================================================
 * 按天分表辅助函数
 * ========================================================== */

/**
 * 列出数据库中所有已存在的日表 y_cdr_YYYYMMDD
 */
function cdrAllTables($pdo) {
    $stmt = $pdo->query("SHOW TABLES LIKE 'y_cdr\\_%'");
    $out = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $tbl = $row[0];
        // 只保留日表 y_cdr_YYYYMMDD（8位数字）
        // 排除 y_cdr_counter / y_cdr_hourly / y_cdr_daily_dim / y_cdr_archive 等非日表
        // 这些表没有 duration / account 等列，混入会导致 SQL Fatal Error
        if (preg_match('/^y_cdr_\d{8}$/', $tbl)) {
            $out[] = $tbl;
        }
    }
    return $out;
}

/**
 * 根据 start_time_from / start_time_to 解析日期范围，返回该范围内已存在的日表。
 * 缺省为今日；跨度上限 92 天（避免超大 UNION）。
 */
function cdrDayTables($pdo) {
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
    return $tables;
}

/**
 * 共用 JOIN（关联节点/结算/对接账户），使用表别名 c
 */
function cdrJoin() {
    return "LEFT JOIN y_nodes n ON c.node_id = n.id
        LEFT JOIN y_gateway_routings gr ON c.node_id = gr.node_id AND c.gateway_out COLLATE utf8mb4_unicode_ci = gr.name COLLATE utf8mb4_unicode_ci
        LEFT JOIN y_gateway_mappings gm ON c.node_id = gm.node_id AND c.gateway_in COLLATE utf8mb4_unicode_ci = gm.name COLLATE utf8mb4_unicode_ci";
}

/**
 * 对一组日表做聚合统计（COUNT/SUM），多表时 UNION 后汇总
 */
function cdrSum($pdo, $tables, $whereSQL, $params) {
    if (empty($tables)) {
        return [
            'total_calls' => 0, 'total_duration' => 0, 'total_bill_duration' => 0,
            'total_fee' => 0, 'avg_duration' => 0, 'outbound_count' => 0, 'inbound_count' => 0,
        ];
    }
    $mk = function ($tbl) use ($whereSQL) {
        return "SELECT COUNT(*) AS total_calls,
                COALESCE(SUM(c.duration),0) AS total_duration,
                COALESCE(SUM(c.bill_duration),0) AS total_bill_duration,
                COALESCE(SUM(c.fee),0) AS total_fee,
                COALESCE(SUM(c.duration),0) AS sum_duration,
                COUNT(CASE WHEN c.direction='outbound' THEN 1 END) AS outbound_count,
                COUNT(CASE WHEN c.direction='inbound' THEN 1 END) AS inbound_count
            FROM `{$tbl}` c " . cdrJoin() . " WHERE {$whereSQL}";
    };
    if (count($tables) === 1) {
        $sql = $mk($tables[0]);
    } else {
        $sql = "SELECT SUM(total_calls) AS total_calls, SUM(total_duration) AS total_duration,
                SUM(total_bill_duration) AS total_bill_duration, SUM(total_fee) AS total_fee,
                SUM(sum_duration)/NULLIF(SUM(total_calls),0) AS avg_duration,
                SUM(outbound_count) AS outbound_count, SUM(inbound_count) AS inbound_count
            FROM (" . implode(' UNION ALL ', array_map($mk, $tables)) . ") x";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ============================================================
 * 查询通话记录列表
 * ========================================================== */
function handleList($pdo) {
    $where = ['1=1'];
    $params = [];

    if (!empty($_GET['cdr_id'])) {
        $where[] = 'c.cdr_id = ?';
        $params[] = $_GET['cdr_id'];
    }
    if (!empty($_GET['node_id'])) {
        $where[] = 'c.node_id = ?';
        $params[] = intval($_GET['node_id']);
    }
    if (!empty($_GET['caller'])) {
        $where[] = 'c.caller LIKE ?';
        $params[] = '%' . $_GET['caller'] . '%';
    }
    if (!empty($_GET['callee'])) {
        $where[] = 'c.callee LIKE ?';
        $params[] = '%' . $_GET['callee'] . '%';
    }
    if (!empty($_GET['caller_in'])) {
        $where[] = 'c.caller_in LIKE ?';
        $params[] = '%' . $_GET['caller_in'] . '%';
    }
    if (!empty($_GET['callee_in'])) {
        $where[] = 'c.callee_in LIKE ?';
        $params[] = '%' . $_GET['callee_in'] . '%';
    }
    if (!empty($_GET['gateway_in'])) {
        $where[] = 'c.gateway_in LIKE ?';
        $params[] = '%' . $_GET['gateway_in'] . '%';
    }
    if (!empty($_GET['gateway_out'])) {
        $where[] = 'c.gateway_out LIKE ?';
        $params[] = '%' . $_GET['gateway_out'] . '%';
    }
    if (!empty($_GET['account'])) {
        $where[] = 'c.account LIKE ?';
        $params[] = '%' . $_GET['account'] . '%';
    }
    if (!empty($_GET['disconnect_cause'])) {
        $where[] = 'c.disconnect_cause = ?';
        $params[] = $_GET['disconnect_cause'];
    }
    if (!empty($_GET['duration_min'])) {
        $where[] = 'c.duration >= ?';
        $params[] = intval($_GET['duration_min']);
    }
    if (!empty($_GET['duration_max'])) {
        $where[] = 'c.duration <= ?';
        $params[] = intval($_GET['duration_max']);
    }
    if (!empty($_GET['start_time_from'])) {
        $where[] = 'c.start_time >= ?';
        $params[] = $_GET['start_time_from'];
    }
    if (!empty($_GET['start_time_to'])) {
        // 纯日期格式(YYYY-MM-DD)补上 23:59:59，否则 <= 当天00:00:00 会漏掉当天全部数据
        $to = $_GET['start_time_to'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to .= ' 23:59:59';
        $where[] = 'c.start_time <= ?';
        $params[] = $to;
    }

    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = min(200, max(1, intval($_GET['pageSize'] ?? 10)));
    $offset = ($page - 1) * $pageSize;

    // 是否「无字段筛选」：仅日期范围（或默认今天），不含任何 caller/callee/gateway/account/
    // duration/disconnect_cause/cdr_id 等字段过滤。node_id 可选。
    // 无字段筛选时 total 直接读 y_cdr_summary（O(天数)，毫秒级，绝不对日表 COUNT(*)）；
    // 数据查询走 branchTop + UNION ALL + LIMIT（offset 受熔断器约束，恒定毫秒级）。
    $noFieldFilter = empty($_GET['cdr_id']) && empty($_GET['caller']) && empty($_GET['callee'])
        && empty($_GET['caller_in']) && empty($_GET['callee_in'])
        && empty($_GET['gateway_in']) && empty($_GET['gateway_out']) && empty($_GET['account'])
        && empty($_GET['disconnect_cause']) && empty($_GET['duration_min']) && empty($_GET['duration_max']);

    // ── 深度分页熔断保护 ──
    // 无条件查询：page > 100 → 返回空数组（前100页已覆盖最近数据，更早的用筛选条件查）
    // 带筛选查询：offset > 5000 → 返回 400（精确 COUNT 深分页慢，防滥用）
    $MAX_PAGE_NO_FILTER = 100;
    if ($noFieldFilter && $page > $MAX_PAGE_NO_FILTER) {
        // 仍需返回真实总数（前端分页栏按真实总数展示页码）
        $tables = cdrDayTables($pdo);
        $dateList = [];
        foreach ($tables as $tbl) {
            if (preg_match('/y_cdr_(\d{4})(\d{2})(\d{2})/', $tbl, $m)) {
                $dateList[] = "{$m[1]}-{$m[2]}-{$m[3]}";
            }
        }
        $total = 0;
        if ($dateList) {
            try {
                $ph = implode(',', array_fill(0, count($dateList), '?'));
                $sStmt = $pdo->prepare("SELECT COALESCE(SUM(total_count), 0) FROM y_cdr_summary WHERE `date` IN ($ph)");
                $sStmt->execute($dateList);
                $total = intval($sStmt->fetchColumn());
            } catch (\PDOException $e) {
                // summary 表不存在，降级为精确 COUNT
                foreach ($tables as $tbl) {
                    $total += intval($pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn());
                }
            }
        }
        Response::success([
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'data' => [],
            'capped' => true,
        ]);
        return;
    }
    if (!$noFieldFilter && $offset > 5000) {
        Response::error('数据量过大，请缩小日期范围或增加筛选条件后重试', 400);
        return;
    }

    $whereSQL = implode(' AND ', $where);
    $tables = cdrDayTables($pdo);

    if (empty($tables)) {
        Response::success(['total' => 0, 'page' => $page, 'pageSize' => $pageSize, 'data' => []]);
        return;
    }

    $cols = "c.id, c.cdr_id, c.node_id, c.node_ip, c.call_id, c.caller, c.callee,
        c.caller_out, c.callee_out, c.caller_in, c.callee_in,
        c.caller_ip, c.callee_ip,
        c.start_time, c.end_time, c.duration, c.continuous_duration, c.bill_duration,
        c.fee_rate, c.fee,
        c.direction, c.disconnect_cause, c.gateway_in, c.gateway_out,
        c.account, c.fee_rate_group, c.raw_data, c.received_at,
        n.name AS node_name,
        gr.clearing_account AS settlement_account,
        gm.account AS mapping_account";

    $branch = function ($tbl) use ($cols, $whereSQL) {
        return "SELECT {$cols} FROM `{$tbl}` c " . cdrJoin() . " WHERE {$whereSQL}";
    };

    $approx = false;

    $dataParams = $params;
    if ($noFieldFilter) {
        // ── 无条件分支：总数走 y_cdr_summary，数据走 Deferred JOIN ──

        // 1) 总数：优先 SUM(y_cdr_summary.total_count)（O(天数)毫秒级）
        //    若 summary 表不存在或查失败，降级为精确 COUNT(*)（兜底保障）
        $dateList = [];
        foreach ($tables as $tbl) {
            if (preg_match('/y_cdr_(\d{4})(\d{2})(\d{2})/', $tbl, $m)) {
                $dateList[] = "{$m[1]}-{$m[2]}-{$m[3]}";
            }
        }

        $total = 0;
        $usedSummary = false;
        if ($dateList) {
            try {
                $ph = implode(',', array_fill(0, count($dateList), '?'));
                $sSQL = "SELECT COALESCE(SUM(total_count), 0) FROM y_cdr_summary WHERE `date` IN ($ph)";
                $sStmt = $pdo->prepare($sSQL);
                $sStmt->execute($dateList);
                $total = intval($sStmt->fetchColumn());
                $usedSummary = true;
            } catch (\PDOException $e) {
                // y_cdr_summary 表不存在（未跑 init.php / fix_cdr_summary.php），降级为精确 COUNT
                $total = 0;
                foreach ($tables as $tbl) {
                    $cStmt = $pdo->query("SELECT COUNT(*) FROM `{$tbl}`");
                    $total += intval($cStmt->fetchColumn());
                }
            }
        }

        // 2) 数据：Deferred JOIN 优化 — 先用 idx_start_time 覆盖索引取 LIMIT 行的 ID（<1ms）,
        //    再只对这几行做 LEFT JOIN — 避免对全表做 3 次 COLLATE 比较
        //    排序字段用 start_time（与 WHERE 过滤同索引，避免 filesort）
        //    cap = offset + pageSize，受熔断器约束（page ≤ 100 → cap ≤ 100×pageSize，恒定有界）
        $cap = $offset + $pageSize;

        if (count($tables) === 1) {
            $innerSQL = "SELECT id FROM `{$tables[0]}` WHERE {$whereSQL} ORDER BY start_time DESC LIMIT {$offset}, {$pageSize}";
            $dataSQL = "SELECT {$cols} FROM ($innerSQL) AS ids JOIN `{$tables[0]}` c ON c.id = ids.id " . cdrJoin();
            $dataParams = $params;
        } else {
            $branchTop = function ($tbl) use ($cols, $whereSQL, $cap) {
                $inner = "SELECT id FROM `{$tbl}` WHERE {$whereSQL} ORDER BY start_time DESC LIMIT {$cap}";
                return "(SELECT {$cols} FROM ($inner) AS ids JOIN `{$tbl}` c ON c.id = ids.id " . cdrJoin() . ")";
            };
            $union = implode(' UNION ALL ', array_map($branchTop, $tables));
            $dataSQL = "SELECT * FROM ({$union}) t ORDER BY start_time DESC LIMIT {$offset}, {$pageSize}";
            $multiParams = [];
            for ($i = 0; $i < count($tables); $i++) {
                $multiParams = array_merge($multiParams, $params);
            }
            $dataParams = $multiParams;
        }
    } else {
        // —— 带字段筛选：counter 无法预聚合，精确 COUNT(*) + Deferred JOIN 数据查询 ——
        // 多表 UNION ALL 时每个 branch 的 WHERE 都有占位符，COUNT/数据查询都需重复 params。
        // 注意：绝不依赖 EXPLAIN 估算作为 total（MySQL 对「UNION ALL + 多 LEFT JOIN + COLLATE」
        // 的 rows 估算严重失真，实测虚高几十倍）。
        $multiParams = $params;
        if (count($tables) > 1) {
            $multiParams = [];
            for ($i = 0; $i < count($tables); $i++) {
                $multiParams = array_merge($multiParams, $params);
            }
        }
        if (count($tables) === 1) {
            $countSQL = "SELECT COUNT(*) FROM `{$tables[0]}` c WHERE {$whereSQL}";
            $cStmt = $pdo->prepare($countSQL);
            $cStmt->execute($params);
        } else {
            // 多表：逐表 COUNT 再 SUM（WHERE 不带 JOIN，LEFT JOIN 不产生额外行，计数一致）
            $countParts = [];
            foreach ($tables as $tbl) {
                $countParts[] = "SELECT COUNT(*) AS cnt FROM `{$tbl}` c WHERE {$whereSQL}";
            }
            $countSQL = "SELECT SUM(cnt) FROM (" . implode(' UNION ALL ', $countParts) . ") x";
            $cStmt = $pdo->prepare($countSQL);
            $cStmt->execute($multiParams);
        }
        $total = intval($cStmt->fetchColumn());

        // 数据查询: Deferred JOIN — 先取 ID+LIMIT(走索引), 再只 JOIN 这几行
        if (count($tables) === 1) {
            $innerSQL = "SELECT id FROM `{$tables[0]}` WHERE {$whereSQL} ORDER BY start_time DESC LIMIT {$offset}, {$pageSize}";
            $dataSQL = "SELECT {$cols} FROM ($innerSQL) AS ids JOIN `{$tables[0]}` c ON c.id = ids.id " . cdrJoin();
            $dataParams = $params;
        } else {
            $dataCap = $offset + $pageSize; // offset>5000 已熔断, 此 cap 有界
            $deferredBranch = function ($tbl) use ($cols, $whereSQL, $dataCap) {
                $inner = "SELECT id FROM `{$tbl}` WHERE {$whereSQL} ORDER BY start_time DESC LIMIT {$dataCap}";
                return "(SELECT {$cols} FROM ($inner) AS ids JOIN `{$tbl}` c ON c.id = ids.id " . cdrJoin() . ")";
            };
            $union = implode(' UNION ALL ', array_map($deferredBranch, $tables));
            $dataSQL = "SELECT * FROM ({$union}) t ORDER BY start_time DESC LIMIT {$offset}, {$pageSize}";
            $dataParams = $multiParams;
        }
    }

    try {
        $stmt = $pdo->prepare($dataSQL);
        $stmt->execute($dataParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        // Deferred JOIN 失败时降级为传统查询（无子查询，直接 SELECT+JOIN+LIMIT）
        $fallbackSQL = count($tables) === 1
            ? $branch($tables[0]) . " ORDER BY c.start_time DESC LIMIT {$offset}, {$pageSize}"
            : "SELECT * FROM (" . implode(' UNION ALL ', array_map($branch, $tables)) . ") t ORDER BY start_time DESC LIMIT {$offset}, {$pageSize}";
        $stmt = $pdo->prepare($fallbackSQL);
        $stmt->execute($dataParams);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    Response::success([
        'total' => $total,
        'approx' => $approx,
        'page' => $page,
        'pageSize' => $pageSize,
        'data' => $rows ?: [],
    ]);
}

/* ============================================================
 * 导出通话记录（CSV，字段选择，行数上限10万）
 * ========================================================== */
function handleExport($pdo) {
    // 字段映射：数据库字段 => CSV 列标题
    $fieldMap = [
        'caller'              => '主叫号码',
        'callee'              => '被叫号码',
        'caller_in'           => '呼入主叫',
        'callee_in'           => '呼入被叫',
        'caller_out'          => '呼出主叫',
        'callee_out'          => '呼出被叫',
        'gateway_in'          => '对接网关',
        'gateway_out'         => '落地网关',
        'start_time'          => '起始时间',
        'end_time'            => '结束时间',
        'duration'            => '实际通话时长(秒)',
        'continuous_duration' => '持续时长(秒)',
        'bill_duration'       => '计费时长(秒)',
        'caller_ip'           => '主叫IP',
        'callee_ip'           => '被叫IP',
        'disconnect_cause'    => '状态',
        'direction'           => '方向',
        'fee_rate'            => '费率',
        'fee'                 => '费用',
        'account'             => '账户',
        'fee_rate_group'      => '费率组',
        'settlement_account'  => '结算账户',
        'mapping_account'     => '对接账户',
        'cdr_id'              => '话单ID',
        'node_name'           => '节点',
        'call_id'             => 'Call ID',
        'received_at'         => '接收时间',
    ];

    // 需要 JOIN 的字段（非 c. 前缀）
    $joinCols = [
        'node_name'          => 'n.name AS node_name',
        'settlement_account' => 'gr.clearing_account AS settlement_account',
        'mapping_account'    => 'gm.account AS mapping_account',
    ];

    // 解析选中字段
    $fieldsParam = $_GET['fields'] ?? '';
    $allowed = array_keys($fieldMap);
    $selected = array_filter(explode(',', $fieldsParam), function ($f) use ($allowed) {
        return in_array(trim($f), $allowed);
    });
    if (empty($selected)) {
        $selected = $allowed;
    }
    $selected = array_map('trim', $selected);

    // 构建筛选条件（同 handleList）
    $where = ['1=1'];
    $params = [];
    if (!empty($_GET['cdr_id']))           { $where[] = 'c.cdr_id = ?';           $params[] = $_GET['cdr_id']; }
    if (!empty($_GET['node_id']))          { $where[] = 'c.node_id = ?';          $params[] = intval($_GET['node_id']); }
    if (!empty($_GET['caller']))           { $where[] = 'c.caller LIKE ?';        $params[] = '%' . $_GET['caller'] . '%'; }
    if (!empty($_GET['callee']))           { $where[] = 'c.callee LIKE ?';        $params[] = '%' . $_GET['callee'] . '%'; }
    if (!empty($_GET['caller_in']))        { $where[] = 'c.caller_in LIKE ?';     $params[] = '%' . $_GET['caller_in'] . '%'; }
    if (!empty($_GET['callee_in']))        { $where[] = 'c.callee_in LIKE ?';     $params[] = '%' . $_GET['callee_in'] . '%'; }
    if (!empty($_GET['gateway_in']))       { $where[] = 'c.gateway_in LIKE ?';    $params[] = '%' . $_GET['gateway_in'] . '%'; }
    if (!empty($_GET['gateway_out']))      { $where[] = 'c.gateway_out LIKE ?';   $params[] = '%' . $_GET['gateway_out'] . '%'; }
    if (!empty($_GET['account']))          { $where[] = 'c.account LIKE ?';       $params[] = '%' . $_GET['account'] . '%'; }
    if (!empty($_GET['disconnect_cause'])) { $where[] = 'c.disconnect_cause = ?'; $params[] = $_GET['disconnect_cause']; }
    if (!empty($_GET['duration_min']))     { $where[] = 'c.duration >= ?';        $params[] = intval($_GET['duration_min']); }
    if (!empty($_GET['duration_max']))     { $where[] = 'c.duration <= ?';        $params[] = intval($_GET['duration_max']); }
    if (!empty($_GET['start_time_from']))  { $where[] = 'c.start_time >= ?';      $params[] = $_GET['start_time_from']; }
    if (!empty($_GET['start_time_to']))    {
        $to = $_GET['start_time_to'];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to .= ' 23:59:59';
        $where[] = 'c.start_time <= ?';
        $params[] = $to;
    }
    $whereSQL = implode(' AND ', $where);

    // 表路由
    $tables = cdrDayTables($pdo);
    if (empty($tables)) {
        Response::error('所选日期范围内无话单数据');
        return;
    }

    // 构建 SELECT 列（加 start_time 做排序，输出时跳过）
    $hasStartTime = in_array('start_time', $selected);
    $selectCols = implode(', ', array_map(function ($f) use ($joinCols) {
        return $joinCols[$f] ?? "c.{$f}";
    }, $selected));
    if (!$hasStartTime) {
        $selectCols .= ', c.start_time AS _sort';
    }

    $branch = function ($tbl) use ($selectCols, $whereSQL) {
        return "SELECT {$selectCols} FROM `{$tbl}` c " . cdrJoin() . " WHERE {$whereSQL}";
    };

    $maxRows = 100000;
    if (count($tables) === 1) {
        $sql = $branch($tables[0]) . " ORDER BY c.start_time DESC LIMIT {$maxRows}";
    } else {
        $union = implode(' UNION ALL ', array_map($branch, $tables));
        $sortField = $hasStartTime ? 'start_time' : '_sort';
        $sql = "SELECT * FROM ({$union}) t ORDER BY {$sortField} DESC LIMIT {$maxRows}";
    }

    // 流式输出 CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cdr_' . date('Ymd_His') . '.csv"');
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'w');
    // UTF-8 BOM（Excel 正确识别编码）
    fwrite($output, "\xEF\xBB\xBF");

    // 表头
    fputcsv($output, array_map(function ($f) use ($fieldMap) {
        return $fieldMap[$f];
    }, $selected));

    // 数据行
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rowCount = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($row['_sort'])) unset($row['_sort']);

        // disconnect_cause 转中文
        if (isset($row['disconnect_cause'])) {
            $code = $row['disconnect_cause'];
            $row['disconnect_cause'] = $code . ' (' . getCauseLabel($code) . ')';
        }
        // direction 转中文
        if (isset($row['direction'])) {
            $row['direction'] = ($row['direction'] === 'outbound') ? '呼出' : '呼入';
        }

        fputcsv($output, $row);
        $rowCount++;
    }

    fclose($output);
}

/* ============================================================
 * 统计概览
 * ========================================================== */
function handleStats($pdo) {
    // 总通话数：所有已存在的日表汇总（按天分表下的"全部"）
    $allTables = cdrAllTables($pdo);
    $basic = cdrSum($pdo, $allTables, '1=1', []);

    // 今日：今日日表
    $todayName = 'y_cdr_' . date('Ymd');
    $todayTables = in_array($todayName, $allTables, true) ? [$todayName] : [];
    if (empty($todayTables)) {
        $escaped = str_replace('_', '\\_', $todayName);
        $st = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($escaped));
        if ($st->fetchColumn()) $todayTables = [$todayName];
    }
    $today = cdrSum($pdo, $todayTables, '1=1', []);

    // 最近24小时趋势（按小时，基于今日日表）
    $trend = [];
    if ($todayTables) {
        $stmt = $pdo->query(
            "SELECT
                DATE_FORMAT(received_at, '%Y-%m-%d %H:00') as hour,
                COUNT(*) as calls,
                COALESCE(SUM(duration), 0) as duration,
                COALESCE(SUM(fee), 0) as fee
            FROM `{$todayName}`
            WHERE received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY DATE_FORMAT(received_at, '%Y-%m-%d %H:00')
            ORDER BY hour ASC"
        );
        $trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    Response::success([
        'basic' => $basic ?: [],
        'today' => $today ?: [],
        'trend' => $trend ?: [],
    ]);
}

/* ============================================================
 * 清空话单（按日期范围，默认今日；需 confirm=yes）
 * ========================================================== */
function handleClear($pdo) {
    // 强确认：必须 confirm=DELETE，避免误触或脚本误带弱确认词
    $confirm = trim($_REQUEST['confirm'] ?? '');
    if ($confirm !== 'DELETE') {
        Response::error('清空话单需显式确认：传 confirm=DELETE（此操作不可逆，仅清空所选日期范围，不传范围默认当天）', 400);
    }

    // 按日期范围清空（start_time_from / start_time_to），不传则默认当天
    $from = trim($_GET['start_time_from'] ?? '');
    $to   = trim($_GET['start_time_to'] ?? '');
    $scope = ($from || $to) ? "{$from}~{$to}" : '当天';
    $tables = cdrDayTables($pdo);
    if (empty($tables)) {
        Response::success(null, '无对应日表可清空');
        return;
    }

    $count = 0;
    foreach ($tables as $t) {
        $pdo->exec("TRUNCATE TABLE `{$t}`");
        // 同步清预聚合计数器 + 总数汇总表：日表被清空时若不同步清，
        // 会导致无筛选查询的总数严重失真。
        if (preg_match('/y_cdr_(\d{4})(\d{2})(\d{2})/', $t, $m)) {
            $dateStr = "{$m[1]}-{$m[2]}-{$m[3]}";
            $stmt = $pdo->prepare("DELETE FROM y_cdr_counter WHERE date = ?");
            $stmt->execute([$dateStr]);
            $stmt = $pdo->prepare("DELETE FROM y_cdr_summary WHERE `date` = ?");
            $stmt->execute([$dateStr]);
        }
        $count++;
    }

    // 审计：不可逆操作必须留痕（Logger 会自动记录操作用户）
    \VOS\Logger::log('delete', 'cdr', $scope, "清空话单 {$count} 张日表（范围：{$scope}）");

    Response::success(null, "已清空 {$count} 张日表（范围：{$scope}）");
}

/* ============================================================
 * 筛选下拉选项
 * ========================================================== */
function handleOptions($pdo) {
    $stmt = $pdo->query("SELECT id, name FROM y_nodes WHERE status = 1 ORDER BY id");
    $nodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $tables = cdrAllTables($pdo);
    if (empty($tables)) {
        Response::success([
            'nodes' => $nodes,
            'accounts' => [], 'gateways_in' => [], 'gateways_out' => [], 'disconnect_causes' => [],
        ]);
        return;
    }

    $distinctCol = function ($col) use ($tables, $pdo) {
        $branches = array_map(function ($t) use ($col) {
            return "SELECT {$col} FROM `{$t}` c WHERE c.{$col} != '' AND c.{$col} IS NOT NULL";
        }, $tables);
        $union = implode(' UNION ALL ', $branches);
        return $pdo->query("SELECT DISTINCT {$col} FROM ({$union}) x ORDER BY {$col}")->fetchAll(PDO::FETCH_COLUMN);
    };

    $accounts = $distinctCol('account');
    $gatewaysIn = $distinctCol('gateway_in');
    $gatewaysOut = $distinctCol('gateway_out');
    $realCodes = $distinctCol('disconnect_cause');

    $causeMap = [];
    foreach ($realCodes as $code) {
        $num = intval($code);
        $isSuccess = ($num === -7 || $num === -8 || $num === -10 || $num === -11 || $num === 0 || $num === 16);
        $causeMap[] = [
            'code' => $code,
            'label' => $code . ' - ' . getCauseLabel($code),
            'is_success' => $isSuccess,
        ];
    }

    Response::success([
        'nodes' => $nodes,
        'accounts' => $accounts,
        'gateways_in' => $gatewaysIn,
        'gateways_out' => $gatewaysOut,
        'disconnect_causes' => $causeMap,
    ]);
}

/**
 * 挂断原因中文描述（PHP端，与前端disconnect-codes.js保持一致）
 */
function getCauseLabel($code) {
    $map = [
        '-7' => '主叫挂断', '-8' => '被叫挂断', '-10' => '主叫关闭连接', '-11' => '被叫关闭连接',
        '-21' => '被叫不在线', '-39' => '被叫忙', '-2' => '账户余额不足', '-3' => '账户不存在',
        '-1' => '无此呼叫权限', '-5' => '费率不存在', '-4' => '无振铃异常挂断',
        '-63' => '结算账户余额不足', '-61' => '落地网关异常', '-62' => '落地网关无结算账户',
        '-100' => '被叫超时', '0' => '操作成功', '16' => '正常清除',
        '404' => 'Not Found', '480' => 'Temporarily Unavailable', '486' => 'Busy Here',
        '503' => 'Service Unavailable',
    ];
    return $map[$code] ?? $code;
}
