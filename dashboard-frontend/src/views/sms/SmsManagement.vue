<script setup>
import { ref, computed, onUnmounted } from 'vue'
import * as XLSX from 'xlsx'
// Self-contained fetch wrapper — no axios, no Pinia, no proxy issues
async function smsRequest(method, path, body) {
  const token = localStorage.getItem('mnh_token')
  const res = await fetch(`/api/v1${path}`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    ...(body ? { body: JSON.stringify(body) } : {}),
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(data?.message || `HTTP ${res.status}`)
    err.status   = res.status
    err.data     = data
    throw err
  }
  return data
}

// ── Balance ──────────────────────────────────────────────────────────────────
const balance = ref(null)
async function fetchBalance() {
  try { balance.value = await smsRequest('GET', '/sms/balance') } catch (_) {}
}
fetchBalance()

// ── Contacts ─────────────────────────────────────────────────────────────────
const contacts        = ref([])
const selectedIds     = ref(new Set())
const fileInputRef    = ref(null)
const isDragging      = ref(false)
const importError     = ref('')
const duplicatesRemoved = ref(0)

const allSelected  = computed(() => contacts.value.length > 0 && selectedIds.value.size === contacts.value.length)
const selectedCount = computed(() => selectedIds.value.size)

function toggleAll() {
  selectedIds.value = allSelected.value ? new Set() : new Set(contacts.value.map((_, i) => i))
}
function toggleOne(idx) {
  const s = new Set(selectedIds.value)
  s.has(idx) ? s.delete(idx) : s.add(idx)
  selectedIds.value = s
}

const normalizePhone = (p) => {
  p = p.replace(/\D/g, '')
  if (p.startsWith('0') && p.length === 10) return '255' + p.slice(1)
  if (p.length === 9 && /^[67]/.test(p))    return '255' + p
  return p
}

function parseFile(file) {
  importError.value = ''
  duplicatesRemoved.value = 0
  const reader = new FileReader()
  reader.onload = (e) => {
    try {
      const wb   = XLSX.read(e.target.result, { type: 'binary' })
      const ws   = wb.Sheets[wb.SheetNames[0]]
      const rows = XLSX.utils.sheet_to_json(ws, { defval: '' })
      if (!rows.length) { importError.value = 'Sheet is empty.'; return }

      const keys     = Object.keys(rows[0])
      const phoneKey = keys.find(k => /phone|mobile|tel|msisdn/i.test(k)) || keys[0]
      const nameKey  = keys.find(k => /name|client|patient/i.test(k) && k !== phoneKey) || null

      const raw = rows
        .map(r => ({ phone: String(r[phoneKey] ?? '').trim().replace(/\s+/g, ''), name: nameKey ? String(r[nameKey] ?? '').trim() : '' }))
        .filter(r => r.phone)

      if (!raw.length) { importError.value = 'No phone numbers found. Ensure a column named "phone" or "mobile" exists.'; return }

      const seen = new Map()
      raw.forEach(r => { const key = normalizePhone(r.phone); if (!seen.has(key)) seen.set(key, { ...r, phone: key }) })

      const parsed = [...seen.values()].map((r, i) => ({ id: i, ...r }))
      duplicatesRemoved.value = raw.length - parsed.length
      contacts.value    = parsed
      selectedIds.value = new Set(parsed.map((_, i) => i))
    } catch (err) { importError.value = 'Failed to read file: ' + err.message }
  }
  reader.readAsBinaryString(file)
}
function onFileChange(e) { if (e.target.files[0]) parseFile(e.target.files[0]) }
function onDrop(e)       { isDragging.value = false; if (e.dataTransfer.files[0]) parseFile(e.dataTransfer.files[0]) }

// ── Messages (3 templates) ───────────────────────────────────────────────────
const messages = ref([
  { enabled: true,  text: '' },
  { enabled: false, text: '' },
  { enabled: false, text: '' },
])
const MAX_SMS    = 918
const activeMsg  = ref(0)   // which tab is open in the editor

const enabledMessages = computed(() => messages.value.filter(m => m.enabled && m.text.trim()))
const smsParts = (txt) => Math.ceil((txt?.length || 1) / 160)

