<script setup>
import { useI18n } from 'vue-i18n'
import { useGameState } from '../composables/useGameState.js'
import { UPGRADE_BASE } from '../data/upgrades.js'
import { formatNumber } from '../utils/format.js'

defineProps({
  compact: { type: Boolean, default: false },
})

const { t } = useI18n()
const { counter, upgrades, rebirth, rebirthPrice, buyUpgrade, doRebirth } = useGameState()

// Resolve the 15 upgrade icons through Vite's asset pipeline (glob order
// isn't numeric, so re-index by the trailing number in the filename).
const iconModules = import.meta.glob('../img/upgrade*.svg', { eager: true, import: 'default' })
const upgradeIcons = new Array(15)
for (const path in iconModules) {
  const match = path.match(/upgrade(\d+)\.svg$/)
  if (match) upgradeIcons[Number(match[1]) - 1] = iconModules[path]
}
</script>

<template>
  <div class="space-y-3">
    <button
        v-for="(up, i) in upgrades"
        :key="i"
        @click="buyUpgrade(i)"
        :disabled="counter < up.price"
        :class="[
          'w-full flex items-center rounded-xl transition-all border',
          compact ? 'p-3' : 'p-4',
          counter >= up.price
            ? 'bg-accent-soft border-accent/40 text-ink hover:border-accent hover:bg-accent/20'
            : 'bg-panel-alt border-border text-muted cursor-not-allowed',
        ]"
    >
      <div class="relative flex flex-col items-center shrink-0">
        <span class="absolute -top-3 left-0 text-[10px] font-bold text-accent">
          {{ t('game.level', { level: up.level }) }}
        </span>
        <div :class="['mt-3 rounded-lg overflow-hidden flex items-center justify-center bg-black/30', compact ? 'w-10 h-10' : 'w-14 h-14']">
          <img :src="upgradeIcons[i]" :alt="t(`game.upgradeNames[${i}]`)" class="object-cover w-2/3 h-2/3" />
        </div>
      </div>

      <div class="flex flex-col justify-center flex-1 ml-4 text-left">
        <span :class="['font-semibold', compact ? 'text-sm' : 'text-base']">{{ t(`game.upgradeNames[${i}]`) }}</span>
        <span :class="['text-muted', compact ? 'text-xs' : 'text-sm']">+{{ formatNumber(UPGRADE_BASE[i].cps) }} {{ t('game.perSecond') }}</span>
        <span :class="['font-mono text-accent-strong', compact ? 'text-xs' : 'text-sm']">{{ formatNumber(up.price) }}</span>
      </div>
    </button>

    <button
        @click="doRebirth"
        :disabled="counter < rebirthPrice"
        :class="[
          'w-full flex items-center rounded-xl transition-all border',
          compact ? 'p-3' : 'p-4',
          counter >= rebirthPrice
            ? 'bg-prestige-soft border-prestige/50 text-ink hover:border-prestige hover:bg-prestige/20 animate-pulse-glow'
            : 'bg-panel-alt border-border text-muted cursor-not-allowed',
        ]"
    >
      <div class="relative flex flex-col items-center shrink-0">
        <span class="absolute -top-3 left-0 text-[10px] font-bold text-prestige">{{ rebirth }}×</span>
        <div :class="['mt-3 rounded-lg overflow-hidden flex items-center justify-center bg-black/30', compact ? 'w-10 h-10' : 'w-14 h-14']">
          <img src="../assets/rebirth.svg" alt="Rebirth" class="object-cover w-2/3 h-2/3" />
        </div>
      </div>

      <div class="flex flex-col justify-center flex-1 ml-4 text-left">
        <span :class="['font-semibold', compact ? 'text-sm' : 'text-base']">{{ t('game.rebirthLabel') }}</span>
        <span :class="['text-muted', compact ? 'text-xs' : 'text-sm']">{{ t('game.rebirthPerClick') }}</span>
        <span :class="['font-mono text-prestige-strong', compact ? 'text-xs' : 'text-sm']">{{ formatNumber(rebirthPrice) }}</span>
      </div>
    </button>
  </div>
</template>
