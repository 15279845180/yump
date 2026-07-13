/**
 * 历史话单迁移脚本：旧单表 y_cdr → 按天分表 y_cdr_YYYYMMDD
 *
 * - 读取旧 y_cdr 全部记录
 * - 按 start_time（缺省 received_at）归到对应日表
 * - INSERT IGNORE 写入（call_id 唯一，避免重复）
 * - 迁移完成后将旧表改名为 y_cdr_archive 作为归档
 *
 * 用法：node migrate-cdr.js
 */
const mysql = require('mysql2/promise')
const { getCdrTableName, getCdrDDL } = require('./cdr-schema')

const DB_CONFIG = {
  host: '127.0.0.1', port: 3306, user: 'yump',
  password: process.env.YUMP_DB_PASSWORD || '',
  database: 'yump', charset: 'utf8mb4',
}

if (!DB_CONFIG.password) {
  console.error('[FATAL] 请设置 YUMP_DB_PASSWORD 环境变量')
  console.error('  export YUMP_DB_PASSWORD="实际密码"')
  process.exit(1)
}

// 与 cdr-schema DDL 一致的列（排除 id 自增，排除 extra_fields 冗余）
const COLS = [
  'cdr_id', 'node_id', 'node_ip', 'raw_data', 'call_id',
  'caller', 'callee', 'caller_out', 'callee_out', 'caller_in', 'callee_in',
  'caller_ip', 'callee_ip', 'start_time', 'end_time', 'duration',
  'continuous_duration', 'bill_duration', 'fee_rate', 'fee', 'direction',
  'disconnect_cause', 'gateway_in', 'gateway_out', 'account', 'fee_rate_group',
  'received_at',
]
const PLACEHOLDERS = COLS.map(() => '?').join(', ')

async function main() {
  const pool = mysql.createPool(DB_CONFIG)

  // 旧表是否还在
  const [exists] = await pool.query("SHOW TABLES LIKE 'y_cdr'")
  if (!exists.length) {
    console.log('[迁移] 未找到旧表 y_cdr，无需迁移')
    await pool.end()
    return
  }

  const [rows] = await pool.query(`SELECT ${COLS.join(', ')} FROM y_cdr`)
  console.log(`[迁移] 旧表 y_cdr 共 ${rows.length} 条`)

  const seenTables = new Set()
  let migrated = 0
  let skipped = 0

  for (const row of rows) {
    const daySrc = row.start_time || row.received_at
    const tableName = getCdrTableName(daySrc)
    if (!seenTables.has(tableName)) {
      await pool.execute(getCdrDDL(tableName))
      seenTables.add(tableName)
    }
    const params = COLS.map(c => row[c])
    try {
      await pool.execute(
        `INSERT IGNORE INTO \`${tableName}\` (${COLS.join(', ')}) VALUES (${PLACEHOLDERS})`,
        params
      )
      migrated++
    } catch (e) {
      console.log(`[迁移] ${tableName} 写入失败 cdr_id=${row.cdr_id}: ${e.message}`)
      skipped++
    }
  }

  console.log(`[迁移] 完成：写入 ${migrated} 条，失败 ${skipped} 条，涉及日表 ${seenTables.size} 张`)

  // 迁移完成后，旧表改名归档（数据仍在 y_cdr_archive，不丢失）
  try {
    await pool.execute('RENAME TABLE y_cdr TO y_cdr_archive')
    console.log('[迁移] 旧表已改名为 y_cdr_archive（归档保留）')
  } catch (e) {
    console.log('[迁移] 改名失败（可能已改名或存在冲突）:', e.message)
  }

  await pool.end()
}

main().catch(e => { console.error(e); process.exit(1) })
