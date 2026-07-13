/**
 * CDR 按天分表共用 schema
 *
 * 设计：
 *   - 每张表结构完全一致（统一 DDL），表名形如 y_cdr_YYYYMMDD
 *   - 仅保留 raw_data (TEXT) 作归档原文，删除 extra_fields JSON 冗余
 *   - 查询/写入均通过日期解析出表名，范围查询对多张日表 UNION
 */

const TABLE_PREFIX = 'y_cdr'

function pad2(n) {
  return String(n).padStart(2, '0')
}

/**
 * 根据日期取表名 y_cdr_YYYYMMDD
 * @param {Date|string|number} date Date 对象 / 'YYYY-MM-DD' 或 'YYYY-MM-DD HH:mm:ss' 字符串 / 时间戳(ms)
 */
function getCdrTableName(date) {
  let d
  if (date instanceof Date) {
    d = date
  } else if (typeof date === 'string') {
    d = new Date(date.replace(/-/g, '/'))
  } else if (typeof date === 'number') {
    d = new Date(date)
  } else {
    d = new Date()
  }
  if (isNaN(d.getTime())) d = new Date()
  return `${TABLE_PREFIX}_${d.getFullYear()}${pad2(d.getMonth() + 1)}${pad2(d.getDate())}`
}

/**
 * 生成指定表的建表 DDL（不含 extra_fields，仅保留 raw_data 归档）
 */
function getCdrDDL(tableName) {
  return `
    CREATE TABLE IF NOT EXISTS \`${tableName}\` (
      id            BIGINT AUTO_INCREMENT PRIMARY KEY,
      cdr_id        VARCHAR(50)  DEFAULT ''  COMMENT 'VOS话单ID(p[41])',
      node_id       INT          DEFAULT 0   COMMENT '关联节点ID(匹配y_nodes)',
      node_ip       VARCHAR(50)  DEFAULT ''  COMMENT '推送节点IP(UDP源地址)',
      raw_data      TEXT         NOT NULL     COMMENT '原始话单文本(归档用)',
      call_id       VARCHAR(100) DEFAULT ''  COMMENT '呼叫ID(SIP Call-ID)',
      caller        VARCHAR(100) DEFAULT ''  COMMENT '主叫号码(p[8])',
      callee        VARCHAR(100) DEFAULT ''  COMMENT '被叫号码(p[14])',
      caller_out    VARCHAR(100) DEFAULT ''  COMMENT '呼出主叫(p[0])',
      callee_out    VARCHAR(100) DEFAULT ''  COMMENT '呼出被叫(p[2])',
      caller_in     VARCHAR(100) DEFAULT ''  COMMENT '呼入主叫(p[1])',
      callee_in     VARCHAR(100) DEFAULT ''  COMMENT '呼入被叫(p[3])',
      caller_ip     VARCHAR(50)  DEFAULT ''  COMMENT '主叫IP(p[4])',
      callee_ip     VARCHAR(50)  DEFAULT ''  COMMENT '被叫IP/本地IP(p[10])',
      start_time    VARCHAR(50)  DEFAULT ''  COMMENT '开始时间(YYYY-MM-DD HH:mm:ss)',
      end_time      VARCHAR(50)  DEFAULT ''  COMMENT '结束时间',
      duration      INT          DEFAULT 0   COMMENT '通话时长(秒)(p[23])',
      continuous_duration DECIMAL(10,3) DEFAULT 0.000 COMMENT '持续时长(秒)(p[21]/1000)',
      bill_duration INT          DEFAULT 0   COMMENT '计费时长(秒)(p[25])',
      fee_rate      DECIMAL(12,4) DEFAULT 0.0000 COMMENT '费率/单价(p[26])',
      fee           DECIMAL(12,4) DEFAULT 0.0000 COMMENT '通话费用(p[27-31]取非0)',
      cost          DECIMAL(12,4) DEFAULT 0.0000 COMMENT '落地成本(通过gateway_out关联结算账户费率计算)',
      direction     VARCHAR(20)  DEFAULT ''  COMMENT '呼叫方向(inbound/outbound)',
      disconnect_cause VARCHAR(20) DEFAULT '' COMMENT '挂断原因(p[48])',
      gateway_in    VARCHAR(100) DEFAULT ''  COMMENT '对接网关(p[6])',
      gateway_out   VARCHAR(100) DEFAULT ''  COMMENT '落地网关(p[12])',
      account       VARCHAR(100) DEFAULT ''  COMMENT '普通账户(p[32])',
      fee_rate_group VARCHAR(100) DEFAULT ''  COMMENT '费率组(p[33])',
      received_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP COMMENT '接收时间',
      INDEX idx_cdr_id (cdr_id),
      INDEX idx_caller (caller),
      INDEX idx_callee (callee),
      INDEX idx_caller_out (caller_out),
      INDEX idx_callee_out (callee_out),
      INDEX idx_caller_in (caller_in),
      INDEX idx_callee_in (callee_in),
      INDEX idx_start_time (start_time),
      INDEX idx_node_id (node_id),
      INDEX idx_node_ip (node_ip),
      INDEX idx_received_at (received_at),
      INDEX idx_account (account),
      INDEX idx_gateway_in (gateway_in),
      INDEX idx_gateway_out (gateway_out),
      UNIQUE KEY uk_call (call_id, node_id, cdr_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='通话记录(CDR) 按天分表'
  `
}