// ── Campaign ──────────────────────────────────────────────────────────────────
const campaignTitle = ref('')

// ── Sending ───────────────────────────────────────────────────────────────────
const isSending    = ref(false)
const sendError    = ref('')
const campaigns    = ref([])   // one per enabled message group
const pollTimer    = ref(null)
const activeTab    = ref('compose')

function shuffle(arr) {
  const a = [...arr]
  for (let i = a.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [a[i], a[j]] = [a[j], a[i]]
  }
  return a
}

async function sendSms() {
  sendError.value = ''

  if (!campaignTitle.value.trim())      { sendError.value = 'Campaign title is required.'; return }
  if (!enabledMessages.value.length)    { sendError.value = 'At least one message with content is required.'; return }
  if (selectedCount.value === 0)        { sendError.value = 'Select at least one recipient.'; return }

  const recipients = shuffle([...selectedIds.value].map(i => ({ phone: contacts.value[i].phone, name: contacts.value[i].name || null })))
  const msgList    = enabledMessages.value
  const groupSize  = Math.ceil(recipients.length / msgList.length)

  // Split recipients into groups — one group per message
  const groups = msgList.map((m, gi) => ({
    message:    m.text.trim(),
    recipients: recipients.slice(gi * groupSize, (gi + 1) * groupSize),
    label:      `Message ${messages.value.indexOf(m) + 1}`,
  })).filter(g => g.recipients.length > 0)

  isSending.value = true
  campaigns.value = []

  try {
    for (const [idx, group] of groups.entries()) {
      const title = groups.length > 1
        ? `${campaignTitle.value.trim()} [${group.label}]`
        : campaignTitle.value.trim()

      const resp = await smsRequest('POST', '/sms/send', {
        title,
        message:    `${campaignTitle.value.trim()}: ${group.message}`,
        recipients: group.recipients,
      })

      campaigns.value.push({
        id:        resp.campaign_id,
        label:     title,
        total:     resp.total,
        delivered: 0,
        failed:    0,
        pending:   resp.total,
        status:    'sent',
        logs:      [],
        shootId:   resp.shoot_id,
      })
    }
    startPolling()
  } catch (err) {
    const status = err?.status
    const d      = err?.data
    console.error('[SMS Send Error]', status, d, err?.message)
    if (d?.errors) {
      const first = Object.values(d.errors)[0]
      sendError.value = Array.isArray(first) ? first[0] : String(first)
    } else if (err?.message && !err.message.startsWith('HTTP')) {
      sendError.value = err.message
    } else if (status === 401) {
      sendError.value = 'Session expired. Please log in again.'
    } else if (status) {
      sendError.value = `Server error (${status}). Please try again or contact support.`
    } else {
      sendError.value = 'Network error — no response from server. Check your connection.'
    }
  } finally {
    isSending.value = false
  }
}

function startPolling() {
  stopPolling()
  pollTimer.value = setInterval(pollAll, 4000)
}
function stopPolling() { if (pollTimer.value) { clearInterval(pollTimer.value); pollTimer.value = null } }

async function pollAll() {
  let allDone = true
  for (const c of campaigns.value) {
    if (c.status === 'completed' || c.status === 'failed') continue
    allDone = false
    try {
      const [sr, lr] = await Promise.all([
        smsRequest('GET', `/sms/campaigns/${c.id}/status`),
        smsRequest('GET', `/sms/campaigns/${c.id}/logs`),
      ])
      Object.assign(c, {
        delivered: sr.delivered,
        failed:    sr.failed,
        pending:   sr.pending,
        status:    sr.status,
        logs:      lr.logs?.data ?? [],
      })
    } catch (_) {}
  }
  if (allDone) stopPolling()
}

const totalDelivered = computed(() => campaigns.value.reduce((s, c) => s + (c.delivered || 0), 0))
const totalFailed    = computed(() => campaigns.value.reduce((s, c) => s + (c.failed || 0), 0))
const totalPending   = computed(() => campaigns.value.reduce((s, c) => s + (c.pending || 0), 0))
const totalSent      = computed(() => campaigns.value.reduce((s, c) => s + (c.total || 0), 0))

