<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from './ui/Modal.vue'
import ProgressBar from './ui/ProgressBar.vue'
import ObjectifList from './ObjectifList.vue'
import { achievements, CATEGORIES } from '../data/achievements.js'
import { useGameState } from '../composables/useGameState.js'

const emit = defineEmits(['close'])
const { t } = useI18n()
const { unlockedIds } = useGameState()

const TIER_POINTS = { bronze: 10, silver: 25, gold: 50, legendary: 100 }

const activeCategory = ref('all')

const items = computed(() =>
  achievements.map((a) => ({ ...a, unlocked: unlockedIds.has(a.id) }))
)

const filteredItems = computed(() =>
  activeCategory.value === 'all'
    ? items.value
    : items.value.filter((a) => a.category === activeCategory.value)
)

const unlockedCount = computed(() => items.value.filter((a) => a.unlocked).length)
const totalPoints = computed(() =>
  items.value.filter((a) => a.unlocked).reduce((sum, a) => sum + TIER_POINTS[a.tier], 0)
)
const progressPercent = computed(() => (unlockedCount.value / items.value.length) * 100)
</script>

<template>
  <Modal :title="t('trophies.title')" max-width="max-w-3xl" @close="emit('close')">
    <div class="space-y-4">
      <div class="flex flex-col gap-2">
        <div class="flex items-center justify-between text-sm text-muted">
          <span>{{ t('trophies.unlockedOf', { unlocked: unlockedCount, total: items.length }) }}</span>
          <span class="text-accent-strong font-semibold">{{ t('trophies.points', { points: totalPoints }) }}</span>
        </div>
        <ProgressBar :percent="progressPercent" color="success" />
      </div>

      <div class="flex flex-wrap gap-2">
        <button
            @click="activeCategory = 'all'"
            :class="[
              'px-3 py-1.5 rounded-full text-xs font-semibold border transition',
              activeCategory === 'all' ? 'bg-accent text-black border-accent' : 'border-border text-muted hover:text-ink',
            ]"
        >
          {{ t('trophies.filters.all') }}
        </button>
        <button
            v-for="cat in CATEGORIES"
            :key="cat"
            @click="activeCategory = cat"
            :class="[
              'px-3 py-1.5 rounded-full text-xs font-semibold border transition',
              activeCategory === cat ? 'bg-accent text-black border-accent' : 'border-border text-muted hover:text-ink',
            ]"
        >
          {{ t(`trophies.categories.${cat}`) }}
        </button>
      </div>

      <ObjectifList :items="filteredItems" />
    </div>
  </Modal>
</template>
