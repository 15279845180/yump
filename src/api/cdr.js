import request from './request'

/**
 * 查询通话记录列表
 * @param {Object} params - 筛选参数
 */
export function getCDRList(params) {
  return request.get('/cdr', { params })
}

/**
 * 获取筛选下拉选项数据
 * 返回：节点、普通账户、结算账户、对接网关、落地网关、挂断原因码
 */
export function getCDROptions() {
  return request.get('/cdr/options')
}

/**
 * 获取通话记录统计概览
 */
export function getCDRStats() {
  return request.get('/cdr/stats')
}

/**
 * 清空通话记录
 */
export function clearCDR() {
  return request.delete('/cdr', { params: { confirm: 'DELETE' } })
}

/**
 * 导出通话记录（CSV，流式下载）
 * @param {Object} params - 筛选参数 + fields（逗号分隔字段名）
 */
export function exportCDR(params) {
  return request.get('/cdr/export', {
    params,
    responseType: 'blob',
    timeout: 120000,
  })
}
