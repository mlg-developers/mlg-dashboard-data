<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import TvClinicsSlide from './TvClinicsSlide.vue'
import TvDoctorsSlide from './TvDoctorsSlide.vue'
import { balancedChunks, chunk } from './layout'
import { useTvData } from './tvData'
import { initLang, useTvI18n } from './i18n'
import { REFRESH_STEPS, initSettings, useTvSettings } from './tvSettings'

const props = defineProps({
  // 'all' rotates clinics and doctors, 'clinics' / 'doctors' show only that dashboard.
  mode: { type: String, default: 'all' },
})

// Device size drives the layout: wide TVs get 4 card columns, tablets 2-3, phones 1.
const viewport = ref({ w: window.innerWidth, h: window.innerHeight })
const onResize = () => {
  viewport.value = { w: window.innerWidth, h: window.innerHeight }
}
const cols = computed(() => {
  const w = viewport.value.w
  return w >= 1500 ? 4 : w >= 1000 ? 3 : w >= 640 ? 2 : 1
})
// stage = screen minus header and footer (about 77% of the height)
const stageAspect = computed(() => viewport.value.w / (viewport.value.h * 0.77))
const clinicsPerPage = computed(() => cols.value * 8)
const doctorCardsPerPage = computed(() => cols.value * 3)

const route = useRoute()
initLang(route.query.lang)
const { lang, setLang, t, formatDate } = useTvI18n()
// ?interval=10 changes the seconds each screen stays up. ?refresh=15 changes how often the numbers
// are reloaded. ?date=2026-11-24 looks at an earlier day (today is used by default).
initSettings(route.query)
const { settings, refreshStep, slideStep, toggleRefresh, setFloor, reset } = useTvSettings()
const interval = computed(() => settings.slideSeconds)
const panelOpen = ref(false)
const dateParam = /^\d{4}-\d{2}-\d{2}$/.test(String(route.query.date || '')) ? String(route.query.date) : null
const { data, status, updatedAt, refresh } = useTvData({
  refreshSeconds: computed(() => settings.refreshSeconds),
  enabled: computed(() => settings.refreshEnabled),
  floor: computed(() => settings.floor),
  date: dateParam,
})
const clinics = computed(() => (data.value ? data.value.clinics : []))
const totals = computed(() => (data.value ? data.value.totals : null))
const show = (value) => (value === null || value === undefined ? '—' : value)

// A clinic with many doctors continues over several cards (GENERAL OPD 1/4, 2/4, ...), so every doctor
// of the clinic is shown. The doctors are spread evenly over the cards of a clinic.
const DOCTORS_PER_CARD = 5
const doctorCards = computed(() =>
  clinics.value.flatMap((c) => {
    if (!c.doctors.length) return []
    const parts = Math.ceil(c.doctors.length / DOCTORS_PER_CARD)
    return chunk(c.doctors, Math.ceil(c.doctors.length / parts)).map((doctors, i) => ({
      ...c,
      key: `${c.code}-${i}`,
      doctors,
      part: i + 1,
      parts,
    }))
  }),
)

const slides = computed(() => {
  const list = []
  if (props.mode !== 'doctors') {
    const pages = balancedChunks(clinics.value, clinicsPerPage.value)
    pages.forEach((items, i) =>
      list.push({ key: `clinics-${i}`, type: 'clinics', items, page: i + 1, pages: pages.length }),
    )
  }
  if (props.mode !== 'clinics') {
    const pages = balancedChunks(doctorCards.value, doctorCardsPerPage.value)
    pages.forEach((items, i) =>
      list.push({ key: `doctors-${i}`, type: 'doctors', items, page: i + 1, pages: pages.length }),
    )
  }
  if (!list.length) {
    list.push({ key: 'empty', type: props.mode === 'doctors' ? 'doctors' : 'clinics', items: [], page: 1, pages: 1 })
  }
  return list
})

