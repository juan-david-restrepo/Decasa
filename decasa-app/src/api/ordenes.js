import api from './index'

export const getOrdenes = (params = {}) => api.get('/ordenes', { params })
export const getOrden = (id) => api.get(`/ordenes/${id}`)
export const updateEstado = (id, estado) => api.patch(`/ordenes/${id}/estado`, { estado })
export const getPagos = (id) => api.get(`/ordenes/${id}/pagos`)
export const registrarPago = (id, data) => api.post(`/ordenes/${id}/pagos`, data)
export const descargarPdfOrden = (id) => api.get(`/ordenes/${id}/pdf`, { responseType: 'blob' })
export const getTiendas = () => api.get('/tiendas')
