import { ref, reactive, computed } from 'vue'
import { UPGRADE_BASE, UPGRADE_MULTIPLIER, REBIRTH_BASE_PRICE } from '../data/upgrades.js'
import { achievements as achievementDefs } from '../data/achievements.js'
import { useGameSave } from './useGameSave.js'
import { submitRun, updateRun } from '../services/api.js'

const SAVE_KEY = 'clicker-save-v1'
const SPEED_CLICK_WINDOW_MS = 15_000
const AFK_CHECK_INTERVAL_MS = 1000
const LEADERBOARD_AUTO_UPDATE_MS = 300_000

function freshUpgrades() {
  return UPGRADE_BASE.map((base) => ({ level: 0, price: base.price }))
}

// Module-level singleton: every component importing this gets the same
// reactive game state, no prop-drilling needed between App/Jeu/modals.
const counter = ref(0)
const totalEarned = ref(0)
const totalSpent = ref(0)
const totalClicks = ref(0)
const upgrades = ref(freshUpgrades())
const rebirth = ref(0)
const rebirthTimestamps = ref([]) // seconds-since-start of each rebirth
const gameStarted = ref(false)
const sessionElapsedSeconds = ref(0)
const activePlaySeconds = ref(0) // sessionElapsedSeconds minus time the tab/window was hidden
const unlockedIds = reactive(new Set())
const leaderboardSubmitted = ref(false)
const leaderboardRunId = ref(null)
const leaderboardPlayerName = ref('')
const leaderboardRank = ref(null)
const konamiUnlocked = ref(false)

const clickTimes = ref([])
const maxClicksInWindow = ref(0)
const lastClickTime = ref(Date.now())
const idleMs = ref(0)

const showTrophy = ref(false)
const showLeaderboard = ref(false)
const showSettings = ref(false)

const { load, scheduleSave, reset: clearSave } = useGameSave(SAVE_KEY)

function snapshot() {
  return {
    counter: counter.value,
    totalEarned: totalEarned.value,
    totalSpent: totalSpent.value,
    totalClicks: totalClicks.value,
    upgrades: upgrades.value,
    rebirth: rebirth.value,
    rebirthTimestamps: rebirthTimestamps.value,
    gameStarted: gameStarted.value,
    sessionElapsedSeconds: sessionElapsedSeconds.value,
    activePlaySeconds: activePlaySeconds.value,
    unlockedIds: Array.from(unlockedIds),
    leaderboardSubmitted: leaderboardSubmitted.value,
    leaderboardRunId: leaderboardRunId.value,
    leaderboardPlayerName: leaderboardPlayerName.value,
    leaderboardRank: leaderboardRank.value,
    konamiUnlocked: konamiUnlocked.value,
  }
}

function persist() {
  scheduleSave(snapshot)
}

function restore() {
  const data = load()
  if (!data) return
  counter.value = data.counter ?? 0
  totalEarned.value = data.totalEarned ?? 0
  totalSpent.value = data.totalSpent ?? 0
  totalClicks.value = data.totalClicks ?? 0
  upgrades.value = Array.isArray(data.upgrades) && data.upgrades.length === UPGRADE_BASE.length
    ? data.upgrades
    : freshUpgrades()
  rebirth.value = data.rebirth ?? 0
  rebirthTimestamps.value = data.rebirthTimestamps ?? []
  gameStarted.value = data.gameStarted ?? false
  sessionElapsedSeconds.value = data.sessionElapsedSeconds ?? 0
  activePlaySeconds.value = data.activePlaySeconds ?? 0
  ;(data.unlockedIds ?? []).forEach((id) => unlockedIds.add(id))
  leaderboardSubmitted.value = data.leaderboardSubmitted ?? false
  leaderboardRunId.value = data.leaderboardRunId ?? null
  leaderboardPlayerName.value = data.leaderboardPlayerName ?? ''
  leaderboardRank.value = data.leaderboardRank ?? null
  konamiUnlocked.value = data.konamiUnlocked ?? false
}

