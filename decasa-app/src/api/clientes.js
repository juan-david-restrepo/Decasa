import api from './index'

export const getClientes = (params = {}) => api.get('/clientes', { params })
export const getCliente = (id) => api.get(`/clientes/${id}`)
export const getClienteOrdenes = (id, params = {}) => api.get(`/clientes/${id}/ordenes`, { params })
export const createCliente = (data) => api.post('/clientes', data)