const current = ref(0)
// One master switch: when auto refresh is off the numbers stop reloading AND the screens stop rotating.
const paused = computed(() => !settings.refreshEnabled)
const restarts = ref(0)
const now = ref(new Date())
const slide = computed(() => slides.value[Math.min(current.value, slides.value.length - 1)])
// A data refresh can change the page list (a clinic appears, a page disappears). Stay on the same
// page instead of jumping, so every page of a dashboard is still shown before the next dashboard.
watch(slides, (list, previous) => {
  const shownKey = previous && previous[current.value] ? previous[current.value].key : null
  const same = shownKey ? list.findIndex((s) => s.key === shownKey) : -1
  if (same >= 0) current.value = same
  else if (current.value >= list.length) current.value = 0

  // The first data arrives after the page has mounted: until then there is only one (loading)
  // slide, so no timer was started. Start the rotation as soon as there is more than one page.
  if (!slideTimer && !paused.value && list.length >= 2) startTimer()
})
// "Ground Floor" -> "GROUND", "First Floor" -> "1ST FLOOR" (Kiswahili: "CHINI", "GHOROFA YA 1")
const ORDINALS = { FIRST: 1, SECOND: 2, THIRD: 3, FOURTH: 4, FIFTH: 5, SIXTH: 6, SEVENTH: 7, EIGHTH: 8 }
const nth = (n) => `${n}${[, 'ST', 'ND', 'RD'][n] || 'TH'}`
const floorShort = (floor) => {
  const name = (floor.name || '').trim().toUpperCase()
  if (!name) return `${t('floor')} ${floor.code}`
  const sw = lang.value === 'sw'
  if (/^GROUND( FLOOR)?$/.test(name)) return sw ? 'CHINI' : 'GROUND'
  const ordinal = name.match(/^([A-Z]+) FLOOR$/)
  if (ordinal && ORDINALS[ordinal[1]]) return sw ? `GHOROFA YA ${ORDINALS[ordinal[1]]}` : `${nth(ORDINALS[ordinal[1]])} FLOOR`
  return name
}
const floorTitle = computed(() => {
  if (!settings.floor) return ''
  const known = floors.value.find((f) => f.code === settings.floor)
  return floorShort(known || { code: settings.floor, name: '' })
})
// the doctors dashboard names the floor it is registered to: "DOCTORS & PATIENTS GROUND"
const title = computed(() => {
  if (slide.value.type === 'clinics') return t('titleClinics')
  return floorTitle.value ? `${t('titleDoctors')} ${floorTitle.value}` : t('titleDoctors')
})
const floors = computed(() => (data.value ? data.value.floors : []))
const floorText = (floor) => floor.name || `${t('floor')} ${floor.code}`
// the floor this TV is registered to, shown next to the date
const floorTag = computed(() => {
  if (!settings.floor) return ''
  const known = floors.value.find((f) => f.code === settings.floor)
  return known ? floorText(known) : `${t('floor')} ${settings.floor}`
})
const dateLabel = computed(() => {
  const iso = data.value ? data.value.date : null
  const date = formatDate(iso ? new Date(`${iso}T00:00:00`) : new Date())
  // the doctors title already names the floor, so only the clinics screen repeats it next to the date
  return floorTag.value && slide.value.type === 'clinics' ? `${date} · ${floorTag.value}` : date
})
const updatedLabel = computed(() =>
  updatedAt.value
    ? updatedAt.value.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
    : '',
)

let slideTimer = null
let clockTimer = null

const startTimer = () => {
  clearTimeout(slideTimer)
  slideTimer = null
  restarts.value += 1
  if (paused.value || slides.value.length < 2) return
  slideTimer = setTimeout(() => go(1), interval.value * 1000)
}

// a new screen time takes effect straight away
watch(interval, () => startTimer())

const go = (step) => {
  const total = slides.value.length
  current.value = (current.value + step + total) % total
  startTimer()
}

const togglePause = () => toggleRefresh()
watch(paused, () => startTimer())

const onKey = (event) => {
  if (event.key === 'ArrowRight') go(1)
  else if (event.key === 'ArrowLeft') go(-1)
  else if (event.key === 'r' || event.key === 'R') refresh()
  else if (event.key === 'Escape') panelOpen.value = false
  else if (event.key === ' ') {
    event.preventDefault()
    togglePause()
  }
}

const clock = computed(() =>
  now.value.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
)

