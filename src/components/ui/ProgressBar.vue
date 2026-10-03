<script setup>
defineProps({
  percent: { type: Number, required: true },
  color: { type: String, default: 'accent' }, // accent | success | prestige
})
</script>

<template>
  <div class="w-full bg-panel-alt rounded-full h-2.5 overflow-hidden border border-border">
    <div
        :class="[
          'h-full rounded-full transition-all duration-500',
          color === 'success' ? 'bg-success' : color === 'prestige' ? 'bg-prestige' : 'bg-accent',
        ]"
        :style="{ width: Math.min(100, Math.max(0, percent)) + '%' }"
    />
  </div>
</template>
