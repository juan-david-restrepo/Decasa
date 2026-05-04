<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  MagnifyingGlassIcon,
  ExclamationTriangleIcon,
  PlusIcon,
  PencilIcon,
  ArchiveBoxIcon,
} from '@heroicons/vue/24/outline'
import { getInventario, addStock } from '@/api/inventario'
import { getTiendas } from '@/api/ordenes'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import EmptyState from '@/components/common/EmptyState.vue'
import api from '@/api'

const router = useRouter()
const auth = useAuthStore()

const tiendas = ref([])
const tiendaId = ref('')
const inventario = ref([])
const todosLosItems = ref([])
const busqueda = ref('')
const loading = ref(false)
const pagina = ref(1)
const tieneMas = ref(false)
const mostrarGestionar = ref(false)
const itemGestionar = ref(null)
const mostrarAgregarStock = ref(false)

const nuevoStock = ref(0)
const stockMotivo = ref('')
const nuevoPrecio = ref(0)
const gestionError = ref('')
const gestionLoading = ref(false)
const stockError = ref('')
const stockLoading = ref(false)

const POR_PAGINA = 20

const paginaVisible = computed(() => {
  return inventario.value.slice(0, pagina.value * POR_PAGINA)
})

const sentinel = ref(null)
let observer = null

async function cargarTiendas() {
  try {
    const { data } = await getTiendas()
    tiendas.value = data
  } catch {}
}

async function cargarInventario(reset = false) {
  if (!tiendaId.value) return
  loading.value = true
  try {
    const { data } = await getInventario(tiendaId.value, busqueda.value.trim())
    todosLosItems.value = data
    inventario.value = data
    pagina.value = 1
    tieneMas.value = data.length > POR_PAGINA
  } catch {
    todosLosItems.value = []
    inventario.value = []
  } finally {
    loading.value = false
  }
}

function loadMore() {
  if (pagina.value * POR_PAGINA >= inventario.value.length) {
    tieneMas.value = false
  }
}

function setupObserver() {
  if (observer) observer.disconnect()
  observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting && tieneMas.value) {
      pagina.value++
      loadMore()
    }
  }, { rootMargin: '200px' })
  nextTick(() => {
    if (sentinel.value) observer.observe(sentinel.value)
  })
}

function openGestionar(item) {
  itemGestionar.value = item
  nuevoPrecio.value = parseFloat(item.producto?.precio_base ?? 0)
  nuevoStock.value = 0
  stockMotivo.value = ''
  gestionError.value = ''
  stockError.value = ''
  mostrarGestionar.value = true
}

async function guardarPrecio() {
  gestionError.value = ''
  gestionLoading.value = true
  try {
    await api.patch(`/productos/${itemGestionar.value.producto_id}`, {
      precio_base: nuevoPrecio.value,
    })
    mostrarGestionar.value = false
    await cargarInventario()
  } catch (e) {
    gestionError.value = e.response?.data?.message ?? 'Error al actualizar el precio.'
  } finally {
    gestionLoading.value = false
  }
}

async function guardarStock() {
  stockError.value = ''
  if (!nuevoStock.value || nuevoStock.value < 1) {
    stockError.value = 'Ingresa una cantidad válida.'
    return
  }
  stockLoading.value = true
  try {
    await addStock({
      producto_id: itemGestionar.value.producto_id,
      tienda_id: tiendaId.value,
      cantidad: nuevoStock.value,
      motivo: stockMotivo.value || undefined,
    })
    mostrarGestionar.value = false
    await cargarInventario()
  } catch (e) {
    stockError.value = e.response?.data?.message ?? 'Error al agregar stock.'
  } finally {
    stockLoading.value = false
  }
}

