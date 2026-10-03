<script setup>
defineProps({
  padded: { type: Boolean, default: true },
})
</script>

<template>
  <div
      :class="[
        'bg-panel border border-border rounded-2xl shadow-xl shadow-black/30',
        padded ? 'p-5' : '',
      ]"
  >
    <slot />
  </div>
</template>
