<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/api'
import { SparklesIcon, XMarkIcon } from '@heroicons/vue/24/solid'

const router = useRouter()
const auth   = useAuthStore()

// ── Pasos ─────────────────────────────────────────────────────────────────────
const step = ref(1)

// ── Tiendas ───────────────────────────────────────────────────────────────────
const tiendas = ref([])
onMounted(async () => {
  const { data } = await api.get('/tiendas')
  tiendas.value = data
})

// ── Paso 1: Cliente ───────────────────────────────────────────────────────────
const clienteQuery     = ref('')
const clienteResultados = ref([])
const clienteSeleccionado = ref(null)
const buscandoCliente   = ref(false)
const modoNuevoCliente  = ref(false)
const nuevoCliente = ref({ nombre: '', cedula: '', telefono: '', email: '', direccion: '' })
const creandoCliente = ref(false)
const errCliente = ref('')

async function buscarCliente() {
  if (!clienteQuery.value.trim()) return
  buscandoCliente.value = true
  try {
    const { data } = await api.get('/clientes', { params: { search: clienteQuery.value } })
    clienteResultados.value = data
  } finally {
    buscandoCliente.value = false
  }
}

function seleccionarCliente(c) {
  clienteSeleccionado.value = c
  clienteResultados.value   = []
  clienteQuery.value        = c.nombre
}

async function crearCliente() {
  errCliente.value  = ''
  creandoCliente.value = true
  try {
    const { data } = await api.post('/clientes', nuevoCliente.value)
    seleccionarCliente(data)
    modoNuevoCliente.value = false
    nuevoCliente.value = { nombre: '', cedula: '', telefono: '', email: '', direccion: '' }
  } catch (e) {
    errCliente.value = e.response?.data?.message ?? 'Error al crear cliente'
  } finally {
    creandoCliente.value = false
  }
}

// ── Paso 1: Tienda + Canal ────────────────────────────────────────────────────
const tiendaId = ref(auth.usuario?.tienda_default_id ?? '')
const canal    = ref('fisica')

const canalesopts = [
  { value: 'fisica',     label: 'Física' },
  { value: 'whatsapp',   label: 'WhatsApp' },
  { value: 'red_social', label: 'Red social' },
  { value: 'otro',       label: 'Otro' },
]

function paso1Valido() {
  return clienteSeleccionado.value && tiendaId.value && canal.value
}

// ── Paso 2: Productos / Carrito ───────────────────────────────────────────────
const productoQuery     = ref('')
const productoResultados = ref([])
const buscandoProducto   = ref(false)
const items = ref([])

async function buscarProducto() {
  if (!productoQuery.value.trim()) return
  buscandoProducto.value = true
  try {
    const { data } = await api.get('/productos', {
      params: { search: productoQuery.value, tienda_id: tiendaId.value },
    })
    productoResultados.value = data
  } finally {
    buscandoProducto.value = false
  }
}

function stockLibre(p) {
  return (p.stock_disponible ?? 0) - (p.stock_reservado ?? 0)
}

function agregarItem(producto) {
  const existe = items.value.find((i) => i.producto_id === producto.id)
  if (existe) {
    existe.cantidad++
    return
  }
  items.value.push({
    producto_id:           producto.id,
    nombre:                producto.nombre,
    categoria:             producto.categoria,
    stock_libre:           stockLibre(producto),
    personalizable:        producto.personalizable ?? false,
    cantidad:              1,
    precio_unitario:       producto.precio_base ?? 0,
    es_personalizado:      false,
    specs_descripcion:     '',
  })
  productoResultados.value = []
  productoQuery.value = ''
}

function quitarItem(idx) {
  items.value.splice(idx, 1)
}

// ── Paso 3: Pago ──────────────────────────────────────────────────────────────
const anticipo_pct         = ref(50)
const anticipo_monto       = ref(0)
const anticipo_metodo      = ref('efectivo')
const anticipo_referencia  = ref('')
const notas                = ref('')
const submitting           = ref(false)
const errSubmit            = ref('')

const metodosOpts = [
  { value: 'efectivo',      label: 'Efectivo' },
  { value: 'transferencia', label: 'Transferencia' },
  { value: 'tarjeta',       label: 'Tarjeta' },
  { value: 'otro',          label: 'Otro' },
]

const valorTotal = computed(() =>
  items.value.reduce((s, i) => s + i.cantidad * i.precio_unitario, 0)
)

const minimoAnticipo = computed(() =>
  Math.ceil(valorTotal.value * anticipo_pct.value / 100)
)

function irAPaso3() {
  anticipo_monto.value = minimoAnticipo.value
  step.value = 3
}

