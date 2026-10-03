export function formatNumber(n) {
  if (n >= 1_000_000_000_000) return (n / 1_000_000_000_000).toFixed(2) + 'T'
  if (n >= 1_000_000_000) return (n / 1_000_000_000).toFixed(2) + 'B'
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(2) + 'M'
  if (n >= 1_000) return (n / 1_000).toFixed(2) + 'k'
  return n.toFixed(1)
}

export function formatDuration(totalSeconds) {
  const safeSeconds = Number.isFinite(totalSeconds) ? totalSeconds : 0
  const m = Math.floor(safeSeconds / 60)
  const s = Math.floor(safeSeconds % 60)
  return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
}
