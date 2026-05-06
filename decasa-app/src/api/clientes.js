import api from './index'

export const getClientes = (params = {}) => api.get('/clientes', { params })
export const getCliente = (id) => api.get(`/clientes/${id}`)
export const getClienteOrdenes = (id, params = {}) => api.get(`/clientes/${id}/ordenes`, { params })
export const createCliente = (data) => api.post('/clientes', data)
export const updateCliente = (id, data) => api.put(`/clientes/${id}`, data)

export const CATEGORIAS_DISPONIBLES = [
  'Sofá',
  'Sofá modular',
  'Silla comedor',
  'Silla auxiliar',
  'Comedor',
  'Sofá cama',
  'Silla de barra',
  'Escritorio',
  'Mesas auxiliares',
  'Camas o colchones',
]
