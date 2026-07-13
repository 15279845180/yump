<template>
  <div class="dashboard">
    <!-- 欢迎区 -->
    <div class="hero-section">
      <div class="hero-content">
        <h1 class="hero-title">VOS3000 多节点管理平台</h1>
        <p class="hero-desc">统一管理多个 VOS3000 节点，实时监控运行状态，实现跨环境高效运维</p>
      </div>
    </div>

    <!-- 统计卡片 -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon stat-icon--total">
          <el-icon size="20"><Connection /></el-icon>
        </div>
        <div class="stat-info">
          <span class="stat-value">{{ nodeStore.nodes.length }}</span>
          <span class="stat-label">节点总数</span>
        </div>
      </div>

      <div class="stat-card stat-card--success">
        <div class="stat-icon stat-icon--online">
          <el-icon size="20"><CircleCheckFilled /></el-icon>
        </div>
        <div class="stat-info">
          <span class="stat-value">{{ onlineCount }}</span>
          <span class="stat-label">在线节点</span>
        </div>
        <div class="stat-badge stat-badge--success">
          {{ nodeStore.nodes.length > 0 ? Math.round(onlineCount / nodeStore.nodes.length * 100) : 0 }}%
        </div>
      </div>

      <div class="stat-card stat-card--danger">
        <div class="stat-icon stat-icon--disabled">
          <el-icon size="20"><SwitchFilled /></el-icon>
        </div>
        <div class="stat-info">
          <span class="stat-value">{{ disabledCount }}</span>
          <span class="stat-label">停用节点</span>
        </div>
      </div>

      <div class="stat-card stat-card--warn">
        <div class="stat-icon stat-icon--offline">
          <el-icon size="20"><WarningFilled /></el-icon>
        </div>
        <div class="stat-info">
          <span class="stat-value">{{ offlineCount }}</span>
          <span class="stat-label">离线节点</span>
        </div>
      </div>
    </div>

    <!-- 并发监控 -->
    <div class="concurrent-section" v-if="activeNodes.length > 0">
      <div class="section-header">
        <div class="section-title-group">
          <span class="section-icon">&#9672;</span>
          <h3>实时并发监控</h3>
        </div>
        <span class="section-total">
          总并发 <strong>{{ totalConcurrent }}</strong>
        </span>
      </div>
      <div class="concurrent-grid">
        <div
          v-for="node in concurrentData"
          :key="node.id"
          class="concurrent-card"
          :class="{ 'concurrent-card--offline': node.callSize === null }"
        >
          <div class="concurrent-left">
            <span
              class="concurrent-indicator"
              :class="node.callSize === null ? 'indicator-offline' : 'indicator-online'"
            ></span>
            <span class="concurrent-name" :title="node.name">{{ node.name }}</span>
          </div>
          <div class="concurrent-right">
            <span class="concurrent-num" :class="{ 'num-offline': node.callSize === null }">
              {{ node.callSize !== null ? node.callSize : '-' }}
            </span>
            <span class="concurrent-unit">路</span>
          </div>
          <div v-if="node.cdrQueueSize > 0" class="concurrent-queue-hint">
            队列 {{ node.cdrQueueSize }}
          </div>
        </div>
      </div>
    </div>

    <!-- 空节点提示 -->
    <el-alert
      v-if="nodesLoaded && nodeStore.nodes.length === 0"
      title="还没有接入任何节点"
      description="前往「系统管理 → 节点管理」添加你的第一个 VOS3000 节点，开始统一管理"
      type="info"
      show-icon
      :closable="false"
    />
  </div>
</template>

<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { useNodeStore } from '@/stores/node'
import { nodeApi } from '@/api/node'

const nodeStore = useNodeStore()
const nodesLoaded = ref(false)

// 在线节点：启用 且 API 检测在线
const onlineCount = computed(() =>
  nodeStore.nodes.filter((n) => n.status === 1 && n.online_status === 1).length
)
// 停用节点：手动停用
const disabledCount = computed(() =>
  nodeStore.nodes.filter((n) => n.status === 0).length
)
// 离线节点：启用 但 API 检测离线
const offlineCount = computed(() =>
  nodeStore.nodes.filter((n) => n.status === 1 && n.online_status === 2).length
)

