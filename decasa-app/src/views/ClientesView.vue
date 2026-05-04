<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  MagnifyingGlassIcon,
  ChevronRightIcon,
  PhoneIcon,
  IdentificationIcon,
  PlusIcon,
} from '@heroicons/vue/24/outline'
import { getClientes, createCliente } from '@/api/clientes'
import EmptyState from '@/components/common/EmptyState.vue'

const router = useRouter()

const clientes = ref([])
const loading  = ref(true)
const busqueda = ref('')

const mostrarCrear = ref(false)
const formError = ref('')
const creando = ref(false)
const nuevo = ref({
  nombre: '',
  cedula: '',
  telefono: '',
  email: '',
  direccion: '',
  canal_pref: '',
})

const canalesOpts = [
  { value: '', label: 'Sin definir' },
  { value: 'fisica', label: 'Física' },
  { value: 'whatsapp', label: 'WhatsApp' },
  { value: 'red_social', label: 'Red social' },
  { value: 'otro', label: 'Otro' },
]

async function buscar() {
  loading.value = true
  try {
    const { data } = await getClientes(busqueda.value)
    clientes.value = data
  } finally {
    loading.value = false
  }
}

function goToCliente(id) {
  router.push({ name: 'cliente-detalle', params: { id } })
}

function abrirCrear() {
  formError.value = ''
  nuevo.value = { nombre: '', cedula: '', telefono: '', email: '', direccion: '', canal_pref: '' }
  mostrarCrear.value = true
}

async function guardar() {
  formError.value = ''
  if (!nuevo.value.nombre.trim()) {
    formError.value = 'El nombre es obligatorio.'
    return
  }
  creando.value = true
  try {
    await createCliente(nuevo.value)
    mostrarCrear.value = false
    await buscar()
  } catch (e) {
    const msgs = e.response?.data?.message
    formError.value = typeof msgs === 'string' ? msgs : Object.values(msgs).flat().join(' ')
  } finally {
    creando.value = false
  }
}

onMounted(buscar)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-3 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-2">
      <h2 class="text-lg font-bold text-gray-800 flex-1">Clientes</h2>
      <button
        @click="abrirCrear"
        class="text-sm text-blue-600 font-medium px-3 py-1.5 rounded-lg border border-blue-200 hover:bg-blue-50 transition-colors flex items-center gap-1"
      >
        <PlusIcon class="w-4 h-4" />
        Crear
      </button>
    </div>

    <!-- Buscador -->
    <div class="relative">
      <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
      <input
        v-model="busqueda"
        @keyup.enter="buscar"
        placeholder="Buscar por nombre, cédula o teléfono..."
        class="w-full rounded-lg border border-gray-300 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
      />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Cargando...</div>

    <!-- Empty -->
    <EmptyState
      v-else-if="busqueda && clientes.length === 0"
      message="No se encontraron clientes."
    />

    <EmptyState
      v-else-if="!busqueda && clientes.length === 0"
      message="No hay clientes registrados."
    />

    <!-- Lista -->
    <ul v-else class="space-y-2">
      <li
        v-for="c in clientes"
        :key="c.id"
        @click="goToCliente(c.id)"
        class="bg-white rounded-xl shadow-sm p-4 flex items-center gap-3 cursor-pointer hover:bg-blue-50 transition-colors active:bg-blue-100"
      >
        <div class="flex-1 min-w-0">
          <p class="font-medium text-gray-800 truncate">{{ c.nombre }}</p>
          <div class="flex items-center gap-3 mt-1">
            <span v-if="c.cedula" class="flex items-center gap-1 text-xs text-gray-400">
              <IdentificationIcon class="w-3.5 h-3.5" />
              {{ c.cedula }}
            </span>
            <span v-if="c.telefono" class="flex items-center gap-1 text-xs text-gray-400">
              <PhoneIcon class="w-3.5 h-3.5" />
              {{ c.telefono }}
            </span>
          </div>
        </div>
        <ChevronRightIcon class="w-5 h-5 text-gray-300 flex-shrink-0" />
      </li>
    </ul>

    <!-- Modal crear cliente -->
    <Transition name="fade">
      <div v-if="mostrarCrear" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center" @click.self="mostrarCrear = false">
        <div class="absolute inset-0 bg-black/40" />
        <div class="relative bg-white rounded-t-2xl sm:rounded-2xl w-full sm:max-w-md p-5 space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800">Nuevo cliente</h3>
            <button @click="mostrarCrear = false" class="text-gray-400 text-2xl leading-none">&times;</button>
          </div>

          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Nombre *</label>
            <input v-model="nuevo.nombre" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Nombre completo" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Cédula</label>
            <input v-model="nuevo.cedula" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Número de cédula" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Teléfono</label>
            <input v-model="nuevo.telefono" type="tel" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Teléfono de contacto" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Email</label>
            <input v-model="nuevo.email" type="email" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="correo@ejemplo.com" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Dirección</label>
            <input v-model="nuevo.direccion" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Dirección" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Canal preferido</label>
            <select v-model="nuevo.canal_pref" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
              <option v-for="c in canalesOpts" :key="c.value" :value="c.value">{{ c.label }}</option>
            </select>
          </div>

          <p v-if="formError" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ formError }}</p>

          <div class="flex gap-3 pt-1">
            <button @click="mostrarCrear = false" class="flex-1 bg-gray-100 text-gray-700 rounded-lg py-2.5 text-sm font-semibold hover:bg-gray-200">Cancelar</button>
            <button
              @click="guardar"
              :disabled="creando"
              class="flex-1 bg-blue-600 text-white rounded-lg py-2.5 text-sm font-semibold hover:bg-blue-700 disabled:opacity-50"
            >
              {{ creando ? 'Guardando...' : 'Guardar' }}
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
