<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { getOrden, updateEstado } from '@/api/ordenes'
import BadgeEstado from '@/components/common/BadgeEstado.vue'
import MoneyDisplay from '@/components/common/MoneyDisplay.vue'
import RegistroPagoModal from '@/components/ordenes/RegistroPagoModal.vue'
import { SparklesIcon } from '@heroicons/vue/24/solid'

const route = useRoute()
const router = useRouter()

const orden = ref(null)
const loading = ref(true)
const error = ref('')
const showPagoModal = ref(false)
const changingEstado = ref(false)
const estadoError = ref('')

const transicionesValidas = {
  pendiente_anticipo: ['en_produccion', 'cancelado'],
  en_produccion: ['listo_entrega', 'cancelado'],
  listo_entrega: ['entregado', 'cancelado'],
  entregado: [],
  cancelado: [],
}

const nuevoEstado = ref('')

const estadosLabel = {
  pendiente_anticipo: 'Pendiente anticipo',
  en_produccion: 'En producción',
  listo_entrega: 'Listo entrega',
  entregado: 'Entregado',
  cancelado: 'Cancelado',
}

const porcentajePagado = computed(() => {
  if (!orden.value || !orden.value.valor_total) return 0
  return Math.min(100, Math.round((orden.value.total_pagado / orden.value.valor_total) * 100))
})

const puedeCambiarEstado = computed(() => {
  return orden.value && !['entregado', 'cancelado'].includes(orden.value.estado)
})

const puedeRegistrarPago = computed(() => {
  return orden.value && !['entregado', 'cancelado'].includes(orden.value.estado) && orden.value.saldo_pendiente > 0
})

const opcionesNuevoEstado = computed(() => {
  if (!orden.value) return []
  return (transicionesValidas[orden.value.estado] ?? []).map((e) => ({
    value: e,
    label: estadosLabel[e] ?? e,
  }))
})

async function cargarOrden() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await getOrden(route.params.id)
    orden.value = data
    nuevoEstado.value = ''
    estadoError.value = ''
  } catch (e) {
    error.value = e.response?.data?.message ?? 'No se pudo cargar la orden.'
  } finally {
    loading.value = false
  }
}

async function cambiarEstado() {
  if (!nuevoEstado.value) return
  changingEstado.value = true
  estadoError.value = ''
  try {
    await updateEstado(orden.value.id, nuevoEstado.value)
    await cargarOrden()
  } catch (e) {
    estadoError.value = e.response?.data?.message ?? 'Error al cambiar el estado.'
  } finally {
    changingEstado.value = false
  }
}

function onPagoRegistrado() {
  cargarOrden()
}