const rebirthPrice = computed(() => (rebirth.value + 1) * REBIRTH_BASE_PRICE)

const totalUpgradeLevels = computed(() =>
  upgrades.value.reduce((sum, u) => sum + u.level, 0)
)

const baseCps = computed(() =>
  upgrades.value.reduce((sum, u, i) => sum + u.level * UPGRADE_BASE[i].cps, 0)
)

// Permanent bonuses granted by unlocked trophies (see achievements.js `reward`).
// Stack additively across every unlocked trophy that has a reward.
const clickBonusMultiplier = computed(() =>
  1 + achievementDefs.reduce((sum, def) => {
    if (!def.reward || !unlockedIds.has(def.id)) return sum
    return def.reward.type === 'click' || def.reward.type === 'both' ? sum + def.reward.value : sum
  }, 0)
)

const cpsBonusMultiplier = computed(() =>
  1 + achievementDefs.reduce((sum, def) => {
    if (!def.reward || !unlockedIds.has(def.id)) return sum
    return def.reward.type === 'cps' || def.reward.type === 'both' ? sum + def.reward.value : sum
  }, 0)
)

const totalCps = computed(() => baseCps.value * cpsBonusMultiplier.value)
const clickAmount = computed(() => (1 + rebirth.value * 3) * clickBonusMultiplier.value)

const firstRebirthAtSeconds = computed(() =>
  rebirthTimestamps.value.length > 0 ? rebirthTimestamps.value[0] : null
)

const fastestRebirthGapMs = computed(() => {
  const times = rebirthTimestamps.value
  if (times.length < 2) return null
  let min = Infinity
  for (let i = 1; i < times.length; i++) {
    min = Math.min(min, (times[i] - times[i - 1]) * 1000)
  }
  return min
})

function buildAchievementState() {
  return {
    counter: counter.value,
    totalEarned: totalEarned.value,
    totalSpent: totalSpent.value,
    totalClicks: totalClicks.value,
    upgrades: upgrades.value,
    totalUpgradeLevels: totalUpgradeLevels.value,
    cps: baseCps.value,
    rebirth: rebirth.value,
    idleMs: idleMs.value,
    maxClicksInWindow: maxClicksInWindow.value,
    firstRebirthAtSeconds: firstRebirthAtSeconds.value,
    fastestRebirthGapMs: fastestRebirthGapMs.value,
    sessionElapsedSeconds: sessionElapsedSeconds.value,
    activePlaySeconds: activePlaySeconds.value,
    leaderboardSubmitted: leaderboardSubmitted.value,
    leaderboardRank: leaderboardRank.value,
    konamiUnlocked: konamiUnlocked.value,
    unlockedCount: unlockedIds.size,
  }
}

function syncAchievements() {
  const state = buildAchievementState()
  for (const def of achievementDefs) {
    if (!unlockedIds.has(def.id) && def.condition(state)) {
      unlockedIds.add(def.id)
    }
  }
  persist()
}

function addMoney(amount) {
  counter.value += amount
  totalEarned.value += amount
}

function doClick() {
  addMoney(clickAmount.value)
  totalClicks.value++
  const now = Date.now()
  lastClickTime.value = now
  idleMs.value = 0
  clickTimes.value.push(now)
  clickTimes.value = clickTimes.value.filter((t) => now - t <= SPEED_CLICK_WINDOW_MS)
  maxClicksInWindow.value = Math.max(maxClicksInWindow.value, clickTimes.value.length)
  syncAchievements()
}

function buyUpgrade(index) {
  const up = upgrades.value[index]
  if (counter.value < up.price) return
  counter.value -= up.price
  totalSpent.value += up.price
  up.level++
  up.price = Math.floor(up.price * UPGRADE_MULTIPLIER)
  syncAchievements()
}

