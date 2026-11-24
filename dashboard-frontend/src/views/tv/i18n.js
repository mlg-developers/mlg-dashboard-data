import { ref } from 'vue'

// Language for the TV displays. Chosen with the small EN / SW switch in the top-right corner,
// remembered in the browser, or forced with ?lang=sw / ?lang=en. Clinic names stay as in the
// hospital's own schedule.

const STORAGE_KEY = 'tv:lang'
const DEFAULT_LANG = 'en'
const LANGS = ['en', 'sw']

const messages = {
  en: {
    titleClinics: 'CLINICS TODAY',
    titleDoctors: 'DOCTORS & PATIENTS',
    statClinics: 'CLINICS OPEN',
    statDoctors: 'DOCTORS WITH PATIENTS',
    statPatients: 'PATIENTS WAITING',
    legendBooked: 'Billed today',
    legendWaiting: 'Waiting',
    legendSeen: 'Seen',
    colDoctor: 'DOCTOR',
    colBooked: 'BILLED',
    colWaiting: 'WAITING',
    colSeen: 'SEEN',
    absent: 'ABSENT TODAY',
    open: 'OPEN NOW',
    closed: 'CLOSED',
    hours24: '24 HOURS',
    patient: 'PATIENT',
    patients: 'PATIENTS',
    waitingPatient: 'WAITING PATIENT',
    waitingPatients: 'WAITING PATIENTS',
    waitingShort: 'waiting',
    more: '+{n} more',
    pageClinics: 'Clinics',
    pageDoctors: 'Doctors',
    paused: 'PAUSED',
    emptyClinics: 'No clinics scheduled today',
    emptyDoctors: 'No doctors with patients yet',
    switchLabel: 'Language',
    floor: 'FLOOR',
    loading: 'Loading clinics…',
    unavailable: 'Data is not available right now. Retrying…',
    stale: 'OLD DATA',
    updated: 'Updated',
    refresh: 'Refresh now',
    noDoctors: 'No doctors listed',
    noPatients: 'NO PATIENTS',
    setTitle: 'Display settings',
    setAutoRefresh: 'Auto refresh (numbers and screens)',
    setOn: 'ON',
    setStopped: 'STOPPED',
    setRefreshEvery: 'Reload numbers every',
    setSlideEvery: 'Pulse & screen change every',
    setSeconds: 's',
    setRefreshNow: 'Refresh now',
    setReset: 'Reset',
    setFloorTitle: 'This TV shows',
    setAllFloors: 'All floors',
    setFloorHint: 'Only the clinics of this floor appear on this TV.',
    setControl: 'Accountant control',
    refreshOff: 'AUTO-REFRESH OFF',
    ctlTitle: 'TV Control',
    ctlLoginTitle: 'Sign in to control the TVs',
    ctlLoginHint: 'Accountant login required. Your session ends when you close this tab.',
    ctlEmail: 'Email',
    ctlPassword: 'Password',
    ctlSignIn: 'Sign in',
    ctlSignOut: 'Sign out',
    ctlLoginFailed: 'Sign in failed. Check your email and password.',
    ctlBack: 'Back to TV',
    ctlDate: 'Date',
    ctlToday: 'Today',
    ctlClinicSearch: 'Search clinic',
    ctlDoctorSearch: 'Search doctor',
    ctlDepartment: 'Department',
    ctlFloor: 'Floor',
    ctlAll: 'All',
    ctlReload: 'Reload',
    ctlClinics: 'clinics',
    ctlOnDuty: 'In clinic today',
    ctlOffDuty: 'Not in clinic today',
    ctlMove: 'Move waiting patients',
    ctlMoveTo: 'To doctor',
    ctlHowMany: 'How many',
    ctlApply: 'Move',
    ctlCancel: 'Cancel',
    ctlUndo: 'Undo',
    ctlMovedOut: 'moved out',
    ctlMovedIn: 'moved in',
    ctlNoResults: 'No clinics match these filters',
    ctlNoAccess: 'Your account role is not allowed to control the TV dashboards.',
    ctlLoading: 'Loading…',
    ctlFailed: 'Could not load the data. Try Reload.',
    ctlSaved: 'Saved',
    ctlOffWarn: 'Switched off but still has {n} waiting patient(s): move them to another doctor.',
    ctlNote: 'Only counts are shown here. Patient names and records are never displayed.',
    ctlWaitingIn: 'waiting',
    ctlStatWaiting: 'Patients waiting',
    ctlStatOff: 'Doctors not in clinic',
    ctlStatMoves: 'Moves made',
    ctlNoDoctors: 'No doctors',
    ctlShowAll: 'Show all assigned doctors',
    ctlOnTv: 'Doctors shown on the TV',
    ctlOtherAssigned: 'Other doctors assigned to this clinic',
    ctlHidden: 'not shown on TV',
    ctlNoneToday: 'No doctor has patients here today. Tick "Show all assigned doctors" to add one.',
    ctlFutureNote: 'Future day: patient counts start at 0.',
  },
  sw: {
    titleClinics: 'KLINIKI ZA LEO',
    titleDoctors: 'MADAKTARI NA WAGONJWA',
    statClinics: 'KLINIKI ZINAZOFANYA KAZI',
    statDoctors: 'MADAKTARI WENYE WAGONJWA',
    statPatients: 'WAGONJWA WANAOSUBIRI',
    legendBooked: 'Waliosajiliwa leo',
    legendWaiting: 'Wanasubiri',
    legendSeen: 'Wameonwa',
    colDoctor: 'DAKTARI',
    colBooked: 'WALIOSAJILIWA',
    colWaiting: 'WANASUBIRI',
    colSeen: 'WAMEONWA',
    absent: 'HAYUPO LEO',
    open: 'WAZI SASA',
    closed: 'IMEFUNGWA',
    hours24: 'SAA 24',
    patient: 'MGONJWA',
    patients: 'WAGONJWA',
    waitingPatient: 'MGONJWA ANAYESUBIRI',
    waitingPatients: 'WAGONJWA WANASUBIRI',
    waitingShort: 'wanasubiri',
    more: '+{n} zaidi',
    pageClinics: 'Kliniki',
    pageDoctors: 'Madaktari',
    paused: 'IMESITISHWA',
    emptyClinics: 'Hakuna kliniki zilizopangwa leo',
    emptyDoctors: 'Hakuna madaktari wenye wagonjwa bado',
    switchLabel: 'Lugha',
    floor: 'GHOROFA',
    loading: 'Inapakia kliniki…',
    unavailable: 'Taarifa hazipatikani kwa sasa. Inajaribu tena…',
    stale: 'TAARIFA ZA ZAMANI',
    updated: 'Imesasishwa',
    refresh: 'Onyesha upya sasa',
    noDoctors: 'Hakuna madaktari waliorodheshwa',
    noPatients: 'HANA WAGONJWA',
    setTitle: 'Mipangilio ya skrini',
    setAutoRefresh: 'Kusasisha kiotomatiki (namba na skrini)',
    setOn: 'IMEWASHWA',
    setStopped: 'IMESIMAMISHWA',
    setRefreshEvery: 'Pakia namba kila',
    setSlideEvery: 'Mapigo na skrini vibadilike kila',
    setSeconds: 's',
    setRefreshNow: 'Sasisha sasa',
    setReset: 'Rudisha',
    setFloorTitle: 'TV hii inaonyesha',
    setAllFloors: 'Ghorofa zote',
    setFloorHint: 'Kliniki za ghorofa hii pekee ndizo zitaonekana kwenye TV hii.',
    setControl: 'Udhibiti wa mhasibu',
    refreshOff: 'KUSASISHA KUMEZIMWA',
    ctlTitle: 'Udhibiti wa TV',
    ctlLoginTitle: 'Ingia ili kudhibiti TV',
    ctlLoginHint: 'Inahitaji kuingia kwa mhasibu. Kipindi chako kinaisha ukifunga tab hii.',
    ctlEmail: 'Barua pepe',
    ctlPassword: 'Nenosiri',
    ctlSignIn: 'Ingia',
    ctlSignOut: 'Toka',
    ctlLoginFailed: 'Imeshindikana kuingia. Angalia barua pepe na nenosiri.',
    ctlBack: 'Rudi kwenye TV',
    ctlDate: 'Tarehe',
    ctlToday: 'Leo',
    ctlClinicSearch: 'Tafuta kliniki',
    ctlDoctorSearch: 'Tafuta daktari',
    ctlDepartment: 'Idara',
    ctlFloor: 'Ghorofa',
    ctlAll: 'Zote',
    ctlReload: 'Pakia upya',
    ctlClinics: 'kliniki',
    ctlOnDuty: 'Yupo kliniki leo',
    ctlOffDuty: 'Hayupo kliniki leo',
    ctlMove: 'Hamisha wagonjwa wanaosubiri',
    ctlMoveTo: 'Kwa daktari',
    ctlHowMany: 'Wangapi',
    ctlApply: 'Hamisha',
    ctlCancel: 'Ghairi',
    ctlUndo: 'Rudisha',
    ctlMovedOut: 'waliohamishwa',
    ctlMovedIn: 'waliohamishiwa',
    ctlNoResults: 'Hakuna kliniki zinazolingana na vichujio hivi',
    ctlNoAccess: 'Cheo cha akaunti yako hakiruhusiwi kudhibiti dashboard za TV.',
    ctlLoading: 'Inapakia…',
    ctlFailed: 'Imeshindwa kupakia taarifa. Jaribu Pakia upya.',
    ctlSaved: 'Imehifadhiwa',
    ctlOffWarn: 'Amezimwa lakini bado ana wagonjwa {n} wanaosubiri: wahamishie daktari mwingine.',
    ctlNote: 'Hapa zinaonyeshwa namba tu. Majina na taarifa za wagonjwa hazionyeshwi kamwe.',
    ctlWaitingIn: 'wanasubiri',
    ctlStatWaiting: 'Wagonjwa wanaosubiri',
    ctlStatOff: 'Madaktari hawapo kliniki',
    ctlStatMoves: 'Uhamisho uliofanywa',
    ctlNoDoctors: 'Hakuna madaktari',
    ctlShowAll: 'Onyesha madaktari wote waliopangiwa',
    ctlOnTv: 'Madaktari wanaoonyeshwa kwenye TV',
    ctlOtherAssigned: 'Madaktari wengine waliopangiwa kliniki hii',
    ctlHidden: 'haonyeshwi kwenye TV',
    ctlNoneToday: 'Hakuna daktari mwenye wagonjwa hapa leo. Weka tiki "Onyesha madaktari wote waliopangiwa" ili kumwongeza.',
    ctlFutureNote: 'Siku ijayo: idadi ya wagonjwa huanza na 0.',
  },
}

