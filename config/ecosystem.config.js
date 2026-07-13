/**
 * PM2 配置文件
 * 
 * 存放位置：任意目录（建议 /etc/yump/）
 * 与 .env.node 放在同一目录下即可
 * 
 * 启动：pm2 start /你的路径/ecosystem.config.js
 */

const fs = require('fs');
const path = require('path');

// ============================================================
// 读取同目录下的 .env.node（敏感密码，不在站点目录内）
// ============================================================
const envFile = path.join(__dirname, '.env.node');
if (!fs.existsSync(envFile)) {
  console.error('[FATAL] 找不到 .env.node，请在同目录下创建该文件');
  process.exit(1);
}

const env = {};
fs.readFileSync(envFile, 'utf-8')
  .split('\n')
  .forEach(line => {
    const m = line.trim().match(/^([^#=\s]+)\s*=\s*(.*)$/);
    if (m) env[m[1]] = m[2];
  });

// 启动前校验
if (!env.YUMP_DB_PASSWORD) {
  console.error('[FATAL] .env.node 中未配置 YUMP_DB_PASSWORD');
  process.exit(1);
}
if (!env.YUMP_WS_SECRET) {
  console.error('[FATAL] .env.node 中未配置 YUMP_WS_SECRET');
  process.exit(1);
}

// ============================================================
// PM2 应用定义
// ============================================================
module.exports = {
  apps: [
    {
      name: 'vos-cdr-receiver',
      cwd: '/www/wwwroot/yump/server/cdr-receiver',
      script: 'cdr-receiver.js',
      env,
      // 日志（路径按需改）
      error_file: '/var/log/vos/cdr-receiver-error.log',
      out_file: '/var/log/vos/cdr-receiver-out.log',
      merge_logs: true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
    },
    {
      name: 'vos-ws-gateway',
      cwd: '/www/wwwroot/yump/server/ws-gateway',
      script: 'index.js',
      env,
      error_file: '/var/log/vos/ws-gateway-error.log',
      out_file: '/var/log/vos/ws-gateway-out.log',
      merge_logs: true,
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
    },
  ],
};
