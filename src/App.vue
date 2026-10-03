<script setup>
import { onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Reglages from './components/Reglages.vue'
import Trophy from './components/Trophy.vue'
import Leaderboard from './components/Leaderboard.vue'
import Jeu from './components/jeu.vue'
import Start from './components/Start.vue'
import { useGameState } from './composables/useGameState.js'

const { t } = useI18n()
const { gameStarted, unlockKonami } = useGameState()

// Easter egg trophy: the classic Konami code.
const KONAMI_SEQUENCE = ['ArrowUp', 'ArrowUp', 'ArrowDown', 'ArrowDown', 'ArrowLeft', 'ArrowRight', 'ArrowLeft', 'ArrowRight', 'b', 'a']
let konamiProgress = 0

function onKeydown(e) {
  const expected = KONAMI_SEQUENCE[konamiProgress]
  if (e.key.toLowerCase() === expected.toLowerCase()) {
    konamiProgress++
    if (konamiProgress === KONAMI_SEQUENCE.length) {
      unlockKonami()
      konamiProgress = 0
    }
  } else {
    konamiProgress = e.key === KONAMI_SEQUENCE[0] ? 1 : 0
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="w-screen h-screen bg-bg text-ink flex flex-col overflow-hidden">
    <header class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-border shrink-0">
      <h1 class="text-lg sm:text-xl font-extrabold tracking-tight">
        <span class="text-accent-strong">⚡</span> {{ t('app.title') }}
      </h1>
      <div class="flex items-center gap-2">
        <Leaderboard />
        <Trophy />
        <Reglages />
      </div>
    </header>

    <main class="flex-1 overflow-y-auto">
      <Jeu />
    </main>
  </div>

  <Start v-if="!gameStarted" />
</template>
