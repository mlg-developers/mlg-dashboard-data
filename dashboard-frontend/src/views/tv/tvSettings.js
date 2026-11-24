import { effectScope, reactive, watch } from 'vue'

// Display settings of one TV (how often the numbers refresh, how long each screen stays up, whether
// auto-refresh is running). Kept in this browser, so they survive a TV restart like the language does.
// Priority: what someone chose with the settings panel > ?refresh= / ?interval= in the URL > defaults.

const STORAGE_KEY = 'tv:settings'

export const REFRESH_STEPS = [5, 10, 15, 30, 60, 120, 300]
export const SLIDE_STEPS = [5, 8, 10, 15, 20, 30, 60]

// floor: '' = all floors, otherwise a floorCode: this TV is registered to that floor only
const DEFAULTS = { refreshSeconds: 15, refreshEnabled: true, slideSeconds: 10, floor: '' }

const read = () => {
  try {
    return JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null')
  } catch {
    return null
  }
}

const write = (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
  } catch {
    // storage can be blocked; the choice then lasts until the page reloads
  }
}

const clamp = (value, steps, fallback) => {
  const n = Number(value)
  return Number.isFinite(n) && n >= steps[0] && n <= steps[steps.length - 1] ? n : fallback
}

const settings = reactive({ ...DEFAULTS })
let started = false

export const initSettings = (query = {}) => {
  const stored = read() || {}
  settings.refreshSeconds = clamp(stored.refreshSeconds ?? query.refresh, REFRESH_STEPS, DEFAULTS.refreshSeconds)
  settings.slideSeconds = clamp(stored.slideSeconds ?? query.interval, SLIDE_STEPS, DEFAULTS.slideSeconds)
  settings.refreshEnabled = stored.refreshEnabled !== false
  const floor = String(stored.floor ?? query.floor ?? '')
  settings.floor = /^[A-Za-z0-9_-]{1,10}$/.test(floor) ? floor : ''

  // Every change someone makes in the settings panel is saved at once and wins over the URL and the
  // defaults from then on, until somebody changes it again. The watcher lives in its own detached scope,
  // so it keeps saving even after the TV page was left and opened again.
  if (!started) {
    started = true
    effectScope(true).run(() => watch(settings, () => write({ ...settings }), { deep: true, flush: 'sync' }))
  }
}

const stepIn = (steps, current, direction) => {
  // move to the next preset in the given direction (the closest one when the value is not a preset)
  const index = steps.findIndex((s) => s >= current)
  const at = index === -1 ? steps.length - 1 : index
  const next = Math.min(steps.length - 1, Math.max(0, at + direction))
  return steps[next]
}

export const useTvSettings = () => ({
  settings,
  refreshStep: (direction) => {
    settings.refreshSeconds = stepIn(REFRESH_STEPS, settings.refreshSeconds, direction)
  },
  slideStep: (direction) => {
    settings.slideSeconds = stepIn(SLIDE_STEPS, settings.slideSeconds, direction)
  },
  toggleRefresh: () => {
    settings.refreshEnabled = !settings.refreshEnabled
  },
  setFloor: (code) => {
    settings.floor = code || ''
  },
  // the floor registration is kept: only the timing settings go back to their defaults
  reset: () => Object.assign(settings, { ...DEFAULTS, floor: settings.floor }),
})