function formatFecha(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr + 'T00:00:00')
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatDateTime(dateStr) {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const tipoPagoLabel = {
  anticipo: 'Anticipo',
  abono: 'Abono',
  saldo_final: 'Saldo final',
}

onMounted(cargarOrden)
</script>

<template>
  <div class="p-4 max-w-2xl mx-auto space-y-4 pb-8">
    <!-- Header -->
    <div class="flex items-center gap-3">
      <button @click="router.back()" class="text-blue-600 text-sm font-medium">← Atrás</button>
      <h2 class="text-lg font-bold text-gray-800 flex-1">
        Orden #{{ orden?.id ?? '...' }}
      </h2>
      <BadgeEstado v-if="orden" :estado="orden.estado" />
    </div>

    <!-- Loading -->
    <div v-if="loading" class="text-center py-12 text-gray-400">Cargando...</div>

    <!-- Error -->
    <div v-else-if="error" class="bg-red-50 rounded-xl px-4 py-3 text-sm text-red-600">
      {{ error }}
    </div>

    <template v-else-if="orden">
      <!-- Info general -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-2 text-sm">
        <p class="text-xs font-semibold text-gray-500 uppercase mb-2">Información general</p>
        <div class="flex justify-between">
          <span class="text-gray-500">Cliente</span>
          <span class="font-medium text-gray-800">{{ orden.cliente?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Tienda</span>
          <span class="font-medium text-gray-800">{{ orden.tienda?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Vendedor</span>
          <span class="font-medium text-gray-800">{{ orden.vendedor?.nombre }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Canal</span>
          <span class="font-medium text-gray-800 capitalize">{{ orden.canal }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-500">Fecha</span>
          <span class="font-medium text-gray-800">{{ formatDateTime(orden.created_at) }}</span>
        </div>
        <div v-if="orden.notas" class="flex justify-between">
          <span class="text-gray-500">Notas</span>
          <span class="font-medium text-gray-800 text-right max-w-[60%]">{{ orden.notas }}</span>
        </div>
      </div>

      <!-- Progreso de pago -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Pago</p>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Total</span>
          <MoneyDisplay :amount="orden.valor_total" bold />
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Pagado</span>
          <span class="font-medium text-green-600"><MoneyDisplay :amount="orden.total_pagado" /></span>
        </div>
        <div class="flex justify-between text-sm">
          <span class="text-gray-500">Saldo</span>
          <span class="font-bold text-red-600"><MoneyDisplay :amount="orden.saldo_pendiente" /></span>
        </div>
        <!-- Barra progreso -->
        <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
          <div
            class="h-full rounded-full transition-all duration-500"
            :class="porcentajePagado >= 100 ? 'bg-green-500' : 'bg-blue-500'"
            :style="{ width: porcentajePagado + '%' }"
          />
        </div>
        <p class="text-xs text-gray-400 text-right">{{ porcentajePagado }}% pagado</p>
      </div>

      <!-- Ítems -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Ítems ({{ orden.items?.length ?? 0 }})</p>
        <div
          v-for="(item, idx) in orden.items"
          :key="idx"
          class="border-b border-gray-100 last:border-0 pb-3 last:pb-0"
        >
          <div class="flex justify-between items-start">
            <div class="flex-1 min-w-0">
              <p class="font-medium text-sm text-gray-800">{{ item.producto?.nombre }}</p>
              <p class="text-xs text-gray-400">{{ item.producto?.categoria }}</p>
              <p class="text-xs text-gray-500 mt-0.5">Cantidad: {{ item.cantidad }}</p>
               <p v-if="item.es_personalizado" class="text-xs text-purple-600 mt-1 flex items-center gap-1">
                <SparklesIcon class="w-3.5 h-3.5" /> Personalizado
                <span v-if="item.specs_personalizacion?.descripcion">
                  — {{ item.specs_personalizacion.descripcion }}
                </span>
              </p>
              <p v-if="item.fecha_entrega_prom" class="text-xs text-gray-500 mt-0.5">
                Entrega estimada: {{ formatFecha(item.fecha_entrega_prom) }}
              </p>
            </div>
            <div class="text-right ml-3">
              <p class="text-xs text-gray-500"><MoneyDisplay :amount="item.precio_unitario" /></p>
              <p class="text-sm font-semibold text-gray-700"><MoneyDisplay :amount="item.cantidad * item.precio_unitario" /></p>
            </div>
          </div>
        </div>
      </div>

      <!-- Historial de pagos -->
      <div class="bg-white rounded-xl shadow-sm p-4 space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Historial de pagos ({{ orden.pagos?.length ?? 0 }})</p>
        <div v-if="orden.pagos?.length === 0" class="text-sm text-gray-400 text-center py-4">
          No hay pagos registrados.
        </div>
        <div
          v-for="pago in orden.pagos"
          :key="pago.id"
          class="flex justify-between items-center border-b border-gray-100 last:border-0 pb-2 last:pb-0"
        >
          <div>
            <p class="text-sm font-medium text-gray-800">
              {{ tipoPagoLabel[pago.tipo] ?? pago.tipo }}
            </p>
            <p class="text-xs text-gray-400 capitalize">{{ pago.metodo }}
              <span v-if="pago.referencia">· {{ pago.referencia }}</span>
            </p>
            <p v-if="pago.notas" class="text-xs text-gray-400">{{ pago.notas }}</p>
          </div>
          <div class="text-right">
            <p class="text-sm font-semibold text-green-600"><MoneyDisplay :amount="pago.monto" /></p>
            <p class="text-xs text-gray-400">{{ formatDateTime(pago.created_at) }}</p>
          </div>
        </div>
      </div>

      <!-- Acciones -->
      <div v-if="puedeCambiarEstado || puedeRegistrarPago" class="space-y-3">
        <p class="text-xs font-semibold text-gray-500 uppercase">Acciones</p>

        <!-- Registrar pago -->
        <button
          v-if="puedeRegistrarPago"
          @click="showPagoModal = true"
          class="w-full bg-blue-600 text-white rounded-xl py-3 text-sm font-semibold hover:bg-blue-700 transition-colors"
        >
          Registrar pago
        </button>

        <!-- Cambiar estado -->
        <div v-if="puedeCambiarEstado" class="space-y-2">
          <div class="flex gap-2">
            <select
              v-model="nuevoEstado"
              class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">Cambiar estado...</option>
              <option v-for="opt in opcionesNuevoEstado" :key="opt.value" :value="opt.value">
                {{ opt.label }}
              </option>
            </select>
            <button
              @click="cambiarEstado"
              :disabled="!nuevoEstado || changingEstado"
              class="bg-gray-800 text-white rounded-lg px-4 py-2 text-sm font-semibold hover:bg-gray-900 disabled:opacity-50 transition-colors"
            >Aplicar</button>
          </div>
          <p v-if="estadoError" class="text-xs text-red-600 bg-red-50 rounded-lg px-3 py-2">{{ estadoError }}</p>
        </div>
      </div>
    </template>

    <!-- Modal de pago -->
    <RegistroPagoModal
      v-if="orden"
      :show="showPagoModal"
      :orden-id="orden.id"
      :valor-total="orden.valor_total"
      :saldo-pendiente="orden.saldo_pendiente"
      @close="showPagoModal = false"
      @pago-registrado="onPagoRegistrado"
    />
  </div>
</template>
