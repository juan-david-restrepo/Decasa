import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes = [
  { path: '/login', name: 'login', component: () => import('@/views/LoginView.vue'), meta: { guest: true } },
  { path: '/',        name: 'dashboard',  component: () => import('@/views/DashboardView.vue'),  meta: { requiresAuth: true } },
  { path: '/ordenes', name: 'ordenes',    component: () => import('@/views/OrdenesView.vue'),    meta: { requiresAuth: true } },
  { path: '/ordenes/:id', name: 'orden-detalle', component: () => import('@/views/OrdenDetalleView.vue'), meta: { requiresAuth: true } },
  { path: '/ordenes/nueva', name: 'nueva-orden', component: () => import('@/views/NuevaOrdenView.vue'), meta: { requiresAuth: true } },
  { path: '/clientes', name: 'clientes',  component: () => import('@/views/ClientesView.vue'),   meta: { requiresAuth: true } },
  { path: '/clientes/:id', name: 'cliente-detalle', component: () => import('@/views/ClienteDetalleView.vue'), meta: { requiresAuth: true } },
  { path: '/inventario', name: 'inventario', component: () => import('@/views/InventarioView.vue'), meta: { requiresAuth: true } },
  { path: '/produccion', name: 'produccion', component: () => import('@/views/ProduccionView.vue'), meta: { requiresAuth: true } },
  { path: '/reportes',   name: 'reportes',   component: () => import('@/views/ReportesView.vue'),   meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios', name: 'usuarios', component: () => import('@/views/UsuariosView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios/crear', name: 'usuario-crear', component: () => import('@/views/UsuarioCrearView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
  { path: '/usuarios/:id', name: 'usuario-detalle', component: () => import('@/views/UsuarioDetalleView.vue'), meta: { requiresAuth: true, requiresSupervisor: true } },
]

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login' }
  if (to.meta.guest && auth.isAuthenticated) return { name: 'dashboard' }
  if (to.meta.requiresSupervisor && !auth.isSupervisor) return { name: 'dashboard' }
})

export default router
