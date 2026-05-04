<script setup>
import { ref, computed, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import {
  FunnelIcon,
  CheckCircleIcon,
  ClockIcon,
  ExclamationTriangleIcon,
  XCircleIcon,
} from '@heroicons/vue/24/outline'
import { getProduccion, updateProduccion } from '@/api/produccion'
import { getTiendas } from '@/api/ordenes'
import EmptyState from '@/components/common/EmptyState.vue'

const auth = useAuthStore()

const producciones = ref([])
const tiendas = ref([])
const loading = ref(true)
const showFilters = ref(false)

const filtros = ref({
  estado: '',
  tienda_id: '',
})

const mostrarModal = ref(false)
const produccionSeleccionada = ref(null)
const nuevoEstado = ref('')
const motivoRetraso = ref('')
const modalError = ref('')
const modalLoading = ref(false)

const estadosOpts = [
  { value: '', label: 'Todos' },
  { value: 'en_proceso', label: 'En proceso' },
  { value: 'listo', label: 'Listo' },
  { value: 'retrasado', label: 'Retrasado' },
  { value: 'entregado', label: 'Entregado' },
]

function badgeInfo(p) {
  if (p.estado === 'entregado') {
    return { label: 'Entregado', cls: 'bg-gray-100 text-gray-500' }
  }
  if (p.estado === 'listo') {
    return { label: 'Listo para entrega', cls: 'bg-blue-100 text-blue-700' }
  }
  if (p.estado === 'retrasado') {
    return { label: `${Math.abs(p.dias_restantes)} días retraso`, cls: 'bg-red-100 text-red-700' }
  }
  if (p.dias_restantes < 0) {
    return { label: `${Math.abs(p.dias_restantes)} días retraso`, cls: 'bg-red-100 text-red-700' }
  }
  if (p.dias_restantes <= 3) {
    return { label: `${p.dias_restantes} días`, cls: 'bg-yellow-100 text-yellow-700' }
  }
  return { label: `${p.dias_restantes} días restantes`, cls: 'bg-green-100 text-green-700' }
}

function estadoIcon(p) {
  if (p.estado === 'entregado') return CheckCircleIcon
  if (p.estado === 'listo') return ClockIcon
  if (p.estado === 'retrasado' || p.dias_restantes < 0) return ExclamationTriangleIcon
  return ClockIcon
}

async function cargarTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
}

async function cargarProduccion() {
  loading.value = true
  try {
    const params = {}
    if (filtros.value.estado) params.estado = filtros.value.estado
    if (filtros.value.tienda_id) params.tienda_id = filtros.value.tienda_id

    const { data } = await getProduccion(params)
    producciones.value = data
  } catch {
    producciones.value = []
  } finally {
    loading.value = false
  }
}

function openModal(p) {
  produccionSeleccionada.value = p
  nuevoEstado.value = p.estado
  motivoRetraso.value = ''
  modalError.value = ''
  mostrarModal.value = true
}

async function guardarEstado() {
  modalError.value = ''
  if (!nuevoEstado.value) return
  if (nuevoEstado.value === 'retrasado' && !motivoRetraso.value.trim()) {
    modalError.value = 'Debes indicar el motivo del retraso.'
    return
  }

  modalLoading.value = true
  try {
    const data = { estado: nuevoEstado.value }
    if (motivoRetraso.value.trim()) {
      data.motivo_retraso = motivoRetraso.value.trim()
    }
    await updateProduccion(produccionSeleccionada.value.id, data)
    mostrarModal.value = false
    await cargarProduccion()
  } catch (e) {
    modalError.value = e.response?.data?.message ?? 'Error al actualizar el estado.'
  } finally {
    modalLoading.value = false
  }
}

function formatFecha(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr + 'T00:00:00')
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function applyFilters() {
  showFilters.value = false
  cargarProduccion()
}

function clearFilters() {
  filtros.value = { estado: '', tienda_id: '' }
  showFilters.value = false
  cargarProduccion()
}

