# CDR 查询分页重构方案

> 目标：无条件查询毫秒级返回 + 分页栏展示真实总数 + 深度分页熔断保护

---

## 架构总览

```
┌─────────────────────────────────────────────────────────────┐
│                     Vue 前端 (el-pagination)                  │
│  total = 真实总数 → 分页栏按总数展示页码                        │
│  page > 100 → 后端返回 capped=true → 前端提示用筛选条件查      │
└────────────────────────┬────────────────────────────────────┘
                         │ GET /api/cdr?page=N&pageSize=10
┌────────────────────────▼────────────────────────────────────┐
│                   PHP 后端 (handleList)                      │
│                                                              │
│  ┌─ 无条件分支 ──────────────────────────────────────────┐  │
│  │ 总数: SELECT SUM(total_count) FROM y_cdr_summary      │  │
│  │       → O(天数) 毫秒级，绝不对日表 COUNT(*)            │  │
│  │ 熔断: page > 100 → 返回空数组 + capped=true           │  │
│  │ 数据: 每表 ORDER BY received_at DESC LIMIT cap        │  │
│  │       → UNION ALL → 全局 LIMIT offset, pageSize       │  │
│  │       → cap 受熔断约束 (≤100×pageSize)，恒定有界       │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌─ 带筛选分支 ──────────────────────────────────────────┐  │
│  │ 总数: 精确 COUNT(*)（counter 无法预聚合筛选维度）      │  │
│  │ 熔断: offset > 5000 → 返回 400                         │  │
│  │ 数据: UNION ALL + LIMIT（同上）                        │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
          ▲                                    ▲
          │ SUM(total_count)                   │ INSERT IGNORE INTO 日表
          │ O(天数) ms                          │
┌─────────┴──────────┐              ┌──────────┴──────────────┐
│  y_cdr_summary     │              │    y_cdr_YYYYMMDD       │
│  (date, total_count)│             │    (按天分表，日表)      │
│  PRIMARY KEY(date) │              │    INDEX idx_received_at│
│  每天一行           │              │    每天一张表            │
└────────────────────┘              └─────────────────────────┘
          ▲
          │ INSERT ... ON DUPLICATE KEY UPDATE
          │ total_count = total_count + VALUES(total_count)
          │ (原子操作，batchCount 条一次性累加)
┌─────────┴──────────────────────────────────────────────────┐
│              Node.js cdr-receiver (flushQueue)              │
│                                                              │
│  UDP 接收 → CSV 解析 → 内存队列 → 批量 INSERT IGNORE 日表    │
│  → inserted = result.affectedRows                           │
│  → UPDATE y_cdr_summary SET total_count = total_count + N   │
│    (严禁逐条 +1，一条 SQL 原子累加整个批次)                    │
└─────────────────────────────────────────────────────────────┘
```

### 核心设计原则

| 红线 | 实现 |
|------|------|
| **禁止无条件 COUNT(\*)** | 总数走 `y_cdr_summary` 汇总表 `SUM(total_count)` |
| **禁止无条件全表扫描** | 数据走 `idx_received_at` 索引 + `LIMIT cap`，受熔断器约束 |
| **总数毫秒级** | `y_cdr_summary` 每天一行，SUM 跨天 = O(天数) ≈ 0ms |
| **数据毫秒级** | `cap = offset + pageSize ≤ 100×pageSize`，每表最多扫 cap 行 |
| **总数真实** | `y_cdr_summary` 由 cdr-receiver 批量写入时原子累加 |
| **分页按总数展示** | 前端 `el-pagination :total="真实总数"` 生成全部页码 |

---

## 模块一：数据库设计 (MySQL DDL)

```sql
-- CDR 按天总数汇总表
-- 极简结构：仅 date + total_count，每天一行
-- 无条件查询时 SELECT SUM(total_count) 毫秒级返回真实总数
-- 绝不需要对亿级日表执行 COUNT(*)
CREATE TABLE IF NOT EXISTS `y_cdr_summary` (
    `date`        DATE          NOT NULL COMMENT '日期(YYYY-MM-DD)',
    total_count   BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '当日话单总条数',
    PRIMARY KEY (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='CDR按天总数汇总(无条件查询毫秒级取总数)';
```

**设计说明：**

