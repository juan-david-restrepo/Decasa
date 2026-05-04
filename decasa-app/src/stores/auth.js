import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { login as apiLogin, logout as apiLogout } from '@/api/auth'

export const useAuthStore = defineStore('auth', () => {
  const token   = ref(localStorage.getItem('token') ?? null)
  const usuario = ref(JSON.parse(localStorage.getItem('usuario') ?? 'null'))

  const isAuthenticated = computed(() => !!token.value)
  const isSupervisor    = computed(() => usuario.value?.rol === 'supervisor')

  async function login(email, password) {
    const { data } = await apiLogin(email, password)
    token.value   = data.token
    usuario.value = { nombre: data.nombre, rol: data.rol, tienda_default_id: data.tienda_default_id }
    localStorage.setItem('token',   data.token)
    localStorage.setItem('usuario', JSON.stringify(usuario.value))
  }

  async function logout() {
    try { await apiLogout() } catch {}
    clearSession()
  }

  function clearSession() {
    token.value   = null
    usuario.value = null
    localStorage.removeItem('token')
    localStorage.removeItem('usuario')
  }

  return { token, usuario, isAuthenticated, isSupervisor, login, logout, clearSession }
})
