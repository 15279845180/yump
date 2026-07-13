import axios from 'axios'
import { ElMessage } from 'element-plus'

const TOKEN_KEY = 'vos3000_token'

// 401 防重复跳转锁：并发请求同时 401 时只弹一次窗、跳一次转
let isRedirecting401 = false

const request = axios.create({
  baseURL: '/api',
  timeout: 30000,
})

// ========== 请求拦截：自动带 JWT ==========
request.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem(TOKEN_KEY)
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => Promise.reject(error)
)

// ========== 响应拦截 ==========
request.interceptors.response.use(
  (response) => response.data,
  (error) => {
    // 401 → token 过期/无效 → 跳登录
    if (error.response?.status === 401) {
      localStorage.removeItem(TOKEN_KEY)
      localStorage.removeItem('vos3000_user')
      // 避免重复弹窗（登录页本身也会返回 401）
      if (!isRedirecting401 && window.location.pathname !== '/login') {
        isRedirecting401 = true
        ElMessage.warning('登录已过期，请重新登录')
        window.location.href = '/login'
      }
      return Promise.reject(error)
    }
    // 其他错误：统一弹出错误提示（调用方仍可在 catch 中做额外处理）
    // blob 请求的错误：responseType=blob 时错误响应也是 Blob，需转 JSON 读 message
    if (error.config?.responseType === 'blob' && error.response?.data instanceof Blob) {
      error.response.data.text().then(text => {
        try {
          const json = JSON.parse(text)
          ElMessage.error(json.message || '操作失败')
        } catch {
          ElMessage.error('操作失败，请重试')
        }
      })
      return Promise.reject(error)
    }
    const msg = error.response?.data?.message || error.message || '请求失败'
    // 避免在下载/特殊请求时弹窗
    if (!error.config?._silent) {
      ElMessage.error(msg)
    }
    return Promise.reject(error)
  }
)

export default request
