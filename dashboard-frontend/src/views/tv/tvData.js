import { computed, onBeforeUnmount, onMounted, ref, unref, watch } from 'vue'

// Live data for the TV dashboards. The backend (GET /api/v1/tv/clinics-today) talks to the Jeeva
// APIs and only ever returns aggregated counts: no patient names, MR numbers or visit records.

const API_BASE = import.meta.env.VITE_API_BASE_URL || '/api/v1'
const REQUEST_TIMEOUT_MS = 45000 // the very first request of the day may need to load all doctor lists

const titleCase = (text) =>
  text
    .toLowerCase()
    .replace(/(^|[\s-])(\p{L})/gu, (_, sep, letter) => sep + letter.toUpperCase())

// "MLG GENERAL OPD" -> "GENERAL OPD" (the MLG prefix is the hospital's own, repeated on every clinic)
const clinicLabel = (name) => name.replace(/^MLG\s+/i, '')

// "NYABWIRE EMMANUEL KUBOJA" -> "Dr. N. Kuboja", short enough for a TV card
const doctorLabel = (name) => {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return ''
  if (parts.length === 1) return titleCase(parts[0])
  return `Dr. ${parts[0][0].toUpperCase()}. ${titleCase(parts[parts.length - 1])}`
}

// Backend response -> the shape the slides render. `null` counts mean "data unavailable" (not zero).
const adapt = (payload) => ({
  date: payload.date,
  floors: (payload.floors || []).map((f) => ({ code: f.code, name: f.name })),
  floorFilter: payload.floor_filter || '',
  clinics: payload.clinics.map((c) => ({
    code: c.code,
    name: clinicLabel(c.name),
    floorCode: c.floor_code,
    floorName: c.floor_name,
    patients: c.waiting,
    doctors: c.doctors.map((d) => ({
      code: d.code,
      name: doctorLabel(d.name),
      absent: Boolean(d.off), // switched off by the accountant: not in the clinic today
      booked: d.booked,
      waiting: d.waiting,
      seen: d.seen,
      movedIn: d.moved_in || 0,
      movedOut: d.moved_out || 0,
      active: (d.booked || 0) > 0,
    })),
  })),
  totals: {
    clinics: payload.totals.clinics,
    doctorsWithPatients: payload.totals.doctors_with_patients,
    waiting: payload.totals.waiting,
  },
})

// refreshSeconds / enabled may be refs: changing them reschedules the polling straight away.
export const useTvData = ({ refreshSeconds = 15, enabled = true, date = null, floor = '' } = {}) => {
  const payload = ref(null) // last good response
  const loading = ref(true)
  const failed = ref(false) // the latest attempt failed
  const updatedAt = ref(null)

  let timer = null
  let current = null // { id, controller } of the request that counts
  let lastId = 0

  // `force` replaces a request that is still running (used when the TV is moved to another floor)
  const refresh = async (force = false) => {
    if (current && !force) return
    if (current) current.controller.abort()

    const mine = { id: ++lastId, controller: new AbortController() }
    current = mine
    const requestedFloor = unref(floor) || ''
    const abort = setTimeout(() => mine.controller.abort(), REQUEST_TIMEOUT_MS)
    try {
      const params = new URLSearchParams()
      if (date) params.set('date', date)
      if (requestedFloor) params.set('floor', requestedFloor)
      const query = params.toString() ? `?${params}` : ''
      const response = await fetch(`${API_BASE}/tv/clinics-today${query}`, {
        signal: mine.controller.signal,
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      })
      const body = await response.json()
      if (mine !== current) return // a newer request took over
      if (!response.ok || body.status !== 'success' || !Array.isArray(body.clinics)) {
        throw new Error(`TV data unavailable (HTTP ${response.status})`)
      }
      payload.value = body
      updatedAt.value = new Date()
      failed.value = false
    } catch {
      if (mine === current) failed.value = true // keep showing the last good data, if any
    } finally {
      clearTimeout(abort)
      if (mine === current) {
        loading.value = false
        current = null
      }
    }
  }

  // polling also picks up the new day (and its clinics) by itself after midnight
  const schedule = () => {
    clearInterval(timer)
    timer = null
    if (unref(enabled)) timer = setInterval(refresh, Math.max(5, Number(unref(refreshSeconds)) || 15) * 1000)
  }

  onMounted(() => {
    refresh()
    schedule()
  })
  watch([() => unref(refreshSeconds), () => unref(enabled)], ([, on], [, wasOn]) => {
    schedule()
    if (on && !wasOn) refresh() // turned back on: catch up right away
  })
  // re-registering the TV to another floor: forget the old floor's numbers at once (no mixing)
  watch(
    () => unref(floor) || '',
    () => {
      payload.value = null
      loading.value = true
      refresh(true)
    },
  )
  onBeforeUnmount(() => clearInterval(timer))

  const data = computed(() => (payload.value ? adapt(payload.value) : null))

  // ok | stale (old numbers still on screen) | loading | unavailable (nothing to show)
  const status = computed(() => {
    if (!payload.value) return loading.value ? 'loading' : 'unavailable'
    const patients = payload.value.sources?.patients
    return failed.value || patients === 'stale' || patients === 'unavailable' ? 'stale' : 'ok'
  })

  return { data, status, updatedAt, refresh }
}
