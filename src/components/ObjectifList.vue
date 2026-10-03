<script setup>
import { useI18n } from 'vue-i18n'

defineProps({
  items: { type: Array, required: true }, // [{ id, icon, tier, unlocked, reward? }]
})

const { t } = useI18n()

const tierStyles = {
  bronze: 'border-tier-bronze/60 bg-tier-bronze/10',
  silver: 'border-tier-silver/60 bg-tier-silver/10',
  gold: 'border-tier-gold/60 bg-tier-gold/10',
  legendary: 'border-tier-legendary/60 bg-tier-legendary/10 shadow-[0_0_16px_-4px_var(--color-tier-legendary)]',
}

function rewardLabel(reward) {
  if (!reward) return ''
  return t(`trophies.reward.${reward.type}`, { percent: Math.round(reward.value * 100) })
}
</script>

<template>
  <div class="grid sm:grid-cols-2 gap-3">
    <div
        v-for="item in items"
        :key="item.id"
        :class="[
          'p-3 rounded-xl border flex items-center gap-3 transition',
          item.unlocked ? tierStyles[item.tier] : 'border-border bg-panel-alt opacity-60',
        ]"
    >
      <span class="text-2xl shrink-0">{{ item.unlocked ? item.icon : '🔒' }}</span>
      <div class="min-w-0">
        <template v-if="item.unlocked || item.category !== 'secret'">
          <h4 class="font-bold text-sm text-ink truncate">{{ t(`achievements.${item.id}.name`) }}</h4>
          <p class="text-xs text-muted truncate">{{ t(`achievements.${item.id}.description`) }}</p>
          <p v-if="item.reward" :class="['text-xs font-semibold truncate', item.unlocked ? 'text-success' : 'text-muted']">
            {{ rewardLabel(item.reward) }}
          </p>
        </template>
        <template v-else>
          <h4 class="font-bold text-sm text-ink truncate">???</h4>
          <p class="text-xs text-muted truncate">{{ t(`trophies.tiers.${item.tier}`) }}</p>
        </template>
      </div>
    </div>
  </div>
</template>