onMounted(() => {
  startTimer()
  clockTimer = setInterval(() => {
    now.value = new Date()
  }, 1000)
  window.addEventListener('keydown', onKey)
  window.addEventListener('resize', onResize)
})

onBeforeUnmount(() => {
  clearTimeout(slideTimer)
  clearInterval(clockTimer)
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', onResize)
})
</script>

<template>
  <div class="tv" :class="{ 'tv--paused': paused }">
    <!-- Heartbeat: one beat travels in from the left edge and one from the right edge.
         When they meet in the centre the screen changes to the next slide. -->
    <div
      :key="`${current}-${restarts}`"
      class="tv-pulse"
      :class="{ 'is-loop': slides.length < 2 }"
      :style="{
        '--pulse-time': `${interval}s`,
        '--pulse-state': paused ? 'paused' : 'running',
      }"
      aria-hidden="true"
    >
      <div class="tv-pulse__half tv-pulse__half--left">
        <div class="tv-pulse__clip">
          <svg class="tv-pulse__svg" width="100%" height="28">
            <defs>
              <pattern id="tv-ecg" width="160" height="28" patternUnits="userSpaceOnUse">
                <path d="M0 14 H30 Q36 5 42 14 H54 L58 17 L63 1 L68 26 L72 14 H88 Q98 3 108 14 H160" />
              </pattern>
            </defs>
            <rect width="100%" height="28" fill="url(#tv-ecg)" />
          </svg>
        </div>
        <span class="tv-pulse__dot"></span>
      </div>
      <div class="tv-pulse__half tv-pulse__half--right">
        <div class="tv-pulse__clip">
          <svg class="tv-pulse__svg tv-pulse__svg--flip" width="100%" height="28">
            <rect width="100%" height="28" fill="url(#tv-ecg)" />
          </svg>
        </div>
        <span class="tv-pulse__dot"></span>
      </div>
    </div>

    <div class="tv-top">
      <button
        type="button"
        class="tv-tool"
        :class="{ 'is-on': panelOpen }"
        :title="t('setTitle')"
        :aria-label="t('setTitle')"
        @click="panelOpen = !panelOpen"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <circle cx="12" cy="12" r="3" />
          <path
            d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"
          />
        </svg>
      </button>
      <router-link class="tv-tool" :to="{ name: 'TvControl' }" :title="t('setControl')" :aria-label="t('setControl')">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0" />
          <circle cx="16" cy="6" r="2" />
          <circle cx="10" cy="12" r="2" />
          <circle cx="18" cy="18" r="2" />
        </svg>
      </router-link>

    <div class="tv-lang" role="group" :aria-label="t('switchLabel')">
      <svg class="tv-lang__icon" viewBox="0 0 24 24" aria-hidden="true">
        <circle cx="12" cy="12" r="9" />
        <path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18" />
      </svg>
      <button type="button" :class="{ 'is-active': lang === 'en' }" @click="setLang('en')">EN</button>
      <button type="button" :class="{ 'is-active': lang === 'sw' }" @click="setLang('sw')">SW</button>
    </div>
    </div>

    <div v-if="panelOpen" class="tv-panel" role="dialog" :aria-label="t('setTitle')">
      <div class="tv-panel__title">{{ t('setTitle') }}</div>

      <div class="tv-panel__block">
        <label for="tv-floor">{{ t('setFloorTitle') }}</label>
        <select id="tv-floor" :value="settings.floor" @change="setFloor($event.target.value)">
          <option value="">{{ t('setAllFloors') }}</option>
          <option v-for="f in floors" :key="f.code" :value="f.code">{{ floorText(f) }}</option>
          <option
            v-if="settings.floor && !floors.some((f) => f.code === settings.floor)"
            :value="settings.floor"
          >
            {{ floorTag }}
          </option>
        </select>
        <small>{{ t('setFloorHint') }}</small>
      </div>

      <div class="tv-panel__row">
        <span>{{ t('setAutoRefresh') }}</span>
        <button
          type="button"
          class="tv-switch"
          :class="{ 'is-on': settings.refreshEnabled }"
          @click="toggleRefresh"
        >
          {{ settings.refreshEnabled ? t('setOn') : t('setStopped') }}
        </button>
      </div>

      <div class="tv-panel__row" :class="{ 'is-dim': !settings.refreshEnabled }">
        <span>{{ t('setRefreshEvery') }}</span>
        <span class="tv-stepper">
          <button type="button" :disabled="settings.refreshSeconds <= REFRESH_STEPS[0]" @click="refreshStep(-1)">−</button>
          <b>{{ settings.refreshSeconds }}{{ t('setSeconds') }}</b>
          <button type="button" :disabled="settings.refreshSeconds >= REFRESH_STEPS[REFRESH_STEPS.length - 1]" @click="refreshStep(1)">+</button>
        </span>
      </div>

      <div class="tv-panel__row">
        <span>{{ t('setSlideEvery') }}</span>
        <span class="tv-stepper">
          <button type="button" :disabled="settings.slideSeconds <= 5" @click="slideStep(-1)">−</button>
          <b>{{ settings.slideSeconds }}{{ t('setSeconds') }}</b>
          <button type="button" :disabled="settings.slideSeconds >= 60" @click="slideStep(1)">+</button>
        </span>
      </div>

      <div class="tv-panel__actions">
        <button type="button" @click="refresh">{{ t('setRefreshNow') }}</button>
        <button type="button" @click="reset">{{ t('setReset') }}</button>
      </div>
    </div>

    <header class="tv-header">
      <div class="tv-heading">
        <span class="tv-logo"><img src="@/assets/images/muhilogo.jpg" alt="Muhimbili National Hospital" /></span>
        <div class="tv-title">
          <h1>{{ title }}</h1>
          <p>{{ dateLabel }}</p>
        </div>
      </div>

      <div class="tv-stats">
        <div class="tv-stat">
          <strong>{{ show(totals && totals.clinics) }}</strong>
          <span>{{ t('statClinics') }}</span>
        </div>
        <div class="tv-stat">
          <strong>{{ show(totals && totals.doctorsWithPatients) }}</strong>
          <span>{{ t('statDoctors') }}</span>
        </div>
        <div class="tv-stat">
          <strong>{{ show(totals && totals.waiting) }}</strong>
          <span>{{ t('statPatients') }}</span>
        </div>
      </div>

      <div class="tv-clock">{{ clock }}</div>
    </header>

    <main class="tv-stage">
      <div v-if="!data" class="tv-message">
        {{ status === 'loading' ? t('loading') : t('unavailable') }}
      </div>
      <Transition v-else name="slide" mode="out-in">
        <TvClinicsSlide
          v-if="slide.type === 'clinics'"
          :key="slide.key"
          :items="slide.items"
          :max-cols="cols"
          :aspect="stageAspect"
        />
        <TvDoctorsSlide v-else :key="slide.key" :items="slide.items" :max-cols="cols" :aspect="stageAspect" />
      </Transition>
    </main>

    <footer class="tv-footer">
      <div v-if="slide.type === 'doctors'" class="tv-legend">
        <span class="is-booked">● {{ t('legendBooked') }}</span>
        <span class="is-waiting">● {{ t('legendWaiting') }}</span>
        <span class="is-seen">● {{ t('legendSeen') }}</span>
      </div>
      <div v-else class="tv-legend"></div>

      <!-- a few screens: one dot each; many screens (every doctor card): a slim progress bar instead -->
      <div v-if="slides.length <= 15" class="tv-dots">
        <span
          v-for="(s, i) in slides"
          :key="s.key"
          class="tv-dot"
          :class="{ 'is-current': i === current }"
        />
      </div>
      <div v-else class="tv-progress" aria-hidden="true">
        <span :style="{ width: `${((current + 1) / slides.length) * 100}%` }"></span>
      </div>

      <div class="tv-page">
        {{ slide.type === 'clinics' ? t('pageClinics') : t('pageDoctors') }} {{ slide.page }}/{{ slide.pages }}
        <button
          v-if="updatedLabel"
          type="button"
          class="tv-refresh"
          :title="t('refresh')"
          @click="refresh"
        >
          · {{ t('updated') }} {{ updatedLabel }}
        </button>
        <span v-if="!settings.refreshEnabled" class="tv-warn"> · {{ t('refreshOff') }}</span>
        <span v-else-if="status === 'stale'" class="tv-warn"> · {{ t('stale') }}</span>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.tv {
  /* Palette taken from the Muhimbili logo: red #E42424, navy #242484, yellow #FCE40C */
  --tv-bg: #0b0b38;
  --tv-bg-glow: #1b1b78;
  --tv-card: #15155e;
  --tv-line: #34349a;
  --tv-text: #ffffff;
  --tv-muted: #aeb4ee;
  --tv-red: #e42424;
  --tv-accent: #fce40c;
  --tv-booked: #7db4ff;
  --tv-waiting: #ff9f43;
  --tv-seen: #3fdc98;
  --tv-absent: #ff6464;

  position: fixed;
  inset: 0;
  z-index: 1000;
  display: grid;
  grid-template-rows: auto 1fr auto;
  /* scales with whichever is tighter, screen height or width, so nothing overflows on any device */
  font-size: min(1.8vh, 1.05vw);
  color: var(--tv-text);
  background: radial-gradient(120% 120% at 50% 0%, var(--tv-bg-glow) 0%, var(--tv-bg) 62%);
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  overflow: hidden;
}

