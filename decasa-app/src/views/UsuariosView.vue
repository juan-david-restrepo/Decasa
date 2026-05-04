<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  MagnifyingGlassIcon,
  PlusIcon,
  ChevronRightIcon,
  MapPinIcon,
} from '@heroicons/vue/24/outline'
import { getUsuarios } from '@/api/usuarios'
import { getTiendas } from '@/api/ordenes'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()

const usuarios = ref([])
const tiendas = ref([])
const loading = ref(true)
const showFilters = ref(false)

const busqueda = ref('')
const filtros = ref({
  rol: '',
  tienda_id: '',
  estado: '',
})

async function cargarTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
}

async function cargarUsuarios() {
  loading.value = true
  try {
    const params = {}
    if (busqueda.value.trim()) params.search = busqueda.value.trim()
    if (filtros.value.rol) params.rol = filtros.value.rol
    if (filtros.value.tienda_id) params.tienda_id = filtros.value.tienda_id
    if (filtros.value.estado) params.estado = filtros.value.estado

    const { data } = await getUsuarios(params)
    usuarios.value = data
  } catch {
    usuarios.value = []
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  showFilters.value = false
  cargarUsuarios()
}

function clearFilters() {
  filtros.value = { rol: '', tienda_id: '', estado: '' }
  showFilters.value = false
  cargarUsuarios()
}

function goToUsuario(id) {
  router.push({ name: 'usuario-detalle', params: { id } })
}

function goToCrear() {
  router.push({ name: 'usuario-crear' })
}

const rolBadgeCls = (rol) =>
  rol === 'supervisor'
    ? 'bg-blue-100 text-blue-700'
    : 'bg-gray-100 text-gray-600'

const estadoBadgeCls = (activo) =>
  activo
    ? 'bg-green-100 text-green-700'
    : 'bg-red-100 text-red-700'

const estadoLabel = (activo) => activo ? 'Activo' : 'Inactivo'

onMounted(async () => {
  await cargarTiendas()
  await cargarUsuarios()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Vendedores</h2>
      <button
        @click="showFilters = !showFilters"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors"
      >
        {{ showFilters ? 'Cerrar' : 'Filtros' }}
      </button>
      <button
        @click="goToCrear"
        class="bg-blue-600 text-white rounded-lg p-2 hover:bg-blue-700 transition-colors"
      >
        <PlusIcon class="w-5 h-5" />
      </button>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="cargarUsuarios"
        placeholder="Buscar por nombre o email..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Filtros -->
    <div v-if="showFilters" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Rol</label>
        <select v-model="filtros.rol" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todos</option>
          <option value="vendedor">Vendedores</option>
          <option value="supervisor">Supervisores</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
        <select v-model="filtros.tienda_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
        <select v-model="filtros.estado" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todos</option>
          <option value="1">Activos</option>
          <option value="0">Inactivos</option>
        </select>
      </div>
      <div class="flex gap-2">
        <button @click="clearFilters" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2 text-sm font-semibold hover:bg-gray-200">Limpiar</button>
        <button @click="applyFilters" class="flex-1 bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700">Aplicar</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Cargando...</div>

    <!-- Empty -->
    <EmptyState
      v-else-if="usuarios.length === 0"
      :message="busqueda ? 'No se encontraron usuarios.' : 'No hay usuarios registrados.'"
    />

    <!-- Lista -->
    <ul v-else class="space-y-2">
      <li
        v-for="u in usuarios"
        :key="u.id"
        @click="goToUsuario(u.id)"
        class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3 cursor-pointer hover:bg-blue-50 transition-colors active:bg-blue-100"
      >
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2 mb-1">
            <p class="font-medium text-gray-800 truncate">{{ u.nombre }}</p>
            <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', rolBadgeCls(u.rol)]">
              {{ u.rol === 'supervisor' ? 'Admin' : 'Vendedor' }}
            </span>
            <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', estadoBadgeCls(u.activo)]">
              {{ estadoLabel(u.activo) }}
            </span>
          </div>
          <p class="text-xs text-gray-400 truncate">{{ u.email }}</p>
          <p v-if="u.tienda" class="text-xs text-gray-400 flex items-center gap-1 mt-0.5">
            <MapPinIcon class="w-3.5 h-3.5" />
            {{ u.tienda.nombre }}
          </p>
        </div>
        <ChevronRightIcon class="w-5 h-5 text-gray-300 flex-shrink-0" />
      </li>
    </ul>
  </div>
</template>
