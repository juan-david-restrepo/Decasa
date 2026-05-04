import api from './index'

export const getClientes = (search = '') => api.get('/clientes', { params: { search } })
export const getCliente = (id) => api.get(`/clientes/${id}`)
export const getClienteOrdenes = (id, params = {}) => api.get(`/clientes/${id}/ordenes`, { params })
export const createCliente = (data) => api.post('/clientes', data)