// ── History ───────────────────────────────────────────────────────────────────
const historyList    = ref([])
const loadingHistory = ref(false)

async function loadHistory() {
  loadingHistory.value = true
  try { historyList.value = ((await smsRequest('GET', '/sms/campaigns'))?.data) ?? [] } catch (_) {}
  finally { loadingHistory.value = false }
}

function switchTab(tab) { activeTab.value = tab; if (tab === 'history') loadHistory() }

function newCampaign() {
  stopPolling()
  campaigns.value  = []
  campaignTitle.value = ''
  messages.value   = [{ enabled: true, text: '' }, { enabled: false, text: '' }, { enabled: false, text: '' }]
  contacts.value   = []
  selectedIds.value = new Set()
  sendError.value  = ''
  activeTab.value  = 'compose'
}

onUnmounted(stopPolling)
</script>

<template>
  <div class="sms-page">

    <!-- ── Top Bar ─────────────────────────────────────────────────────────── -->
    <div class="top-bar">
      <div class="top-bar-left">
        <div class="page-icon"><CIcon icon="cil-speech" /></div>
        <div>
          <h1 class="page-title">SMS Management</h1>
          <p class="page-sub">Promotional &amp; event messages to clients</p>
        </div>
      </div>

      <div class="top-bar-center">
        <button :class="['tab-pill', activeTab==='compose' && 'active']" @click="switchTab('compose')">
          <CIcon icon="cil-pencil" /> Compose
        </button>
        <button :class="['tab-pill', activeTab==='history' && 'active']" @click="switchTab('history')">
          <CIcon icon="cil-list" /> History
        </button>
      </div>

      <div class="top-bar-right">
        <div v-if="balance" class="balance-chip">
          <CIcon icon="cil-credit-card" />
          Balance: <strong>{{ balance.balance ?? balance.credits ?? balance.data?.balance ?? '—' }}</strong> SMS
        </div>
      </div>
    </div>

    <!-- ── COMPOSE ─────────────────────────────────────────────────────────── -->
    <div v-if="activeTab==='compose'" class="compose-grid">

      <!-- LEFT: Import + Recipients -->
      <div class="panel">

        <!-- Import -->
        <div class="card">
          <div class="card-head"><CIcon icon="cil-cloud-upload" /><span>Import Recipients</span></div>
          <div class="card-body">
            <div class="drop-zone" :class="{ dragging: isDragging }"
              @dragover.prevent="isDragging=true" @dragleave="isDragging=false"
              @drop.prevent="onDrop" @click="fileInputRef?.click()">
              <CIcon icon="cil-file" class="drop-icon" />
              <p class="drop-text">Drop Excel file or <span class="link">browse</span></p>
              <p class="drop-hint">Columns: <code>phone</code> (required) · <code>name</code> (optional)</p>
              <input ref="fileInputRef" type="file" accept=".xlsx,.xls,.csv" hidden @change="onFileChange" />
            </div>
            <div v-if="importError" class="notice danger">{{ importError }}</div>
            <div v-if="duplicatesRemoved > 0" class="notice warning">
              <CIcon icon="cil-warning" /> {{ duplicatesRemoved }} duplicate{{ duplicatesRemoved>1?'s':'' }} removed — unique numbers only.
            </div>
          </div>
        </div>

        <!-- Recipients -->
        <div v-if="contacts.length" class="card mt">
          <div class="card-head">
            <CIcon icon="cil-people" />
            <span>Recipients</span>
            <span class="badge">{{ contacts.length }}</span>
            <button class="ghost-btn ms-auto" @click="toggleAll">{{ allSelected ? 'Deselect All' : 'Select All' }}</button>
          </div>
          <div class="recipients-list">
            <div v-for="(c, i) in contacts" :key="i"
              class="r-row" :class="{ sel: selectedIds.has(i) }" @click="toggleOne(i)">
              <div class="r-check" :class="{ on: selectedIds.has(i) }">
                <CIcon v-if="selectedIds.has(i)" icon="cil-check" class="chk-icon" />
              </div>
              <div class="r-info">
                <span class="r-name">{{ c.name || '—' }}</span>
                <span class="r-phone">{{ c.phone }}</span>
              </div>
            </div>
          </div>
          <div class="card-foot"><strong>{{ selectedCount }}</strong> of {{ contacts.length }} selected</div>
        </div>

      </div>

      <!-- RIGHT: Messages + Campaign + Send -->
      <div class="panel">

        <!-- Campaign Title -->
        <div class="card">
          <div class="card-head"><CIcon icon="cil-description" /><span>Campaign Details</span></div>
          <div class="card-body">
            <label class="lbl">Campaign Title <span class="req">*</span></label>
            <input v-model="campaignTitle" class="inp" placeholder="e.g. Happy Week — Customer Care 2026" maxlength="255" />
          </div>
        </div>

        <!-- 3 Message Templates -->
        <div class="card mt">
          <div class="card-head"><CIcon icon="cil-comment-square" /><span>Messages</span>
            <span class="hint-pill">Recipients get one message randomly</span>
          </div>
          <div class="card-body no-bot-pad">

            <!-- Tab row -->
            <div class="msg-tabs">
              <div v-for="(m, i) in messages" :key="i"
                :class="['msg-tab', activeMsg===i && 'active', m.enabled && m.text.trim() && 'has-content']"
                @click="activeMsg=i">
                <label class="toggle-wrap" @click.stop>
                  <input type="checkbox" v-model="m.enabled" />
                  <span class="toggle"></span>
                </label>
                <span>Message {{ i+1 }}</span>
                <span v-if="m.text.trim()" class="dot-ready"></span>
              </div>
            </div>

            <!-- Active message editor -->
            <div v-for="(m, i) in messages" :key="'e'+i" v-show="activeMsg===i" class="msg-editor">
              <div v-if="!m.enabled" class="msg-disabled-overlay">
                Enable Message {{ i+1 }} to edit
              </div>
              <textarea v-model="m.text" class="msg-textarea" :disabled="!m.enabled"
                :placeholder="`Type Message ${i+1} here…`" :maxlength="MAX_SMS" rows="5"></textarea>
              <div class="msg-meta">
                <span :class="m.text.length > 160 ? 'warn' : 'muted'">{{ m.text.length }}/{{ MAX_SMS }}</span>
                <span class="muted">· {{ smsParts(m.text) }} part{{ smsParts(m.text)>1?'s':'' }}</span>
                <span v-if="m.enabled && m.text.trim() && selectedCount>0" class="muted ms-auto">
                  ~{{ Math.ceil(selectedCount / enabledMessages.length) }} recipients
                </span>
              </div>
              <div v-if="m.text.trim()" class="preview-bubble">{{ m.text }}</div>
            </div>

          </div>
        </div>

        <!-- Send Panel -->
        <div class="card mt">
          <div class="card-body">
            <div v-if="sendError" class="notice danger mb">{{ sendError }}</div>

            <div class="send-summary">
              <div class="pill"><CIcon icon="cil-people" /> {{ selectedCount }} recipients</div>
              <div class="pill"><CIcon icon="cil-comment-square" /> {{ enabledMessages.length }} message{{ enabledMessages.length!==1?'s':'' }}</div>
              <div class="pill" v-if="enabledMessages.length>1">
                <CIcon icon="cil-shuffle" /> Random split
              </div>
            </div>

            <button class="send-btn" :disabled="isSending || selectedCount===0 || !enabledMessages.length || !campaignTitle.trim()" @click="sendSms">
              <span v-if="!isSending"><CIcon icon="cil-send" /> Send to {{ selectedCount }} Recipient{{ selectedCount!==1?'s':'' }}</span>
              <span v-else class="d-flex align-items-center gap-2">
                <span class="spinner-border spinner-border-sm"></span> Sending…
              </span>
            </button>
          </div>
        </div>

        <!-- Delivery Report -->
        <div v-if="campaigns.length" class="card mt">
          <div class="card-head">
            <CIcon icon="cil-chart" /><span>Delivery Report</span>
            <button class="ghost-btn ms-auto" @click="newCampaign">+ New Campaign</button>
          </div>
          <div class="card-body">

            <!-- Overall progress -->
            <div class="prog-bar-bg">
              <div class="prog-fill delivered" :style="{ width: totalSent ? (totalDelivered/totalSent*100)+'%' : '0%' }"></div>
              <div class="prog-fill failed"    :style="{ width: totalSent ? (totalFailed/totalSent*100)+'%' : '0%', left: totalSent ? (totalDelivered/totalSent*100)+'%' : '0%' }"></div>
            </div>

            <div class="report-grid">
              <div class="r-stat delivered"><span class="r-num">{{ totalDelivered }}</span><span class="r-lbl">Delivered</span></div>
              <div class="r-stat failed">   <span class="r-num">{{ totalFailed }}</span>   <span class="r-lbl">Failed</span></div>
              <div class="r-stat pending">  <span class="r-num">{{ totalPending }}</span>  <span class="r-lbl">Pending</span></div>
              <div class="r-stat total">    <span class="r-num">{{ totalSent }}</span>     <span class="r-lbl">Total</span></div>
            </div>

            <!-- Per-message group breakdown -->
            <div v-for="c in campaigns" :key="c.id" class="msg-group-row mt">
              <div class="msg-group-head">
                <span class="msg-group-label">{{ c.label }}</span>
                <span :class="`log-badge ${c.status}`">{{ c.status }}</span>
                <span class="muted ms-auto small">{{ c.total }} recipients</span>
              </div>
              <div v-if="c.logs.length" class="logs-wrap">
                <table class="logs-table">
                  <thead><tr><th>#</th><th>Name</th><th>Phone</th><th>Status</th><th>Note</th></tr></thead>
                  <tbody>
                    <tr v-for="(log, li) in c.logs" :key="log.id">
                      <td>{{ li+1 }}</td>
                      <td>{{ log.recipient_name||'—' }}</td>
                      <td>{{ log.phone }}</td>
                      <td><span :class="`log-badge ${log.status}`">{{ log.status }}</span></td>
                      <td class="muted small">{{ log.error_message||'—' }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

          </div>
        </div>

      </div>
    </div>

    <!-- ── HISTORY ─────────────────────────────────────────────────────────── -->
    <div v-if="activeTab==='history'" class="card mt">
      <div class="card-head"><CIcon icon="cil-history" /><span>Campaign History</span></div>
      <div v-if="loadingHistory" class="empty"><span class="spinner-border text-primary"></span></div>
      <div v-else-if="!historyList.length" class="empty">No campaigns yet.</div>
      <div v-else class="logs-wrap">
        <table class="logs-table">
          <thead><tr><th>#</th><th>Title</th><th>Total</th><th>Delivered</th><th>Failed</th><th>Status</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="(c,i) in historyList" :key="c.id">
              <td>{{ i+1 }}</td><td>{{ c.title }}</td><td>{{ c.total_recipients }}</td>
              <td class="green bold">{{ c.delivered_count }}</td>
              <td class="red bold">{{ c.failed_count }}</td>
              <td><span :class="`log-badge ${c.status}`">{{ c.status }}</span></td>
              <td class="muted small">{{ new Date(c.created_at).toLocaleString() }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</template>

<style scoped>
/* ── Reset / Base ─────────────────────────────────────────────────────────── */
.sms-page {
  min-height: calc(100vh - 56px);
  padding: 1.25rem 1.5rem;
  background: #f4f6f9;
  font-family: 'Inter', system-ui, sans-serif;
  box-sizing: border-box;
}

/* ── Top Bar ──────────────────────────────────────────────────────────────── */
.top-bar {
  display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
  background: white; border-radius: 14px;
  box-shadow: 0 2px 10px rgba(0,0,0,.07);
  padding: 0.85rem 1.25rem; margin-bottom: 1.25rem;
}
.top-bar-left  { display: flex; align-items: center; gap: 0.9rem; flex: 1; min-width: 0; }
.top-bar-center{ display: flex; gap: 0.5rem; }
.top-bar-right { display: flex; align-items: center; gap: 0.75rem; }

.page-icon {
  width: 42px; height: 42px; border-radius: 11px;
  background: linear-gradient(135deg,#007f3e,#00b359);
  display: flex; align-items: center; justify-content: center;
  color: white; font-size: 1.1rem; flex-shrink: 0;
}
.page-title { font-size: 1.1rem; font-weight: 700; margin: 0; color: #1a2533; }
.page-sub   { font-size: 0.75rem; color: #6c757d; margin: 0; }

.tab-pill {
  display: flex; align-items: center; gap: 0.35rem;
  padding: 0.4rem 0.9rem; border-radius: 8px;
  border: 1.5px solid #dee2e6; background: transparent;
  font-size: 0.82rem; font-weight: 500; color: #6c757d; cursor: pointer;
  transition: all .2s;
}
.tab-pill.active { background: #007f3e; border-color: #007f3e; color: white; }
.tab-pill:hover:not(.active) { background: #f0f4f8; }

.balance-chip {
  display: flex; align-items: center; gap: 0.35rem;
  background: #f0fff6; border: 1.5px solid #b7f0d0; border-radius: 20px;
  padding: 0.3rem 0.85rem; font-size: 0.78rem; color: #166534;
}
.balance-chip strong { font-weight: 700; }

/* ── Grid ─────────────────────────────────────────────────────────────────── */
.compose-grid { display: grid; grid-template-columns: 1fr 1.5fr; gap: 1.25rem; align-items: start; }
.panel        { display: flex; flex-direction: column; }
.mt           { margin-top: 1rem; }

/* ── Cards ────────────────────────────────────────────────────────────────── */
.card {
  background: white; border-radius: 14px;
  box-shadow: 0 2px 10px rgba(0,0,0,.07); overflow: hidden;
}
.card-head {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.65rem 1rem; border-bottom: 1px solid #f0f0f0;
  font-size: 0.82rem; font-weight: 600; color: #1a2533;
  background: linear-gradient(to right,#f8f9fa,#fff);
}
.card-body       { padding: 0.9rem; }
.card-body.no-bot-pad { padding-bottom: 0; }
.card-foot       { padding: 0.45rem 1rem; background: #f8f9fa; border-top: 1px solid #f0f0f0; font-size: 0.75rem; color: #6c757d; }
.badge           { background: #007f3e; color: white; border-radius: 20px; padding: 0 7px; font-size: 0.7rem; font-weight: 700; }
.hint-pill       { margin-left: auto; background: #eff6ff; color: #1d4ed8; border-radius: 20px; padding: 0.15rem 0.6rem; font-size: 0.7rem; font-weight: 600; }
.ghost-btn       { background: none; border: 1.5px solid #007f3e; color: #007f3e; border-radius: 6px; padding: 0.2rem 0.6rem; font-size: 0.73rem; font-weight: 600; cursor: pointer; transition: all .2s; }
.ghost-btn:hover { background: #007f3e; color: white; }
.ms-auto         { margin-left: auto; }

/* ── Drop Zone ───────────────────────────────────────────────────────────── */
.drop-zone {
  border: 2px dashed #c8d8e0; border-radius: 10px;
  padding: 1.25rem; text-align: center; cursor: pointer;
  background: #f8fbff; transition: all .25s;
}
.drop-zone:hover, .drop-zone.dragging { border-color: #007f3e; background: #f0fff6; }
.drop-icon  { width: 28px; height: 28px; color: #007f3e; margin-bottom: 0.4rem; }
.drop-text  { font-size: 0.85rem; font-weight: 500; color: #344767; margin: 0.15rem 0; }
.link       { color: #007f3e; text-decoration: underline; }
.drop-hint  { font-size: 0.73rem; color: #6c757d; margin: 0.2rem 0 0; }

/* ── Notices ─────────────────────────────────────────────────────────────── */
.notice        { padding: 0.5rem 0.8rem; border-radius: 8px; font-size: 0.8rem; margin-top: 0.5rem; display: flex; align-items: center; gap: 0.4rem; }
.notice.danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.notice.warning{ background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.mb            { margin-bottom: 0.75rem; }

/* ── Recipients ──────────────────────────────────────────────────────────── */
.recipients-list { max-height: 300px; overflow-y: auto; }
.r-row  { display: flex; align-items: center; gap: 0.7rem; padding: 0.5rem 1rem; border-bottom: 1px solid #f4f4f4; cursor: pointer; transition: background .15s; }
.r-row:hover { background: #f8fff9; }
.r-row.sel   { background: #f0fff5; }
.r-check     { width: 17px; height: 17px; border-radius: 5px; border: 2px solid #c5d0d8; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: all .2s; }
.r-check.on  { background: #007f3e; border-color: #007f3e; }
.chk-icon    { width: 10px; height: 10px; color: white; }
.r-info      { display: flex; flex-direction: column; min-width: 0; }
.r-name      { font-size: 0.8rem; font-weight: 600; color: #1a2533; }
.r-phone     { font-size: 0.73rem; color: #6c757d; }

/* ── Labels / Inputs ────────────────────────────────────────────────────── */
.lbl  { display: block; font-size: 0.78rem; font-weight: 600; color: #344767; margin-bottom: 0.3rem; }
.req  { color: #dc2626; }
.inp  { width: 100%; padding: 0.55rem 0.8rem; border: 1.5px solid #e2e8f0; border-radius: 8px; font-size: 0.86rem; color: #1a2533; transition: border-color .2s; box-sizing: border-box; }
.inp:focus { outline: none; border-color: #007f3e; box-shadow: 0 0 0 3px rgba(0,127,62,.1); }

/* ── Message Tabs ────────────────────────────────────────────────────────── */
.msg-tabs { display: flex; border-bottom: 1px solid #f0f0f0; }
.msg-tab  {
  display: flex; align-items: center; gap: 0.4rem;
  padding: 0.55rem 1rem; font-size: 0.8rem; font-weight: 500; color: #6c757d;
  cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px;
  transition: all .2s; user-select: none;
}
.msg-tab.active { color: #007f3e; border-bottom-color: #007f3e; background: #f0fff6; }
.msg-tab.has-content .dot-ready { display: inline-block; width: 7px; height: 7px; background: #22c55e; border-radius: 50%; }
.dot-ready { display: none; }

/* Toggle switch */
.toggle-wrap { display: flex; align-items: center; cursor: pointer; }
.toggle-wrap input { display: none; }
.toggle { width: 28px; height: 16px; background: #d1d5db; border-radius: 99px; position: relative; transition: background .2s; }
.toggle::after { content: ''; position: absolute; width: 12px; height: 12px; background: white; border-radius: 50%; top: 2px; left: 2px; transition: left .2s; }
.toggle-wrap input:checked + .toggle { background: #007f3e; }
.toggle-wrap input:checked + .toggle::after { left: 14px; }

/* ── Message Editor ──────────────────────────────────────────────────────── */
.msg-editor { position: relative; padding: 0.9rem; }
.msg-disabled-overlay {
  position: absolute; inset: 0; background: rgba(248,249,250,.85);
  display: flex; align-items: center; justify-content: center;
  font-size: 0.82rem; color: #6c757d; z-index: 1; border-radius: 0 0 14px 14px;
  pointer-events: none;
}
.msg-textarea {
  width: 100%; padding: 0.6rem 0.8rem; border: 1.5px solid #e2e8f0;
  border-radius: 8px; font-size: 0.85rem; color: #1a2533; resize: vertical;
  font-family: inherit; box-sizing: border-box; transition: border-color .2s;
}
.msg-textarea:focus { outline: none; border-color: #007f3e; box-shadow: 0 0 0 3px rgba(0,127,62,.1); }
.msg-textarea:disabled { background: #f8f9fa; color: #adb5bd; }
.msg-meta { display: flex; gap: 0.5rem; font-size: 0.73rem; margin-top: 0.3rem; flex-wrap: wrap; }
.warn     { color: #d97706; font-weight: 600; }
.muted    { color: #6c757d; }
.preview-bubble {
  margin-top: 0.7rem; background: #e9f5ee; border-left: 3px solid #007f3e;
  border-radius: 10px 10px 10px 0; padding: 0.55rem 0.8rem;
  font-size: 0.8rem; color: #1a4d2e; white-space: pre-wrap; word-break: break-word;
  max-height: 80px; overflow-y: auto;
}

/* ── Send ────────────────────────────────────────────────────────────────── */
.send-summary { display: flex; gap: 0.6rem; flex-wrap: wrap; margin-bottom: 0.8rem; }
.pill { display: flex; align-items: center; gap: 0.35rem; background: #f0f4f8; border-radius: 20px; padding: 0.28rem 0.7rem; font-size: 0.78rem; color: #344767; font-weight: 500; }
.send-btn {
  width: 100%; padding: 0.8rem; background: linear-gradient(135deg,#007f3e,#005c3e);
  color: white; border: none; border-radius: 10px; font-size: 0.95rem; font-weight: 600;
  cursor: pointer; transition: all .25s; display: flex; align-items: center; justify-content: center; gap: 0.4rem;
  box-shadow: 0 6px 16px rgba(0,127,62,.25);
}
.send-btn:hover:not(:disabled) { background: linear-gradient(135deg,#009949,#007f3e); transform: translateY(-1px); }
.send-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Delivery Report ─────────────────────────────────────────────────────── */
.prog-bar-bg { position: relative; height: 8px; background: #e9ecef; border-radius: 99px; overflow: hidden; margin-bottom: 0.9rem; }
.prog-fill   { position: absolute; top: 0; height: 100%; border-radius: 99px; transition: width .4s; }
.prog-fill.delivered { background: #22c55e; left: 0; z-index: 2; }
.prog-fill.failed    { background: #ef4444; z-index: 1; }

.report-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 0.5rem; }
.r-stat      { text-align: center; padding: 0.55rem; border-radius: 8px; background: #f8f9fa; }
.r-stat.delivered { background: #f0fff4; }
.r-stat.failed    { background: #fff5f5; }
.r-stat.pending   { background: #fffbeb; }
.r-num { display: block; font-size: 1.25rem; font-weight: 700; }
.r-stat.delivered .r-num { color: #22c55e; }
.r-stat.failed    .r-num { color: #ef4444; }
.r-stat.pending   .r-num { color: #f59e0b; }
.r-lbl { font-size: 0.68rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }

.msg-group-row  { border: 1px solid #f0f0f0; border-radius: 10px; overflow: hidden; }
.msg-group-head { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; background: #f8f9fa; font-size: 0.8rem; }
.msg-group-label{ font-weight: 700; color: #1a2533; }

/* ── Logs Table ─────────────────────────────────────────────────────────── */
.logs-wrap  { overflow-x: auto; }
.logs-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
.logs-table th { position: sticky; top: 0; background: #f8f9fa; padding: 0.45rem 0.75rem; text-align: left; font-size: 0.7rem; text-transform: uppercase; color: #6c757d; font-weight: 700; border-bottom: 1px solid #e9ecef; }
.logs-table td { padding: 0.45rem 0.75rem; border-bottom: 1px solid #f4f4f4; vertical-align: middle; }
.logs-table tbody tr:hover { background: #f8f9fa; }

.log-badge { display: inline-block; padding: 0.18rem 0.5rem; border-radius: 20px; font-size: 0.68rem; font-weight: 700; text-transform: capitalize; }
.log-badge.delivered,.log-badge.completed { background: #dcfce7; color: #166534; }
.log-badge.sent    { background: #dbeafe; color: #1e40af; }
.log-badge.failed  { background: #fee2e2; color: #991b1b; }
.log-badge.pending,.log-badge.sending { background: #fef3c7; color: #92400e; }

/* ── Misc ────────────────────────────────────────────────────────────────── */
.empty { padding: 2rem; text-align: center; color: #6c757d; font-size: 0.85rem; }
.small { font-size: 0.75rem; }
.green { color: #22c55e; }
.red   { color: #ef4444; }
.bold  { font-weight: 700; }

/* ── Responsive ──────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
  .compose-grid { grid-template-columns: 1fr; }
  .report-grid  { grid-template-columns: repeat(2,1fr); }
  .top-bar      { flex-direction: column; align-items: flex-start; }
  .msg-tabs     { overflow-x: auto; }
}
</style>