.tv-pulse {
  position: absolute;
  top: 0.35em;
  left: 0;
  right: 0;
  height: 28px;
  display: flex;
  pointer-events: none;
}
/* faint flat track the two beats travel along */
.tv-pulse::before {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  top: 14px;
  height: 1px;
  background: rgba(228, 36, 36, 0.28);
}
.tv-pulse__half {
  position: relative;
  flex: 1 1 50%;
  height: 100%;
}
.tv-pulse__clip {
  height: 100%;
  animation: reveal-left var(--pulse-time) linear forwards;
  animation-play-state: var(--pulse-state);
}
.tv-pulse__half--right .tv-pulse__clip {
  animation-name: reveal-right;
}
.tv-pulse__svg {
  display: block;
  filter: drop-shadow(0 0 3px rgba(228, 36, 36, 0.9));
}
.tv-pulse__svg--flip {
  transform: scaleX(-1); /* the right-hand beat comes in mirrored, from the right edge */
}
.tv-pulse__svg path {
  fill: none;
  stroke: var(--tv-red);
  stroke-width: 2.2;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.tv-pulse__dot {
  position: absolute;
  top: 14px;
  width: 10px;
  height: 10px;
  margin: -5px;
  border-radius: 50%;
  background: #ff5a5a;
  box-shadow: 0 0 10px 3px rgba(255, 90, 90, 0.85);
  animation: dot-left var(--pulse-time) linear forwards;
  animation-play-state: var(--pulse-state);
}
.tv-pulse__half--right .tv-pulse__dot {
  animation-name: dot-right;
}
.tv-pulse.is-loop .tv-pulse__clip,
.tv-pulse.is-loop .tv-pulse__dot {
  animation-iteration-count: infinite;
}
@keyframes reveal-left {
  from { clip-path: inset(0 100% 0 0); }
  to { clip-path: inset(0 0 0 0); }
}
@keyframes reveal-right {
  from { clip-path: inset(0 0 0 100%); }
  to { clip-path: inset(0 0 0 0); }
}
@keyframes dot-left {
  from { left: 0; }
  to { left: 100%; }
}
@keyframes dot-right {
  from { right: 0; }
  to { right: 100%; }
}

.tv-header {
  position: relative;
  display: grid;
  grid-template-columns: 1fr auto auto;
  align-items: center;
  gap: 2.5em;
  padding: 2.4em 2.2em 0.9em;
}

.tv-top {
  position: absolute;
  top: 0.7em;
  right: 1.1em;
  z-index: 5;
  display: flex;
  align-items: center;
  gap: 0.5em;
  font-size: 0.8em;
}
.tv-tool {
  display: grid;
  place-items: center;
  width: 2em;
  height: 2em;
  padding: 0;
  border: 0;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.08);
  color: var(--tv-muted);
  cursor: pointer;
}
.tv-tool:hover,
.tv-tool.is-on {
  background: rgba(255, 255, 255, 0.18);
  color: #ffffff;
}
.tv-tool svg {
  width: 1.15em;
  height: 1.15em;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.7;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.tv-panel {
  position: absolute;
  top: 3.6em;
  right: 1.1em;
  z-index: 20;
  width: 24em;
  padding: 1em 1.2em;
  border: 1px solid var(--tv-line);
  border-radius: 0.8em;
  background: #10104a;
  box-shadow: 0 1em 3em rgba(0, 0, 0, 0.6);
  font-size: 0.9em;
}
.tv-panel__title {
  margin-bottom: 0.7em;
  color: var(--tv-accent);
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}
.tv-panel__block {
  display: grid;
  gap: 0.4em;
  padding: 0.55em 0 0.7em;
  border-top: 1px solid var(--tv-line);
}
.tv-panel__block label {
  font-weight: 700;
}
.tv-panel__block small {
  color: var(--tv-muted);
}
.tv-panel select {
  padding: 0.4em 0.6em;
  border: 1px solid var(--tv-line);
  border-radius: 0.5em;
  background: #1b1b78;
  color: var(--tv-text);
  font: inherit;
}
.tv-panel__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1em;
  padding: 0.55em 0;
  border-top: 1px solid var(--tv-line);
}
.tv-panel__row.is-dim {
  opacity: 0.45;
}
.tv-stepper {
  display: inline-flex;
  align-items: center;
  gap: 0.6em;
}
.tv-stepper b {
  min-width: 3.2em;
  text-align: center;
  font-variant-numeric: tabular-nums;
}
.tv-panel button {
  padding: 0.3em 0.8em;
  border: 1px solid var(--tv-line);
  border-radius: 0.5em;
  background: rgba(255, 255, 255, 0.08);
  color: var(--tv-text);
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}
.tv-panel button:disabled {
  opacity: 0.35;
  cursor: default;
}
.tv-panel .tv-switch.is-on {
  border-color: var(--tv-seen);
  background: rgba(63, 220, 152, 0.18);
  color: var(--tv-seen);
}
.tv-panel__actions {
  display: flex;
  gap: 0.6em;
  margin-top: 0.8em;
  padding-top: 0.8em;
  border-top: 1px solid var(--tv-line);
}