/**
 * 生成 CDR 预聚合计数器表 DDL（按天+节点维护当日总数，供亿级查询秒级返回总数）
 * 与日表分离，单表小（每天每节点一行），读一行即得精确总数，避免 COUNT(*) 扫亿级日表
 */
function getCounterDDL(tableName = 'y_cdr_counter') {
  return `
    CREATE TABLE IF NOT EXISTS \`${tableName}\` (
      date     DATE    NOT NULL COMMENT '日期(YYYY-MM-DD)',
      node_id  INT     NOT NULL DEFAULT 0 COMMENT '节点ID',
      total    BIGINT  NOT NULL DEFAULT 0 COMMENT '当日该节点话单总数',
      PRIMARY KEY (date, node_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CDR按天按节点预聚合计数器'
  `
}

/**
 * 取 [fromDay, toDay] 闭区间内的所有日表名
 * @param {Date|string} fromDay 'YYYY-MM-DD' 或 Date
 * @param {Date|string} toDay   'YYYY-MM-DD' 或 Date
 */
function getDateRangeTables(fromDay, toDay) {
  const tables = []
  const d = new Date(fromDay instanceof Date ? fromDay : new Date(String(fromDay).replace(/-/g, '/')))
  const end = new Date(toDay instanceof Date ? toDay : new Date(String(toDay).replace(/-/g, '/')))
  if (isNaN(d.getTime())) return tables
  if (isNaN(end.getTime())) end.setTime(d.getTime())
  while (d <= end) {
    tables.push(getCdrTableName(d))
    d.setDate(d.getDate() + 1)
  }
  return tables
}

/**
 * 生成 CDR 按小时预聚合表 DDL（时间趋势/KPI 数据源）
 * 每天每节点每方向 24 行，亿级日表查询改为读此小表（每天每节点≤48行）
 * 含 ACD 时长分桶(b1-b5)，供通话时长分布直接求和
 */
function getHourlyDDL(tableName = 'y_cdr_hourly') {
  return `
    CREATE TABLE IF NOT EXISTS \`${tableName}\` (
      date            DATE    NOT NULL COMMENT '日期(YYYY-MM-DD)',
      hour            TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '小时(0-23)',
      node_id         INT     NOT NULL DEFAULT 0 COMMENT '节点ID',
      direction       VARCHAR(20) NOT NULL DEFAULT '' COMMENT '呼叫方向(inbound/outbound)',
      calls           INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT '呼叫总数',
      answered        INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT '接通数(duration>0)',
      total_duration  BIGINT  NOT NULL DEFAULT 0 COMMENT '通话时长合计(秒)',
      bill_duration   BIGINT  NOT NULL DEFAULT 0 COMMENT '计费时长合计(秒)',
      fee             DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT '费用合计',
      cost            DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT '成本合计',
      b1              INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ACD分布 0-10秒',
      b2              INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ACD分布 10-30秒',
      b3              INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ACD分布 30-60秒',
      b4              INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ACD分布 1-3分钟',
      b5              INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ACD分布 3分钟+',
      PRIMARY KEY (date, hour, node_id, direction),
      INDEX idx_date_node (date, node_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CDR按小时预聚合(趋势/KPI)'
  `
}

/**
 * 生成 CDR 按维度预聚合表 DDL（网关/账户/号码段/挂断原因 TOP-N 数据源）
 * 每天每节点每维度每值一行，亿级下 TOP-N 改为读此小表，避免 GROUP BY 扫亿级日表
 */
function getDailyDimDDL(tableName = 'y_cdr_daily_dim') {
  return `
    CREATE TABLE IF NOT EXISTS \`${tableName}\` (
      date            DATE    NOT NULL COMMENT '日期(YYYY-MM-DD)',
      node_id         INT     NOT NULL DEFAULT 0 COMMENT '节点ID',
      dim             VARCHAR(20) NOT NULL DEFAULT '' COMMENT '维度: gateway_in/gateway_out/account/caller_prefix/disconnect_cause',
      dim_value       VARCHAR(100) NOT NULL DEFAULT '' COMMENT '维度值',
      calls           INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT '呼叫总数',
      answered        INT     UNSIGNED NOT NULL DEFAULT 0 COMMENT '接通数',
      total_duration  BIGINT  NOT NULL DEFAULT 0 COMMENT '通话时长合计(秒)',
      fee             DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT '费用合计',
      cost            DECIMAL(14,4) NOT NULL DEFAULT 0 COMMENT '成本合计',
      PRIMARY KEY (date, node_id, dim, dim_value),
      INDEX idx_date_dim (date, dim)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='CDR按维度预聚合(TOP-N)'
  `
}

module.exports = { TABLE_PREFIX, getCdrTableName, getCdrDDL, getCounterDDL, getHourlyDDL, getDailyDimDDL, getDateRangeTables }