- **为什么按天而不是单行全局计数？** 系统采用按天分表（`y_cdr_YYYYMMDD`），前端默认查"今天"，也可能选日期范围。按天维护可精确支持 `WHERE date BETWEEN ? AND ?` 的日期范围总数，单行全局计数做不到。
- **为什么不要 node_id 维度？** 旧的 `y_cdr_counter(date, node_id, total)` 有 node_id 维度，但无条件查询不需要它。去掉后表更小、查询更简单。按 node_id 筛选属于"带筛选"，走精确 COUNT。
- **BIGINT UNSIGNED**：单日最高 ~42 亿条，足够。
- **PRIMARY KEY(date)**：一天一行，主键即索引，`WHERE date IN (...)` 走主键查找。

**文件位置：**
- DDL 定义：`server/cdr-receiver/cdr-schema.js` → `getSummaryDDL()`
- PHP 侧建表：`server/database/init.php`（`CREATE IF NOT EXISTS`，幂等安全）
- Node 侧建表：`server/cdr-receiver/cdr-receiver.js` → `ensureSummaryTable()`（启动时自动执行）

---

## 模块二：数据写入侧更新逻辑 (Node.js)

在 `server/cdr-receiver/cdr-receiver.js` 的 `flushQueue()` 中，批量 INSERT 成功后原子累加汇总表：

```javascript
// ===== 总数汇总表建表确保（initDB 中调用）=====
async function ensureSummaryTable() {
  await pool.execute(getSummaryDDL('y_cdr_summary'))
  console.log('[DB] y_cdr_summary 总数汇总表就绪')
}

// ===== 批量写入成功后原子累加当日全局总数 =====
// 严禁逐条 +1！一条 SQL 原子累加整个批次的 inserted 条
// INSERT ... ON DUPLICATE KEY UPDATE 是原子操作，并发安全
async function updateCdrSummary(date, n) {
  if (!n) return
  try {
    await pool.execute(
      `INSERT INTO y_cdr_summary (\`date\`, total_count) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE total_count = total_count + VALUES(total_count)`,
      [date, n]
    )
  } catch (err) {
    console.error('[SUMMARY] 总数汇总更新失败:', err.message)
  }
}

