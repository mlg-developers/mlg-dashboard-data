<script setup>
import { computed, onMounted, ref } from 'vue'
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
const inClinic = (doctor) => doctor.visible && !doctor.off
const toggleDoctor = (clinic, doctor) => {
  const wantIn = !inClinic(doctor)
  // state 'on' / 'off' is only stored when it differs from what the doctor is by default
  const state = wantIn ? (doctor.default_visible ? null : 'on') : doctor.default_visible ? 'off' : null
  return save('/tv/control/doctor', { date: date.value, clinic_code: clinic.code, doctor_code: doctor.code, state })
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
const targets = (clinic) => clinic.doctors.filter((d) => moving.value && d.code !== moving.value.from && !d.off)
const targetsShown = (clinic) => targets(clinic).filter((d) => d.visible)
const targetsOther = (clinic) => targets(clinic).filter((d) => !d.visible)

const isFuture = computed(() => date.value > hospitalToday())
const waitingLabel = (n) => (n === null || n === undefined ? '—' : n)
</script>

<template>
  <div class="ctl">
    <header class="ctl-bar">
      <div class="ctl-brand">
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
    <section class="ctl-filters">
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
    </section>

    <p class="ctl-note">{{ t('ctlNote') }}</p>
    <p v-if="isFuture" class="ctl-note">{{ t('ctlFutureNote') }}</p>
    <p v-if="error" class="ctl-error" role="alert">{{ error }}</p>
    <p v-if="forbidden" class="ctl-error" role="alert">{{ t('ctlNoAccess') }}</p>
    <p v-if="loading" class="ctl-muted">{{ t('ctlLoading') }}</p>

    <template v-else-if="payload">
      <p class="ctl-count">{{ visible.length }} / {{ clinics.length }} {{ t('ctlClinics') }}</p>
      <p v-if="!visible.length" class="ctl-muted">{{ t('ctlNoResults') }}</p>

      <article v-for="row in visible" :key="row.clinic.code" class="ctl-clinic">
        <button type="button" class="ctl-clinic__head" @click="toggleOpen(row.clinic.code)">
          <span class="ctl-clinic__name">{{ row.clinic.name }}</span>
          <span class="ctl-clinic__meta">{{ row.clinic.department }} · {{ floorLabel(row.clinic) }}</span>
          <span class="ctl-clinic__count">
            <b>{{ waitingLabel(row.clinic.waiting) }}</b> {{ t('ctlWaitingIn') }}
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
                <th class="num">{{ t('colBooked') }}</th>
                <th class="num">{{ t('colWaiting') }}</th>
                <th class="num">{{ t('colSeen') }}</th>
                <th>{{ t('ctlOnDuty') }}</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="d in row.doctors" :key="d.code">
                <tr :class="{ 'is-off': d.off || !d.visible }">
                  <td>
                    <div class="ctl-doctor">{{ d.name }}</div>
                    <div class="ctl-sub">
                      {{ d.qualification }}
                      <span v-if="!d.visible" class="ctl-tag hid">{{ t('ctlHidden') }}</span>
                      <span v-if="d.moved_in" class="ctl-tag in">+{{ d.moved_in }} {{ t('ctlMovedIn') }}</span>
                      <span v-if="d.moved_out" class="ctl-tag out">−{{ d.moved_out }} {{ t('ctlMovedOut') }}</span>
                    </div>
                    <div v-if="d.off && d.waiting" class="ctl-warn">{{ t('ctlOffWarn', { n: d.waiting }) }}</div>
                  </td>
                  <td class="num">{{ waitingLabel(d.booked) }}</td>
                  <td class="num strong">{{ waitingLabel(d.waiting) }}</td>
                  <td class="num">{{ waitingLabel(d.seen) }}</td>
                  <td>
                    <button
                      type="button"
                      class="ctl-switch"
                      :class="{ 'is-on': inClinic(d) }"
                      :disabled="busy"
                      :aria-pressed="inClinic(d)"
                      :title="inClinic(d) ? t('ctlOnDuty') : t('ctlOffDuty')"
                      @click="toggleDoctor(row.clinic, d)"
                    >
                      <span></span>
                    </button>
                  </td>
                  <td>
                    <button
                      v-if="d.waiting > 0"
                      type="button"
                      class="ctl-link"
                      :disabled="busy"
                      @click="moving && moving.from === d.code ? (moving = null) : startMove(row.clinic, d)"
                    >
                      {{ t('ctlMove') }}
                    </button>
                  </td>
                </tr>
                <tr v-if="moving && moving.clinic === row.clinic.code && moving.from === d.code" class="ctl-moverow">
                  <td colspan="6">
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
  padding: 0 max(16px, env(safe-area-inset-right, 0px)) 4rem max(16px, env(safe-area-inset-left, 0px));
  background: var(--bg);
  color: var(--ink);
  font-family: system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  font-size: 15px;
}
.ctl > * {
  max-width: 1100px;
  margin-left: auto;
  margin-right: auto;
}
.ctl-bar {
  max-width: none;
  margin: 0 -16px 1rem;
  padding: 0.9rem 16px;
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
  max-width: 380px;
  margin-top: 3rem;
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

@media (max-width: 720px) {
  .ctl-clinic__head {
    grid-template-columns: minmax(0, 1fr) auto;
  }
  .ctl-clinic__meta {
    grid-column: 1 / -1;
    order: 3;
  }
}
</style>
