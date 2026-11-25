<script setup>
import { computed, onMounted, ref } from 'vue'
import logo from '@/assets/images/muhilogo.jpg'
import { initLang, useTvI18n } from './i18n'

// Accountant page for the TV dashboards (login required). Only counts are shown: patients are never
// listed, so a "move" shifts a number of waiting patients from one doctor to another.

const API_BASE = import.meta.env.VITE_API_BASE_URL || '/api/v1'

initLang()
const { lang, setLang, t } = useTvI18n()

const hospitalToday = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Dar_es_Salaam' })

const date = ref(hospitalToday())
const payload = ref(null)
const loading = ref(true)
const error = ref('')
const forbidden = ref(false)
const toast = ref('')
const busy = ref(false)

const clinicQuery = ref('')
const doctorQuery = ref('')
const showAll = ref(false) // also list assigned doctors that are not in the clinic today
const department = ref('')
const floor = ref('')
const open = ref(new Set())
const moving = ref(null) // { clinic, from, to, count } while the move form is open
const migrating = ref(null) // { clinic, doctor, target } while the migrate form is open
const view = ref(null) // null | 'off' | 'frozen' | 'migrated': the lists of switched-off doctors

// The control page always asks for its own login. The token lives in sessionStorage only, so it is
// gone when the tab/browser is closed and it is never shared with the dashboard session.
const TOKEN_KEY = 'tv_ctl_token'
const USER_KEY = 'tv_ctl_user'
const readSession = (key) => {
  try {
    return sessionStorage.getItem(key)
  } catch {
    return null
  }
}
const writeSession = (key, value) => {
  try {
    value === null ? sessionStorage.removeItem(key) : sessionStorage.setItem(key, value)
  } catch {
    // blocked storage: the login then lasts until the page reloads
  }
}

const token = ref(readSession(TOKEN_KEY))
const userName = ref(readSession(USER_KEY) || '')
const loginEmail = ref('')
const loginPassword = ref('')
const loginError = ref('')
const loggingIn = ref(false)