onMounted(async () => {
  await cargarTiendas()
  await cargarProduccion()
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Producción</h2>
      <button
        @click="showFilters = !showFilters"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors flex items-center gap-1"
      >
        <FunnelIcon class="w-4 h-4" />
        {{ showFilters ? 'Cerrar' : 'Filtros' }}
      </button>
    </div>

    <!-- Filtros -->
    <div v-if="showFilters" class="bg-white rounded-xl shadow-sm p-4 space-y-3">
      <div>
        <label class="block text-xs font-medium text-gray-500 mb-1">Estado</label>
        <select v-model="filtros.estado" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option v-for="e in estadosOpts" :key="e.value" :value="e.value">{{ e.label }}</option>
        </select>
      </div>
      <div v-if="auth.isSupervisor">
        <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
        <select v-model="filtros.tienda_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
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
      v-else-if="producciones.length === 0"
      message="No hay pedidos en producción."
    />

    <!-- Lista -->
    <ul v-else class="space-y-2">
      <li
        v-for="p in producciones"
        :key="p.id"
        class="bg-white rounded-xl shadow-sm p-4 space-y-2"
      >
        <!-- Producto + badge de días -->
        <div class="flex justify-between items-start">
          <div class="flex-1 min-w-0">
            <p class="font-medium text-sm text-gray-800 truncate">{{ p.orden_item?.producto?.nombre }}</p>
            <p class="text-xs text-gray-400">{{ p.orden_item?.producto?.categoria }}</p>
          </div>
          <span
            :class="['inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium flex-shrink-0 ml-2', badgeInfo(p).cls]"
          >
            <component :is="estadoIcon(p)" class="w-3.5 h-3.5" />
            {{ badgeInfo(p).label }}
          </span>
        </div>

        <!-- Info -->
        <div class="grid grid-cols-2 gap-2 text-xs text-gray-500">
          <div>
            <p class="text-gray-400">Cliente</p>
            <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.cliente?.nombre }}</p>
          </div>
          <div>
            <p class="text-gray-400">Teléfono</p>
            <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.cliente?.telefono }}</p>
          </div>
          <div>
            <p class="text-gray-400">Vendedor</p>
            <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.vendedor?.nombre }}</p>
          </div>
          <div>
            <p class="text-gray-400">Tienda</p>
            <p class="font-medium text-gray-700">{{ p.orden_item?.orden?.tienda?.nombre }}</p>
          </div>
        </div>

        <!-- Fechas -->
        <div class="flex justify-between items-center text-xs pt-1 border-t border-gray-100">
          <span class="text-gray-400">Compromiso: <span class="font-medium text-gray-600">{{ formatFecha(p.fecha_compromiso) }}</span></span>
          <button
            v-if="p.estado !== 'entregado'"
            @click="openModal(p)"
            class="text-blue-600 font-medium flex items-center gap-1"
          >
            Cambiar estado
          </button>
          <span v-else class="text-gray-400">Entregado {{ formatFecha(p.fecha_real) }}</span>
        </div>
      </li>
    </ul>

    <!-- Modal cambiar estado -->
    <Transition name="fade">
      <div v-if="mostrarModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarModal = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Cambiar estado</h3>
            <button @click="mostrarModal = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <p class="text-sm text-gray-600">{{ produccionSeleccionada?.orden_item?.producto?.nombre }}</p>
          <p class="text-xs text-gray-400">Estado actual: <span class="font-medium text-gray-600">{{ produccionSeleccionada?.estado }}</span></p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nuevo estado</label>
            <select v-model="nuevoEstado" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option value="en_proceso">En proceso</option>
              <option value="listo">Listo para entrega</option>
              <option value="retrasado">Retrasado</option>
              <option value="entregado">Entregado</option>
            </select>
          </div>

          <div v-if="nuevoEstado === 'retrasado'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo del retraso *</label>
            <textarea
              v-model="motivoRetraso"
              rows="3"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
              placeholder="Explica por qué se retrasó este pedido..."
            />
          </div>

          <p v-if="modalError" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ modalError }}</p>

          <div class="flex gap-3">
            <button @click="mostrarModal = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold">Cancelar</button>
            <button
              @click="guardarEstado"
              :disabled="modalLoading"
              class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
            >
              {{ modalLoading ? 'Guardando...' : 'Guardar' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
