<script setup>
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import {
  PlusIcon,
  ClipboardDocumentListIcon,
  UserGroupIcon,
  UsersIcon,
  UserPlusIcon,
  ArchiveBoxIcon,
  WrenchScrewdriverIcon,
  ChartBarIcon,
} from '@heroicons/vue/24/outline'

const auth   = useAuthStore()
const router = useRouter()

const accesos = [
  { label: 'Nueva orden',  icon: PlusIcon, to: { name: 'nueva-orden' } },
  { label: 'Órdenes',      icon: ClipboardDocumentListIcon, to: { name: 'ordenes' } },
  { label: 'Clientes',     icon: UserGroupIcon, to: { name: 'clientes' } },
  { label: 'Inventario',   icon: ArchiveBoxIcon, to: { name: 'inventario' } },
  { label: 'Producción',   icon: WrenchScrewdriverIcon, to: { name: 'produccion' } },
]

const accesosAdmin = [
  { label: 'Vendedores',   icon: UsersIcon, to: { name: 'usuarios' } },
  { label: 'Nuevo vendedor', icon: UserPlusIcon, to: { name: 'usuario-crear' } },
  { label: 'Reportes',     icon: ChartBarIcon, to: { name: 'reportes' } },
]
</script>

<template>
  <div class="p-4 space-y-4">
    <div class="bg-blue-600 text-white rounded-2xl p-5">
      <p class="text-sm opacity-80">Bienvenido</p>
      <p class="text-xl font-bold">{{ auth.usuario?.nombre }}</p>
      <p class="text-xs opacity-70 mt-1 capitalize">{{ auth.usuario?.rol }}</p>
    </div>

    <div class="grid grid-cols-2 gap-3">
      <button
        v-for="a in accesos"
        :key="a.label"
        @click="router.push(a.to)"
        class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
      >
        <component :is="a.icon" class="w-8 h-8" />
        {{ a.label }}
      </button>

      <button
        v-for="a in accesosAdmin"
        :key="a.label"
        @click="router.push(a.to)"
        class="bg-white rounded-xl shadow-sm p-4 flex flex-col items-center gap-2 text-sm font-medium text-gray-700 hover:bg-blue-50 transition-colors"
      >
        <component :is="a.icon" class="w-8 h-8" />
        {{ a.label }}
      </button>
    </div>
  </div>
</template>