async function submit() {
  errSubmit.value = ''
  submitting.value = true
  try {
    const payload = {
      cliente_id:           clienteSeleccionado.value.id,
      tienda_id:            tiendaId.value,
      canal:                canal.value,
      anticipo_pct:         anticipo_pct.value,
      anticipo_monto:       anticipo_monto.value,
      anticipo_metodo:      anticipo_metodo.value,
      anticipo_referencia:  anticipo_referencia.value || undefined,
      notas:                notas.value || undefined,
      items: items.value.map((i) => ({
        producto_id:           i.producto_id,
        cantidad:              i.cantidad,
        precio_unitario:       i.precio_unitario,
        es_personalizado:      i.es_personalizado,
        specs_personalizacion: i.es_personalizado && i.specs_descripcion
          ? { descripcion: i.specs_descripcion }
          : undefined,
      })),
    }

    const { data } = await api.post('/ordenes', payload)
    router.push({ name: 'ordenes' })
  } catch (e) {
    errSubmit.value = e.response?.data?.message ?? 'Error al crear la orden'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="p-4 max-w-lg mx-auto space-y-4 pb-8">

    <!-- Cabecera + progreso -->
    <div class="flex items-center gap-3">
      <button
        v-if="step > 1"
        @click="step--"
        class="text-blue-600 text-sm font-medium"
      >← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">Nueva Orden</h2>
      <span class="text-xs text-gray-400">{{ step }}/3</span>
    </div>

    <!-- Barra de pasos -->
    <div class="flex gap-1">
      <div v-for="n in 3" :key="n"
        :class="['h-1 flex-1 rounded-full transition-colors',
          n <= step ? 'bg-blue-600' : 'bg-gray-200']"
      />
    </div>

    <!-- ═══════════════════════════════════════════════════════ PASO 1 ══ -->
    <template v-if="step === 1">

      <!-- Tienda -->
      <div>
        <label class="label">Tienda</label>
        <select v-model="tiendaId" class="input">
          <option value="">Seleccionar...</option>
          <option v-for="t in tiendas" :key="t.id" :value="t.id">{{ t.nombre }}</option>
        </select>
      </div>

      <!-- Canal -->
      <div>
        <label class="label">Canal de venta</label>
        <div class="flex gap-2 flex-wrap">
          <button
            v-for="c in canalesopts"
            :key="c.value"
            @click="canal = c.value"
            :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
              canal === c.value
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >{{ c.label }}</button>
        </div>
      </div>

      <!-- Búsqueda de cliente -->
      <div>
        <label class="label">Cliente</label>
        <div class="flex gap-2">
          <input
            v-model="clienteQuery"
            @keyup.enter="buscarCliente"
            placeholder="Nombre, cédula o teléfono..."
            class="input flex-1"
            :disabled="!!clienteSeleccionado"
          />
          <button
            v-if="!clienteSeleccionado"
            @click="buscarCliente"
            :disabled="buscandoCliente"
            class="btn-primary px-3"
          >Buscar</button>
          <button
            v-else
            @click="clienteSeleccionado = null; clienteQuery = ''"
            class="text-xs text-red-500 font-medium px-2"
          >Cambiar</button>
        </div>

        <!-- Resultados -->
        <ul v-if="clienteResultados.length" class="mt-1 bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
          <li
            v-for="c in clienteResultados"
            :key="c.id"
            @click="seleccionarCliente(c)"
            class="px-4 py-3 hover:bg-blue-50 cursor-pointer flex justify-between"
          >
            <span class="font-medium text-sm text-gray-800">{{ c.nombre }}</span>
            <span class="text-xs text-gray-400">{{ c.telefono }}</span>
          </li>
        </ul>

        <!-- Sin resultados -->
        <div
          v-else-if="clienteQuery && !buscandoCliente && !clienteSeleccionado && clienteResultados.length === 0"
          class="mt-2 text-sm text-gray-500"
        >
          No encontrado.
          <button @click="modoNuevoCliente = true" class="text-blue-600 font-medium ml-1">Crear nuevo</button>
        </div>

        <!-- Cliente seleccionado -->
        <div v-if="clienteSeleccionado" class="mt-2 bg-blue-50 rounded-lg px-3 py-2 text-sm">
          <span class="font-semibold text-blue-700">{{ clienteSeleccionado.nombre }}</span>
          <span class="text-blue-500 ml-2">{{ clienteSeleccionado.telefono }}</span>
        </div>
      </div>

      <!-- Formulario nuevo cliente -->
      <div v-if="modoNuevoCliente" class="bg-gray-50 rounded-xl p-4 space-y-3">
        <p class="text-sm font-semibold text-gray-700">Nuevo cliente</p>
        <input v-model="nuevoCliente.nombre"    class="input" placeholder="Nombre completo *" />
        <input v-model="nuevoCliente.cedula"    class="input" placeholder="Cédula" />
        <input v-model="nuevoCliente.telefono"  class="input" placeholder="Teléfono" type="tel" />
        <input v-model="nuevoCliente.email"     class="input" placeholder="Email" type="email" />
        <input v-model="nuevoCliente.direccion" class="input" placeholder="Dirección" />
        <p v-if="errCliente" class="text-xs text-red-600">{{ errCliente }}</p>
        <div class="flex gap-2">
          <button @click="modoNuevoCliente = false" class="btn-secondary flex-1">Cancelar</button>
          <button @click="crearCliente" :disabled="creandoCliente || !nuevoCliente.nombre" class="btn-primary flex-1">
            {{ creandoCliente ? 'Guardando...' : 'Guardar' }}
          </button>
        </div>
      </div>

      <button
        @click="step = 2"
        :disabled="!paso1Valido()"
        class="btn-primary w-full mt-2"
      >Continuar → Productos</button>
    </template>

    <!-- ═══════════════════════════════════════════════════════ PASO 2 ══ -->
    <template v-else-if="step === 2">

      <!-- Buscador de productos -->
      <div class="flex gap-2">
        <input
          v-model="productoQuery"
          @keyup.enter="buscarProducto"
          placeholder="Buscar producto..."
          class="input flex-1"
        />
        <button @click="buscarProducto" :disabled="buscandoProducto" class="btn-primary px-3">
          Buscar
        </button>
      </div>

      <!-- Resultados de productos -->
      <ul v-if="productoResultados.length" class="space-y-2">
        <li
          v-for="p in productoResultados"
          :key="p.id"
          class="bg-white rounded-xl shadow-sm p-3 flex justify-between items-center"
        >
          <div class="flex-1 min-w-0">
            <p class="font-medium text-sm text-gray-800 truncate">{{ p.nombre }}</p>
            <p class="text-xs text-gray-400">{{ p.categoria }}</p>
            <p class="text-xs mt-0.5"
              :class="stockLibre(p) > 0 ? 'text-green-600' : 'text-orange-500'"
            >
              Stock libre: {{ stockLibre(p) }}
              <span v-if="p.personalizable" class="ml-2 text-purple-500 flex items-center gap-0.5 inline-flex"><SparklesIcon class="w-3 h-3" /> personalizable</span>
            </p>
          </div>
          <div class="ml-3 flex flex-col items-end gap-1">
            <span class="text-sm font-semibold text-gray-700">
              ${{ Number(p.precio_base).toLocaleString('es-CO') }}
            </span>
            <button
              @click="agregarItem(p)"
              :disabled="!p.personalizable && stockLibre(p) === 0"
              class="btn-primary text-xs px-2 py-1 disabled:opacity-40"
            >+ Agregar</button>
          </div>
        </li>
      </ul>

      <!-- Carrito -->
      <div v-if="items.length" class="space-y-3">
        <p class="text-sm font-semibold text-gray-600">Carrito ({{ items.length }} ítem{{ items.length > 1 ? 's' : '' }})</p>

        <div
          v-for="(item, idx) in items"
          :key="idx"
          class="bg-white rounded-xl shadow-sm p-3 space-y-2"
        >
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800 truncate">{{ item.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.categoria }}</p>
            </div>
            <button @click="quitarItem(idx)" class="text-red-400 hover:text-red-600 ml-2"><XMarkIcon class="w-5 h-5" /></button>
          </div>

          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="text-xs text-gray-500">Cantidad</label>
              <input
                v-model.number="item.cantidad"
                type="number" min="1"
                :max="item.es_personalizado ? undefined : item.stock_libre"
                class="input text-sm"
              />
            </div>
            <div>
              <label class="text-xs text-gray-500">Precio unitario</label>
              <input
                v-model.number="item.precio_unitario"
                type="number" min="0"
                class="input text-sm"
              />
            </div>
          </div>

          <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
            <input type="checkbox" v-model="item.es_personalizado" class="rounded" />
            Ítem personalizado
          </label>

          <textarea
            v-if="item.es_personalizado"
            v-model="item.specs_descripcion"
            placeholder="Descripción de personalización (tela, medidas, color...)"
            rows="2"
            class="input text-sm resize-none"
          />

          <p class="text-xs text-right text-gray-500">
            Subtotal: <strong class="text-gray-800">
              ${{ (item.cantidad * item.precio_unitario).toLocaleString('es-CO') }}
            </strong>
          </p>
        </div>

        <!-- Total -->
        <div class="bg-blue-50 rounded-xl px-4 py-3 flex justify-between items-center">
          <span class="font-semibold text-gray-700">Total</span>
          <span class="text-lg font-bold text-blue-700">${{ valorTotal.toLocaleString('es-CO') }}</span>
        </div>
      </div>

      <div v-else class="text-center py-6 text-gray-400 text-sm">
        Busca y agrega productos al carrito.
      </div>

      <button
        @click="irAPaso3"
        :disabled="items.length === 0"
        class="btn-primary w-full"
      >Continuar → Pago</button>
    </template>

    <!-- ═══════════════════════════════════════════════════════ PASO 3 ══ -->
    <template v-else-if="step === 3">

      <!-- Resumen de orden -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-1">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Resumen</p>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Cliente</span>
          <span class="font-medium text-gray-800">{{ clienteSeleccionado.nombre }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Tienda</span>
          <span class="font-medium text-gray-800">{{ tiendas.find(t => t.id == tiendaId)?.nombre }}</span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-600">Ítems</span>
          <span class="font-medium text-gray-800">{{ items.length }}</span>
        </div>
        <div class="flex justify-between text-sm font-bold border-t border-gray-100 pt-2 mt-2">
          <span>Total</span>
          <span class="text-blue-700">${{ valorTotal.toLocaleString('es-CO') }}</span>
        </div>
      </div>

      <!-- Anticipo % -->
      <div>
        <label class="label">Porcentaje mínimo anticipo</label>
        <div class="flex gap-2">
          <button v-for="pct in [30, 50, 70, 100]" :key="pct"
            @click="anticipo_pct = pct; anticipo_monto = minimoAnticipo"
            :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
              anticipo_pct === pct
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >{{ pct }}%</button>
        </div>
      </div>

      <!-- Anticipo monto -->
      <div>
        <label class="label">
          Monto anticipo
          <span class="text-gray-400 font-normal ml-1">(mínimo ${{ minimoAnticipo.toLocaleString('es-CO') }})</span>
        </label>
        <input
          v-model.number="anticipo_monto"
          type="number"
          :min="minimoAnticipo"
          class="input"
        />
      </div>

      <!-- Método pago -->
      <div>
        <label class="label">Método de pago</label>
        <div class="flex gap-2 flex-wrap">
          <button
            v-for="m in metodosOpts"
            :key="m.value"
            @click="anticipo_metodo = m.value"
            :class="['px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors',
              anticipo_metodo === m.value
                ? 'bg-blue-600 text-white border-blue-600'
                : 'bg-white text-gray-700 border-gray-300']"
          >{{ m.label }}</button>
        </div>
      </div>

      <!-- Referencia (para transferencia/tarjeta) -->
      <div v-if="anticipo_metodo !== 'efectivo'">
        <label class="label">Referencia / número transacción</label>
        <input v-model="anticipo_referencia" class="input" placeholder="Opcional" />
      </div>

      <!-- Notas -->
      <div>
        <label class="label">Notas (opcional)</label>
        <textarea v-model="notas" rows="2" class="input resize-none" placeholder="Observaciones de la orden..." />
      </div>

      <p v-if="errSubmit" class="text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ errSubmit }}</p>

      <button
        @click="submit"
        :disabled="submitting || anticipo_monto < minimoAnticipo"
        class="btn-primary w-full text-base py-3"
      >
        {{ submitting ? 'Guardando...' : 'Crear orden' }}
      </button>
    </template>

  </div>
</template>

<style scoped>
.label {
  display: block;
  font-size: 0.875rem;
  font-weight: 500;
  color: #374151;
  margin-bottom: 0.25rem;
}
.input {
  width: 100%;
  border-radius: 0.5rem;
  border: 1px solid #d1d5db;
  padding: 0.5rem 0.75rem;
  font-size: 0.875rem;
  background: white;
}
.input:focus {
  outline: none;
  --tw-ring-shadow: 0 0 0 2px rgba(59, 130, 246, 0.5);
  box-shadow: var(--tw-ring-shadow);
}
.btn-primary {
  background: #2563eb;
  color: white;
  border-radius: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 600;
  transition: background-color 0.15s;
}
.btn-primary:hover {
  background: #1d4ed8;
}
.btn-primary:disabled {
  opacity: 0.5;
}
.btn-secondary {
  background: #f3f4f6;
  color: #374151;
  border-radius: 0.5rem;
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
  font-weight: 600;
  transition: background-color 0.15s;
}
.btn-secondary:hover {
  background: #e5e7eb;
}
</style>
