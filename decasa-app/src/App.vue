<script setup>
import { computed, ref, watch, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useNotificacionesStore } from '@/stores/notificaciones'
import ScrollToTop from '@/components/common/ScrollToTop.vue'
import {
  HomeIcon,
  ClipboardDocumentListIcon,
  UserGroupIcon,
  UsersIcon,
  ArchiveBoxIcon,
  WrenchScrewdriverIcon,
  ChartBarIcon,
  PresentationChartLineIcon,
  ArrowRightStartOnRectangleIcon,
  BellIcon,
  UserCircleIcon,
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const notif  = useNotificacionesStore()

const showNav    = computed(() => auth.isAuthenticated && route.name !== 'login')
const abrirNotif = ref(false)

// Refrescar datos del usuario (firma_url, id) al cargar la app
onMounted(() => { auth.fetchMe() })

watch(() => auth.isAuthenticated, (isAuth) => {
  if (!isAuth) return
  notif.cargar()
  if (!window.Echo) return
  const canal = auth.isSupervisor
    ? 'notificaciones'
    : `notificaciones.${auth.usuario?.id}`
  window.Echo.channel(canal).listen('.nueva.notificacion', n => {
    notif.agregarNueva(n)
  })
}, { immediate: true })

const navItems = computed(() => {
  const base = [
    { name: 'dashboard',  label: 'Inicio',     icon: HomeIcon },
    { name: 'ordenes',    label: 'Órdenes',    icon: ClipboardDocumentListIcon },
    { name: 'clientes',   label: 'Clientes',   icon: UserGroupIcon },
    { name: 'inventario', label: 'Inventario', icon: ArchiveBoxIcon },
  ]
  if (auth.isSupervisor) {
    base.push({ name: 'produccion', label: 'Producción', icon: WrenchScrewdriverIcon })
    base.push({ name: 'usuarios',   label: 'Vendedores', icon: UsersIcon })
    base.push({ name: 'reportes',   label: 'Reportes',   icon: ChartBarIcon })
  } else {
    base.push({ name: 'mis-stats', label: 'Estadíst.', icon: PresentationChartLineIcon })
  }
  return base
})

async function doLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}

async function abrirNotificacion(n) {
  notif.leer(n.id)
  abrirNotif.value = false
  const datos = n.datos ?? {}
  if (datos.orden_id) {
    router.push({ name: 'orden-detalle', params: { id: datos.orden_id } })
  }
}

function tipoIcono(tipo) {
  return {
    venta_nueva:       '🛒',
    venta_otra_tienda: '🏪',
    en_produccion:     '🔨',
    entregado:         '✅',
    retrasado:         '⚠️',
    por_vencer:        '⏰',
    cancelado:         '❌',
  }[tipo] ?? '🔔'
}

function formatFecha(iso) {
  if (!iso) return ''
  const d = new Date(iso)
  const diffMin = Math.floor((Date.now() - d) / 60000)
  if (diffMin < 1)  return 'Ahora'
  if (diffMin < 60) return `Hace ${diffMin} min`
  const h = Math.floor(diffMin / 60)
  if (h < 24) return `Hace ${h} h`
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}
</script>

<template>
  <div class="flex flex-col min-h-screen bg-gray-50">
    <!-- Top bar -->
    <header v-if="showNav" class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
      <span class="font-bold text-blue-600 text-lg">Decasa</span>
      <div class="flex items-center gap-3">
        <button
          @click="router.push({ name: 'perfil' })"
          class="hidden sm:flex items-center gap-1.5 text-xs text-gray-500 hover:text-blue-600 transition-colors"
        >
          <UserCircleIcon class="w-5 h-5" />
          {{ auth.usuario?.nombre }}
        </button>

        <!-- Campana de notificaciones -->
        <div v-if="auth.isAuthenticated" class="relative">
          <button @click="abrirNotif = !abrirNotif" class="relative p-1">
            <BellIcon class="w-6 h-6 text-gray-600" />
            <span
              v-if="notif.noLeidas > 0"
              class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 flex items-center justify-center px-0.5"
            >
              {{ notif.noLeidas > 9 ? '9+' : notif.noLeidas }}
            </span>
          </button>

          <!-- Dropdown -->
          <div
            v-if="abrirNotif"
            class="absolute right-0 top-full mt-1 w-80 max-h-[28rem] overflow-y-auto bg-white rounded-xl shadow-xl border border-gray-200 z-50"
          >
            <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100 sticky top-0 bg-white">
              <span class="font-semibold text-sm text-gray-700">Notificaciones</span>
              <button
                v-if="notif.noLeidas > 0"
                @click="notif.leerTodas()"
                class="text-xs text-blue-600 hover:underline"
              >
                Marcar todas como leídas
              </button>
            </div>

            <div v-if="notif.items.length === 0" class="py-10 text-center text-gray-400 text-sm">
              Sin notificaciones
            </div>

            <button
              v-for="n in notif.items"
              :key="n.id"
              @click="abrirNotificacion(n)"
              :class="[
                'w-full text-left px-4 py-3 border-b border-gray-50 hover:bg-gray-50 transition-colors',
                !n.leida && 'bg-blue-50',
              ]"
            >
              <div class="flex gap-2 items-start">
                <span class="text-base mt-0.5">{{ tipoIcono(n.tipo) }}</span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium text-gray-800 leading-tight">{{ n.titulo }}</p>
                  <p class="text-xs text-gray-500 leading-snug mt-0.5">{{ n.mensaje }}</p>
                  <p class="text-[11px] text-gray-400 mt-1">{{ formatFecha(n.created_at) }}</p>
                </div>
                <span v-if="!n.leida" class="w-2 h-2 bg-blue-500 rounded-full mt-1 flex-shrink-0" />
              </div>
            </button>
          </div>

          <!-- Backdrop -->
          <div v-if="abrirNotif" class="fixed inset-0 z-40" @click="abrirNotif = false" />
        </div>

        <button
          @click="doLogout"
          class="flex items-center gap-1.5 bg-red-500 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-red-600 transition-colors"
        >
          <ArrowRightStartOnRectangleIcon class="w-4 h-4" />
          Cerrar sesión
        </button>
      </div>
    </header>

    <!-- Page content -->
    <main class="flex-1 pb-20">
      <RouterView />
    </main>

    <!-- Bottom tab bar (mobile) -->
    <nav
      v-if="showNav"
      class="fixed bottom-0 inset-x-0 bg-white border-t border-gray-200 flex z-10"
    >
      <button
        v-for="item in navItems"
        :key="item.name"
        @click="router.push({ name: item.name })"
        :class="[
          'flex-1 flex flex-col items-center py-2 text-xs gap-0.5 transition-colors',
          route.name === item.name ? 'text-blue-600 font-semibold' : 'text-gray-500',
        ]"
      >
        <component :is="item.icon" class="w-6 h-6" />
        {{ item.label }}
      </button>
    </nav>

    <!-- Scroll to top -->
    <ScrollToTop />
  </div>
</template>
