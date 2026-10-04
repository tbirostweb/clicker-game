<script setup>
import { useI18n } from 'vue-i18n'
import Modal from './ui/Modal.vue'
import { useGameState } from '../composables/useGameState.js'

const { t } = useI18n()
const { showPrivacy } = useGameState()
</script>

<template>
  <Modal v-if="showPrivacy" :title="t('privacy.title')" max-width="max-w-2xl" @close="showPrivacy = false">
    <div class="space-y-5 text-sm text-ink">
      <section class="space-y-1">
        <h3 class="font-semibold text-accent-strong">{{ t('privacy.editorTitle') }}</h3>
        <p>{{ t('privacy.editor') }}</p>
        <p>{{ t('privacy.director') }}</p>
        <p>{{ t('privacy.host') }}</p>
      </section>
      <section class="space-y-1">
        <h3 class="font-semibold text-accent-strong">{{ t('privacy.dataTitle') }}</h3>
        <p>{{ t('privacy.dataLocal') }}</p>
        <p>{{ t('privacy.dataLeaderboard') }}</p>
        <p>{{ t('privacy.dataLogs') }}</p>
        <p>{{ t('privacy.noTracking') }}</p>
      </section>
      <section class="space-y-1">
        <h3 class="font-semibold text-accent-strong">{{ t('privacy.purposeTitle') }}</h3>
        <p>{{ t('privacy.purpose') }}</p>
        <p>{{ t('privacy.retention') }}</p>
      </section>
      <section class="space-y-1">
        <h3 class="font-semibold text-accent-strong">{{ t('privacy.rightsTitle') }}</h3>
        <p>{{ t('privacy.rights') }}</p>
        <p class="text-muted">{{ t('privacy.resetNote') }}</p>
      </section>
    </div>
  </Modal>
</template>
