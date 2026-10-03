<script setup>
defineProps({
  title: { type: String, default: '' },
  maxWidth: { type: String, default: 'max-w-lg' },
})
const emit = defineEmits(['close'])
</script>

<template>
  <div
      class="fixed inset-0 z-50 flex items-start sm:items-center justify-center bg-black/60 backdrop-blur-sm p-4 overflow-y-auto"
      @click.self="emit('close')"
  >
    <div
        :class="[
          'w-full bg-panel border border-border rounded-2xl shadow-2xl shadow-black/50 animate-scale-up my-8 sm:my-0',
          maxWidth,
        ]"
    >
      <div class="flex items-center justify-between px-6 py-4 border-b border-border">
        <h2 class="text-lg font-bold text-ink">{{ title }}</h2>
        <button
            @click="emit('close')"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-muted hover:text-ink hover:bg-panel-alt transition"
            aria-label="close"
        >
          ×
        </button>
      </div>
      <div class="p-6 max-h-[75vh] overflow-y-auto">
        <slot />
      </div>
    </div>
  </div>
</template>