// ===== flushQueue 中的调用位置 =====
async function flushQueue() {
  // ... 取出队列、预处理、按天分组 ...

  for (const { tableName, nodeId, cdrs } of groups.values()) {
    // 批量 INSERT IGNORE，返回真实新增条数（重复 call_id 不计）
    const inserted = await batchInsert(tableName, cdrs)
    stats.written += inserted
    const date = tableNameToDate(tableName)

    // 原子累加当日全局总数（batchCount 条一次性 +N）
    await updateCdrSummary(date, inserted)

    // 其他聚合更新（counter / hourly / dim）...
  }
}
```

**关键点：**

1. **`inserted` 是 `INSERT IGNORE` 的 `affectedRows`**，即真实新增条数（重复 call_id 被跳过），不会多计。
2. **`ON DUPLICATE KEY UPDATE total_count = total_count + VALUES(total_count)`** 是 MySQL 原子操作，无需事务、无需锁，高并发下安全。
3. **`date` 口径**：取自表名（`y_cdr_20260713` → `2026-07-13`），与日表严格对应，不走 `start_time` 的 `DATE()`，避免口径不一致导致漂移。

---

## 模块三：PHP 后端查询接口逻辑

`server/api/cdr/index.php` → `handleList()`，完整重构后的核心逻辑：

```php
function handleList($pdo) {
    // ── 1. 构建筛选条件（与原代码一致）──
    $where = ['1=1'];
    $params = [];
    // ... caller/callee/gateway/account/disconnect_cause/duration/start_time 等筛选
    // （省略，与原代码完全相同）

    // ── 2. 分页参数 ──
    $page = max(1, intval($_GET['page'] ?? 1));
    $pageSize = min(200, max(1, intval($_GET['pageSize'] ?? 10)));  // 默认 10
    $offset = ($page - 1) * $pageSize;

    // ── 3. 判断是否「无字段筛选」──
    $noFieldFilter = empty($_GET['cdr_id']) && empty($_GET['caller'])
        && empty($_GET['callee']) && empty($_GET['caller_in'])
        && empty($_GET['callee_in']) && empty($_GET['gateway_in'])
        && empty($_GET['gateway_out']) && empty($_GET['account'])
        && empty($_GET['disconnect_cause'])
        && empty($_GET['duration_min']) && empty($_GET['duration_max']);

    // ── 4. 深度分页熔断保护 ──
    $MAX_PAGE_NO_FILTER = 100;
    if ($noFieldFilter && $page > $MAX_PAGE_NO_FILTER) {
        // 仍需返回真实总数（前端分页栏按真实总数展示页码）
        $total = getSummaryTotal($pdo, $tables);
        Response::success([
            'total' => $total, 'page' => $page, 'pageSize' => $pageSize,
            'data' => [], 'capped' => true,  // 告诉前端：已熔断
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

    // ── 5. 查询逻辑分支 ──
    if ($noFieldFilter) {
        // ★ 无条件：总数从 y_cdr_summary 毫秒级读取
        $total = getSummaryTotal($pdo, $tables);

        // ★ 数据：branchTop + UNION ALL + 全局 LIMIT
        $cap = $offset + $pageSize;  // 受熔断约束，≤ 100×pageSize

        if (count($tables) === 1) {
            $dataSQL = $branch($tables[0])
                . " ORDER BY c.received_at DESC LIMIT {$offset}, {$pageSize}";
            $dataParams = $params;
        } else {
            $branchTop = function ($tbl) use ($cols, $whereSQL, $cap) {
                return "(SELECT {$cols} FROM `{$tbl}` c " . cdrJoin()
                    . " WHERE {$whereSQL} ORDER BY c.received_at DESC LIMIT {$cap})";
            };
            $union = implode(' UNION ALL ', array_map($branchTop, $tables));
            $dataSQL = "SELECT * FROM ({$union}) t"
                . " ORDER BY received_at DESC LIMIT {$offset}, {$pageSize}";
            $multiParams = [];
            for ($i = 0; $i < count($tables); $i++) {
                $multiParams = array_merge($multiParams, $params);
            }
            $dataParams = $multiParams;
        }
    } else {
        // ★ 带筛选：精确 COUNT(*)（counter 无法预聚合筛选维度）
        // 多表 UNION ALL 逐表 COUNT 再 SUM
        // （与原代码一致，此处省略）
    }

    // ── 6. 执行查询 ──
    $stmt = $pdo->prepare($dataSQL);
    $stmt->execute($dataParams);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success([
        'total' => $total,
        'page' => $page,
        'pageSize' => $pageSize,
        'data' => $rows ?: [],
    ]);
}

// 辅助函数：从 y_cdr_summary 取总数
function getSummaryTotal($pdo, $tables) {
    $dateList = [];
    foreach ($tables as $tbl) {
        if (preg_match('/y_cdr_(\d{4})(\d{2})(\d{2})/', $tbl, $m)) {
            $dateList[] = "{$m[1]}-{$m[2]}-{$m[3]}";
        }
    }
    if (empty($dateList)) return 0;
    $ph = implode(',', array_fill(0, count($dateList), '?'));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_count), 0) FROM y_cdr_summary WHERE `date` IN ($ph)");
    $stmt->execute($dateList);
    return intval($stmt->fetchColumn());
}
```

**关键设计决策：**

| 决策 | 原因 |
|------|------|
| 总数用 `SUM(total_count)` 不用单行 `SELECT total_count` | 系统按天分表，需支持日期范围查询，按天存储才能 `WHERE date BETWEEN` |
| 数据用 `branchTop + UNION ALL` 不用分段定位 | 分段定位虽然任意页快，但代码复杂。熔断器把 cap 约束在 ≤100×pageSize，branchTop 足够快且代码简洁 |
| 熔断阈值 100 页 | 100×10=1000 条，覆盖最近话单足够；更早的数据用筛选条件查。类似 VOS3000 只展示最近 1000 条 |
| 熔断时仍返回真实 total | 前端分页栏需要真实总数展示页码，用户能看到总页数但点击 >100 页会收到提示 |
| 带筛选保持精确 COUNT | counter/summary 无法预聚合 caller/callee/gateway 等维度，只能 COUNT(*) |

**handleClear 同步更新：** 清空日表时同步删 `y_cdr_summary` 对应日期行，防止漂移。

---

## 模块四：Vue 前端对接逻辑

`src/views/call/CallRecord.vue` 核心逻辑：

```vue
<template>
  <!-- 分页 -->
  <div class="pagination-wrap">
    <el-pagination
      v-model:current-page="pagination.page"
      v-model:page-size="pagination.pageSize"
      :page-sizes="[10, 50, 100, 200]"
      :total="pagination.total"
      layout="total, sizes, prev, pager, next, jumper"
      @size-change="handleSizeChange"
      @current-change="handlePageChange"
    />
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { ElMessage } from 'element-plus'
import { getCDRList } from '@/api/cdr'

// 分页状态
const pagination = reactive({
  page: 1,
  pageSize: 10,   // 默认 10 条/页
  total: 0,       // 真实总数（从 y_cdr_summary 读取）
  approx: false,
})

// 加载数据（竞态保护）
let loadSeq = 0
async function loadData() {
  const seq = ++loadSeq
  tableLoading.value = true
  try {
    const res = await getCDRList({
      ...filters,
      page: pagination.page,
      pageSize: pagination.pageSize,
      // start_time_from / start_time_to ...
    })
    if (seq !== loadSeq) return  // 丢弃过期响应
    if (res.success) {
      const d = res.data
      tableData.value = d.data || []
      pagination.total = d.total || 0       // 真实总数绑定到 el-pagination
      pagination.approx = !!d.approx

      // 熔断提示：无条件查询超过 100 页时后端返回空数据 + capped=true
      if (d.capped) {
        ElMessage.info('已超出深翻页限制（前100页），请使用筛选条件查询更早的数据')
      }
    }
  } catch (e) {
    if (seq !== loadSeq) return
    tableData.value = []
    pagination.total = 0
  } finally {
    if (seq === loadSeq) tableLoading.value = false
  }
}

// 翻页
function handlePageChange() { loadData() }

// 切换每页条数（重置到第1页）
function handleSizeChange() { pagination.page = 1; loadData() }
</script>
```

**要点：**

1. **`:total="pagination.total"`** — 绑定真实总数，`el-pagination` 自动计算总页数并生成页码
2. **`layout="total, sizes, prev, pager, next, jumper"`** — `total` 在最左侧显示"共 N 条"
3. **`pageSize` 默认 10** — 与用户需求一致
4. **`capped` 处理** — 熔断时后端返回 `capped: true` + 空 data，前端弹提示让用户用筛选条件
5. **竞态保护** — 快速翻页时 `loadSeq` 丢弃过期响应，防止旧数据覆盖新数据

---

## 迁移脚本

已有数据需要一次性回填 `y_cdr_summary`，运行 `server/database/fix_cdr_summary.php`：

```php
// 1. 建表兜底（CREATE IF NOT EXISTS）
// 2. DELETE FROM y_cdr_summary（清空旧数据）
// 3. 遍历所有日表 y_cdr_YYYYMMDD：
//    $cnt = SELECT COUNT(*) FROM y_cdr_YYYYMMDD
//    INSERT INTO y_cdr_summary (date, total_count) VALUES ('YYYY-MM-DD', $cnt)
// 4. 输出汇总：重建 N 张日表，总计 M 条
```

**上线步骤：**
```bash
# 1. 部署新代码
# 2. 运行迁移脚本（一次性，将现有日表数据回填到汇总表）
php server/database/fix_cdr_summary.php
# 3. 重启 cdr-receiver（新代码会在批量写入时自动维护汇总表）
pm2 restart cdr-receiver
```

---

## 与旧方案对比

| 维度 | 旧方案 (v17 分段定位) | 新方案 (汇总表 + 熔断器) |
|------|----------------------|------------------------|
| 总数来源 | `y_cdr_counter` SUM (含 node_id) | `y_cdr_summary` SUM (极简) |
| 数据查询 | 前缀和定位 → 单表局部 LIMIT | branchTop + UNION ALL + LIMIT |
| 任意页码 | 毫秒级（不限制页码） | 前 100 页毫秒级，>100 页返回空 |
| 代码复杂度 | 高（前缀和/跨界补查/localOffset） | 低（一条 SQL 搞定） |
| 可维护性 | 差（逻辑绕，注释多） | 好（一眼看懂） |
| 用户体验 | 任意翻页都快 | 前 100 页快，>100 提示用筛选 |
| 总数准确性 | 依赖 counter 与日表一致 | 依赖 summary 与日表一致 |
| 清空同步 | handleClear 清 counter | handleClear 清 summary |

**核心取舍：** 旧方案追求"任意页都快"但代码复杂；新方案接受"前 100 页够用"换来代码极简。对于话单场景，用户几乎只看最近数据，>100 页的数据用筛选条件查更合理。