.tv-lang {
  display: flex;
  align-items: center;
  gap: 0.2em;
  padding: 0.2em 0.35em 0.2em 0.55em;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.08);
  color: var(--tv-muted);
}
.tv-lang__icon {
  width: 1.2em;
  height: 1.2em;
  margin-right: 0.2em;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.6;
  stroke-linecap: round;
}
.tv-lang button {
  padding: 0.15em 0.55em;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-weight: 700;
  letter-spacing: 0.06em;
  cursor: pointer;
}
.tv-lang button.is-active {
  background: var(--tv-red);
  color: #ffffff;
}

/* title and date sit in the middle of the header, between the logo and the numbers */
.tv-title {
  position: absolute;
  top: 2.4em;
  bottom: 0.9em;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  justify-content: center;
  text-align: center;
  white-space: nowrap;
}

.tv-heading {
  display: flex;
  align-items: center;
  gap: 1em;
}
.tv-logo {
  display: grid;
  place-items: center;
  padding: 0.35em;
  border-radius: 0.6em;
  background: #ffffff;
  border-bottom: 0.25em solid var(--tv-red);
}
.tv-logo img {
  display: block;
  height: 4.2em;
  width: auto;
}
h1 {
  margin: 0;
  color: var(--tv-text);
  font-size: 2.6em;
  font-weight: 800;
  letter-spacing: 0.03em;
  line-height: 1.05;
}
.tv-heading p {
  margin: 0.2em 0 0;
  color: var(--tv-muted);
  font-size: 1.3em;
}