function doRebirth() {
  if (counter.value < rebirthPrice.value) return
  counter.value -= rebirthPrice.value
  rebirth.value++
  rebirthTimestamps.value = [...rebirthTimestamps.value, sessionElapsedSeconds.value]
  upgrades.value = freshUpgrades()
  counter.value = 0
  syncAchievements()
}

function startGame() {
  gameStarted.value = true
  persist()
}

function submitCurrentRun(name) {
  const payload = {
    name,
    rebirths: rebirth.value,
    score: Math.floor(totalEarned.value),
    timeSeconds: sessionElapsedSeconds.value,
    activeSeconds: activePlaySeconds.value,
    trophies: unlockedIds.size,
  }
  // Refresh the same leaderboard row in place once a first run exists,
  // instead of freezing the entry at whatever the state was at first
  // submission (e.g. only 1 rebirth) every time the player keeps playing.
  const request = leaderboardRunId.value
    ? updateRun(leaderboardRunId.value, payload)
    : submitRun(payload)

  return request.then((result) => {
    leaderboardSubmitted.value = true
    leaderboardRunId.value = result.id
    leaderboardPlayerName.value = name
    syncAchievements()
    return result
  })
}

// Once a run has been submitted at least once, silently refresh it every
// few minutes so the leaderboard reflects current progress (more rebirths,
// money, trophies) without the player having to reopen the panel and click
// "update" by hand every time.
function autoUpdateLeaderboard() {
  if (!gameStarted.value || !leaderboardRunId.value) return
  submitCurrentRun(leaderboardPlayerName.value).catch(() => {
    // Silent: a transient network hiccup shouldn't interrupt gameplay. The
    // next scheduled tick (or the player's own "update" click) retries it.
  })
}

function setLeaderboardRank(rank) {
  leaderboardRank.value = rank
  syncAchievements()
}

function unlockKonami() {
  if (konamiUnlocked.value) return
  konamiUnlocked.value = true
  syncAchievements()
}

function resetProgress() {
  counter.value = 0
  totalEarned.value = 0
  totalSpent.value = 0
  totalClicks.value = 0
  upgrades.value = freshUpgrades()
  rebirth.value = 0
  rebirthTimestamps.value = []
  gameStarted.value = false
  sessionElapsedSeconds.value = 0
  activePlaySeconds.value = 0
  unlockedIds.clear()
  leaderboardSubmitted.value = false
  leaderboardRunId.value = null
  leaderboardPlayerName.value = ''
  leaderboardRank.value = null
  konamiUnlocked.value = false
  clickTimes.value = []
  maxClicksInWindow.value = 0
  clearSave()
}

// Tick every second: session timer, AFK detection and passive CPS income,
// all need wall-clock time regardless of whether the player is actively clicking.
setInterval(() => {
  if (gameStarted.value) {
    sessionElapsedSeconds.value++
    if (typeof document === 'undefined' || document.visibilityState !== 'hidden') {
      activePlaySeconds.value++
    }
    if (totalCps.value > 0) addMoney(totalCps.value)
  }
  idleMs.value = Date.now() - lastClickTime.value
  syncAchievements()
}, AFK_CHECK_INTERVAL_MS)

setInterval(autoUpdateLeaderboard, LEADERBOARD_AUTO_UPDATE_MS)

restore()

export function useGameState() {
  return {
    counter,
    totalEarned,
    totalSpent,
    totalClicks,
    upgrades,
    rebirth,
    rebirthPrice,
    totalCps,
    clickBonusMultiplier,
    cpsBonusMultiplier,
    totalUpgradeLevels,
    gameStarted,
    sessionElapsedSeconds,
    activePlaySeconds,
    unlockedIds,
    leaderboardSubmitted,
    leaderboardRunId,
    leaderboardPlayerName,
    leaderboardRank,
    showTrophy,
    showLeaderboard,
    showSettings,
    doClick,
    buyUpgrade,
    doRebirth,
    startGame,
    submitCurrentRun,
    setLeaderboardRank,
    unlockKonami,
    resetProgress,
  }
}