// ===== 并发监控 =====
const activeNodes = computed(() =>
  nodeStore.nodes.filter((n) => n.status === 1)
)

const concurrentData = ref([])
const totalConcurrent = computed(() =>
  concurrentData.value.reduce((sum, n) => sum + (n.callSize || 0), 0)
)

let concurrentTimer = null

async function loadConcurrent() {
  if (activeNodes.value.length === 0) {
    concurrentData.value = []
    return
  }
  const results = await Promise.allSettled(
    activeNodes.value.map(async (node) => {
      try {
        const res = await nodeApi.performance(node.id)
        return {
          id: node.id,
          name: node.name,
          callSize: res.data?.callSize ?? 0,
          cdrQueueSize: res.data?.cdrQueueSize ?? 0,
        }
      } catch {
        return { id: node.id, name: node.name, callSize: null, cdrQueueSize: 0 }
      }
    })
  )
  concurrentData.value = results.map((r) => r.value)
}

let refreshTimer = null

onMounted(async () => {
  try {
    await nodeStore.loadNodes()
  } catch {
    // 网络错误，响应拦截器已弹提示，不阻塞后续初始化
  } finally {
    nodesLoaded.value = true
    loadConcurrent()
    refreshTimer = setInterval(() => {
      nodeStore.loadNodes().catch(() => {})
    }, 30000)
    concurrentTimer = setInterval(loadConcurrent, 15000)
  }
})

onUnmounted(() => {
  if (refreshTimer) clearInterval(refreshTimer)
  if (concurrentTimer) clearInterval(concurrentTimer)
})
</script>

<style scoped>
/* ============================================
   Dashboard — 现代轻质感仪表盘
   视觉层次: Hero → 统计(重) → 监控(中) → 操作(轻)
   设计语言: 投影分层 · 微渐变底色 · 克制动效
   ============================================ */

.dashboard {
  max-width: 920px;
}

/* ===== Hero 欢迎区 ===== */
.hero-section {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 28px 28px 24px;
  background: linear-gradient(135deg, var(--color-primary-light) 0%, #fff 60%);
  border-radius: 14px;
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
}

/* 背景纹理 — 微妙的网格点阵 */
.hero-section::before {
  content: '';
  position: absolute;
  top: -40px;
  right: -30px;
  width: 180px;
  height: 180px;
  border-radius: 50%;
  background: radial-gradient(circle, var(--color-primary) 0%, transparent 70%);
  opacity: 0.04;
}

.hero-title {
  font-size: 22px;
  font-weight: 700;
  color: var(--color-text-primary);
  letter-spacing: -0.03em;
  line-height: 1.25;
  margin-bottom: 6px;
}

.hero-desc {
  font-size: 14px;
  color: var(--color-text-secondary);
  line-height: 1.55;
  max-width: 400px;
}

/* ===== 统计卡片 ===== */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 32px;
}

@media (max-width: 800px) {
  .stats-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

.stat-card {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 20px 20px 18px;
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border-light);
  border-radius: 12px;
  box-shadow: 0 1px 3px rgba(19, 17, 26, 0.04), 0 1px 2px rgba(19, 17, 26, 0.02);
  transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1),
              box-shadow 0.2s cubic-bezier(0.4, 0, 0.2, 1),
              border-color 0.15s ease;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(19, 17, 26, 0.08), 0 2px 4px rgba(19, 17, 26, 0.03);
  border-color: transparent;
}

