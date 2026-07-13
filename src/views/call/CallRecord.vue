<template>
  <div class="page-container">
    <!-- 筛选区（两行布局） -->
    <el-card shadow="never" class="filter-card">
      <el-form :inline="true" :model="filters" @submit.prevent class="filter-form">
        <!-- 第一行：主叫号码、被叫号码、状态、时间范围 -->
        <div class="filter-row">
          <el-form-item label="主叫号码">
            <el-input v-model="filters.caller" clearable style="width:150px" @keyup.enter="doSearch" />
          </el-form-item>
          <el-form-item label="被叫号码">
            <el-input v-model="filters.callee" clearable style="width:150px" @keyup.enter="doSearch" />
          </el-form-item>
          <el-form-item label="状态">
          <el-select
            v-model="filters.disconnect_cause"
            placeholder="选择状态"
            clearable
            filterable
            style="width:180px"
          >
          <el-option
            v-for="item in causeOptions"
            :key="item.code"
            :label="item.label"
            :value="item.code"
          />
          </el-select>
        </el-form-item>
        <el-form-item label="时间范围">
          <el-date-picker
            v-model="dateRange"
            type="datetimerange"
            range-separator="至"
            start-placeholder="开始"
            end-placeholder="结束"
            format="YYYY-MM-DD HH:mm:ss"
            value-format="YYYY-MM-DD HH:mm:ss"
            :shortcuts="dateShortcuts"
            :disabled-date="disabledDate"
            @calendar-change="onCalendarChange"
            @visible-change="onVisibleChange"
            @change="handleDateChange"
            style="width:420px"
          />
        </el-form-item>
        </div>

        <!-- 第二行：呼入主叫、呼入被叫、普通账户、对接网关、落地网关 -->
        <div class="filter-row">
          <el-form-item label="呼入主叫">
            <el-input v-model="filters.caller_in" clearable style="width:150px" @keyup.enter="doSearch" />
          </el-form-item>
          <el-form-item label="呼入被叫">
            <el-input v-model="filters.callee_in" clearable style="width:150px" @keyup.enter="doSearch" />
          </el-form-item>
          <el-form-item label="普通账户">
            <el-select
            v-model="filters.account"
            placeholder="选择账户"
            clearable
            filterable
            style="width:160px"
            >
              <el-option v-for="a in accountOptions" :key="a" :label="a" :value="a" />
            </el-select>
          </el-form-item>
          <el-form-item label="对接网关">
            <el-select
            v-model="filters.gateway_in"
            placeholder="选择网关"
            clearable
            filterable
            style="width:160px"
            >
              <el-option v-for="g in gatewayInOptions" :key="g" :label="g" :value="g" />
            </el-select>
          </el-form-item>
          <el-form-item label="落地网关">
            <el-select
            v-model="filters.gateway_out"
            placeholder="选择网关"
            clearable
            filterable
            style="width:160px"
            >
              <el-option v-for="g in gatewayOutOptions" :key="g" :label="g" :value="g" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button class="btn-modern" @click="doSearch" :loading="tableLoading">
              <el-icon><Search /></el-icon>查询
            </el-button>
            <el-button class="btn-modern" @click="openExportDialog">
              <el-icon><Download /></el-icon>导出
            </el-button>
          </el-form-item>
        </div>
      </el-form>
    </el-card>

    <!-- 表格 -->
    <el-card shadow="never" class="table-card">
      <el-table
        :data="tableData"
        v-loading="tableLoading"
        stripe
        border
        style="width:100%"
        @sort-change="onSortChange"
      >
        <el-table-column prop="cdr_id" label="ID" width="80" sortable>
          <template #default="{ row }">
            <el-button type="primary" link class="id-link" @click="showDetail(row)">
              {{ row.cdr_id || '' }}
            </el-button>
          </template>
        </el-table-column>
        <el-table-column label="主叫号码" min-width="130">
          <template #default="{ row }">
            <span>{{ displayCaller(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="被叫号码" min-width="130">
          <template #default="{ row }">
            <span>{{ displayCallee(row) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="呼入主叫" min-width="130">
          <template #default="{ row }">
            <span v-if="row.caller_in" class="num-inbound">{{ row.caller_in }}</span>
          </template>
        </el-table-column>
        <el-table-column label="呼入被叫" min-width="130">
          <template #default="{ row }">
            <span v-if="row.callee_in" class="num-inbound">{{ row.callee_in }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="start_time" label="呼叫时间" width="160" sortable />
        <el-table-column prop="gateway_in" label="对接网关" min-width="130" />
        <el-table-column prop="gateway_out" label="落地网关" min-width="130" />
        <el-table-column prop="duration" label="通话时长" width="100" sortable>
          <template #default="{ row }">
            <span :class="(row.duration || 0) > 0 ? 'duration-value' : 'duration-zero'">
              {{ (row.duration || 0) + 's' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column prop="disconnect_cause" label="挂断原因" min-width="130">
          <template #default="{ row }">
            <span
              v-if="row.disconnect_cause !== '' && row.disconnect_cause !== null && row.disconnect_cause !== undefined"
              class="cause-tag"
              :class="isCallSuccess(row) ? 'cause-success' : 'cause-fail'"
              :title="'原始码: ' + row.disconnect_cause"
            >
              {{ isCallSuccess(row) ? '成功' : (row.disconnect_cause + ' ' + getDisconnectText(row.disconnect_cause)) }}
            </span>
          </template>
        </el-table-column>
      </el-table>

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
    </el-card>

    <!-- 话单详情弹窗 -->
    <el-dialog v-model="detailVisible" title="" width="960px" :close-on-click-modal="true" class="cdr-detail-dialog">
      <div v-if="currentRow.id" class="detail-body">
        <!-- 顶部标题栏 -->
        <div class="detail-header">
          <div class="detail-header-left">
            <span class="detail-title">话单详情</span>
            <span class="detail-id">#{{ currentRow.cdr_id || currentRow.id }}</span>
          </div>
          <span class="detail-time">{{ currentRow.start_time }}</span>
        </div>

        <!-- 摘要卡片 -->
        <div class="detail-summary">
          <div class="summary-flow">
            <div class="summary-party">
              <div class="summary-party-label">主叫号码</div>
              <div class="summary-party-value">{{ currentRow.caller || '' }}</div>
            </div>
            <div class="summary-arrow">→</div>
            <div class="summary-party">
              <div class="summary-party-label">被叫号码</div>
              <div class="summary-party-value">{{ currentRow.callee || '' }}</div>
            </div>
          </div>
          <div class="summary-status">
            <span
              class="summary-cause-tag"
              :class="isCallSuccess(currentRow) ? 'summary-success' : 'summary-fail'"
            >
              {{ isCallSuccess(currentRow) ? '成功' : ((currentRow.disconnect_cause || '') + ' ' + (getDisconnectText(currentRow.disconnect_cause) || '未知')) }}
            </span>
            <div class="summary-duration-block">
              <div class="summary-duration-label">通话时长</div>
              <div class="summary-duration-value">{{ currentRow.duration || 0 }}s</div>
            </div>
          </div>
        </div>

        <!-- 分组卡片 -->
        <div class="detail-cards">
          <!-- 账户信息 -->
          <div class="detail-card">
            <div class="detail-card-title">账户信息</div>
            <div class="detail-card-fields">
              <div class="detail-field" v-if="currentRow.node_name || currentRow.node_ip">
                <span class="detail-field-label">所属节点</span>
                <span class="detail-field-value">{{ currentRow.node_name || currentRow.node_ip }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.account || rawFieldMap[32]">
                <span class="detail-field-label">普通账户</span>
                <span class="detail-field-value">{{ currentRow.account || rawFieldMap[32] }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.settlement_account">
                <span class="detail-field-label">结算账户</span>
                <span class="detail-field-value">{{ currentRow.settlement_account }}</span>
              </div>
            </div>
          </div>

          <!-- 参与方 -->
          <div class="detail-card">
            <div class="detail-card-title">参与方</div>
            <div class="detail-card-fields">
              <div class="detail-field" v-if="currentRow.caller || rawFieldMap[8]">
                <span class="detail-field-label">主叫号码</span>
                <span class="detail-field-value">{{ currentRow.caller || rawFieldMap[8] }}</span>
              </div>
              <div class="detail-field" v-if="inboundCaller">
                <span class="detail-field-label">呼入主叫</span>
                <span class="detail-field-value">{{ inboundCaller }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.callee || rawFieldMap[14]">
                <span class="detail-field-label">被叫号码</span>
                <span class="detail-field-value">{{ currentRow.callee || rawFieldMap[14] }}</span>
              </div>
              <div class="detail-field" v-if="inboundCallee">
                <span class="detail-field-label">呼入被叫</span>
                <span class="detail-field-value">{{ inboundCallee }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.caller_ip || rawFieldMap[4]">
                <span class="detail-field-label">主叫 IP</span>
                <span class="detail-field-value val-mono">{{ currentRow.caller_ip || rawFieldMap[4] }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.callee_ip || rawFieldMap[10]">
                <span class="detail-field-label">被叫 IP</span>
                <span class="detail-field-value val-mono">{{ currentRow.callee_ip || rawFieldMap[10] }}</span>
              </div>
              <div class="detail-field" v-if="rawFieldMap[7]">
                <span class="detail-field-label">主叫设备</span>
                <span class="detail-field-value">{{ rawFieldMap[7] }}</span>
              </div>
              <div class="detail-field" v-if="rawFieldMap[13]">
                <span class="detail-field-label">被叫设备</span>
                <span class="detail-field-value">{{ rawFieldMap[13] }}</span>
              </div>
            </div>
          </div>

          <!-- 时间 -->
          <div class="detail-card">
            <div class="detail-card-title">时间</div>
            <div class="detail-card-fields">
              <div class="detail-field" v-if="currentRow.start_time">
                <span class="detail-field-label">起始时间</span>
                <span class="detail-field-value val-mono">{{ currentRow.start_time }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.end_time">
                <span class="detail-field-label">终止时间</span>
                <span class="detail-field-value val-mono">{{ currentRow.end_time }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.duration">
                <span class="detail-field-label">通话时长</span>
                <span class="detail-field-value">{{ currentRow.duration }} 秒</span>
              </div>
              <div class="detail-field" v-if="currentRow.continuous_duration">
                <span class="detail-field-label">持续时长</span>
                <span class="detail-field-value">{{ currentRow.continuous_duration }} 秒</span>
              </div>
              <div class="detail-field" v-if="rawFieldMap[22] && formatConnectDelay(rawFieldMap[22])">
                <span class="detail-field-label">接通延迟</span>
                <span class="detail-field-value" :class="{ 'val-danger': formatConnectDelay(rawFieldMap[22]) === '未接通' }">{{ formatConnectDelay(rawFieldMap[22]) }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.bill_duration">
                <span class="detail-field-label">计费时长</span>
                <span class="detail-field-value">{{ currentRow.bill_duration }} 秒</span>
              </div>
            </div>
          </div>

          <!-- 计费 -->
          <div class="detail-card">
            <div class="detail-card-title">计费</div>
            <div class="detail-card-fields">
              <div class="detail-field" v-if="currentRow.fee_rate || rawFieldMap[26]">
                <span class="detail-field-label">费率</span>
                <span class="detail-field-value">{{ currentRow.fee_rate || rawFieldMap[26] }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.fee">
                <span class="detail-field-label">通话费用</span>
                <span class="detail-field-value">{{ '¥' + parseFloat(currentRow.fee).toFixed(4) }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.fee_rate_group">
                <span class="detail-field-label">费率组</span>
                <span class="detail-field-value">{{ currentRow.fee_rate_group }}</span>
              </div>
            </div>
          </div>

          <!-- 网关 -->
          <div class="detail-card">
            <div class="detail-card-title">网关</div>
            <div class="detail-card-fields">
              <div class="detail-field" v-if="currentRow.gateway_in || rawFieldMap[6]">
                <span class="detail-field-label">对接网关</span>
                <span class="detail-field-value">{{ currentRow.gateway_in || rawFieldMap[6] }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.gateway_out || rawFieldMap[12]">
                <span class="detail-field-label">落地网关</span>
                <span class="detail-field-value">{{ currentRow.gateway_out || rawFieldMap[12] }}</span>
              </div>
            </div>
          </div>

          <!-- 媒体与挂断 -->
          <div class="detail-card">
            <div class="detail-card-title">媒体与挂断</div>
            <div class="detail-card-fields">
              <div class="detail-field" v-if="rawFieldMap[5] || rawFieldMap[11]">
                <span class="detail-field-label">语音编码</span>
                <span class="detail-field-value">{{ rawFieldMap[5] || rawFieldMap[11] }}</span>
              </div>
              <div class="detail-field" v-if="rawFieldMap[46]">
                <span class="detail-field-label">录音</span>
                <span class="detail-field-value">{{ formatRecording(rawFieldMap[46]) }}</span>
              </div>
              <div class="detail-field" v-if="rawFieldMap[47] || rawFieldMap[47] === '0' || rawFieldMap[47] === '1'">
                <span class="detail-field-label">挂断方</span>
                <span class="detail-field-value">{{ formatHangupParty(rawFieldMap[47]) }}</span>
              </div>
              <div class="detail-field" v-if="currentRow.disconnect_cause">
                <span class="detail-field-label">挂断原因</span>
                <span class="detail-field-value" :class="isCallSuccess(currentRow) ? 'val-success' : 'val-danger'">
                  {{ currentRow.disconnect_cause }} {{ getDisconnectText(currentRow.disconnect_cause) }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- 原始数据折叠区 -->
        <div class="detail-raw-section">
          <div class="raw-toggle" @click="showRawData = !showRawData">
            <el-icon class="toggle-icon" :class="{ 'expanded': showRawData }"><ArrowRight /></el-icon>
            <span>原始数据与字段对照</span>
          </div>
          <div v-show="showRawData" class="raw-content">
            <div class="raw-fields-grid">
              <div v-for="f in rawFields" :key="f.index" class="raw-field-item">
                <span class="raw-index">[{{ f.index }}]</span>
                <span class="raw-value" :title="f.value">{{ f.value || '' }}</span>
              </div>
            </div>
            <pre class="raw-text">{{ currentRow.raw_data }}</pre>
          </div>
        </div>
      </div>
    </el-dialog>

    <!-- 导出弹窗 -->
    <el-dialog v-model="exportVisible" title="导出通话记录" width="560px" :close-on-click-modal="false">
      <div class="export-dialog-body">
        <div class="export-tip">
          将导出当前筛选条件下的通话记录（最多 10 万条），请选择需要导出的字段：
        </div>
        <div class="export-actions">
          <el-button link type="primary" @click="selectAllFields">全选</el-button>
          <el-button link type="primary" @click="resetDefaultFields">恢复默认</el-button>
        </div>
        <div class="export-fields">
          <div class="field-group">
            <div class="group-title">常用字段</div>
            <el-checkbox-group v-model="exportSelected">
              <el-checkbox v-for="f in defaultExportFields" :key="f.key" :label="f.key">{{ f.label }}</el-checkbox>
            </el-checkbox-group>
          </div>
          <div class="field-group">
            <div class="group-title">其他字段</div>
            <el-checkbox-group v-model="exportSelected">
              <el-checkbox v-for="f in otherExportFields" :key="f.key" :label="f.key">{{ f.label }}</el-checkbox>
            </el-checkbox-group>
          </div>
        </div>
      </div>
      <template #footer>
        <el-button class="btn-modern" @click="exportVisible = false">取消</el-button>
        <el-button type="primary" class="btn-modern" @click="doExport" :loading="exporting">导出 CSV</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
defineOptions({ name: 'CallRecord' })
import { ref, reactive, computed, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { Search, ArrowRight, Download } from '@element-plus/icons-vue'
import { getCDRList, getCDROptions, exportCDR } from '@/api/cdr'
import { getDisconnectText, getDisconnectOptions } from '@/utils/disconnect-codes'
import { useMonthRange } from '@/composables/useMonthRange'

// ===== 下拉选项 =====
const causeOptions = ref(getDisconnectOptions())
const accountOptions = ref([])       // 普通账户列表
const gatewayInOptions = ref([])     // 对接网关列表
const gatewayOutOptions = ref([])    // 落地网关列表

async function loadOptions() {
  try {
    const res = await getCDROptions()
    if (res.success) {
      accountOptions.value = res.data.accounts || []
      gatewayInOptions.value = res.data.gateways_in || []
      gatewayOutOptions.value = res.data.gateways_out || []
    }
  } catch (e) {
    // 下拉选项加载失败不影响主功能
  }
}

// ===== 通话成功判断 =====
// 接通（有通话时长 duration > 0）= 成功绿色，只显示"成功"二字
// 未接通（duration <= 0 或无时长）= 红色，显示失败原因文字
function isCallSuccess(row) {
  if (!row) return false
  const duration = parseInt(row.duration)
  return duration > 0
}

// ===== 日期快捷选项 & 默认值 =====
function pad(n) {
  return String(n).padStart(2, '0')
}
function formatDateTime(date) {
  const y = date.getFullYear()
  const m = pad(date.getMonth() + 1)
  const d = pad(date.getDate())
  const h = pad(date.getHours())
  const min = pad(date.getMinutes())
  const s = pad(date.getSeconds())
  return `${y}-${m}-${d} ${h}:${min}:${s}`
}
function getDayStart(date = new Date()) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0)
}
function getDayEnd(date = new Date()) {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 23, 59, 59)
}
const dateShortcuts = [
  {
    text: '今天',
    value: () => [getDayStart(), getDayEnd()],
  },
  {
    text: '昨天',
    value: () => {
      const y = new Date()
      y.setDate(y.getDate() - 1)
      return [getDayStart(y), getDayEnd(y)]
    },
  },
  {
    text: '前天',
    value: () => {
      const y = new Date()
      y.setDate(y.getDate() - 2)
      return [getDayStart(y), getDayEnd(y)]
    },
  },
  {
    text: '近一周',
    value: () => {
      const end = new Date()
      const start = new Date()
      start.setDate(start.getDate() - 6)
      return [getDayStart(start), getDayEnd(end)]
    },
  },
  {
    text: '本月',
    value: () => {
      const now = new Date()
      const start = new Date(now.getFullYear(), now.getMonth(), 1, 0, 0, 0)
      return [start, getDayEnd(now)]
    },
  },
  {
    text: '上月',
    value: () => {
      const now = new Date()
      const start = new Date(now.getFullYear(), now.getMonth() - 1, 1, 0, 0, 0)
      const end = new Date(now.getFullYear(), now.getMonth(), 0, 23, 59, 59)
      return [start, end]
    },
  },
]
const dateRange = ref([
  formatDateTime(getDayStart()),
  formatDateTime(getDayEnd()),
])

// ===== 日期不跨月限制 =====
const { disabledDate, onCalendarChange, onVisibleChange, handleDateChange } = useMonthRange(dateRange)

// ===== 筛选 =====
const filters = reactive({
  caller: '',
  callee: '',
  caller_in: '',
  callee_in: '',
  disconnect_cause: '',
  account: '',
  gateway_in: '',
  gateway_out: '',
})

// ===== 表格（分页） =====
const tableData = ref([])
const tableLoading = ref(false)
const pagination = reactive({
  page: 1,
  pageSize: 10,
  total: 0,
  approx: false,      // 总数是否为估算（带筛选时 EXPLAIN 估算）
})

const currentSort = reactive({ prop: '', order: '' })

// 组装查询参数
function buildParams() {
  const params = { ...filters, page: pagination.page, pageSize: pagination.pageSize }
  if (dateRange.value && dateRange.value.length === 2) {
    params.start_time_from = dateRange.value[0]
    params.start_time_to = dateRange.value[1]
  }
  return params
}

// 查询（重置到第1页）
async function doSearch() {
  pagination.page = 1
  await loadData()
}

// 加载数据（分页）— 竞态保护：快速翻页时丢弃过期响应
let loadSeq = 0
async function loadData() {
  const seq = ++loadSeq
  tableLoading.value = true
  try {
    const res = await getCDRList(buildParams())
    if (seq !== loadSeq) return  // 丢弃过期响应
    if (res.success) {
      const d = res.data
      tableData.value = d.data || []
      pagination.total = d.total || 0
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
function handlePageChange() {
  loadData()
}

// 切换每页条数
function handleSizeChange() {
  pagination.page = 1
  loadData()
}

// 大数格式化：亿/万
function formatTotal(n) {
  if (n >= 1e8) return (n / 1e8).toFixed(2) + '亿'
  if (n >= 1e4) return (n / 1e4).toFixed(1) + '万'
  return String(n)
}

// ===== 导出功能 =====
const exportVisible = ref(false)
const exporting = ref(false)
const exportSelected = ref([])

const defaultExportFields = [
  { key: 'caller', label: '主叫号码' },
  { key: 'callee', label: '被叫号码' },
  { key: 'caller_in', label: '呼入主叫' },
  { key: 'callee_in', label: '呼入被叫' },
  { key: 'gateway_in', label: '对接网关' },
  { key: 'start_time', label: '起始时间' },
  { key: 'end_time', label: '结束时间' },
  { key: 'duration', label: '实际通话时长' },
  { key: 'caller_ip', label: '主叫IP' },
  { key: 'disconnect_cause', label: '状态' },
]

const otherExportFields = [
  { key: 'caller_out', label: '呼出主叫' },
  { key: 'callee_out', label: '呼出被叫' },
  { key: 'gateway_out', label: '落地网关' },
  { key: 'continuous_duration', label: '持续时长' },
  { key: 'bill_duration', label: '计费时长' },
  { key: 'callee_ip', label: '被叫IP' },
  { key: 'direction', label: '方向' },
  { key: 'fee_rate', label: '费率' },
  { key: 'fee', label: '费用' },
  { key: 'account', label: '账户' },
  { key: 'fee_rate_group', label: '费率组' },
  { key: 'settlement_account', label: '结算账户' },
  { key: 'mapping_account', label: '对接账户' },
  { key: 'cdr_id', label: '话单ID' },
  { key: 'node_name', label: '节点' },
  { key: 'call_id', label: 'Call ID' },
  { key: 'received_at', label: '接收时间' },
]

const allExportFields = [...defaultExportFields, ...otherExportFields]

function openExportDialog() {
  exportSelected.value = defaultExportFields.map(f => f.key)
  exportVisible.value = true
}

function selectAllFields() {
  exportSelected.value = allExportFields.map(f => f.key)
}

function resetDefaultFields() {
  exportSelected.value = defaultExportFields.map(f => f.key)
}

async function doExport() {
  if (exportSelected.value.length === 0) {
    ElMessage.warning('请至少选择一个导出字段')
    return
  }
  exporting.value = true
  try {
    const params = { ...filters, fields: exportSelected.value.join(',') }
    if (dateRange.value && dateRange.value.length === 2) {
      params.start_time_from = dateRange.value[0]
      params.start_time_to = dateRange.value[1]
    }
    const blob = await exportCDR(params)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    const now = new Date()
    const ts = `${now.getFullYear()}${pad(now.getMonth() + 1)}${pad(now.getDate())}_${pad(now.getHours())}${pad(now.getMinutes())}${pad(now.getSeconds())}`
    a.download = `通话记录_${ts}.csv`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    URL.revokeObjectURL(url)
    exportVisible.value = false
    ElMessage.success('导出成功')
  } catch (e) {
    // request.js 已处理错误提示
  } finally {
    exporting.value = false
  }
}

function onSortChange({ prop, order }) {
  currentSort.prop = prop
  currentSort.order = order
}

// ===== 详情弹窗 =====
const detailVisible = ref(false)
const currentRow = ref({})
const showRawData = ref(false)

function showDetail(row) {
  currentRow.value = row
  showRawData.value = false
  detailVisible.value = true
}

/**
 * 解析原始话单文本（raw_data）为 [{index, value}] 列表。
 * 与后端 csv-parse 一致：双引号包裹字段，双引号内两个双引号表示转义。
 */
function parseRawLine(line) {
  const out = []
  let cur = ''
  let inQ = false
  for (let i = 0; i < line.length; i++) {
    const ch = line[i]
    if (inQ) {
      if (ch === '"') {
        if (line[i + 1] === '"') { cur += '"'; i++ }
        else inQ = false
      } else {
        cur += ch
      }
    } else if (ch === '"') {
      inQ = true
    } else if (ch === ',') {
      out.push(cur)
      cur = ''
    } else {
      cur += ch
    }
  }
  out.push(cur)
  return out
}

const rawFieldList = computed(() => {
  const row = currentRow.value
  if (!row || !row.raw_data) return []
  return parseRawLine(row.raw_data).map((v, i) => ({ index: i, value: (v || '').trim() }))
})

const rawFieldMap = computed(() => {
  const map = {}
  rawFieldList.value.forEach(f => { map[f.index] = f.value })
  return map
})

/**
 * 表格主被叫显示逻辑：
 * 主叫号码 = caller (p[8])，无落地网关时为空，不回退
 * 被叫号码 = callee (p[14])，无落地网关时为空，不回退
 * 呼入主叫/呼入被叫独立列展示，始终有值
 */
function displayCaller(row) {
  return row.caller || ''
}
function displayCallee(row) {
  return row.callee || ''
}

/**
 * 呼入方向字段（详情弹窗）
 * 直接取自独立存储的字段，回退到原始字段索引
 */
const inboundCaller = computed(() => currentRow.value.caller_in || rawFieldMap.value[1] || '')
const inboundCallee = computed(() => currentRow.value.callee_in || rawFieldMap.value[3] || '')

/**
 * 原始字段列表（用于字段索引对照区域），由 raw_data 实时解析
 */
const rawFields = computed(() => rawFieldList.value)

/**
 * 挂断方映射
 */
function formatHangupParty(val) {
  if (val === '0' || val === 0) return '主叫'
  if (val === '1' || val === 1) return '被叫'
  return val || ''
}

/**
 * 录音标志
 */
function formatRecording(val) {
  if (val === '1' || val === 1) return '有'
  if (val === '0' || val === 0) return '无'
  return val || ''
}

/**
 * 接通延迟：P22是毫秒，需÷1000转秒；-1表示未接通
 */
function formatConnectDelay(val) {
  if (!val || val === '') return ''
  const num = parseFloat(val)
  if (num === -1) return '未接通'
  if (isNaN(num) || num === 0) return ''
  return (num / 1000).toFixed(3) + ' 秒'
}

// ===== 初始化 =====
onMounted(() => {
  loadOptions()
  // 手动查询模式：打开页面不再自动查询，用户点击「查询」按钮触发
})
</script>

<style scoped>
/* ===== 筛选区 ===== */
.filter-card {
  margin-bottom: 12px;
}

.filter-card :deep(.el-form-item) {
  margin-bottom: 8px;
}

.filter-form {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0;
  align-items: center;
}

/* 下拉选项状态颜色 */
.opt-success {
  color: #10b981;
}

.opt-fail {
  color: #ef4444;
}

/* ===== 表格 ===== */
.table-card :deep(.el-table) {
  font-size: var(--font-size-sm);
}

.id-link {
  font-weight: var(--font-weight-semibold);
  font-family: 'JetBrains Mono', monospace;
}

.duration-value {
  color: var(--color-text-primary);
  font-weight: var(--font-weight-medium);
}

.duration-zero {
  color: var(--color-text-tertiary);
}

/* 呼入主叫/呼入被叫方向标记 */
.num-inbound {
  color: var(--color-success);
  font-weight: var(--font-weight-medium);
}

.num-outbound {
  color: var(--color-info);
  font-weight: var(--font-weight-medium);
}

/* 挂断原因标签：成功=绿，失败=红 */
.cause-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: var(--font-size-xs);
  font-weight: var(--font-weight-medium);
  cursor: default;
  white-space: nowrap;
}

.cause-success {
  background: rgba(16, 185, 129, 0.15);
  color: #10b981;
}

.cause-fail {
  background: rgba(239, 68, 68, 0.15);
  color: #ef4444;
}

.pagination-wrap {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
}

/* ===== 导出弹窗 ===== */
.export-dialog-body {
  padding: 0 4px;
}

.export-tip {
  color: var(--el-text-color-secondary);
  font-size: 13px;
  margin-bottom: 12px;
}

.export-actions {
  display: flex;
  gap: 12px;
  margin-bottom: 8px;
}

.export-fields {
  max-height: 360px;
  overflow-y: auto;
}

.field-group {
  margin-bottom: 16px;
}

.group-title {
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 8px;
  padding-bottom: 4px;
  border-bottom: 1px solid var(--el-border-color-lighter);
}

.field-group .el-checkbox {
  margin-right: 24px;
  margin-bottom: 8px;
}

/* ===== 详情弹窗 ===== */
.detail-body {
  padding: 0;
}

/* 标题栏 */
.detail-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.detail-header-left {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.detail-title {
  font-size: 16px;
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

.detail-id {
  font-family: 'JetBrains Mono', monospace;
  font-size: 13px;
  color: var(--color-text-secondary);
}

.detail-time {
  font-size: 12px;
  color: var(--color-text-tertiary);
}

/* 摘要卡片 */
.detail-summary {
  background: var(--color-bg-subtle, #f8f9fa);
  border-radius: var(--radius-lg);
  padding: 16px 20px;
  margin-bottom: 12px;
  border: 1px solid var(--color-border-light);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.summary-flow {
  display: flex;
  align-items: center;
  gap: 16px;
}

.summary-party {
  min-width: 100px;
}

.summary-party-label {
  font-size: 12px;
  color: var(--color-text-secondary);
  margin-bottom: 4px;
}

.summary-party-value {
  font-size: 20px;
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  word-break: break-all;
}

.summary-arrow {
  font-size: 18px;
  color: var(--color-text-tertiary);
  flex-shrink: 0;
}

.summary-status {
  display: flex;
  align-items: center;
  gap: 16px;
}

.summary-cause-tag {
  display: inline-block;
  padding: 6px 12px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: var(--font-weight-medium);
}

.summary-success {
  background: rgba(16, 185, 129, 0.15);
  color: #10b981;
}

.summary-fail {
  background: rgba(239, 68, 68, 0.15);
  color: #ef4444;
}

.summary-duration-block {
  text-align: right;
}

.summary-duration-label {
  font-size: 12px;
  color: var(--color-text-secondary);
}

.summary-duration-value {
  font-size: 22px;
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
}

/* 分组卡片网格 — 3列宽布局 */
.detail-cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.detail-card {
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border-light);
  border-radius: var(--radius-lg);
  padding: 12px 14px;
}

.detail-card-title {
  font-size: 12px;
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  margin-bottom: 10px;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--color-border-light);
}

.detail-card-fields {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* 横向排列：label | value */
.detail-field {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.detail-field-label {
  font-size: 12px;
  color: var(--color-text-secondary);
  flex-shrink: 0;
  min-width: 72px;
  text-align: right;
}

.detail-field-value {
  font-size: 13px;
  font-weight: var(--font-weight-medium);
  color: var(--color-text-primary);
  word-break: break-all;
  line-height: 1.4;
  flex: 1;
  min-width: 0;
}

.val-mono {
  font-family: 'JetBrains Mono', monospace;
  font-size: 13px;
}

.val-success {
  color: #10b981;
  font-weight: var(--font-weight-semibold);
}

.val-danger {
  color: #ef4444;
  font-weight: var(--font-weight-semibold);
}

/* 原始数据折叠区 */
.detail-raw-section {
  margin-top: 16px;
}

.raw-toggle {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: var(--font-weight-semibold);
  color: var(--color-text-primary);
  cursor: pointer;
  user-select: none;
  padding: 12px 16px;
  background: var(--color-bg-surface);
  border: 1px solid var(--color-border-light);
  border-radius: var(--radius-lg);
  transition: background var(--transition-fast);
}

.raw-toggle:hover {
  background: var(--color-bg-subtle);
}

.toggle-icon {
  transition: transform var(--transition-fast);
  font-size: 12px;
}

.toggle-icon.expanded {
  transform: rotate(90deg);
}

.raw-content {
  margin-top: 8px;
}

.raw-fields-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 6px;
  margin-bottom: 10px;
}

.raw-field-item {
  display: flex;
  align-items: baseline;
  gap: 4px;
  background: var(--color-bg-subtle);
  border: 1px solid var(--color-border-light);
  border-radius: var(--radius-sm);
  padding: 6px 8px;
  font-size: var(--font-size-xs);
  overflow: hidden;
}

.raw-index {
  color: var(--color-primary);
  font-family: 'JetBrains Mono', monospace;
  font-weight: var(--font-weight-medium);
  flex-shrink: 0;
}

.raw-value {
  color: var(--color-text-secondary);
  word-break: break-all;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.raw-text {
  background: var(--color-bg-subtle);
  border: 1px solid var(--color-border-light);
  border-radius: var(--radius-sm);
  padding: 12px;
  font-family: 'JetBrains Mono', monospace;
  font-size: var(--font-size-xs);
  white-space: pre-wrap;
  word-break: break-all;
  max-height: 200px;
  overflow-y: auto;
  margin: 0;
}

@media (max-width: 768px) {
  .detail-cards {
    grid-template-columns: repeat(2, 1fr);
  }
  .detail-summary {
    flex-direction: column;
  }
  .raw-fields-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 480px) {
  .detail-cards {
    grid-template-columns: 1fr;
  }
  .raw-fields-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
