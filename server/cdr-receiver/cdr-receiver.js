/**
 * VOS3000 CDR（话单）UDP 接收服务 — 批量写入版
 *
 * VOS3000 配置推送地址为本服务的 UDP 端口
 * 话单格式为 CSV（逗号分隔，字段带双引号），基于真实 VOS 推送数据校准
 *
 * 启动方式：
 *   node cdr-receiver.js          # 默认端口 5140
 *   node cdr-receiver.js 8888     # 自定义端口
 *
 * 数据流：UDP接收 → CSV解析 → 入内存队列 → 每200ms/200条批量INSERT
 *
 * 性能特性：
 *   - 批量写入：多条话单合并为一条 INSERT ... VALUES (...),(...),... 语句
 *   - 节点IP缓存：node_ip → node_id 内存映射，避免每条话单都查DB
 *   - 成本费率缓存：gateway_out → cost_rate，5分钟TTL
 *   - 队列溢出保护：超过上限时丢弃最旧话单并告警
 *   - 日志降频：摘要统计 + 错误详情，不再逐条打印
 *   - 优雅降级：DB未就绪时UDP照常接收，话单暂存队列等DB恢复
 */

const dgram = require('dgram')
const mysql = require('mysql2/promise')
const { parse } = require('csv-parse/sync')
const { getCdrTableName, getCdrDDL, getCounterDDL, getHourlyDDL, getDailyDimDDL } = require('./cdr-schema')

// ===== 配置 =====
const CDR_PORT = parseInt(process.argv[2]) || 5140
// 密码优先从环境变量 YUMP_DB_PASSWORD 读取
const DB_CONFIG = {
  host: '127.0.0.1',
  port: 3306,
  user: 'yump',
  password: process.env.YUMP_DB_PASSWORD || '',
  database: 'yump',
  charset: 'utf8mb4',
}

if (!DB_CONFIG.password) {
  console.error('[FATAL] 未设置 YUMP_DB_PASSWORD 环境变量，无法连接数据库')
  console.error('  设置方式: export YUMP_DB_PASSWORD="你的密码" 或在 systemd/PM2 中配置')
  process.exit(1)
}

// ===== 批量写入配置 =====
const BATCH_SIZE = parseInt(process.env.CDR_BATCH_SIZE) || 200    // 攒满多少条触发写入
const BATCH_INTERVAL = parseInt(process.env.CDR_BATCH_INTERVAL) || 200  // 攒批最大等待(ms)
const QUEUE_MAX = parseInt(process.env.CDR_QUEUE_MAX) || 10000    // 队列上限，超过丢弃最旧
const POOL_SIZE = parseInt(process.env.CDR_POOL_SIZE) || 10       // 连接池大小

// ===== 统计计数器 =====
const stats = {
  received: 0,            // 接收总数
  written: 0,             // 写入成功数
  dropped: 0,             // 队列溢出丢弃数
  dropped_disabled: 0,    // 节点停用丢弃
  dropped_pull_mode: 0,   // 节点拉取模式丢弃（推送话单不应接收）
  dropped_unknown: 0,     // 未知IP（非 y_nodes 配置节点）丢弃
  errors: 0,              // 写入错误数
  lastFlushAt: 0,         // 上次flush时间
  queuePeak: 0,           // 队列峰值
}

// ===== MySQL 连接池 =====
let pool = null
let dbReady = false

async function initDB() {
  pool = mysql.createPool({
    ...DB_CONFIG,
    waitForConnections: true,
    connectionLimit: POOL_SIZE,
    queueLimit: 0,
  })

  const conn = await pool.getConnection()
  console.log('[DB] MySQL 连接成功')
  dbReady = true
  conn.release()

  // 按天分表：确保今日 + 明日表存在
  await ensureTable(new Date())
  await ensureTable(new Date(Date.now() + 86400000))
  // 预聚合计数器表（按天+节点维护当日总数）
  await ensureCounterTable()
  // 预聚合汇总表（按小时 / 按维度），供亿级报表秒级返回
  await ensureSummaryTables()
  console.log('[DB] y_cdr 按天分表就绪')
  console.log(`[CONFIG] 批量写入: 每${BATCH_INTERVAL}ms或${BATCH_SIZE}条触发, 队列上限${QUEUE_MAX}, 连接池${POOL_SIZE}`)
}

