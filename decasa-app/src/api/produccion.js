import api from './index'

export const getProduccion = (params = {}) =>
  api.get('/produccion', { params })

export const updateProduccion = (id, data) =>
  api.patch(`/produccion/${id}`, data)