.tv-stats {
  display: flex;
  gap: 2em;
}
.tv-stat {
  display: flex;
  flex-direction: column;
  align-items: center;
}
.tv-stat strong {
  color: var(--tv-accent);
  font-size: 2.6em;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}
.tv-stat span {
  margin-top: 0.25em;
  color: var(--tv-muted);
  font-size: 0.8em;
  font-weight: 700;
  letter-spacing: 0.08em;
}

.tv-clock {
  font-size: 3em;
  font-weight: 300;
  font-variant-numeric: tabular-nums;
}

.tv-stage {
  position: relative;
  min-height: 0;
  padding: 0 2.2em;
}

.tv-footer {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  padding: 0.7em 2.2em 2em;
  color: var(--tv-muted);
  font-size: 1.05em;
}
.tv-legend {
  display: flex;
  gap: 1.6em;
  font-weight: 700;
}
.tv-page {
  text-align: right;
}
.tv-refresh {
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
.tv-warn {
  color: var(--tv-waiting);
  font-weight: 800;
}
.tv-message {
  height: 100%;
  display: grid;
  place-items: center;
  padding: 0 2em;
  color: var(--tv-muted);
  font-size: 2.2em;
  text-align: center;
}

.tv-progress {
  width: 22em;
  max-width: 40vw;
  height: 0.45em;
  border-radius: 999px;
  background: var(--tv-line);
  overflow: hidden;
}
.tv-progress span {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--tv-red);
  transition: width 0.4s ease;
}