onMounted(async () => {
  await cargarTiendas()
  if (auth.usuario?.tienda_default_id) {
    tiendaId.value = auth.usuario.tienda_default_id
    await cargarInventario()
    setupObserver()
  }
})
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Inventario</h2>
    </div>

    <!-- Selector de tienda -->
    <div>
      <label class="block text-xs font-medium text-gray-500 mb-1">Tienda</label>
      <select
        v-model="tiendaId"
        @change="cargarInventario(true)"
        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      >
        <option value="">Seleccionar tienda...</option>
        <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
      </select>
    </div>

    <!-- Buscador -->
    <div v-if="tiendaId" class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="cargarInventario(true)"
        placeholder="Buscar por nombre o categoría..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Sin tienda seleccionada -->
    <EmptyState
      v-if="!tiendaId"
      message="Selecciona una tienda para ver el inventario."
      icon="ArchiveBoxIcon"
    />

    <!-- Loading -->
    <div v-else-if="loading" class="text-center py-12 text-gray-400">Cargando...</div>

    <!-- Empty -->
    <EmptyState
      v-else-if="inventario.length === 0"
      message="No hay productos en esta tienda."
    />

    <!-- Lista -->
    <template v-else>
      <ul class="space-y-2">
        <li
          v-for="item in paginaVisible"
          :key="item.id"
          class="bg-white rounded-xl shadow-sm p-4 space-y-2"
        >
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800 truncate">{{ item.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.producto?.categoria }}</p>
            </div>
            <button
              @click="openGestionar(item)"
              class="text-blue-600 text-xs font-medium flex items-center gap-1 flex-shrink-0 ml-2"
            >
              <PencilIcon class="w-4 h-4" />
              Gestionar
            </button>
          </div>

          <!-- Stock -->
          <div class="grid grid-cols-4 gap-2 text-center">
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-800">{{ item.cantidad_disponible }}</p>
              <p class="text-xs text-gray-400">Disponible</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-500">{{ item.cantidad_reservada }}</p>
              <p class="text-xs text-gray-400">Reservado</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-green-600">{{ item.stock_libre }}</p>
              <p class="text-xs text-gray-400">Libre</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-1.5">
              <p class="text-lg font-bold text-gray-600">{{ item.stock_minimo }}</p>
              <p class="text-xs text-gray-400">Mínimo</p>
            </div>
          </div>

          <!-- Precio y badge bajo stock -->
          <div class="flex justify-between items-center">
            <MoneyDisplay :amount="parseFloat(item.producto?.precio_base ?? 0)" bold />
            <span
              v-if="item.bajo_stock"
              class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700"
            >
              <ExclamationTriangleIcon class="w-3.5 h-3.5" />
              Bajo stock
            </span>
          </div>
        </li>
      </ul>

      <!-- Sentinel scroll infinito -->
      <div ref="sentinel" class="py-4 text-center">
        <div v-if="tieneMas" class="text-sm text-gray-400">Cargando más...</div>
        <div v-else-if="inventario.length > 0" class="text-xs text-gray-300">
          Mostrando {{ inventario.length }} productos
        </div>
      </div>
    </template>

    <!-- Modal Gestionar -->
    <Transition name="fade">
      <div v-if="mostrarGestionar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarGestionar = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-4">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Gestionar producto</h3>
            <button @click="mostrarGestionar = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <p class="text-sm font-medium text-gray-800">{{ itemGestionar?.producto?.nombre }}</p>

          <!-- Cambiar precio -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Precio base</label>
            <input
              v-model.number="nuevoPrecio"
              type="number"
              min="0"
              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <p v-if="gestionError" class="text-xs text-red-600 mt-1">{{ gestionError }}</p>
            <button
              @click="guardarPrecio"
              :disabled="gestionLoading"
              class="mt-2 w-full bg-blue-600 text-white rounded-lg py-2 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
            >
              {{ gestionLoading ? 'Guardando...' : 'Actualizar precio' }}
            </button>
          </div>

          <div class="border-t border-gray-100 my-2" />

          <!-- Agregar stock -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Agregar stock</label>
            <div class="flex gap-2">
              <input
                v-model.number="nuevoStock"
                type="number"
                min="1"
                class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                placeholder="Cantidad"
              />
              <button
                @click="guardarStock"
                :disabled="stockLoading"
                class="bg-green-600 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-green-700 disabled:opacity-50 flex items-center gap-1"
              >
                <PlusIcon class="w-4 h-4" />
                Agregar
              </button>
            </div>
            <input
              v-model="stockMotivo"
              class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
              placeholder="Motivo (opcional)"
            />
            <p v-if="stockError" class="text-xs text-red-600 mt-1">{{ stockError }}</p>
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