/* 语义色卡片 — 微渐变底色 */
.stat-card--success {
  background: linear-gradient(135deg, #f6fdf9 0%, #edf8f3 100%);
  border-color: rgba(29, 158, 117, 0.12);
}
.stat-card--danger {
  background: linear-gradient(135deg, #fef7f6 0%, #fdeeeb 100%);
  border-color: rgba(216, 90, 80, 0.12);
}
.stat-card--warn {
  background: linear-gradient(135deg, #fefbf5 0%, #fdf6ed 100%);
  border-color: rgba(230, 138, 46, 0.12);
}

/* 图标容器 — 更大的圆角方块，图标居中浮出 */
.stat-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 11px;
  margin-bottom: 14px;
  transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.stat-card:hover .stat-icon {
  transform: scale(1.06);
}

.stat-icon--total    { background: var(--color-primary-light); color: var(--color-primary); }
.stat-icon--online   { background: rgba(29, 158, 117, 0.12); color: var(--color-success); }
.stat-icon--disabled { background: rgba(216, 90, 80, 0.1);  color: var(--color-danger); }
.stat-icon--offline  { background: rgba(230, 138, 46, 0.1);  color: var(--color-warning); }

/* 数字与标签 */
.stat-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.stat-value {
  font-size: 28px;
  font-weight: 750;
  color: var(--color-text-primary);
  line-height: 1.15;
  letter-spacing: -0.03em;
}

.stat-card--success .stat-value { color: var(--color-success); }
.stat-card--warn .stat-value    { color: var(--color-warning); }
.stat-card--danger .stat-value  { color: var(--color-danger); }

.stat-label {
  font-size: 13px;
  color: var(--color-text-secondary);
  font-weight: 500;
}

/* 百分比小标签 */
.stat-badge {
  position: absolute;
  top: 14px;
  right: 16px;
  font-size: 12px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 100px;
  background: rgba(29, 158, 117, 0.1);
  color: var(--color-success);
}

/* ===== Section Header — 统一的区块标题样式 ===== */
.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.section-title-group {
  display: flex;
  align-items: center;
  gap: 8px;
}

.section-icon {
  color: var(--color-primary);
  font-size: 10px;
  opacity: 0.5;
  line-height: 1;
}
.section-icon--grid {
  opacity: 0.35;
  font-size: 12px;
}

.section-header h3 {
  font-size: 15px;
  font-weight: 650;
  color: var(--color-text-primary);
  letter-spacing: -0.01em;
}

.section-total {
  font-size: 13px;
  color: var(--color-text-secondary);
}

.section-total strong {
  font-size: 18px;
  font-weight: 750;
  color: var(--color-primary);
  margin-left: 4px;
  letter-spacing: -0.02em;
}

/* ===== 并发监控 — 紧凑横向卡片 ===== */
.concurrent-section {
  margin-bottom: 32px;
}

.concurrent-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 12px;
}

.concurrent-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 18px;
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border-light);
  border-radius: 11px;
  box-shadow: 0 1px 2px rgba(19, 17, 26, 0.03);
  transition: all 0.18s cubic-bezier(0.4, 0, 0.2, 1);
  position: relative;
}

.concurrent-card:hover {
  border-color: var(--color-primary-light);
  box-shadow: 0 3px 10px rgba(91, 74, 191, 0.07), 0 1px 3px rgba(19, 17, 26, 0.04);
}

.concurrent-card--offline {
  opacity: 0.52;
}

/* 左侧：状态指示 + 节点名 */
.concurrent-left {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.concurrent-indicator {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
  transition: box-shadow 0.2s ease;
}

.indicator-online {
  background: var(--color-success);
  box-shadow: 0 0 0 3px rgba(29, 158, 117, 0.15);
}
.indicator-offline {
  background: var(--gray-300);
}

.concurrent-name {
  font-size: 13px;
  font-weight: 550;
  color: var(--color-text-primary);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 右侧：数值 + 单位 */
.concurrent-right {
  display: flex;
  align-items: baseline;
  gap: 2px;
  flex-shrink: 0;
}

.concurrent-num {
  font-size: 22px;
  font-weight: 750;
  color: var(--color-primary);
  line-height: 1.15;
  letter-spacing: -0.02em;
  tabular-nums: true;
}

.num-offline {
  color: var(--gray-300);
  font-weight: 600;
}

.concurrent-unit {
  font-size: 12px;
  color: var(--color-text-placeholder);
  font-weight: 500;
}

/* 话单队列提示 */
.concurrent-queue-hint {
  position: absolute;
  bottom: 6px;
  left: 18px;
  font-size: 11px;
  color: var(--color-warning);
  font-weight: 500;
}

</style>