async function initDBWithRetry() {
  while (true) {
    try {
      await initDB()
      return
    } catch (err) {
      console.error(`[DB] 连接失败: ${err.message}，5 秒后重试...`)
      await new Promise(r => setTimeout(r, 5000))
    }
  }
}

async function ensureTable(day) {
  const tableName = getCdrTableName(day)
  await pool.execute(getCdrDDL(tableName))
  return tableName
}

async function ensureCounterTable() {
  await pool.execute(getCounterDDL('y_cdr_counter'))
  console.log('[DB] y_cdr_counter 计数器表就绪')
}

// 从日表名 y_cdr_YYYYMMDD 提取日期 YYYY-MM-DD
function tableNameToDate(tableName) {
  const m = String(tableName).match(/y_cdr_(\d{4})(\d{2})(\d{2})/)
  return m ? `${m[1]}-${m[2]}-${m[3]}` : new Date().toISOString().slice(0, 10)
}

// 批量写入成功后累加当日节点计数器（total += 真实新增条数）
async function updateCounter(date, nodeId, n) {
  if (!n) return
  try {
    await pool.execute(
      `INSERT INTO y_cdr_counter (date, node_id, total) VALUES (?, ?, ?)
       ON DUPLICATE KEY UPDATE total = total + VALUES(total)`,
      [date, nodeId, n]
    )
  } catch (err) {
    console.error('[COUNTER] 计数器更新失败:', err.message)
  }
}

// 预聚合汇总表（hourly + daily_dim）建表确保
async function ensureSummaryTables() {
  await pool.execute(getHourlyDDL('y_cdr_hourly'))
  await pool.execute(getDailyDimDDL('y_cdr_daily_dim'))
  console.log('[DB] y_cdr_hourly / y_cdr_daily_dim 汇总表就绪')
}

// ACD 时长分桶
function acdBucket(duration) {
  const d = parseInt(duration) || 0
  if (d <= 10) return 1
  if (d <= 30) return 2
  if (d <= 60) return 3
  if (d <= 180) return 4
  return 5
}

// 批量写入成功后增量维护预聚合汇总表（每话单仅计一次，与计数器同语义）
async function updateSummary(tableName, nodeId, cdrs) {
  if (!cdrs || cdrs.length === 0) return
  const date = tableNameToDate(tableName)

  // 内存聚合：hourly(按 date+hour+node+direction) 与 dim(按 date+node+维度+值)
  const hourly = new Map() // key: date|hour|node|direction
  const dim = new Map()    // key: date|node|dim|value
  const DIMS = ['gateway_in', 'gateway_out', 'account', 'disconnect_cause']

  for (const c of cdrs) {
    const hour = parseInt(String(c.start_time).slice(11, 13)) || 0
    const dir = c.direction || ''
    const answered = (parseInt(c.duration) || 0) > 0 ? 1 : 0
    const dur = parseInt(c.duration) || 0
    const bill = parseInt(c.bill_duration) || 0
    const fee = parseFloat(c.fee) || 0
    const cost = parseFloat(c.cost) || 0
    const bk = acdBucket(dur)

    // hourly
    const hk = `${date}|${hour}|${nodeId}|${dir}`
    if (!hourly.has(hk)) hourly.set(hk, { calls: 0, answered: 0, dur: 0, bill: 0, fee: 0, cost: 0, b: [0, 0, 0, 0, 0] })
    const h = hourly.get(hk)
    h.calls++; h.answered += answered; h.dur += dur; h.bill += bill; h.fee += fee; h.cost += cost; h.b[bk - 1]++

    // dim: gateway_in / gateway_out / account / disconnect_cause
    for (const d of DIMS) {
      const v = (c[d] || '').trim()
      if (!v) continue
      const dk = `${date}|${nodeId}|${d}|${v}`
      if (!dim.has(dk)) dim.set(dk, { calls: 0, answered: 0, dur: 0, fee: 0, cost: 0 })
      const r = dim.get(dk)
      r.calls++; r.answered += answered; r.dur += dur; r.fee += fee; r.cost += cost
    }
    // dim: 主叫号码段(前4位)
    const prefix = (c.caller || '').slice(0, 4)
    if (prefix) {
      const pk = `${date}|${nodeId}|caller_prefix|${prefix}`
      if (!dim.has(pk)) dim.set(pk, { calls: 0, answered: 0, dur: 0, fee: 0, cost: 0 })
      const pr = dim.get(pk)
      pr.calls++; pr.answered += answered; pr.dur += dur; pr.fee += fee; pr.cost += cost
    }
  }

  try {
    // hourly 增量 upsert（value 未存 hour/dir，由 key 携带，单独刷新）
    if (hourly.size > 0) await flushHourly(date, nodeId, hourly)

    // dim 增量 upsert
    if (dim.size > 0) {
      const dSql = `INSERT INTO y_cdr_daily_dim
        (date, node_id, dim, dim_value, calls, answered, total_duration, fee, cost)
        VALUES ${[...dim.entries()].map(() => '(?,?,?,?,?,?,?,?,?)').join(',')}
        ON DUPLICATE KEY UPDATE
          calls = calls + VALUES(calls), answered = answered + VALUES(answered),
          total_duration = total_duration + VALUES(total_duration), fee = fee + VALUES(fee), cost = cost + VALUES(cost)`
      const dParams = []
      for (const [k, v] of dim) {
        const parts = k.split('|')
        dParams.push(parts[0], parseInt(parts[1]), parts[2], parts[3], v.calls, v.answered, v.dur, v.fee, v.cost)
      }
      await pool.execute(dSql, dParams)
    }
  } catch (err) {
    console.error('[SUMMARY] 汇总表更新失败:', err.message)
  }
}

