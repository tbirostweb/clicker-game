<script setup>
const props = defineProps({
  variant: { type: String, default: 'accent' }, // accent | prestige | ghost | success
  disabled: { type: Boolean, default: false },
  size: { type: String, default: 'md' }, // sm | md | lg | icon
})

const sizeClasses = {
  icon: 'w-11 h-11 p-0',
  sm: 'px-3 py-2 text-sm',
  md: 'px-5 py-3',
  lg: 'px-8 py-4 text-lg',
}

const variantClasses = {
  accent: 'bg-accent text-black hover:bg-accent-strong shadow-lg shadow-accent-soft',
  prestige: 'bg-prestige text-black hover:bg-prestige-strong shadow-lg shadow-prestige-soft',
  success: 'bg-success text-black hover:brightness-110',
  ghost: 'bg-panel-alt text-ink border border-border hover:border-accent/60 hover:text-accent',
}
</script>

<template>
  <button
      :disabled="disabled"
      :class="[
        'flex items-center justify-center gap-2 rounded-xl font-semibold transition-all duration-150',
        'active:scale-95',
        sizeClasses[size],
        disabled
          ? 'bg-panel-alt text-muted cursor-not-allowed border border-border'
          : variantClasses[variant] + ' hover:scale-[1.03]',
      ]"
  >
    <slot />
  </button>
</template>
