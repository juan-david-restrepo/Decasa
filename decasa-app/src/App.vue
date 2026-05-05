<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
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
} from '@heroicons/vue/24/outline'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const showNav = computed(() => auth.isAuthenticated && route.name !== 'login')

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
</script>

<template>
  <div class="flex flex-col min-h-screen bg-gray-50">
    <!-- Top bar -->
    <header v-if="showNav" class="sticky top-0 z-10 bg-white border-b border-gray-200 px-4 py-3 flex items-center justify-between">
      <span class="font-bold text-blue-600 text-lg">Decasa</span>
      <div class="flex items-center gap-3">
        <span class="text-xs text-gray-500 hidden sm:block">{{ auth.usuario?.nombre }}</span>
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