// hourly 单独刷新：value 携带 hour/dir
async function flushHourly(date, nodeId, hourly) {
  for (const [k, v] of hourly) {
    const parts = k.split('|') // date|hour|node|dir
    const hour = parseInt(parts[1])
    const dir = parts[3]
    await pool.execute(
      `INSERT INTO y_cdr_hourly
        (date, hour, node_id, direction, calls, answered, total_duration, bill_duration, fee, cost, b1, b2, b3, b4, b5)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
       ON DUPLICATE KEY UPDATE
        calls = calls + VALUES(calls), answered = answered + VALUES(answered),
        total_duration = total_duration + VALUES(total_duration), bill_duration = bill_duration + VALUES(bill_duration),
        fee = fee + VALUES(fee), cost = cost + VALUES(cost),
        b1 = b1 + VALUES(b1), b2 = b2 + VALUES(b2), b3 = b3 + VALUES(b3), b4 = b4 + VALUES(b4), b5 = b5 + VALUES(b5)`,
      [date, hour, nodeId, dir, v.calls, v.answered, v.dur, v.bill, v.fee, v.cost, v.b[0], v.b[1], v.b[2], v.b[3], v.b[4]]
    )
  }
}

// ===== 时间戳转换 =====
function msToDateTime(ms) {
  if (!ms || isNaN(ms)) return ''
  const d = new Date(parseInt(ms))
  if (isNaN(d.getTime())) return ''
  const pad = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

// ===== VOS3000 CDR 字段映射（基于 2026-07-07 用户确认校正） =====
function parseCDR(rawText) {
  const result = {
    raw_data: rawText,
    cdr_id: '',
    call_id: '',
    caller: '',
    callee: '',
    caller_out: '',
    callee_out: '',
    caller_in: '',
    callee_in: '',
    caller_ip: '',
    callee_ip: '',
    start_time: '',
    end_time: '',
    duration: 0,
    continuous_duration: 0,
    bill_duration: 0,
    fee_rate: 0,
    fee: 0,
    direction: '',
    disconnect_cause: '',
    gateway_in: '',
    gateway_out: '',
    account: '',
    fee_rate_group: '',
  }

  const rows = parse(rawText, {
    delimiter: ',',
    quote: '"',
    escape: '"',
    skip_empty_lines: true,
    trim: true,
  })

  if (!rows.length || !rows[0].length) return result

  const p = rows[0]

  const directionFlag = parseInt(p[15])
  result.direction = directionFlag === 1 ? 'inbound' : 'outbound'

  result.caller = (p[8] || '').trim()
  result.callee = (p[14] || '').trim()

  result.caller_out = (p[0] || '').trim()
  result.callee_out = (p[2] || '').trim()
  result.caller_in = (p[1] || '').trim()
  result.callee_in = (p[3] || '').trim()

  result.account = (p[32] || '').trim()

  result.caller_ip = (p[4] || '').trim()
  result.callee_ip = (p[10] || '').trim()

  result.gateway_in = (p[6] || '').trim()
  result.gateway_out = (p[12] || '').trim()

  const startMs = p[19] || ''
  const endMs = p[20] || ''
  result.start_time = msToDateTime(startMs)
  result.end_time = msToDateTime(endMs)

  result.duration = parseInt(p[23]) || 0
  result.continuous_duration = (parseFloat(p[21]) || 0) / 1000
  result.bill_duration = parseInt(p[25]) || 0

  result.fee_rate = parseFloat(p[26]) || 0

  result.fee = parseFloat(p[27]) || parseFloat(p[28]) || parseFloat(p[29]) ||
               parseFloat(p[30]) || parseFloat(p[31]) || parseFloat(p[26]) || 0

  result.disconnect_cause = (p[48] || '').trim()
  result.fee_rate_group = (p[33] || '').trim()
  result.cdr_id = (p[41] || '').trim()

  let callId = (p[45] || '').trim() || (p[46] || '').trim() || (p[44] || '').trim() || result.cdr_id
  if (!callId) {
    callId = `GEN_${result.start_time}_${result.caller}_${result.callee}_${result.direction}`
  }
  result.call_id = callId

  return result
}

// ===== 节点IP → node_id 内存缓存 =====
// 缓存值: 正整数=有效节点, 0=未匹配, -1=节点已停用, -2=节点为拉取模式
const nodeIpCache = new Map()  // ip → { id, updatedAt }
const NODE_CACHE_TTL = 60 * 1000  // 60秒过期

async function matchNodeId(nodeIp) {
  if (!nodeIp) return 0
  // 查缓存
  const cached = nodeIpCache.get(nodeIp)
  if (cached && Date.now() - cached.updatedAt < NODE_CACHE_TTL) {
    return cached.id
  }
  // 查DB（含 status + data_mode 判断）
  try {
    const [rows1] = await pool.execute(
      'SELECT id, status, IFNULL(data_mode, 0) AS data_mode FROM y_nodes WHERE ip_address = ? LIMIT 1',
      [nodeIp]
    )
    if (rows1.length) {
      const { id, status, data_mode } = rows1[0]
      let resultId
      if (status !== 1) resultId = -1        // 停用
      else if (data_mode === 0) resultId = -2 // 拉取模式，不接收推送
      else resultId = id                       // 正常推送节点
      nodeIpCache.set(nodeIp, { id: resultId, updatedAt: Date.now() })
      return resultId
    }
    const [rows2] = await pool.execute('SELECT id, status, IFNULL(data_mode, 0) AS data_mode, api_base_url FROM y_nodes')
    for (const row of rows2) {
      const urlMatch = row.api_base_url.match(/https?:\/\/([^:/]+)/)
      if (urlMatch && urlMatch[1] === nodeIp) {
        let resultId
        if (row.status !== 1) resultId = -1
        else if (row.data_mode === 0) resultId = -2
        else resultId = row.id
        nodeIpCache.set(nodeIp, { id: resultId, updatedAt: Date.now() })
        return resultId
      }
    }
    // 未匹配也缓存，避免重复查
    nodeIpCache.set(nodeIp, { id: 0, updatedAt: Date.now() })
    return 0
  } catch (err) {
    console.error('[DB] node_id匹配失败:', err.message)
    return 0
  }
}

// ===== 落地成本缓存：gateway_out → 折算费率 =====
const costCache = new Map()
const COST_CACHE_TTL = 5 * 60 * 1000

async function getCostRate(gatewayName, nodeId) {
  if (!gatewayName) return 0
  const cacheKey = nodeId + '|' + gatewayName
  const cached = costCache.get(cacheKey)
  if (cached && Date.now() - cached.updatedAt < COST_CACHE_TTL) {
    return cached.rate
  }
  try {
    const [rows] = await pool.execute(
      `SELECT IFNULL(c.money, 0) AS cost_rate
       FROM y_gateway_routings g
       LEFT JOIN y_customers c ON c.account = g.clearing_account
       WHERE g.node_id = ? AND g.name = ?
       LIMIT 1`,
      [nodeId, gatewayName]
    )
    const rate = rows.length ? parseFloat(rows[0].cost_rate) || 0 : 0
    costCache.set(cacheKey, { rate, updatedAt: Date.now() })
    return rate
  } catch (err) {
    console.error('[DB] 成本费率查询失败:', err.message)
    return 0
  }
}

function calcCost(billDuration, costRate) {
  if (!billDuration || !costRate) return 0
  return parseFloat(((billDuration / 60) * costRate).toFixed(4))
}

// ===== 内存队列 =====
const cdrQueue = []

/**
 * 将一条话单入队
 */
function enqueueCDR(cdr, rinfo) {
  cdr.node_ip = rinfo.address

  // 队列溢出保护：丢弃最旧的话单
  if (cdrQueue.length >= QUEUE_MAX) {
    cdrQueue.shift()
    stats.dropped++
    if (stats.dropped % 100 === 1) {
      console.warn(`[QUEUE] 队列已满(${QUEUE_MAX})，丢弃话单（累计${stats.dropped}条）`)
    }
  }

  cdrQueue.push(cdr)
  stats.received++

  if (cdrQueue.length > stats.queuePeak) {
    stats.queuePeak = cdrQueue.length
  }

  // 攒满立即触发
  if (cdrQueue.length >= BATCH_SIZE) {
    flushQueue()
  }
}

// 正在写入标记，防止并发flush
let flushing = false

/**
 * 批量写入队列中的话单
 * 按天分表分组，每组一条多VALUES的INSERT
 */
async function flushQueue() {
  if (flushing || cdrQueue.length === 0) return
  flushing = true

  // 取出当前队列快照
  const batch = cdrQueue.splice(0, cdrQueue.length)

  try {
    // 预处理：匹配node_id + 计算成本；停用/拉取模式节点话单直接丢弃
    const validCDRs = []
    for (const cdr of batch) {
      cdr.node_id = await matchNodeId(cdr.node_ip)
      // node_id <= 0 表示不接收推送 — -1 停用, -2 拉取模式, 0 未知IP(非 y_nodes 配置节点, 一律不收)
      if (cdr.node_id <= 0) {
        if (cdr.node_id === -2) stats.dropped_pull_mode++
        else if (cdr.node_id === -1) stats.dropped_disabled++
        else {
          stats.dropped_unknown++
          if (stats.dropped_unknown % 50 === 1) {
            console.warn(`[CDR] 丢弃未知来源话单（IP=${cdr.node_ip} 未配置为节点，累计${stats.dropped_unknown}条）`)
          }
        }
        continue
      }
      const costRate = await getCostRate(cdr.gateway_out, cdr.node_id)
      cdr.cost = calcCost(cdr.bill_duration, costRate)
      validCDRs.push(cdr)
    }

    if (validCDRs.length === 0) { flushing = false; return }

    // 按 (表名+节点) 分组：同一天同一节点的话单写入同一日表，便于精确累加计数器
    const groups = new Map()  // `${tableName}|${node_id}` → { tableName, nodeId, cdrs }
    for (const cdr of validCDRs) {
      const day = cdr.start_time ? cdr.start_time : new Date()
      const tableName = getCdrTableName(day)
      const key = `${tableName}|${cdr.node_id}`
      if (!groups.has(key)) {
        groups.set(key, { tableName, nodeId: cdr.node_id, cdrs: [] })
        // 懒建表兜底
        try {
          await ensureTable(day)
        } catch (e) {
          // CREATE IF NOT EXISTS 失败大概率表已存在，忽略
        }
      }
      groups.get(key).cdrs.push(cdr)
    }

    // 每组一条批量INSERT，累加当日节点计数器 + 维护预聚合汇总表
    for (const { tableName, nodeId, cdrs } of groups.values()) {
      const inserted = await batchInsert(tableName, cdrs)
      stats.written += inserted
      const date = tableNameToDate(tableName)
      await updateCounter(date, nodeId, inserted)
      // 汇总表按批次内话单增量累加（每话单仅计一次，与计数器同语义）
      await updateSummary(tableName, nodeId, cdrs)
    }

    stats.lastFlushAt = Date.now()
  } catch (err) {
    stats.errors += batch.length
    console.error(`[BATCH] 批量写入失败(${batch.length}条):`, err.message)
    // 失败的话单不重入队，避免无限重试撑爆内存
    // 如需持久化可后续引入落盘重试机制
  } finally {
    flushing = false
  }
}

/**
 * 批量INSERT：一条语句写入多条记录
 * 分批写入：MySQL 单语句占位符上限 65535，每行 27 列，故按 MAX_INSERT_ROWS 切片，
 * 避免高并发下整批因 "too many placeholders" 失败而静默丢话单。
 */
const MAX_INSERT_ROWS = 2000
async function batchInsert(tableName, cdrs) {
  if (!cdrs.length) return 0

  let totalAffected = 0
  for (let start = 0; start < cdrs.length; start += MAX_INSERT_ROWS) {
    const chunk = cdrs.slice(start, start + MAX_INSERT_ROWS)
    const placeholders = []
    const values = []

    for (const cdr of chunk) {
      placeholders.push('(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
      values.push(
        cdr.cdr_id, cdr.node_id, cdr.node_ip, cdr.raw_data, cdr.call_id, cdr.caller, cdr.callee,
        cdr.caller_out, cdr.callee_out, cdr.caller_in, cdr.callee_in,
        cdr.caller_ip, cdr.callee_ip, cdr.start_time, cdr.end_time,
        cdr.duration, cdr.continuous_duration, cdr.bill_duration,
        cdr.fee_rate, cdr.fee, cdr.cost, cdr.direction,
        cdr.disconnect_cause, cdr.gateway_in, cdr.gateway_out,
        cdr.account, cdr.fee_rate_group,
      )
    }

    // INSERT IGNORE：重复话单（uk_call 命中）跳过不更新，affectedRows 即真实新增数
    // 用 IGNORE 而非 ON DUPLICATE KEY UPDATE，是为了让计数器能拿到精确的"新增条数"
    const [result] = await pool.execute(
      `INSERT IGNORE INTO \`${tableName}\` (
        cdr_id, node_id, node_ip, raw_data, call_id, caller, callee,
        caller_out, callee_out, caller_in, callee_in,
        caller_ip, callee_ip,
        start_time, end_time, duration, continuous_duration, bill_duration,
        fee_rate, fee, cost, direction,
        disconnect_cause, gateway_in, gateway_out, account, fee_rate_group
      ) VALUES ${placeholders.join(', ')}
      ON DUPLICATE KEY UPDATE
        cdr_id = VALUES(cdr_id),
        node_id = VALUES(node_id),
        node_ip = VALUES(node_ip),
        raw_data = VALUES(raw_data),
        caller = VALUES(caller),
        callee = VALUES(callee),
        caller_out = VALUES(caller_out),
        callee_out = VALUES(callee_out),
        caller_in = VALUES(caller_in),
        callee_in = VALUES(callee_in),
        caller_ip = VALUES(caller_ip),
        callee_ip = VALUES(callee_ip),
        start_time = VALUES(start_time),
        end_time = VALUES(end_time),
        duration = VALUES(duration),
        continuous_duration = VALUES(continuous_duration),
        bill_duration = VALUES(bill_duration),
        fee_rate = VALUES(fee_rate),
        fee = VALUES(fee),
        cost = VALUES(cost),
        direction = VALUES(direction),
        disconnect_cause = VALUES(disconnect_cause),
        gateway_in = VALUES(gateway_in),
        gateway_out = VALUES(gateway_out),
        account = VALUES(account),
        fee_rate_group = VALUES(fee_rate_group),
        received_at = CURRENT_TIMESTAMP`,
      values
    )
    totalAffected += result.affectedRows
  }

  return totalAffected
}

// ===== 统计日志（每5秒输出一次摘要）=====
function startStatsLogger() {
  setInterval(() => {
    const qLen = cdrQueue.length
    if (stats.received === 0 && qLen === 0) return

    console.log(
      `[STATS] 接收=${stats.received} 写入=${stats.written} ` +
      `丢弃溢出=${stats.dropped} 丢弃停用=${stats.dropped_disabled} 丢弃拉取=${stats.dropped_pull_mode} 丢弃未知=${stats.dropped_unknown} 错误=${stats.errors} ` +
      `队列=${qLen}(峰值${stats.queuePeak})`
    )
  }, 5000)
}

// ===== UDP 服务器 =====
async function startServer() {
  // 后台异步连接数据库，不阻塞 UDP 服务启动
  initDBWithRetry().catch(err => {
    console.error('[DB] 数据库初始化意外终止:', err.message)
  })

  // 定时器：攒批写入
  setInterval(() => {
    flushQueue()
  }, BATCH_INTERVAL)

  // 定时器：统计日志
  startStatsLogger()

  // 按天分表：每小时确保今日+明日表存在
  const ensureUpcoming = async () => {
    if (!dbReady || !pool) return
    try {
      await ensureTable(new Date())
      await ensureTable(new Date(Date.now() + 86400000))
    } catch (e) {
      console.error('[DB] 预建日表失败:', e.message)
    }
  }
  setInterval(ensureUpcoming, 3600 * 1000)

  const server = dgram.createSocket('udp4')

  server.on('message', (msg, rinfo) => {
    const rawText = msg.toString().trim()
    if (!rawText) return

    // VOS 可能一个 UDP 包推多条话单（多行），逐行解析后入队
    const lines = rawText.split(/\r?\n/).filter(Boolean)

    for (const line of lines) {
      try {
        const cdr = parseCDR(line)
        enqueueCDR(cdr, rinfo)
      } catch (err) {
        stats.errors++
        console.error('[CDR] 解析失败:', err.message, '| raw:', line.substring(0, 200))
      }
    }
  })

  server.on('error', (err) => {
    console.error('[UDP] 服务器错误:', err)
    process.exit(1)
  })

  server.on('listening', () => {
    const address = server.address()
    console.log(`\n============================================`)
    console.log(`  VOS3000 CDR 接收服务已启动（批量写入版）`)
    console.log(`  监听: UDP ${address.address}:${address.port}`)
    console.log(`  批量: ${BATCH_SIZE}条/${BATCH_INTERVAL}ms  队列上限: ${QUEUE_MAX}`)
    console.log(`  连接池: ${POOL_SIZE}  统计间隔: 5s`)
    console.log(`============================================\n`)
  })

  server.bind(CDR_PORT)

  // 优雅退出： flush 剩余队列
  process.on('SIGTERM', async () => {
    console.log('[EXIT] 收到 SIGTERM，flush 剩余队列...')
    await flushQueue()
    console.log(`[EXIT] 最终统计: 接收=${stats.received} 写入=${stats.written} 丢弃溢出=${stats.dropped} 丢弃停用=${stats.dropped_disabled} 丢弃拉取=${stats.dropped_pull_mode} 丢弃未知=${stats.dropped_unknown} 错误=${stats.errors}`)
    process.exit(0)
  })
  process.on('SIGINT', async () => {
    console.log('[EXIT] 收到 SIGINT，flush 剩余队列...')
    await flushQueue()
    console.log(`[EXIT] 最终统计: 接收=${stats.received} 写入=${stats.written} 丢弃溢出=${stats.dropped} 丢弃停用=${stats.dropped_disabled} 丢弃拉取=${stats.dropped_pull_mode} 丢弃未知=${stats.dropped_unknown} 错误=${stats.errors}`)
    process.exit(0)
  })
}

// ===== 启动 =====
startServer().catch(err => {
  console.error('[启动失败]', err)
  process.exit(1)
})
