# CDR（通话记录）模块 — 代码包清单与数据库表设计

> 打包日期：2026-07-13
> 用途：将「通话记录」这一块涉及的**全部代码、数据库表设计、测试、文档**单独归档，便于交付/审查/交接。
> 本包**不含任何改造**，仅为既有代码收集。

---

## 一、本包包含的文件清单

### 后端（PHP API + Node 话单接收 + 数据库脚本）
| 文件 | 作用 |
|------|------|
| `server/api/cdr/index.php` | CDR 查询/列表/导出 API 入口（核心查询逻辑、counter 分段定位分页） |
| `server/api/report/index.php` | 报表统计 API（引用 `y_cdr` 做统计） |
| `server/cdr-receiver/cdr-receiver.js` | Node 话单接收主进程（监听 UDP 5140，解析 VOS 话单、动态建日表、累加 counter） |
| `server/cdr-receiver/cdr-schema.js` | **日表 + 汇总表的 DDL 定义（Node 侧）**，见第三节 |
| `server/cdr-receiver/backfill_cdr.js` | 话单回填/修复脚本 |
| `server/cdr-receiver/migrate-cdr.js` | 日表迁移脚本 |
| `server/cdr-receiver/package.json` / `package-lock.json` | Node 依赖声明（依赖装在 `node_modules/`，见排除项） |
| `server/database/init.php` | 建表脚本（含 `y_cdr_counter` / `y_cdr_hourly` / `y_cdr_daily_dim` 三个汇总表，见第三节） |
| `server/database/fix_cdr_counter.php` | 重建计数器（先清空再按日表名日期重新聚合，修复 counter 漂移） |
| `server/database/backfill_cdr_summary.php` | 一次性回填脚本（将已有日表聚合进 `y_cdr_hourly` / `y_cdr_daily_dim` / `y_cdr_counter`） |

### 前端
| 文件 | 作用 |
|------|------|
| `src/api/cdr.js` | CDR 相关接口的 API 封装（请求层） |
| `src/views/call/CallRecord.vue` | **通话记录列表页**（筛选、分页、详情弹窗，核心页面） |
| `src/views/call/CurrentCall.vue` | 当前通话（实时通话监控） |
| `src/views/Dashboard.vue` | 仪表盘（含 CDR 统计卡片，整文件引用了 CDR 接口，故一并纳入） |

### 测试
| 文件 | 作用 |
|------|------|
| `tests/cdr-dimension-test.php` | CDR 多维度查询测试矩阵（21 用例，DB 交叉验证） |
| `tests/cdr-perf-bench.php` | CDR 查询性能压测（MySQL 5.7 兼容批量插入） |
| `tests/cdr-segmented-bench.php` | 深分页基准（验证无筛选任意页码毫秒级） |

### 文档
| 文件 | 作用 |
|------|------|
| `docs/测试报告-20260712-话单维度.md` | 话单多维度查询测试报告 |
| `docs/测试报告-20260713-查询优化.md` | CDR 查询性能优化报告 |
| `docs/线上部署指南.md` | 线上部署指南（含 UDP 5140 / WS 8089 端口、Nginx、PM2） |
| `docs/上线手册-v16.md` / `docs/上线手册-v17.md` | 上线手册（含 CDR 计数器重建必做项、分段定位分页说明） |

---

## 二、架构与数据流

```
VOS3000 节点 ──UDP 5140──▶ cdr-receiver (Node)
                              ├─ 解析 VOS 话单文本
                              ├─ 按日期动态建 y_cdr_YYYYMMDD 日表 (DDL 见 cdr-schema.js)
                              ├─ 写入日表 (原始话单 + 结构化字段)
                              └─ 增量累加 y_cdr_counter(当日节点总数)
                                         │
                 查询请求                 ▼
   浏览器 ──▶ CallRecord.vue ──▶ src/api/cdr.js ──▶ server/api/cdr/index.php
                                                       ├─ 无筛选: total=SUM(y_cdr_counter) 秒级;
                                                       │          用 counter 前缀和定位 → 局部 LIMIT (毫秒级深翻页)
                                                       └─ 带筛选: 精确 COUNT(*) 逐表 SUM
```

- **日表** `y_cdr_YYYYMMDD`：每天一张，由 cdr-receiver 收到首条话单时自动建。
- **预聚合汇总表**（`y_cdr_counter` / `y_cdr_hourly` / `y_cdr_daily_dim`）：由 cdr-receiver 实时维护 + `fix_cdr_counter.php` / `backfill_cdr_summary.php` 可重建，用于「秒级查总数 / 趋势 / TOP-N」，避免扫亿级日表。
- `node_id` 当前全为 0（cdr-receiver 未正确关联节点），「按节点筛选」在数据侧待修复——这是已知数据问题，非本包范围。

---

## 三、数据库表设计（完整 DDL 来源）

> DDL 在两处都有定义，字段一致：
> - **Node 侧**：`server/cdr-receiver/cdr-schema.js`（`getCdrDDL` / `getCounterDDL` / `getHourlyDDL` / `getDailyDimDDL`）
> - **PHP 侧**：`server/database/init.php`（建 `y_cdr_counter` / `y_cdr_hourly` / `y_cdr_daily_dim`）