const signOut = async (callServer = true) => {
  const current = token.value
  token.value = null
  userName.value = ''
  payload.value = null
  writeSession(TOKEN_KEY, null)
  writeSession(USER_KEY, null)
  if (callServer && current) {
    fetch(`${API_BASE}/logout`, { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${current}` } }).catch(() => {})
  }
}

const signIn = async () => {
  loggingIn.value = true
  loginError.value = ''
  try {
    const response = await fetch(`${API_BASE}/login`, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: loginEmail.value, password: loginPassword.value, device_name: 'tv-control' }),
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok || !data.data || !data.data.token) {
      loginError.value = t('ctlLoginFailed')
      return
    }
    token.value = data.data.token
    userName.value = data.data.user.name || data.data.user.email
    writeSession(TOKEN_KEY, token.value)
    writeSession(USER_KEY, userName.value)
    loginPassword.value = ''
    await load()
  } catch {
    loginError.value = t('ctlLoginFailed')
  } finally {
    loggingIn.value = false
  }
}

const api = async (method, path, body) => {
  const response = await fetch(`${API_BASE}${path}`, {
    method,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  })
  const data = await response.json().catch(() => ({}))
  if (response.status === 401) await signOut(false) // session expired: back to the login form
  if (!response.ok) {
    const failure = new Error(data.message || `HTTP ${response.status}`)
    failure.status = response.status
    throw failure
  }
  return data
}

const load = async () => {
  if (!token.value) {
    loading.value = false
    return
  }
  loading.value = true
  error.value = ''
  forbidden.value = false
  moving.value = null
  try {
    payload.value = await api('GET', `/tv/control/overview?date=${encodeURIComponent(date.value)}`)
  } catch (failure) {
    payload.value = null
    forbidden.value = failure.status === 403
    error.value = failure.status === 403 ? '' : failure.message
  } finally {
    loading.value = false
  }
}

onMounted(load)

const clinics = computed(() => (payload.value ? payload.value.clinics : []))

const floorLabel = (clinic) =>
  clinic.floor_name || (clinic.floor_code ? `${t('ctlFloor')} ${clinic.floor_code}` : '')

const departments = computed(() => {
  const seen = new Map()
  clinics.value.forEach((c) => c.department_code && seen.set(c.department_code, c.department))
  return [...seen].map(([code, name]) => ({ code, name })).sort((a, b) => a.name.localeCompare(b.name))
})

const floors = computed(() => {
  const seen = new Map()
  clinics.value.forEach((c) => c.floor_code && seen.set(c.floor_code, floorLabel(c)))
  return [...seen].map(([code, name]) => ({ code, name })).sort((a, b) => a.code.localeCompare(b.code))
})

const norm = (text) => String(text || '').toLowerCase()

// Clinics that pass every filter; when searching for a doctor only the matching doctors are listed.
const visible = computed(() => {
  const cq = norm(clinicQuery.value).trim()
  const dq = norm(doctorQuery.value).trim()
  return clinics.value
    .filter((c) => (!cq || norm(c.name).includes(cq)) && (!department.value || c.department_code === department.value) && (!floor.value || c.floor_code === floor.value))
    .map((c) => ({
      clinic: c,
      doctors: c.doctors.filter((d) => (dq ? norm(d.name).includes(dq) || norm(d.code).includes(dq) : showAll.value || d.visible)),
      assigned: c.doctors.length,
    }))
    .filter((row) => !dq || row.doctors.length)
})

const isOpen = (code) => open.value.has(code) || Boolean(doctorQuery.value.trim())
const toggleOpen = (code) => {
  const next = new Set(open.value)
  next.has(code) ? next.delete(code) : next.add(code)
  open.value = next
}

const flash = (message) => {
  toast.value = message
  setTimeout(() => (toast.value = ''), 2500)
}

// replace one clinic with the recomputed copy returned by the server
const replaceClinic = (clinic) => {
  if (!clinic || !payload.value) return
  payload.value = {
    ...payload.value,
    clinics: payload.value.clinics.map((c) => (c.code === clinic.code ? clinic : c)),
  }
}

const save = async (path, body) => {
  busy.value = true
  error.value = ''
  try {
    const result = await api('PUT', path, body)
    replaceClinic(result.clinic)
    flash(t('ctlSaved'))
    return true
  } catch (failure) {
    error.value = failure.message
    return false
  } finally {
    busy.value = false
  }
}

// A doctor is on the TV when he has patients in this clinic today, or when he was switched on here.
// The switch means "in the clinic today": which stored state gives the wanted result depends on that default.
const inClinic = (doctor) => !doctor.rule && doctor.visible && !doctor.off
const toggleDoctor = (clinic, doctor) => {
  const wantIn = !inClinic(doctor)
  // state 'on' / 'off' is only stored when it differs from what the doctor is by default
  const state = wantIn ? (doctor.default_visible ? null : 'on') : doctor.default_visible ? 'off' : null
  return save('/tv/control/doctor', { date: date.value, clinic_code: clinic.code, doctor_code: doctor.code, state })
}

// Permanent rules (not tied to a day). They survive reloads until the accountant turns the switch back.
const quietReload = async () => {
  try {
    payload.value = await api('GET', `/tv/control/overview?date=${encodeURIComponent(date.value)}`)
  } catch (failure) {
    error.value = failure.message
  }
}

const saveRule = async (clinicCode, doctorCode, rule, target = null) => {
  busy.value = true
  error.value = ''
  try {
    await api('PUT', '/tv/control/rule', {
      date: date.value,
      clinic_code: clinicCode,
      doctor_code: doctorCode,
      rule,
      ...(target ? { target_clinic_code: target } : {}),
    })
    await quietReload()
    flash(t('ctlSaved'))
    return true
  } catch (failure) {
    error.value = failure.message
    return false
  } finally {
    busy.value = false
  }
}

const isFrozen = (d) => d.rule === 'frozen'
const isMigrated = (d) => d.rule === 'migrated'
const toggleFreeze = (clinic, d) => saveRule(clinic.code, d.code, isFrozen(d) ? null : 'frozen')

const toggleMigrate = (clinic, d) => {
  if (isMigrated(d)) {
    migrating.value = null
    return saveRule(clinic.code, d.code, null)
  }
  if (migrating.value && migrating.value.clinic === clinic.code && migrating.value.doctor === d.code) {
    migrating.value = null // closes the form
    return
  }
  migrating.value = { clinic: clinic.code, doctor: d.code, target: '' }
}

const migrateTargets = (clinic) =>
  clinics.value
    .filter((c) => c.code !== clinic.code)
    .map((c) => ({ code: c.code, label: `${clinicName(c.name)}${floorLabel(c) ? ` · ${floorLabel(c)}` : ''}` }))
    .sort((a, b) => a.label.localeCompare(b.label))

const applyMigrate = async (clinic) => {
  const m = migrating.value
  if (!m || !m.target) return
  if (await saveRule(clinic.code, m.doctor, 'migrated', m.target)) migrating.value = null
}

const startMove = (clinic, doctor) => {
  const target = clinic.doctors.find((d) => d.code !== doctor.code && !d.off && d.visible) || clinic.doctors.find((d) => d.code !== doctor.code && !d.off)
  moving.value = { clinic: clinic.code, from: doctor.code, to: target ? target.code : '', count: 1 }
}

const applyMove = async (clinic) => {
  const m = moving.value
  if (!m || !m.to) return
  if (await save('/tv/control/move', { date: date.value, clinic_code: clinic.code, from_doctor_code: m.from, to_doctor_code: m.to, count: Number(m.count) })) {
    moving.value = null
  }
}

const undoMove = (clinic, move) =>
  save('/tv/control/move', { date: date.value, clinic_code: clinic.code, from_doctor_code: move.from, to_doctor_code: move.to, count: 0 })

const maxMove = (clinic) => {
  const from = moving.value && clinic.doctors.find((d) => d.code === moving.value.from)
  return from ? Math.max(1, from.waiting || 1) : 1
}

// every doctor assigned to the clinic can receive patients, except one switched off for the day
const targets = (clinic) => clinic.doctors.filter((d) => moving.value && d.code !== moving.value.from && !d.off && !d.rule)
const targetsShown = (clinic) => targets(clinic).filter((d) => d.visible)
const targetsOther = (clinic) => targets(clinic).filter((d) => !d.visible)

// "MLG GENERAL OPD" -> "GENERAL OPD", "MLG-INTERNAL MEDICINE DEPARTMENT" -> "INTERNAL MEDICINE"
const clinicName = (name) => String(name || '').replace(/^MLG\s+/i, '')
const deptLabel = (name) =>
  String(name || '')
    .replace(/^MLG\s*-\s*/i, '')
    .replace(/\s+DEPARTMENT$/i, '')

// the four tiles above the list (for the clinics that pass the filters)
const summary = computed(() => ({
  clinics: visible.value.length,
  waiting: visible.value.reduce((n, row) => n + (row.clinic.waiting || 0), 0),
  off: visible.value.reduce((n, row) => n + row.clinic.doctors.filter((d) => d.off).length, 0),
  moves: visible.value.reduce((n, row) => n + (row.clinic.moves ? row.clinic.moves.length : 0), 0),
}))

// the "view" lists: doctors turned off for the viewed day, and the permanent freeze / migration rules
const rules = computed(() => (payload.value && payload.value.rules) || [])
const offList = computed(() =>
  clinics.value.flatMap((c) => c.doctors.filter((d) => d.off).map((d) => ({ clinic: c, doctor: d }))),
)
const frozenList = computed(() => rules.value.filter((r) => r.rule === 'frozen'))
const migratedList = computed(() => rules.value.filter((r) => r.rule === 'migrated'))
const counts = computed(() => ({ off: offList.value.length, frozen: frozenList.value.length, migrated: migratedList.value.length }))
const setView = (name) => {
  view.value = view.value === name ? null : name
}
const restoreRule = (r) => saveRule(r.clinic_code, r.doctor_code, null)
const restoreOff = (entry) => toggleDoctor(entry.clinic, entry.doctor)

const isFuture = computed(() => date.value > hospitalToday())
const waitingLabel = (n) => (n === null || n === undefined ? '—' : n)
</script>

<template>
  <div class="ctl">
    <div class="ctl-sticky">
    <header class="ctl-bar">
      <div class="ctl-brand">
        <span class="ctl-logo"><img :src="logo" alt="Muhimbili National Hospital" /></span>
        <router-link :to="{ name: 'TvDisplay' }" class="ctl-back">← {{ t('ctlBack') }}</router-link>
        <h1>{{ t('ctlTitle') }}</h1>
      </div>
      <div class="ctl-right">
      <span v-if="token" class="ctl-user">{{ userName }}</span>
      <button v-if="token" type="button" class="ctl-signout" @click="signOut()">{{ t('ctlSignOut') }}</button>
      <div class="ctl-lang" role="group">
        <button type="button" :class="{ 'is-active': lang === 'en' }" @click="setLang('en')">EN</button>
        <button type="button" :class="{ 'is-active': lang === 'sw' }" @click="setLang('sw')">SW</button>
      </div>
      </div>
    </header>
    <section v-if="token" class="ctl-filters">
      <label>
        <span>{{ t('ctlDate') }}</span>
        <span class="ctl-date">
          <input v-model="date" type="date" @change="load" />
          <button type="button" :disabled="date === hospitalToday()" @click="date = hospitalToday(); load()">{{ t('ctlToday') }}</button>
        </span>
      </label>
      <label>
        <span>{{ t('ctlClinicSearch') }}</span>
        <input v-model="clinicQuery" type="search" :placeholder="t('ctlClinicSearch')" />
      </label>
      <label>
        <span>{{ t('ctlDoctorSearch') }}</span>
        <input v-model="doctorQuery" type="search" :placeholder="t('ctlDoctorSearch')" />
      </label>
      <label>
        <span>{{ t('ctlDepartment') }}</span>
        <select v-model="department">
          <option value="">{{ t('ctlAll') }}</option>
          <option v-for="d in departments" :key="d.code" :value="d.code">{{ d.name }}</option>
        </select>
      </label>
      <label>
        <span>{{ t('ctlFloor') }}</span>
        <select v-model="floor">
          <option value="">{{ t('ctlAll') }}</option>
          <option v-for="f in floors" :key="f.code" :value="f.code">{{ f.name }}</option>
        </select>
      </label>
      <label class="ctl-check">
        <input v-model="showAll" type="checkbox" />
        <span>{{ t('ctlShowAll') }}</span>
      </label>
      <button type="button" class="ctl-reload" :disabled="loading" @click="load">↻ {{ t('ctlReload') }}</button>
      <nav v-if="token && payload" class="ctl-views" :aria-label="t('ctlViews')">
        <span class="ctl-views__label">{{ t('ctlViews') }}</span>
        <button type="button" class="vw is-off" :class="{ 'is-active': view === 'off' }" @click="setView('off')">
          {{ t('ctlViewOff') }} <b>{{ counts.off }}</b>
        </button>
        <button type="button" class="vw is-frozen" :class="{ 'is-active': view === 'frozen' }" @click="setView('frozen')">
          {{ t('ctlViewFrozen') }} <b>{{ counts.frozen }}</b>
        </button>
        <button type="button" class="vw is-migrated" :class="{ 'is-active': view === 'migrated' }" @click="setView('migrated')">
          {{ t('ctlViewMigrated') }} <b>{{ counts.migrated }}</b>
        </button>
      </nav>
    </section>
    </div>

    <form v-if="!token" class="ctl-login" @submit.prevent="signIn">
      <h2>{{ t('ctlLoginTitle') }}</h2>
      <p class="ctl-muted">{{ t('ctlLoginHint') }}</p>
      <label>
        <span>{{ t('ctlEmail') }}</span>
        <input v-model="loginEmail" type="email" autocomplete="username" required />
      </label>
      <label>
        <span>{{ t('ctlPassword') }}</span>
        <input v-model="loginPassword" type="password" autocomplete="current-password" required />
      </label>
      <p v-if="loginError" class="ctl-error" role="alert">{{ loginError }}</p>
      <button type="submit" class="primary" :disabled="loggingIn">{{ loggingIn ? t('ctlLoading') : t('ctlSignIn') }}</button>
    </form>

    <template v-if="token">

    <p v-if="isFuture" class="ctl-note">{{ t('ctlFutureNote') }}</p>
    <p v-if="error" class="ctl-error" role="alert">{{ error }}</p>
    <p v-if="forbidden" class="ctl-error" role="alert">{{ t('ctlNoAccess') }}</p>
    <p v-if="loading" class="ctl-muted">{{ t('ctlLoading') }}</p>

    <template v-else-if="payload">
      <section class="ctl-stats">
        <div class="ctl-stat is-navy">
          <b>{{ summary.clinics }}<small> / {{ clinics.length }}</small></b>
          <span>{{ t('ctlClinics') }}</span>
        </div>
        <div class="ctl-stat is-orange">
          <b>{{ summary.waiting }}</b>
          <span>{{ t('ctlStatWaiting') }}</span>
        </div>
        <div class="ctl-stat is-red">
          <b>{{ summary.off }}</b>
          <span>{{ t('ctlStatOff') }}</span>
        </div>
        <div class="ctl-stat is-green">
          <b>{{ summary.moves }}</b>
          <span>{{ t('ctlStatMoves') }}</span>
        </div>
      </section>

      <section v-if="view" class="ctl-list">
        <header>
          <h2>{{ view === 'off' ? t('ctlViewOff') : view === 'frozen' ? t('ctlViewFrozen') : t('ctlViewMigrated') }}</h2>
          <p class="ctl-muted">{{ view === 'off' ? t('ctlListOffHint') : view === 'frozen' ? t('ctlListFrozenHint') : t('ctlListMigratedHint') }}</p>
          <button type="button" @click="view = null">← {{ t('ctlBackToClinics') }}</button>
        </header>
        <p v-if="!counts[view]" class="ctl-muted">{{ t('ctlListEmpty') }}</p>

        <ul v-if="view === 'off'">
          <li v-for="e in offList" :key="e.clinic.code + e.doctor.code">
            <span class="who"><b>{{ e.doctor.name }}</b> <small>{{ e.doctor.qualification }}</small></span>
            <span class="where">{{ clinicName(e.clinic.name) }}</span>
            <span class="note">{{ date }}</span>
            <button type="button" class="ctl-switch" :disabled="busy" :title="t('ctlRestore')" @click="restoreOff(e)"><span></span></button>
          </li>
        </ul>
        <ul v-else-if="view === 'frozen'">
          <li v-for="r in frozenList" :key="r.clinic_code + r.doctor_code">
            <span class="who"><b>{{ r.doctor_name || r.doctor_code }}</b></span>
            <span class="where">{{ clinicName(r.clinic_name || r.clinic_code) }}</span>
            <span class="note">{{ r.updated_by }}</span>
            <button type="button" class="ctl-switch is-frozen" :disabled="busy" :title="t('ctlRestore')" @click="restoreRule(r)"><span></span></button>
          </li>
        </ul>
        <ul v-else>
          <li v-for="r in migratedList" :key="r.clinic_code + r.doctor_code">
            <span class="who"><b>{{ r.doctor_name || r.doctor_code }}</b></span>
            <span class="where">{{ clinicName(r.clinic_name || r.clinic_code) }} → <b>{{ clinicName(r.target_clinic_name || r.target_clinic_code) }}</b></span>
            <span class="note">{{ r.updated_by }}</span>
            <button type="button" class="ctl-switch is-blue is-on" :disabled="busy" :title="t('ctlRestore')" @click="restoreRule(r)"><span></span></button>
          </li>
        </ul>
      </section>

      <p v-if="!view && !visible.length" class="ctl-muted">{{ t('ctlNoResults') }}</p>

      <div v-if="!view" class="ctl-grid">
      <article v-for="row in visible" :key="row.clinic.code" class="ctl-clinic" :class="{ 'is-open': isOpen(row.clinic.code) }">
        <button type="button" class="ctl-clinic__head" @click="toggleOpen(row.clinic.code)">
          <span class="ctl-clinic__title">
            <span class="ctl-clinic__name">{{ clinicName(row.clinic.name) }}</span>
            <span class="ctl-chips">
              <span class="ctl-chip">{{ deptLabel(row.clinic.department) }}</span>
              <span v-if="floorLabel(row.clinic)" class="ctl-chip is-floor">{{ floorLabel(row.clinic) }}</span>
            </span>
          </span>
          <span class="ctl-badge">
            <b>{{ waitingLabel(row.clinic.waiting) }}</b>
            <small>{{ t('ctlWaitingIn') }}</small>
          </span>
          <span class="ctl-chevron">{{ isOpen(row.clinic.code) ? '▲' : '▼' }}</span>
        </button>

        <div v-if="isOpen(row.clinic.code)" class="ctl-clinic__body">
          <p v-if="!row.doctors.length" class="ctl-muted">{{ row.assigned ? t('ctlNoneToday') : t('ctlNoDoctors') }}</p>

          <ul v-if="row.clinic.moves && row.clinic.moves.length" class="ctl-moves">
            <li v-for="m in row.clinic.moves" :key="m.from + m.to">
              {{ m.from_name }} → {{ m.to_name }}: <b>{{ m.applied }}</b>
              <span v-if="m.applied !== m.count" class="ctl-muted">({{ m.count }})</span>
              <button type="button" :disabled="busy" @click="undoMove(row.clinic, m)">{{ t('ctlUndo') }}</button>
            </li>
          </ul>

          <table v-if="row.doctors.length" class="ctl-table">
            <thead>
              <tr>
                <th>{{ t('colDoctor') }}</th>
                <th class="num is-billed">{{ t('colBooked') }}</th>
                <th class="num is-waiting">{{ t('colWaiting') }}</th>
                <th class="num is-seen">{{ t('colSeen') }}</th>
                <th class="tg">{{ t('ctlColToday') }}</th>
                <th class="tg">{{ t('ctlColActive') }}</th>
                <th class="tg">{{ t('ctlColMigrate') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="d in row.doctors" :key="d.code">
                <tr :class="{ 'is-off': d.off || !d.visible, 'is-frozen': isFrozen(d), 'is-migrated': isMigrated(d) }">
                  <td class="who">
                    <span class="ctl-doctor">{{ d.name }}</span>
                    <small class="ctl-sub">{{ d.qualification }}</small>
                    <span v-if="isFrozen(d)" class="ctl-tag frz">{{ t('ctlTagFrozen') }}</span>
                    <span v-else-if="isMigrated(d)" class="ctl-tag mig">→ {{ clinicName(d.migrated_to_name || d.migrated_to) }}</span>
                    <span v-else-if="d.migrated_from" class="ctl-tag in">← {{ clinicName(d.migrated_from_name || d.migrated_from) }}</span>
                    <span v-else-if="!d.visible" class="ctl-tag hid">{{ t('ctlHidden') }}</span>
                    <span v-if="d.moved_in" class="ctl-tag in">+{{ d.moved_in }}</span>
                    <span v-if="d.moved_out" class="ctl-tag out">−{{ d.moved_out }}</span>
                    <span v-if="d.off && d.waiting" class="ctl-warn" :title="t('ctlOffWarn', { n: d.waiting })">⚠ {{ d.waiting }}</span>
                  </td>
                  <td class="num">{{ waitingLabel(d.booked) }}</td>
                  <td class="num strong">{{ waitingLabel(d.waiting) }}</td>
                  <td class="num">{{ waitingLabel(d.seen) }}</td>
                  <td class="tg">
                    <button
                      type="button"
                      class="ctl-switch"
                      :class="{ 'is-on': inClinic(d) }"
                      :disabled="busy || Boolean(d.rule)"
                      :aria-pressed="inClinic(d)"
                      :title="inClinic(d) ? t('ctlOnDuty') : t('ctlOffDuty')"
                      @click="toggleDoctor(row.clinic, d)"
                    >
                      <span></span>
                    </button>
                  </td>
                  <td class="tg">
                    <button
                      type="button"
                      class="ctl-switch"
                      :class="{ 'is-on': !isFrozen(d), 'is-frozen': isFrozen(d) }"
                      :disabled="busy || isMigrated(d)"
                      :aria-pressed="!isFrozen(d)"
                      :title="isFrozen(d) ? t('ctlUnfreezeTip') : t('ctlFreezeTip')"
                      @click="toggleFreeze(row.clinic, d)"
                    >
                      <span></span>
                    </button>
                  </td>
                  <td class="tg">
                    <button
                      type="button"
                      class="ctl-switch is-blue"
                      :class="{ 'is-on': isMigrated(d) || (migrating && migrating.clinic === row.clinic.code && migrating.doctor === d.code) }"
                      :disabled="busy || isFrozen(d)"
                      :aria-pressed="isMigrated(d)"
                      :title="isMigrated(d) ? t('ctlUnmigrateTip') : t('ctlMigrateTip')"
                      @click="toggleMigrate(row.clinic, d)"
                    >
                      <span></span>
                    </button>
                  </td>
                  <td class="act">
                    <button
                      v-if="d.waiting > 0 && !d.rule"
                      type="button"
                      class="ctl-link"
                      :disabled="busy"
                      @click="moving && moving.from === d.code ? (moving = null) : startMove(row.clinic, d)"
                    >
                      {{ t('ctlMoveShort') }}
                    </button>
                  </td>
                </tr>
                <tr v-if="migrating && migrating.clinic === row.clinic.code && migrating.doctor === d.code" class="ctl-moverow">
                  <td colspan="8">
                    <div class="ctl-moveform">
                      <label>
                        <span>{{ t('ctlMigrateTo') }}</span>
                        <select v-model="migrating.target">
                          <option value="" disabled>—</option>
                          <option v-for="o in migrateTargets(row.clinic)" :key="o.code" :value="o.code">{{ o.label }}</option>
                        </select>
                      </label>
                      <button type="button" class="primary" :disabled="busy || !migrating.target" @click="applyMigrate(row.clinic)">{{ t('ctlConfirm') }}</button>
                      <button type="button" @click="migrating = null">{{ t('ctlCancel') }}</button>
                    </div>
                  </td>
                </tr>
                <tr v-if="moving && moving.clinic === row.clinic.code && moving.from === d.code" class="ctl-moverow">
                  <td colspan="8">
                    <div class="ctl-moveform">
                      <label>
                        <span>{{ t('ctlMoveTo') }}</span>
                        <select v-model="moving.to">
                          <optgroup :label="t('ctlOnTv')">
                            <option v-for="o in targetsShown(row.clinic)" :key="o.code" :value="o.code">{{ o.name }} ({{ waitingLabel(o.waiting) }})</option>
                          </optgroup>
                          <optgroup v-if="targetsOther(row.clinic).length" :label="t('ctlOtherAssigned')">
                            <option v-for="o in targetsOther(row.clinic)" :key="o.code" :value="o.code">{{ o.name }}</option>
                          </optgroup>
                        </select>
                      </label>
                      <label>
                        <span>{{ t('ctlHowMany') }}</span>
                        <input v-model.number="moving.count" type="number" min="1" :max="maxMove(row.clinic)" />
                      </label>
                      <button type="button" class="primary" :disabled="busy || !moving.to || moving.count < 1" @click="applyMove(row.clinic)">{{ t('ctlApply') }}</button>
                      <button type="button" @click="moving = null">{{ t('ctlCancel') }}</button>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </article>
      </div>
    </template>

    </template>

    <div v-if="toast" class="ctl-toast">{{ toast }}</div>
  </div>
</template>

<style scoped>
.ctl {
  --navy: #242484;
  --red: #e42424;
  --ink: #1b1d3a;
  --muted: #646a8f;
  --line: #dfe2f1;
  --bg: #f4f5fb;
  position: fixed;
  inset: 0;
  z-index: 1000;
  overflow-y: auto;
  /* the page uses the whole screen width; only the side gutter grows with the screen */
  --gutter: clamp(16px, 2.5vw, 56px);
  padding: 0 max(var(--gutter), env(safe-area-inset-right, 0px)) 4rem max(var(--gutter), env(safe-area-inset-left, 0px));
  background: var(--bg);
  color: var(--ink);
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  font-size: clamp(15px, 0.85vw, 20px); /* a little larger on big screens */
}
.ctl-bar {
  margin: 0 calc(var(--gutter) * -1) 0.7rem;
  padding: 0.9rem var(--gutter);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  background: var(--navy);
  border-bottom: 4px solid var(--red);
  color: #fff;
}
.ctl-brand {
  display: flex;
  align-items: baseline;
  gap: 1rem;
  flex-wrap: wrap;
}
.ctl-bar h1 {
  margin: 0;
  color: #fff;
  font-size: 1.3rem;
  letter-spacing: 0.04em;
}
.ctl-back {
  color: #cdd1ff;
  text-decoration: none;
  font-size: 0.9rem;
}
.ctl-right {
  display: flex;
  align-items: center;
  gap: 0.8rem;
}
.ctl-user {
  color: #cdd1ff;
  font-size: 0.9rem;
}
.ctl-signout {
  padding: 0.25rem 0.7rem;
  border: 1px solid #cdd1ff;
  border-radius: 999px;
  background: transparent;
  color: #fff;
  cursor: pointer;
}
.ctl-login {
  display: grid;
  gap: 0.9rem;
  width: min(100%, 420px);
  margin: 3rem auto 0;
  padding: 1.5rem;
  border: 1px solid var(--line);
  border-top: 5px solid var(--red);
  border-radius: 12px;
  background: #fff;
}
.ctl-login h2 {
  margin: 0;
  font-size: 1.25rem;
}
.ctl-login label {
  display: grid;
  gap: 0.25rem;
}
.ctl-login label span {
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.ctl-login button {
  padding: 0.6rem 1rem;
  border-radius: 8px;
  font-weight: 700;
  cursor: pointer;
}
.ctl-lang button {
  padding: 0.25rem 0.7rem;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: #cdd1ff;
  font-weight: 700;
  cursor: pointer;
}
.ctl-lang button.is-active {
  background: var(--red);
  color: #fff;
}

.ctl-filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 0.8rem;
  align-items: end;
}
.ctl-filters label {
  display: grid;
  gap: 0.25rem;
  min-width: 0;
}
.ctl-filters label > span:first-child {
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
input,
select {
  min-width: 0;
  padding: 0.5rem 0.6rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
  color: var(--ink);
  font: inherit;
}
.ctl-date {
  display: flex;
  gap: 0.4rem;
}
.ctl-date input {
  flex: 1;
}
button {
  font: inherit;
}
.ctl-date button,
.ctl-reload,
.ctl-moveform button,
.ctl-moves button {
  padding: 0.45rem 0.8rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
  color: var(--ink);
  font-weight: 600;
  cursor: pointer;
}
.ctl button:disabled {
  opacity: 0.5;
  cursor: default;
}
.primary {
  border-color: var(--navy) !important;
  background: var(--navy) !important;
  color: #fff !important;
}

.ctl-note,
.ctl-muted,
.ctl-count {
  color: var(--muted);
  margin-top: 0.8rem;
  margin-bottom: 0;
}
.ctl-error {
  margin-top: 0.8rem;
  margin-bottom: 0;
  padding: 0.6rem 0.8rem;
  border-radius: 8px;
  background: #fde8e8;
  color: #9b1c1c;
}

.ctl-clinic {
  margin-top: 0.8rem;
  border: 1px solid var(--line);
  border-left: 5px solid var(--red);
  border-radius: 10px;
  background: #fff;
  overflow: hidden;
}
.ctl-clinic__head {
  width: 100%;
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1.6fr) auto auto;
  align-items: center;
  gap: 0.8rem;
  padding: 0.75rem 1rem;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;
}
.ctl-clinic__name {
  font-weight: 800;
}
.ctl-clinic__meta {
  color: var(--muted);
  font-size: 0.88rem;
}
.ctl-clinic__count b {
  color: var(--navy);
  font-size: 1.15rem;
}
.ctl-chevron {
  color: var(--muted);
  font-size: 0.75rem;
}
.ctl-clinic__body {
  padding: 0 1rem 1rem;
  overflow-x: auto;
}
.ctl-moves {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin: 0 0 0.6rem;
  padding: 0;
  list-style: none;
}
.ctl-moves li {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.25rem 0.6rem;
  border-radius: 999px;
  background: #eef0ff;
  font-size: 0.88rem;
}
.ctl-moves button {
  padding: 0.1rem 0.5rem;
  font-size: 0.8rem;
}

.ctl-table {
  width: 100%;
  border-collapse: collapse;
}
.ctl-table th,
.ctl-table td {
  padding: 0.5rem 0.4rem;
  border-top: 1px solid var(--line);
  text-align: left;
  vertical-align: middle;
}
.ctl-table th {
  border-top: 0;
  color: var(--muted);
  font-size: 0.75rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}
.num {
  text-align: right !important;
  font-variant-numeric: tabular-nums;
}
.strong {
  font-weight: 800;
  color: var(--navy);
}
.is-off .ctl-doctor {
  color: var(--muted);
  text-decoration: line-through;
}
.ctl-sub {
  color: var(--muted);
  font-size: 0.8rem;
}
.ctl-tag {
  margin-left: 0.4rem;
  padding: 0.05rem 0.4rem;
  border-radius: 999px;
  font-weight: 700;
}
.ctl-tag.in {
  background: #dcf7ea;
  color: #11693f;
}
.ctl-tag.hid {
  background: #eceef7;
  color: var(--muted);
}
.ctl-check {
  grid-auto-flow: column;
  justify-content: start;
  align-items: center;
  align-self: center;
}
.ctl-check span {
  text-transform: none !important;
  font-size: 0.9rem !important;
  color: var(--ink) !important;
}
.ctl-tag.out {
  background: #fde8e8;
  color: #9b1c1c;
}
.ctl-warn {
  margin-top: 0.2rem;
  color: #9b1c1c;
  font-size: 0.8rem;
  font-weight: 600;
}

.ctl-switch {
  position: relative;
  width: 44px;
  height: 24px;
  padding: 0;
  border: 0;
  border-radius: 999px;
  background: #c7cbe0;
  cursor: pointer;
  transition: background 0.15s;
}
.ctl-switch span {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #fff;
  transition: transform 0.15s;
}
.ctl-switch.is-on {
  background: #1fa971;
}
.ctl-switch.is-on span {
  transform: translateX(20px);
}
.ctl-link {
  border: 0;
  background: none;
  color: var(--navy);
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
  white-space: nowrap;
}
.ctl-moverow td {
  background: #f7f8ff;
}
.ctl-moveform {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  gap: 0.8rem;
}
.ctl-moveform label {
  display: grid;
  gap: 0.2rem;
  font-size: 0.78rem;
  color: var(--muted);
  font-weight: 700;
  text-transform: uppercase;
}
.ctl-moveform input[type='number'] {
  width: 6rem;
}

.ctl-toast {
  position: fixed;
  left: 50%;
  bottom: 1.5rem;
  transform: translateX(-50%);
  padding: 0.6rem 1.2rem;
  border-radius: 999px;
  background: #1fa971;
  color: #fff;
  font-weight: 700;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}


/* the header and the filters stay at the top while the list scrolls underneath */
.ctl-sticky {
  position: sticky;
  top: 0;
  z-index: 30;
  margin: 0 calc(var(--gutter) * -1);
  padding: 0 var(--gutter) 0.7rem;
  background: #e9ebfa;
  box-shadow: 0 6px 14px rgba(36, 36, 132, 0.12);
}

/* ---------- look & feel: Muhimbili colours, cards, full-width grid ---------- */
.ctl {
  background: linear-gradient(180deg, #e9ebfa 0, var(--bg) 360px);
}
.ctl-bar {
  box-shadow: 0 4px 16px rgba(36, 36, 132, 0.25);
}
.ctl-brand {
  align-items: center;
  gap: 0.9rem;
}
.ctl-logo {
  display: grid;
  place-items: center;
  padding: 0.25rem;
  border-radius: 10px;
  background: #fff;
}
.ctl-logo img {
  display: block;
  height: 2.4rem;
  width: auto;
}

.ctl-filters {
  margin-top: 0;
  gap: 0.6rem 0.8rem;
  padding: 0.65rem 1rem;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(36, 36, 132, 0.07);
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
}
input:focus,
select:focus {
  outline: 2px solid rgba(36, 36, 132, 0.35);
  outline-offset: 1px;
}
.ctl-reload {
  align-self: end;
}

.ctl-stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 0.9rem;
  margin-top: 1.1rem;
}
.ctl-stat {
  display: grid;
  gap: 0.1rem;
  padding: 0.9rem 1.1rem;
  border-radius: 14px;
  border-left: 6px solid var(--navy);
  background: #fff;
  box-shadow: 0 2px 8px rgba(36, 36, 132, 0.07);
}
.ctl-stat b {
  font-size: 2rem;
  line-height: 1.1;
  color: var(--navy);
  font-variant-numeric: tabular-nums;
}
.ctl-stat b small {
  color: var(--muted);
  font-size: 0.95rem;
  font-weight: 600;
}
.ctl-stat span {
  color: var(--muted);
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}
.ctl-stat.is-orange {
  border-left-color: #f59e0b;
}
.ctl-stat.is-red {
  border-left-color: var(--red);
}
.ctl-stat.is-green {
  border-left-color: #1fa971;
}

.ctl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 540px), 1fr));
  gap: 0.9rem;
  margin-top: 1.1rem;
  align-items: start;
}
.ctl-clinic {
  margin: 0;
  border-radius: 14px;
  box-shadow: 0 2px 8px rgba(36, 36, 132, 0.07);
  transition: box-shadow 0.15s, transform 0.15s;
}
.ctl-clinic:hover {
  box-shadow: 0 8px 22px rgba(36, 36, 132, 0.16);
  transform: translateY(-1px);
}
.ctl-clinic.is-open {
  grid-column: 1 / -1;
}
.ctl-clinic__head {
  grid-template-columns: minmax(0, 1fr) auto auto;
  padding: 0.9rem 1.1rem;
}
.ctl-clinic__title {
  display: grid;
  gap: 0.4rem;
  min-width: 0;
}
.ctl-clinic__name {
  font-size: 1.1rem;
  letter-spacing: 0.01em;
}
.ctl-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.ctl-chip {
  padding: 0.1rem 0.6rem;
  border-radius: 999px;
  background: #eef0ff;
  color: var(--navy);
  font-size: 0.78rem;
  font-weight: 600;
}
.ctl-chip.is-floor {
  background: #fff4d6;
  color: #8a5a00;
}
.ctl-badge {
  display: grid;
  justify-items: center;
  min-width: 5.2rem;
  padding: 0.4rem 0.8rem;
  border-radius: 12px;
  background: #fce40c;
  color: var(--navy);
  line-height: 1.1;
}
.ctl-badge b {
  font-size: 1.7rem;
  font-variant-numeric: tabular-nums;
}
.ctl-badge small {
  font-size: 0.66rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.ctl-clinic__body {
  padding-top: 0.4rem;
  border-top: 1px solid var(--line);
}
.ctl-table tbody tr:nth-child(odd):not(.ctl-moverow) {
  background: #fafbff;
}
.ctl-table tbody tr:hover:not(.ctl-moverow) {
  background: #f0f2ff;
}
.ctl-table th.is-billed {
  color: #2563eb;
}
.ctl-table th.is-waiting {
  color: #d97706;
}
.ctl-table th.is-seen {
  color: #15803d;
}
.ctl-doctor {
  font-weight: 700;
}

/* ---------- compact one-row doctors, toggles and the view lists ---------- */
.ctl-table {
  font-size: 0.84rem;
}
.ctl-table th,
.ctl-table td {
  padding: 0.28rem 0.4rem;
  white-space: nowrap;
}
.ctl-table th {
  font-size: 0.68rem;
}
.ctl-table td.who {
  white-space: normal;
}
.ctl-table .tg {
  text-align: center;
  width: 5.2rem;
}
.ctl-table td.who .ctl-sub {
  margin-left: 0.4rem;
  font-size: 0.72rem;
}
.ctl-table .ctl-tag {
  margin-left: 0.3rem;
  font-size: 0.7rem;
}
.ctl-tag.frz {
  background: #fde8e8;
  color: #9b1c1c;
}
.ctl-tag.mig {
  background: #dfe9ff;
  color: #1d4ed8;
}
.ctl-table .ctl-warn {
  display: inline;
  margin-left: 0.3rem;
}
.ctl-table tr.is-frozen .ctl-doctor,
.ctl-table tr.is-migrated .ctl-doctor {
  color: var(--muted);
  text-decoration: line-through;
}
.ctl-table td.act {
  text-align: right;
}
.ctl-table .ctl-switch {
  width: 36px;
  height: 20px;
}
.ctl-table .ctl-switch span {
  top: 2px;
  left: 2px;
  width: 16px;
  height: 16px;
}
.ctl-table .ctl-switch.is-on span {
  transform: translateX(16px);
}
.ctl-switch.is-frozen {
  background: #e42424;
}
.ctl-switch.is-frozen span {
  transform: none;
}
.ctl-switch.is-blue.is-on {
  background: #2563eb;
}
.ctl-switch:focus-visible {
  outline: 2px solid var(--navy);
  outline-offset: 2px;
}
.ctl-switch:disabled {
  opacity: 0.4;
}

.ctl-views {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  padding-top: 0.55rem;
}
.ctl-views__label {
  margin-right: 0.2rem;
  color: var(--muted);
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
}
.vw {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.3rem 0.85rem;
  border: 1px solid var(--line);
  border-radius: 999px;
  background: #fff;
  color: var(--ink);
  font-size: 0.85rem;
  font-weight: 700;
  cursor: pointer;
}
.vw b {
  min-width: 1.4rem;
  padding: 0 0.4rem;
  border-radius: 999px;
  background: #eceef7;
  text-align: center;
  font-size: 0.78rem;
}
.vw.is-off b {
  background: #fff1cc;
  color: #8a5a00;
}
.vw.is-frozen b {
  background: #fde8e8;
  color: #9b1c1c;
}
.vw.is-migrated b {
  background: #dfe9ff;
  color: #1d4ed8;
}
.vw.is-active {
  border-color: var(--navy);
  background: var(--navy);
  color: #fff;
}

.ctl-list {
  margin-top: 1.1rem;
  padding: 0.9rem 1.1rem;
  border: 1px solid var(--line);
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(36, 36, 132, 0.07);
}
.ctl-list header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.4rem 1rem;
}
.ctl-list h2 {
  margin: 0;
  font-size: 1.05rem;
  color: var(--navy);
}
.ctl-list header p {
  flex: 1;
  margin: 0;
  font-size: 0.82rem;
}
.ctl-list header button {
  padding: 0.3rem 0.8rem;
  border: 1px solid var(--line);
  border-radius: 8px;
  background: #fff;
  font-weight: 600;
  cursor: pointer;
}
.ctl-list ul {
  margin: 0.6rem 0 0;
  padding: 0;
  list-style: none;
}
.ctl-list li {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(0, 1.6fr) minmax(0, 0.8fr) auto;
  align-items: center;
  gap: 0.8rem;
  padding: 0.35rem 0.3rem;
  border-top: 1px solid var(--line);
  font-size: 0.86rem;
}
.ctl-list li:nth-child(odd) {
  background: #fafbff;
}
.ctl-list li small,
.ctl-list li .note {
  color: var(--muted);
  font-size: 0.75rem;
}
.ctl-list li .who,
.ctl-list li .where {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
@media (max-width: 720px) {
  .ctl-list li {
    grid-template-columns: minmax(0, 1fr) auto;
  }
  .ctl-list li .where,
  .ctl-list li .note {
    grid-column: 1 / -1;
    white-space: normal;
  }
}

/* ---------- filters: one compact row, every field the same height ---------- */
.ctl-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem 0.6rem;
  padding: 0.5rem 0.8rem;
  font-size: 0.78rem;
}
.ctl-filters label {
  flex: 1 1 130px;
  gap: 0.15rem;
}
.ctl-filters label > span:first-child {
  font-size: 0.62rem;
}
.ctl-filters input,
.ctl-filters select,
.ctl-filters .ctl-date button,
.ctl-filters .ctl-reload {
  box-sizing: border-box;
  height: 2rem;
  padding: 0 0.5rem;
  font-size: 0.78rem;
  line-height: 1;
}
.ctl-filters .ctl-date {
  gap: 0.3rem;
}
.ctl-filters .ctl-date button {
  padding: 0 0.6rem;
}
.ctl-filters .ctl-reload {
  flex: 0 0 auto;
  white-space: nowrap;
}
.ctl-filters .ctl-check {
  flex: 0 0 auto;
  align-self: flex-end;
  height: 2rem;
  gap: 0.35rem;
}
.ctl-filters .ctl-check span {
  font-size: 0.78rem !important;
  white-space: nowrap;
}
.ctl-filters .ctl-views {
  flex: 0 0 auto;
  flex-wrap: nowrap;
  align-self: flex-end;
  gap: 0.3rem;
  padding: 0;
}
.ctl-filters .ctl-views__label {
  display: none;
}
.ctl-filters .vw {
  height: 2rem;
  padding: 0 0.6rem;
  font-size: 0.74rem;
  white-space: nowrap;
}
.ctl-filters .vw b {
  font-size: 0.68rem;
}
@media (min-width: 1500px) {
  .ctl-filters {
    flex-wrap: nowrap;
  }
}

/* ---------- normal-sized text for the tiles and the clinic cards ---------- */
.ctl {
  font-size: clamp(13px, 0.7vw, 15px);
}
.ctl-bar h1 {
  font-size: 1.1rem;
}
.ctl-logo img {
  height: 2rem;
}
.ctl-bar {
  padding: 0.6rem var(--gutter);
}
.ctl-stats {
  gap: 0.6rem;
  margin-top: 0.8rem;
}
.ctl-stat {
  padding: 0.5rem 0.9rem;
  border-radius: 12px;
  border-left-width: 5px;
}
.ctl-stat b {
  font-size: 1.35rem;
}
.ctl-stat b small {
  font-size: 0.8rem;
}
.ctl-stat span {
  font-size: 0.68rem;
}
.ctl-grid {
  gap: 0.6rem;
  margin-top: 0.8rem;
}
.ctl-clinic {
  border-radius: 12px;
  border-left-width: 4px;
}
.ctl-clinic__head {
  padding: 0.5rem 0.8rem;
  gap: 0.6rem;
}
.ctl-clinic__title {
  gap: 0.25rem;
}
.ctl-clinic__name {
  font-size: 0.92rem;
}
.ctl-chip {
  padding: 0.05rem 0.5rem;
  font-size: 0.68rem;
}
.ctl-badge {
  min-width: 3.6rem;
  padding: 0.25rem 0.55rem;
  border-radius: 10px;
}
.ctl-badge b {
  font-size: 1.15rem;
}
.ctl-badge small {
  font-size: 0.56rem;
}
.ctl-chevron {
  font-size: 0.65rem;
}

@media (max-width: 720px) {
  /* phones: the filters are too tall to pin, so everything scrolls normally */
  .ctl-sticky {
    position: static;
    box-shadow: none;
  }
  .ctl-clinic__head {
    grid-template-columns: minmax(0, 1fr) auto;
  }
  .ctl-clinic__meta {
    grid-column: 1 / -1;
    order: 3;
  }
}
</style>
