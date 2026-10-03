// Data-driven achievement definitions.
// Display text (name/description) lives in the i18n locale files under
// `achievements.<id>.name` / `achievements.<id>.description` — this file only
// holds structure + unlock logic, evaluated against a `state` snapshot built
// by useGameState.js (see buildAchievementState there).
//
// `reward` is optional: { type: 'click' | 'cps' | 'both', value: 0.1 } grants
// a permanent +10% bonus to click income and/or passive income for life once
// unlocked. Bonuses from all unlocked trophies stack additively.

export const CATEGORIES = ['basics', 'upgrades', 'rebirth', 'wealth', 'time', 'fun', 'secret']

export const TIERS = ['bronze', 'silver', 'gold', 'legendary']

export const achievements = [
  // --- Progression basique (nombre de clics manuels) ---
  { id: 'click_1', icon: '👆', category: 'basics', tier: 'bronze', condition: (s) => s.totalClicks >= 1 },
  { id: 'click_10', icon: '🐣', category: 'basics', tier: 'bronze', condition: (s) => s.totalClicks >= 10 },
  { id: 'click_100', icon: '⚡', category: 'basics', tier: 'bronze', condition: (s) => s.totalClicks >= 100 },
  { id: 'click_1000', icon: '🔥', category: 'basics', tier: 'silver', condition: (s) => s.totalClicks >= 1_000 },
  { id: 'click_10000', icon: '💪', category: 'basics', tier: 'silver', condition: (s) => s.totalClicks >= 10_000 },
  { id: 'click_100000', icon: '🦾', category: 'basics', tier: 'gold', condition: (s) => s.totalClicks >= 100_000 },
  { id: 'click_1000000', icon: '🏋️', category: 'basics', tier: 'gold', condition: (s) => s.totalClicks >= 1_000_000 },
  { id: 'click_100000000', icon: '🌌', category: 'basics', tier: 'legendary', condition: (s) => s.totalClicks >= 100_000_000, reward: { type: 'click', value: 0.15 } },

  // --- Améliorations & production ---
  { id: 'upgrade_first', icon: '🔧', category: 'upgrades', tier: 'bronze', condition: (s) => s.totalUpgradeLevels >= 1 },
  { id: 'upgrade_5', icon: '💼', category: 'upgrades', tier: 'bronze', condition: (s) => s.totalUpgradeLevels >= 5 },
  { id: 'upgrade_10', icon: '⚙️', category: 'upgrades', tier: 'silver', condition: (s) => s.totalUpgradeLevels >= 10 },
  { id: 'upgrade_50', icon: '🏭', category: 'upgrades', tier: 'gold', condition: (s) => s.totalUpgradeLevels >= 50, reward: { type: 'cps', value: 0.05 } },
  { id: 'upgrade_screen', icon: '🖥️', category: 'upgrades', tier: 'bronze', condition: (s) => s.upgrades[2]?.level >= 1 },
  { id: 'upgrade_robot', icon: '🤖', category: 'upgrades', tier: 'silver', condition: (s) => s.upgrades[6]?.level >= 1 },
  { id: 'upgrade_satellite', icon: '🛰️', category: 'upgrades', tier: 'gold', condition: (s) => s.upgrades[9]?.level >= 1 },
  { id: 'upgrade_network', icon: '🌐', category: 'upgrades', tier: 'gold', condition: (s) => s.upgrades[12]?.level >= 1 },
  { id: 'upgrade_dimension', icon: '✨', category: 'upgrades', tier: 'legendary', condition: (s) => s.upgrades[13]?.level >= 1 },
  { id: 'upgrade_divine', icon: '🙏', category: 'upgrades', tier: 'legendary', condition: (s) => s.upgrades[14]?.level >= 1 },
  { id: 'cps_100', icon: '📈', category: 'upgrades', tier: 'bronze', condition: (s) => s.cps >= 100 },
  { id: 'cps_10000', icon: '📊', category: 'upgrades', tier: 'silver', condition: (s) => s.cps >= 10_000 },
  { id: 'cps_1000000', icon: '🚂', category: 'upgrades', tier: 'gold', condition: (s) => s.cps >= 1_000_000, reward: { type: 'cps', value: 0.10 } },
  { id: 'cps_100000000', icon: '🏗️', category: 'upgrades', tier: 'legendary', condition: (s) => s.cps >= 100_000_000, reward: { type: 'cps', value: 0.20 } },
  { id: 'maxed_all', icon: '🧠', category: 'upgrades', tier: 'legendary', condition: (s) => s.upgrades.length > 0 && s.upgrades.every((u) => u.level >= 5), reward: { type: 'both', value: 0.15 } },

  // --- Rebirth ---
  { id: 'rebirth_1', icon: '🔄', category: 'rebirth', tier: 'bronze', condition: (s) => s.rebirth >= 1 },
  { id: 'rebirth_2', icon: '♻️', category: 'rebirth', tier: 'bronze', condition: (s) => s.rebirth >= 2 },
  { id: 'rebirth_3', icon: '🌟', category: 'rebirth', tier: 'silver', condition: (s) => s.rebirth >= 3 },
  { id: 'rebirth_5', icon: '🎭', category: 'rebirth', tier: 'silver', condition: (s) => s.rebirth >= 5 },
  { id: 'rebirth_10', icon: '🚀', category: 'rebirth', tier: 'gold', condition: (s) => s.rebirth >= 10, reward: { type: 'click', value: 0.10 } },
  { id: 'rebirth_20', icon: '🌌', category: 'rebirth', tier: 'legendary', condition: (s) => s.rebirth >= 20, reward: { type: 'click', value: 0.20 } },
  { id: 'rebirth_50', icon: '👼', category: 'rebirth', tier: 'legendary', condition: (s) => s.rebirth >= 50, reward: { type: 'both', value: 0.30 } },

  // --- Argent accumulé (cumulé sur toute la partie, ne redescend pas après un rebirth) ---
  { id: 'wealth_1k', icon: '💵', category: 'wealth', tier: 'bronze', condition: (s) => s.totalEarned >= 1_000 },
  { id: 'wealth_10k', icon: '💳', category: 'wealth', tier: 'bronze', condition: (s) => s.totalEarned >= 10_000 },
  { id: 'wealth_1m', icon: '💰', category: 'wealth', tier: 'silver', condition: (s) => s.totalEarned >= 1_000_000 },
  { id: 'wealth_10m', icon: '💎', category: 'wealth', tier: 'silver', condition: (s) => s.totalEarned >= 10_000_000 },
  { id: 'wealth_1b', icon: '🏦', category: 'wealth', tier: 'gold', condition: (s) => s.totalEarned >= 1_000_000_000 },
  { id: 'wealth_10b', icon: '🏰', category: 'wealth', tier: 'gold', condition: (s) => s.totalEarned >= 10_000_000_000 },
  { id: 'wealth_1t', icon: '👑', category: 'wealth', tier: 'legendary', condition: (s) => s.totalEarned >= 1_000_000_000_000, reward: { type: 'cps', value: 0.10 } },
  { id: 'wealth_1q', icon: '🌍', category: 'wealth', tier: 'legendary', condition: (s) => s.totalEarned >= 1_000_000_000_000_000, reward: { type: 'both', value: 0.15 } },

  // --- Temps de jeu ---
  { id: 'playtime_10m', icon: '⏱️', category: 'time', tier: 'bronze', condition: (s) => s.sessionElapsedSeconds >= 10 * 60 },
  { id: 'playtime_30m', icon: '⏳', category: 'time', tier: 'silver', condition: (s) => s.sessionElapsedSeconds >= 30 * 60 },
  { id: 'session_1h', icon: '🌙', category: 'time', tier: 'gold', condition: (s) => s.sessionElapsedSeconds >= 3600 },
  { id: 'playtime_3h', icon: '🕰️', category: 'time', tier: 'legendary', condition: (s) => s.sessionElapsedSeconds >= 3 * 3600, reward: { type: 'cps', value: 0.10 } },
  { id: 'active_1h', icon: '🎯', category: 'time', tier: 'legendary', condition: (s) => s.activePlaySeconds >= 3600, reward: { type: 'click', value: 0.10 } },

  // --- Fun / défis ---
  { id: 'afk_master', icon: '😴', category: 'fun', tier: 'silver', condition: (s) => s.idleMs >= 10 * 60 * 1000 },
  { id: 'speed_click', icon: '🐇', category: 'fun', tier: 'gold', condition: (s) => s.maxClicksInWindow >= 100 },
  { id: 'hyper_click', icon: '🐆', category: 'fun', tier: 'legendary', condition: (s) => s.maxClicksInWindow >= 300, reward: { type: 'click', value: 0.10 } },
  { id: 'speed_first_rebirth', icon: '💨', category: 'fun', tier: 'silver', condition: (s) => s.firstRebirthAtSeconds !== null && s.firstRebirthAtSeconds <= 90 },
  { id: 'rebirth_chain', icon: '⛓️', category: 'fun', tier: 'gold', condition: (s) => s.fastestRebirthGapMs !== null && s.fastestRebirthGapMs <= 30_000 },
  { id: 'big_spender', icon: '🛒', category: 'fun', tier: 'bronze', condition: (s) => s.totalSpent >= 1_000_000_000_000 },

  // --- Secrets ---
  { id: 'leaderboard_submit', icon: '📡', category: 'secret', tier: 'gold', condition: (s) => s.leaderboardSubmitted === true },
  { id: 'leaderboard_top3', icon: '🥇', category: 'secret', tier: 'legendary', condition: (s) => typeof s.leaderboardRank === 'number' && s.leaderboardRank <= 3 },
  { id: 'leaderboard_top1', icon: '👑', category: 'secret', tier: 'legendary', condition: (s) => s.leaderboardRank === 1, reward: { type: 'both', value: 0.10 } },
  { id: 'trophy_master', icon: '🏆', category: 'secret', tier: 'legendary', condition: (s) => s.unlockedCount >= 35, reward: { type: 'both', value: 0.10 } },
  { id: 'secret_konami', icon: '🕹️', category: 'secret', tier: 'legendary', condition: (s) => s.konamiUnlocked === true },
]

export function computeUnlocked(state) {
  return new Set(achievements.filter((a) => a.condition(state)).map((a) => a.id))
}