.tv-dots {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  max-width: 45vw;
  gap: 0.55em;
}
.tv-dot {
  width: 0.7em;
  height: 0.7em;
  border-radius: 50%;
  background: var(--tv-line);
}
.tv-dot.is-current {
  background: var(--tv-red);
}

.is-booked {
  color: var(--tv-booked);
}
.is-waiting {
  color: var(--tv-waiting);
}
.is-seen {
  color: var(--tv-seen);
}

/* Large screens: "clinics open" and "doctors with patients" sit on the left next to the logo,
   "patients waiting" and the clock stay on the right, the title is centred between them. */
@media (min-width: 1001px) {
  .tv-header {
    grid-template-columns: auto auto auto 1fr auto auto;
  }
  .tv-header > * {
    grid-row: 1;
  }
  .tv-stats {
    display: contents;
  }
  .tv-heading {
    grid-column: 1;
  }
  .tv-stat:nth-child(1) {
    grid-column: 2;
  }
  .tv-stat:nth-child(2) {
    grid-column: 3;
  }
  .tv-stat:nth-child(3) {
    grid-column: 5;
  }
  .tv-clock {
    grid-column: 6;
  }
}

/* Tablets and phones: stack the header and footer, shrink the text, keep everything on screen. */
@media (max-width: 1000px) {
  .tv {
    font-size: clamp(10px, 2.1vw, 20px);
  }
  .tv-header {
    grid-template-columns: 1fr auto;
    gap: 0.8em 1.2em;
    padding: 2.6em 1em 0.7em;
  }
  .tv-stats {
    grid-column: 1 / -1;
    order: 3;
    justify-content: space-between;
    gap: 0.8em;
  }
  .tv-stat {
    flex: 1 1 0;
    text-align: center;
  }
  .tv-stat span {
    font-size: 0.7em;
  }
  h1 {
    font-size: 1.7em;
  }
  .tv-heading p {
    font-size: 1.05em;
  }
  .tv-logo img {
    height: 3em;
  }
  /* small screens: no room to float in the middle, so the title stays next to the logo */
  .tv-title {
    position: static;
    transform: none;
    text-align: left;
    white-space: normal;
  }
  .tv-clock {
    font-size: 2em;
  }
  .tv-stage {
    padding: 0 1em;
  }
  .tv-footer {
    grid-template-columns: auto 1fr;
    gap: 0.4em 1em;
    padding: 0.5em 1em 1em;
    font-size: 0.95em;
  }
  .tv-legend {
    grid-column: 1 / -1;
    flex-wrap: wrap;
    gap: 0.4em 1.2em;
  }
  .tv-dots {
    flex-wrap: wrap;
  }
}

.slide-enter-active,
.slide-leave-active {
  transition:
    opacity 0.45s ease,
    transform 0.45s ease;
}
.slide-enter-from {
  opacity: 0;
  transform: translateX(2.5%);
}
.slide-leave-to {
  opacity: 0;
  transform: translateX(-2.5%);
}
</style>
