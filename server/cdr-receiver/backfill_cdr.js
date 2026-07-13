/**
 * 历史话单回填脚本（按天分表版）
 *
 * 从各 y_cdr_YYYYMMDD 表的 raw_data 重新解析呼入/呼出主被叫四个字段并写回。
 * 仅回填这 4 个字段，不影响其他数据。
 *
 * 用法：node backfill_cdr.js
 */
const mysql = require('mysql2/promise')
const { parse } = require('csv-parse/sync')
const { TABLE_PREFIX } = require('./cdr-schema')

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

async function listDailyTables(pool) {
  const [rows] = await pool.query(
    "SHOW TABLES LIKE ?",
    [`${TABLE_PREFIX}\\_%`]
  )
  // 结果列名形如 Tables_in_yump (y_cdr_...)
  return rows.map(r => Object.values(r)[0]).filter(Boolean)
}

async function ensureColumns(pool, table) {
  const cols = [
    ['caller_out', 'VARCHAR(100) DEFAULT \'\' COMMENT "呼出主叫(p[0])"'],
    ['callee_out', 'VARCHAR(100) DEFAULT \'\' COMMENT "呼出被叫(p[2])"'],
    ['caller_in', 'VARCHAR(100) DEFAULT \'\' COMMENT "呼入主叫(p[1])"'],
    ['callee_in', 'VARCHAR(100) DEFAULT \'\' COMMENT "呼入被叫(p[3])"'],
  ]
  for (const [col, def] of cols) {
    try {
      await pool.execute(`ALTER TABLE \`${table}\` ADD COLUMN ${col} ${def}`)
      console.log(`[回填] ${table} 已添加 ${col} 字段`)
    } catch (err) {
      if (err.code !== 'ER_DUP_FIELDNAME') console.log(`[回填] ${table} ${col} 检查:`, err.message)
    }
  }
}

async function main() {
  const pool = mysql.createPool(DB_CONFIG)

  const tables = await listDailyTables(pool)
  if (!tables.length) {
    console.log('[回填] 未发现任何 y_cdr_YYYYMMDD 表，结束')
    await pool.end()
    return
  }
  console.log(`[回填] 发现日表: ${tables.join(', ')}`)

  for (const table of tables) {
    await ensureColumns(pool, table)
    const [rows] = await pool.execute(
      `SELECT id, raw_data FROM \`${table}\` WHERE raw_data IS NOT NULL AND raw_data != ""`
    )
    console.log(`[回填] ${table} 待处理话单: ${rows.length} 条`)
    let updated = 0
    for (const r of rows) {
      let p = []
      try {
        const parsed = parse(r.raw_data, { delimiter: ',', quote: '"', escape: '"', skip_empty_lines: false, trim: false })
        p = parsed[0] || []
      } catch (e) {
        console.log(`[回填] ${table} id=${r.id} 解析失败: ${e.message}`)
        continue
      }
      const caller_out = (p[0] || '').trim()
      const callee_out = (p[2] || '').trim()
      const caller_in = (p[1] || '').trim()
      const callee_in = (p[3] || '').trim()
      await pool.execute(
        `UPDATE \`${table}\` SET caller_out=?, callee_out=?, caller_in=?, callee_in=? WHERE id=?`,
        [caller_out, callee_out, caller_in, callee_in, r.id]
      )
      updated++
    }
    console.log(`[回填] ${table} 完成，已更新 ${updated} 条`)
  }

  await pool.end()
}

main().catch(e => { console.error(e); process.exit(1) })
