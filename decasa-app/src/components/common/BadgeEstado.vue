<script setup>
const props = defineProps({
  estado: {
    type: String,
    required: true,
  },
})

const map = {
  pendiente_anticipo: { label: 'Pendiente anticipo', cls: 'bg-yellow-100 text-yellow-800' },
  en_produccion: { label: 'En producción', cls: 'bg-blue-100 text-blue-800' },
  listo_entrega: { label: 'Listo entrega', cls: 'bg-emerald-100 text-emerald-800' },
  entregado: { label: 'Entregado', cls: 'bg-gray-100 text-gray-600' },
  cancelado: { label: 'Cancelado', cls: 'bg-red-100 text-red-800' },
}

const cfg = map[props.estado] ?? { label: props.estado, cls: 'bg-gray-100 text-gray-600' }
</script>

<template>
  <span :class="['inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium', cfg.cls]">
    {{ cfg.label }}
  </span>
</template>