const DAYS_SW = ['Jumapili', 'Jumatatu', 'Jumanne', 'Jumatano', 'Alhamisi', 'Ijumaa', 'Jumamosi']
const MONTHS_SW = [
  'Januari', 'Februari', 'Machi', 'Aprili', 'Mei', 'Juni',
  'Julai', 'Agosti', 'Septemba', 'Oktoba', 'Novemba', 'Desemba',
]

const readStored = () => {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    return null
  }
}

const writeStored = (value) => {
  try {
    localStorage.setItem(STORAGE_KEY, value)
  } catch {
    // storage can be blocked; the choice then lasts until the page reloads
  }
}

const lang = ref(DEFAULT_LANG)
let listening = false

// Priority: the language someone picked with the switch (kept across TV restarts) >
// ?lang=sw|en in the URL (only used until a choice has been saved) > the default.
export const initLang = (queryLang) => {
  const stored = readStored()
  const wanted = String(queryLang || '').toLowerCase()

  if (LANGS.includes(stored)) {
    lang.value = stored
  } else if (LANGS.includes(wanted)) {
    lang.value = wanted
    writeStored(wanted)
  } else {
    lang.value = DEFAULT_LANG
  }

  // Keep other open tabs/windows of the same browser in step with the switch.
  if (!listening && typeof window !== 'undefined') {
    listening = true
    window.addEventListener('storage', (event) => {
      if (event.key === STORAGE_KEY && LANGS.includes(event.newValue)) lang.value = event.newValue
    })
  }
}

export const useTvI18n = () => {
  const setLang = (value) => {
    if (!LANGS.includes(value)) return
    lang.value = value
    writeStored(value)
  }

  const t = (key, params = {}) =>
    Object.entries(params).reduce(
      (text, [name, value]) => text.replace(`{${name}}`, value),
      messages[lang.value][key] ?? messages.en[key] ?? key,
    )

  const formatDate = (date) => {
    if (lang.value === 'sw') {
      return `${DAYS_SW[date.getDay()]}, ${date.getDate()} ${MONTHS_SW[date.getMonth()]} ${date.getFullYear()}`
    }
    return date.toLocaleDateString('en-GB', {
      weekday: 'long',
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    })
  }

  return { lang, setLang, t, formatDate }
}
