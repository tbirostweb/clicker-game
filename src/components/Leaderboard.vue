<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseButton from './ui/BaseButton.vue'
import Modal from './ui/Modal.vue'
import { fetchLeaderboard } from '../services/api.js'
import { useGameState } from '../composables/useGameState.js'
import { formatNumber, formatDuration } from '../utils/format.js'

const { t } = useI18n()
const { showLeaderboard, rebirth, leaderboardRunId, leaderboardPlayerName, submitCurrentRun, setLeaderboardRank } = useGameState()

const SORTS = ['active', 'rebirths', 'score', 'trophies']

const runs = ref([])
const loading = ref(false)
const error = ref(false)
const playerName = ref(leaderboardPlayerName.value)
const submitState = ref('idle') // idle | sending | done | error
const wasUpdate = ref(false) // which message/label the last completed submit was
const activeSort = ref('active')

async function load() {
  loading.value = true
  error.value = false
  try {
    runs.value = await fetchLeaderboard(20, activeSort.value)
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
}

function open() {
  showLeaderboard.value = true
  load()
}

function selectSort(sort) {
  if (sort === activeSort.value) return
  activeSort.value = sort
  load()
}

async function onSubmit() {
  if (!playerName.value.trim()) return
  submitState.value = 'sending'
  const isUpdate = !!leaderboardRunId.value
  try {
    const result = await submitCurrentRun(playerName.value.trim())
    await load()
    // rank is always tracked against the active-playtime ranking, the game's
    // primary/flagship leaderboard, regardless of which tab is open.
    const activeRuns = activeSort.value === 'active' ? runs.value : await fetchLeaderboard(20, 'active')
    const rank = activeRuns.findIndex((r) => r.id === result.id)
    if (rank !== -1) setLeaderboardRank(rank + 1)
    wasUpdate.value = isUpdate
    submitState.value = 'done'
  } catch {
    submitState.value = 'error'
  }
}
</script>

<template>
  <BaseButton variant="ghost" size="icon" :title="t('topbar.leaderboard')" @click="open">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <line x1="18" x2="18" y1="20" y2="10"/><line x1="12" x2="12" y1="20" y2="4"/><line x1="6" x2="6" y1="20" y2="14"/>
    </svg>
  </BaseButton>

  <Modal v-if="showLeaderboard" :title="t('leaderboard.title')" max-width="max-w-2xl" @close="showLeaderboard = false">
    <p class="text-sm text-muted mb-4">{{ t('leaderboard.subtitle') }}</p>

    <div class="flex flex-wrap gap-2 mb-4">
      <button
          v-for="sort in SORTS"
          :key="sort"
          @click="selectSort(sort)"
          :class="[
            'px-3 py-1.5 rounded-full text-xs font-semibold border transition',
            activeSort === sort ? 'bg-accent text-black border-accent' : 'border-border text-muted hover:text-ink',
          ]"
      >
        {{ t(`leaderboard.sort.${sort}`) }}
      </button>
    </div>

    <div v-if="rebirth >= 1" class="mb-5 p-4 rounded-xl border border-accent/40 bg-accent-soft space-y-3">
      <p class="text-sm font-semibold text-ink">{{ leaderboardRunId ? t('game.updatePrompt') : t('game.submitPrompt') }}</p>
      <div class="flex gap-2">
        <input
            v-model="playerName"
            :placeholder="t('game.namePlaceholder')"
            maxlength="20"
            class="flex-1 min-w-0 px-3 py-2 rounded-lg bg-panel border border-border text-ink text-sm focus:outline-none focus:border-accent"
        />
        <BaseButton size="sm" :disabled="submitState === 'sending'" @click="onSubmit">
          {{ leaderboardRunId ? t('game.update') : t('game.submit') }}
        </BaseButton>
      </div>
      <p v-if="submitState === 'done'" class="text-xs text-success">{{ wasUpdate ? t('game.updated') : t('game.submitted') }}</p>
      <p v-if="submitState === 'error'" class="text-xs text-red-400">{{ t('game.submitError') }}</p>
      <p v-if="leaderboardRunId" class="text-xs text-muted">{{ t('game.autoUpdateHint') }}</p>
    </div>

    <div v-if="loading" class="text-center text-muted text-sm py-8">{{ t('leaderboard.loading') }}</div>
    <div v-else-if="error" class="text-center text-red-400 text-sm py-8">{{ t('leaderboard.error') }}</div>
    <div v-else-if="runs.length === 0" class="text-center text-muted text-sm py-8">{{ t('leaderboard.empty') }}</div>
    <div v-else class="overflow-x-auto">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-muted text-left border-b border-border">
            <th class="py-2 pr-2">{{ t('leaderboard.rank') }}</th>
            <th class="py-2 pr-2">{{ t('leaderboard.name') }}</th>
            <th class="py-2 pr-2">{{ t('leaderboard.time') }}</th>
            <th :class="['py-2 pr-2', activeSort === 'active' && 'text-accent-strong']">{{ t('leaderboard.activeTime') }}</th>
            <th :class="['py-2 pr-2', activeSort === 'rebirths' && 'text-accent-strong']">{{ t('leaderboard.rebirths') }}</th>
            <th :class="['py-2 pr-2', activeSort === 'trophies' && 'text-accent-strong']">{{ t('leaderboard.trophies') }}</th>
            <th :class="['py-2', activeSort === 'score' && 'text-accent-strong']">{{ t('leaderboard.score') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
              v-for="(run, i) in runs"
              :key="run.id"
              :class="['border-b border-border/60', i < 3 ? 'text-accent-strong font-semibold' : 'text-ink']"
          >
            <td class="py-2 pr-2">{{ i + 1 }}</td>
            <td class="py-2 pr-2 truncate max-w-[10rem]">{{ run.name }}</td>
            <td class="py-2 pr-2 font-mono">{{ formatDuration(run.timeSeconds) }}</td>
            <td class="py-2 pr-2 font-mono">{{ formatDuration(run.activeSeconds) }}</td>
            <td class="py-2 pr-2">{{ run.rebirths }}</td>
            <td class="py-2 pr-2">🏆 {{ run.trophies }}</td>
            <td class="py-2 font-mono">{{ formatNumber(run.score) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="mt-4 flex justify-end">
      <BaseButton variant="ghost" size="sm" @click="load">{{ t('leaderboard.refresh') }}</BaseButton>
    </div>
  </Modal>
</template>
