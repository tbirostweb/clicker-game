<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import UpgradeList from './UpgradeList.vue'
import Panel from './ui/Panel.vue'
import Modal from './ui/Modal.vue'
import clickButtonImg from '../assets/button2.png'
import { useGameState } from '../composables/useGameState.js'
import { formatNumber, formatDuration } from '../utils/format.js'

const { t } = useI18n()
const { counter, totalCps, rebirth, gameStarted, sessionElapsedSeconds, doClick } = useGameState()

const showUpgrades = ref(false)

const formattedCounter = computed(() => formatNumber(counter.value))
const formattedTimer = computed(() => formatDuration(sessionElapsedSeconds.value))

function onClickButton() {
  if (gameStarted.value) doClick()
}
</script>

<template>
  <div class="grid lg:grid-cols-2 gap-6 w-full h-full p-4 sm:p-6 text-ink">
    <!-- Colonne gauche : compteur + bouton clic -->
    <div class="flex flex-col items-center justify-center gap-10">
      <div class="flex flex-col items-center gap-1">
        <span class="text-sm text-muted font-mono">{{ t('game.timer') }} · {{ formattedTimer }}</span>
        <div class="flex items-center gap-2">
          <h2 class="text-5xl font-extrabold text-ink tracking-tight">{{ formattedCounter }}</h2>
          <span class="text-xl font-extrabold bg-accent text-black px-3 py-1 rounded-full shadow">$</span>
        </div>
        <h3 class="text-muted">{{ formatNumber(totalCps) }} {{ t('game.perSecond') }}</h3>
        <h4 class="text-prestige-strong font-semibold">{{ rebirth }} {{ t('game.rebirthCount') }}</h4>
      </div>

      <button @click="onClickButton" class="relative group">
        <img
            :class="['w-56 sm:w-72 transition-transform active:scale-90', gameStarted ? 'animate-slow-spin' : '']"
            :src="clickButtonImg"
            alt="click"
            style="filter: sepia(1) saturate(6) hue-rotate(-8deg) brightness(1.05) drop-shadow(0 0 35px var(--color-accent-soft));"
        />
      </button>
    </div>

    <!-- Colonne droite : améliorations (desktop) -->
    <Panel class="hidden lg:flex flex-col gap-4 max-h-[80vh] overflow-y-auto">
      <h3 class="text-lg font-bold text-center text-ink">{{ t('game.upgradesTitle') }}</h3>
      <UpgradeList />
    </Panel>

    <!-- Bouton flottant mobile -->
    <button
        @click="showUpgrades = true"
        class="fixed flex items-center justify-center w-12 h-12 bottom-6 right-6 lg:hidden bg-accent text-black rounded-full shadow-lg shadow-accent-soft transition transform active:scale-95 z-30"
    >
      <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/></svg>
    </button>

    <!-- Modale améliorations (mobile) -->
    <Modal v-if="showUpgrades" :title="t('game.upgradesTitle')" class="lg:hidden" @close="showUpgrades = false">
      <UpgradeList compact />
    </Modal>
  </div>
</template>
