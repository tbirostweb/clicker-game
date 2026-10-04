<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick, useId } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps({
  title: { type: String, default: '' },
  maxWidth: { type: String, default: 'max-w-lg' },
})
const emit = defineEmits(['close'])
const { t } = useI18n()

const titleId = `modal-title-${useId()}`
const dialog = ref(null)
let previouslyFocused = null

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function focusables() {
  return dialog.value ? Array.from(dialog.value.querySelectorAll(FOCUSABLE)) : []
}

// Escape closes; Tab / Shift+Tab stay inside the dialog (focus trap).
function onKeydown(e) {
  if (e.key === 'Escape') {
    e.stopPropagation()
    emit('close')
    return
  }
  if (e.key !== 'Tab') return
  const items = focusables()
  if (items.length === 0) {
    e.preventDefault()
    dialog.value?.focus()
    return
  }
  const first = items[0]
  const last = items[items.length - 1]
  if (e.shiftKey && (document.activeElement === first || document.activeElement === dialog.value)) {
    e.preventDefault()
    last.focus()
  } else if (!e.shiftKey && document.activeElement === last) {
    e.preventDefault()
    first.focus()
  }
}

onMounted(async () => {
  previouslyFocused = document.activeElement
  await nextTick()
  const items = focusables()
  ;(items[0] ?? dialog.value)?.focus()
})

// Give focus back to the control that opened the dialog.
onBeforeUnmount(() => {
  if (previouslyFocused && typeof previouslyFocused.focus === 'function') previouslyFocused.focus()
})
</script>

<template>
  <div
      class="fixed inset-0 z-50 flex items-start sm:items-center justify-center bg-black/60 backdrop-blur-sm p-4 overflow-y-auto"
      @click.self="emit('close')"
  >
    <div
        ref="dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        tabindex="-1"
        :class="[
          'w-full bg-panel border border-border rounded-2xl shadow-2xl shadow-black/50 animate-scale-up my-8 sm:my-0 focus:outline-none',
          maxWidth,
        ]"
        @keydown="onKeydown"
    >
      <div class="flex items-center justify-between px-6 py-4 border-b border-border">
        <h2 :id="titleId" class="text-lg font-bold text-ink">{{ title }}</h2>
        <button
            type="button"
            @click="emit('close')"
            class="w-8 h-8 flex items-center justify-center rounded-lg text-muted hover:text-ink hover:bg-panel-alt transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-accent"
            :aria-label="t('common.close')"
            :title="t('common.close')"
        >
          <span aria-hidden="true">×</span>
        </button>
      </div>
      <div class="p-6 max-h-[75vh] overflow-y-auto">
        <slot />
      </div>
    </div>
  </div>
</template>
