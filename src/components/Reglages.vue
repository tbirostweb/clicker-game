<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseButton from './ui/BaseButton.vue'
import Modal from './ui/Modal.vue'
import { setLocale } from '../i18n/index.js'
import { useGameState } from '../composables/useGameState.js'

const { t, locale } = useI18n()
const { showSettings, resetProgress, withdrawFromLeaderboard, canWithdrawFromLeaderboard, openPrivacy } = useGameState()

const confirmingReset = ref(false)
const resetDone = ref(false)
const confirmingWithdraw = ref(false)
const withdrawState = ref('idle') // idle | sending | done | error

async function onWithdraw() {
  if (withdrawState.value === 'sending') return
  if (!confirmingWithdraw.value) {
    confirmingWithdraw.value = true
    return
  }
  withdrawState.value = 'sending'
  try {
    await withdrawFromLeaderboard()
    withdrawState.value = 'done'
  } catch {
    withdrawState.value = 'error'
  } finally {
    confirmingWithdraw.value = false
  }
}

function onReset() {
  if (!confirmingReset.value) {
    confirmingReset.value = true
    return
  }
  resetProgress()
  confirmingReset.value = false
  resetDone.value = true
  setTimeout(() => (resetDone.value = false), 2500)
}

function close() {
  showSettings.value = false
  confirmingReset.value = false
  confirmingWithdraw.value = false
}
</script>

<template>
  <BaseButton variant="ghost" size="icon" :title="t('topbar.settings')" @click="showSettings = true">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M11 10.27 7 3.34"/><path d="m11 13.73-4 6.93"/><path d="M12 22v-2"/><path d="M12 2v2"/>
      <path d="M14 12h8"/><path d="m17 20.66-1-1.73"/><path d="m17 3.34-1 1.73"/><path d="M2 12h2"/>
      <path d="m20.66 17-1.73-1"/><path d="m20.66 7-1.73 1"/><path d="m3.34 17 1.73-1"/><path d="m3.34 7 1.73 1"/>
      <circle cx="12" cy="12" r="2"/><circle cx="12" cy="12" r="8"/>
    </svg>
  </BaseButton>

  <Modal v-if="showSettings" :title="t('settings.title')" max-width="max-w-sm" @close="close">
    <div class="space-y-6">
      <div class="space-y-2">
        <p id="settings-language" class="text-sm font-semibold text-muted">{{ t('settings.language') }}</p>
        <div class="flex gap-2" role="group" aria-labelledby="settings-language">
          <button
              v-for="lang in ['fr', 'en']"
              :key="lang"
              type="button"
              :lang="lang"
              :aria-pressed="locale === lang"
              @click="setLocale(lang)"
              :class="[
                'flex-1 py-2 rounded-xl border font-semibold uppercase text-sm transition',
                locale === lang ? 'bg-accent text-black border-accent' : 'border-border text-muted hover:text-ink',
              ]"
          >
            {{ lang }}
          </button>
        </div>
      </div>

      <div class="space-y-2 pt-4 border-t border-border">
        <button
            v-if="canWithdrawFromLeaderboard"
            type="button"
            :disabled="withdrawState === 'sending'"
            @click="onWithdraw"
            :class="[
              'w-full py-2.5 rounded-xl border font-semibold text-sm transition',
              confirmingWithdraw
                ? 'bg-red-500/90 text-white border-red-400'
                : 'border-border text-muted hover:border-red-400 hover:text-red-300',
            ]"
        >
          {{ confirmingWithdraw ? t('settings.withdrawConfirm') : t('settings.withdraw') }}
        </button>
        <p v-else-if="withdrawState !== 'done'" class="text-xs text-muted">{{ t('settings.withdrawUnavailable') }}</p>
        <p role="status" aria-live="polite" class="text-xs text-success text-center empty:hidden">{{ withdrawState === 'done' ? t('settings.withdrawDone') : '' }}</p>
        <p role="alert" class="text-xs text-red-400 text-center empty:hidden">{{ withdrawState === 'error' ? t('settings.withdrawError') : '' }}</p>
      </div>

      <div class="space-y-2 pt-4 border-t border-border">
        <button
            type="button"
            @click="onReset"
            :class="[
              'w-full py-2.5 rounded-xl border font-semibold text-sm transition',
              confirmingReset
                ? 'bg-red-500/90 text-white border-red-400'
                : 'border-border text-muted hover:border-red-400 hover:text-red-300',
            ]"
        >
          {{ confirmingReset ? t('settings.resetConfirm') : t('settings.reset') }}
        </button>
        <p role="status" aria-live="polite" class="text-xs text-success text-center empty:hidden">{{ resetDone ? t('settings.resetDone') : '' }}</p>
      </div>

      <div class="pt-4 border-t border-border text-center">
        <button type="button" class="text-xs text-muted underline hover:text-ink" @click="openPrivacy">{{ t('settings.privacy') }}</button>
      </div>
    </div>
  </Modal>
</template>
