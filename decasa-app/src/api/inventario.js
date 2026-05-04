import api from './index'

export const getInventario = (tiendaId, search = '') =>
  api.get('/inventario', { params: { tienda_id: tiendaId, search } })

export const addStock = (data) => api.post('/inventario/entrada', data)