### 1) 日表 `y_cdr_YYYYMMDD`（按天分表，结构由 cdr-schema.js `getCdrDDL` 定义）
| 字段 | 类型 | 说明 |
|------|------|------|
| id | BIGINT AUTO_INCREMENT PK | 自增主键 |
| cdr_id | VARCHAR(50) | VOS 话单 ID (p[41]) |
| node_id | INT DEFAULT 0 | 关联节点 ID（当前数据全 0，待修） |
| node_ip | VARCHAR(50) | 推送节点 IP（UDP 源地址） |
| raw_data | TEXT NOT NULL | 原始话单文本（归档） |
| call_id | VARCHAR(100) | 呼叫 ID（SIP Call-ID） |
| caller / callee | VARCHAR(100) | 主叫 / 被叫（p[8]/p[14]） |
| caller_out / callee_out | VARCHAR(100) | 呼出主叫 / 被叫（p[0]/p[2]） |
| caller_in / callee_in | VARCHAR(100) | 呼入主叫 / 被叫（p[1]/p[3]） |
| caller_ip / callee_ip | VARCHAR(50) | 主叫 / 被叫 IP（p[4]/p[10]） |
| start_time / end_time | VARCHAR(50) | 开始 / 结束时间 |
| duration | INT | 通话时长（秒，p[23]） |
| continuous_duration | DECIMAL(10,3) | 持续时长（秒，p[21]/1000） |
| bill_duration | INT | 计费时长（秒，p[25]） |
| fee_rate | DECIMAL(12,4) | 费率 / 单价（p[26]） |
| fee / cost | DECIMAL(12,4) | 通话费用 / 落地成本 |
| direction | VARCHAR(20) | 呼叫方向（inbound/outbound） |
| disconnect_cause | VARCHAR(20) | 挂断原因（p[48]） |
| gateway_in / gateway_out | VARCHAR(100) | 对接 / 落地网关（p[6]/p[12]） |
| account | VARCHAR(100) | 普通账户（p[32]） |
| fee_rate_group | VARCHAR(100) | 费率组（p[33]） |
| received_at | TIMESTAMP | 接收时间 |
| 索引 | — | idx_cdr_id, idx_caller, idx_callee, idx_caller_out/in, idx_callee_out/in, idx_start_time, idx_node_id, idx_node_ip, **idx_received_at**（分页排序关键）, idx_account, idx_gateway_in/out |
| 唯一键 | — | uk_call (call_id, node_id, cdr_id) |

### 2) 计数器表 `y_cdr_counter`（getCounterDDL）
| 字段 | 类型 | 说明 |
|------|------|------|
| date | DATE NOT NULL | 日期（YYYY-MM-DD） |
| node_id | INT NOT NULL DEFAULT 0 | 节点 ID |
| total | BIGINT NOT NULL DEFAULT 0 | 当日该节点话单总数 |
| 主键 | — | PK(date, node_id) |

### 3) 按小时汇总表 `y_cdr_hourly`（getHourlyDDL，趋势/KPI 数据源）
| 字段 | 类型 | 说明 |
|------|------|------|
| date / hour / node_id / direction | DATE / TINYINT / INT / VARCHAR(20) | 维度 |
| calls / answered | INT UNSIGNED | 呼叫总数 / 接通数 |
| total_duration / bill_duration | BIGINT | 时长合计（秒） |
| fee / cost | DECIMAL(14,4) | 费用 / 成本合计 |
| b1~b5 | INT UNSIGNED | ACD 时长分桶（0-10s / 10-30s / 30-60s / 1-3min / 3min+） |
| 主键 | — | PK(date, hour, node_id, direction) |

### 4) 按维度汇总表 `y_cdr_daily_dim`（getDailyDimDDL，TOP-N 数据源）
| 字段 | 类型 | 说明 |
|------|------|------|
| date / node_id | DATE / INT | 维度 |
| dim | VARCHAR(20) | 维度：gateway_in / gateway_out / account / caller_prefix / disconnect_cause |
| dim_value | VARCHAR(100) | 维度值 |
| calls / answered | INT UNSIGNED | 呼叫总数 / 接通数 |
| total_duration | BIGINT | 时长合计（秒） |
| fee / cost | DECIMAL(14,4) | 费用 / 成本合计 |
| 主键 | — | PK(date, node_id, dim, dim_value) |

---

## 四、打包时**已排除**的项（安全 / 体积）
- `node_modules/`（cdr-receiver 的 npm 依赖，体积大且可从 package.json 重装）
- `server/.env`（数据库密码等密钥）
- `server/cdr-receiver/pm2-dev.json`（含明文密码 / 本地路径）
- `server/logs/`（运行日志）
- 其他与 CDR 无关的模块（节点管理、客户、费率、支付、鉴权等其他 API 与前端页面）

> 部署时：`cdr-receiver` 需先 `npm install --production` 安装依赖；`server/.env` 与 `pm2-dev.json` 需按 `docs/线上部署指南.md` 自行填写。
