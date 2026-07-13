<template>
  <div class="page-container">
    <!-- 筛选区 -->
    <el-card shadow="never" class="filter-card">
      <el-form :inline="true" :model="filters">
        <el-form-item label="节点">
          <el-select v-model="filters.nodeId" placeholder="全部节点" clearable style="width:190px" @change="onFilterChange">
            <el-option v-for="n in enabledNodes" :key="n.id" :label="n.name" :value="n.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="对接网关">
          <el-select v-model="filters.gatewayIn" placeholder="选择网关" clearable filterable style="width:160px" @change="onFilterChange">
            <el-option v-for="g in gatewayInOptions" :key="g" :label="g" :value="g" />
          </el-select>
        </el-form-item>
        <el-form-item label="落地网关">
          <el-select v-model="filters.gatewayOut" placeholder="选择网关" clearable filterable style="width:160px" @change="onFilterChange">
            <el-option v-for="g in gatewayOutOptions" :key="g" :label="g" :value="g" />
          </el-select>
        </el-form-item>
        <el-form-item label="主叫号码">
          <el-input v-model="filters.caller" placeholder="主叫号码" clearable style="width:140px" @keyup.enter="doSearch" @clear="onFilterChange" />
        </el-form-item>
        <el-form-item label="被叫号码">
          <el-input v-model="filters.callee" placeholder="被叫号码" clearable style="width:140px" @keyup.enter="doSearch" @clear="onFilterChange" />
        </el-form-item>
        <el-form-item label="IP地址">
          <el-input v-model="filters.ip" placeholder="RTP IP搜索" clearable style="width:150px" @keyup.enter="doSearch" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" class="btn-modern" @click="doSearch" :loading="tableLoading">
            <el-icon><Search /></el-icon>查询
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 表格组件 -->
    <CurrentCallTable ref="tableRef" :filters="tableFilters" />
  </div>
</template>

<script setup>
defineOptions({ name: 'CurrentCall' })
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { Search } from '@element-plus/icons-vue'
import { nodeApi } from '@/api/node'
import { getCDROptions } from '@/api/cdr'
import CurrentCallTable from '@/components/call/CurrentCallTable.vue'

const route = useRoute()

// ===== 节点列表 =====
const enabledNodes = ref([])

async function loadNodes() {
  try {
    const res = await nodeApi.list()
    enabledNodes.value = (res.data || []).filter(n => n.status === 1)
  } catch (e) { /* ignore */ }
}

// ===== 网关下拉选项 =====
const gatewayInOptions = ref([])     // 对接网关列表
const gatewayOutOptions = ref([])    // 落地网关列表

async function loadGatewayOptions() {
  try {
    const res = await getCDROptions()
    if (res.success) {
      gatewayInOptions.value = res.data.gateways_in || []
      gatewayOutOptions.value = res.data.gateways_out || []
    }
  } catch (e) { /* ignore */ }
}

// ===== 筛选条件 =====
const filters = reactive({
  nodeId: null,
  gatewayIn: '',
  gatewayOut: '',
  caller: '',
  callee: '',
  ip: '',
})

// 传给表格组件的 filters
const tableFilters = computed(() => ({
  nodeId: filters.nodeId,
  gatewayMappingName: filters.gatewayIn,
  gatewayRoutingName: filters.gatewayOut,
  caller: filters.caller,
  callee: filters.callee,
  ip: filters.ip,
}))

const tableRef = ref(null)
const tableLoading = ref(false)

function doSearch() {
  tableRef.value?.refresh()
}

function onFilterChange() {
  // 清除过滤不自动搜索，等手动点查询
}

// 从路由 query 初始化（兼容旧跳转）
function initFromRoute() {
  const q = route.query
  if (q.nodeId) {
    filters.nodeId = parseInt(q.nodeId, 10) || null
  }
  if (q.gatewayName) {
    filters.gatewayIn = q.gatewayName
  }
}

onMounted(() => {
  loadNodes().then(() => {
    initFromRoute()
  })
  loadGatewayOptions()
})
</script>

<style scoped>
.filter-card {
  margin-bottom: 16px;
}
</style>
