<script setup>
import { ref, computed, onUnmounted } from 'vue'
import * as XLSX from 'xlsx'
import { useDashboardStore } from '@/stores/dashboard'

const dashboard = useDashboardStore()

// ── Contacts ────────────────────────────────────────────────────────────────
const contacts = ref([])
const selectedIds = ref(new Set())
const fileInputRef = ref(null)
const isDragging = ref(false)
const importError = ref('')

const allSelected = computed(() =>
  contacts.value.length > 0 && selectedIds.value.size === contacts.value.length
)
const selectedCount = computed(() => selectedIds.value.size)

function toggleAll() {
  if (allSelected.value) {
    selectedIds.value = new Set()
  } else {
    selectedIds.value = new Set(contacts.value.map((_, i) => i))
  }
}

function toggleOne(idx) {
  const s = new Set(selectedIds.value)
  s.has(idx) ? s.delete(idx) : s.add(idx)
  selectedIds.value = s
}

function parseFile(file) {
  importError.value = ''
  const reader = new FileReader()
  reader.onload = (e) => {
    try {
      const wb = XLSX.read(e.target.result, { type: 'binary' })
      const ws = wb.Sheets[wb.SheetNames[0]]
      const rows = XLSX.utils.sheet_to_json(ws, { defval: '' })
      if (!rows.length) { importError.value = 'Sheet is empty.'; return }

      // Auto-detect phone & name columns (case-insensitive)
      const keys = Object.keys(rows[0])
      const phoneKey = keys.find(k => /phone|mobile|tel|msisdn/i.test(k)) || keys[0]
      const nameKey  = keys.find(k => /name|client|patient/i.test(k) && k !== phoneKey) || null

      const parsed = rows
        .map((r, i) => ({
          id:    i,
          phone: String(r[phoneKey] ?? '').trim(),
          name:  nameKey ? String(r[nameKey] ?? '').trim() : '',
        }))
        .filter(r => r.phone)

      if (!parsed.length) { importError.value = 'No phone numbers found. Ensure a column named "phone" or "mobile" exists.'; return }

      contacts.value = parsed
      selectedIds.value = new Set(parsed.map((_, i) => i))
    } catch (err) {
      importError.value = 'Failed to read file: ' + err.message
    }
  }
  reader.readAsBinaryString(file)
}

function onFileChange(e) { if (e.target.files[0]) parseFile(e.target.files[0]) }
function onDrop(e) { isDragging.value = false; if (e.dataTransfer.files[0]) parseFile(e.dataTransfer.files[0]) }

// ── Compose ─────────────────────────────────────────────────────────────────
const campaignTitle = ref('')
const smsText = ref('')
const MAX_SMS = 918
const smsLen = computed(() => smsText.value.length)
const smsParts = computed(() => Math.ceil(smsLen.value / 160) || 1)

// ── Sending ──────────────────────────────────────────────────────────────────
const isSending = ref(false)
const sendError = ref('')
const activeCampaign = ref(null)
const pollTimer = ref(null)

async function sendSms() {
  sendError.value = ''
  if (!campaignTitle.value.trim()) { sendError.value = 'Campaign title is required.'; return }
  if (!smsText.value.trim()) { sendError.value = 'SMS message is required.'; return }
  if (selectedCount.value === 0) { sendError.value = 'Select at least one recipient.'; return }

  const recipients = [...selectedIds.value].map(i => ({
    phone: contacts.value[i].phone,
    name:  contacts.value[i].name || null,
  }))

  isSending.value = true
  try {
    const resp = await dashboard.api.post('/sms/send', {
      title:      campaignTitle.value.trim(),
      message:    smsText.value.trim(),
      recipients,
    })
    activeCampaign.value = {
      id:        resp.data.campaign_id,
      total:     resp.data.total,
      delivered: 0,
      failed:    0,
      pending:   resp.data.total,
      status:    'sending',
      logs:      [],
    }
    startPolling(resp.data.campaign_id)
  } catch (err) {
    sendError.value = err?.response?.data?.message || 'Failed to send. Please try again.'
  } finally {
    isSending.value = false
  }
}

function startPolling(id) {
  stopPolling()
  pollTimer.value = setInterval(() => pollStatus(id), 3000)
}

function stopPolling() {
  if (pollTimer.value) { clearInterval(pollTimer.value); pollTimer.value = null }
}

async function pollStatus(id) {
  try {
    const [statusResp, logsResp] = await Promise.all([
      dashboard.api.get(`/sms/campaigns/${id}/status`),
      dashboard.api.get(`/sms/campaigns/${id}/logs`),
    ])
    const s = statusResp.data
    activeCampaign.value = {
      ...activeCampaign.value,
      delivered: s.delivered,
      failed:    s.failed,
      pending:   s.pending,
      status:    s.status,
      logs:      logsResp.data.logs?.data ?? [],
    }
    if (s.status === 'completed' || s.status === 'failed') stopPolling()
  } catch (_) {}
}

// ── History ──────────────────────────────────────────────────────────────────
const campaigns = ref([])
const loadingHistory = ref(false)
const activeTab = ref('compose') // 'compose' | 'history'

async function loadHistory() {
  loadingHistory.value = true
  try {
    const r = await dashboard.api.get('/sms/campaigns')
    campaigns.value = r.data.data ?? []
  } catch (_) {
  } finally {
    loadingHistory.value = false
  }
}

function switchTab(tab) {
  activeTab.value = tab
  if (tab === 'history') loadHistory()
}

function newCampaign() {
  stopPolling()
  activeCampaign.value = null
  campaignTitle.value = ''
  smsText.value = ''
  contacts.value = []
  selectedIds.value = new Set()
  sendError.value = ''
  activeTab.value = 'compose'
}

onUnmounted(stopPolling)

function statusBadge(s) {
  const m = { pending:'warning', sent:'info', delivered:'success', failed:'danger', sending:'primary', completed:'success' }
  return m[s] ?? 'secondary'
}
</script>

<template>
  <div class="sms-page">
    <!-- Header -->
    <div class="sms-header">
      <div class="sms-header-left">
        <div class="sms-icon-wrap">
          <CIcon icon="cil-speech" class="sms-icon" />
        </div>
        <div>
          <h1 class="sms-title">SMS Management</h1>
          <p class="sms-subtitle">Send promotional and event messages to clients</p>
        </div>
      </div>
      <div class="sms-header-tabs">
        <button :class="['tab-btn', activeTab === 'compose' && 'active']" @click="switchTab('compose')">
          <CIcon icon="cil-pencil" class="me-1" /> Compose
        </button>
        <button :class="['tab-btn', activeTab === 'history' && 'active']" @click="switchTab('history')">
          <CIcon icon="cil-list" class="me-1" /> History
        </button>
      </div>
    </div>

    <!-- ── COMPOSE TAB ── -->
    <div v-if="activeTab === 'compose'" class="sms-grid">

      <!-- Left column -->
      <div class="sms-col-left">

        <!-- Import Section -->
        <div class="sms-card">
          <div class="sms-card-header">
            <CIcon icon="cil-cloud-upload" class="card-header-icon" />
            <span>Import Recipients</span>
          </div>
          <div class="sms-card-body">
            <div
              class="drop-zone"
              :class="{ dragging: isDragging }"
              @dragover.prevent="isDragging = true"
              @dragleave="isDragging = false"
              @drop.prevent="onDrop"
              @click="fileInputRef?.click()"
            >
              <CIcon icon="cil-file" class="drop-icon" />
              <p class="drop-text">Drop Excel file here or <span class="drop-link">browse</span></p>
              <p class="drop-hint">Columns: <code>phone</code> (required) · <code>name</code> (optional)</p>
              <input ref="fileInputRef" type="file" accept=".xlsx,.xls,.csv" hidden @change="onFileChange" />
            </div>
            <div v-if="importError" class="alert-mini danger mt-2">{{ importError }}</div>
          </div>
        </div>

        <!-- Recipients List -->
        <div v-if="contacts.length" class="sms-card mt-3">
          <div class="sms-card-header">
            <CIcon icon="cil-people" class="card-header-icon" />
            <span>Recipients</span>
            <span class="badge-count">{{ contacts.length }}</span>
            <button class="select-all-btn ms-auto" @click="toggleAll">
              {{ allSelected ? 'Deselect All' : 'Select All' }}
            </button>
          </div>
          <div class="sms-card-body no-pad">
            <div class="recipients-list">
              <div
                v-for="(c, i) in contacts"
                :key="i"
                class="recipient-row"
                :class="{ selected: selectedIds.has(i) }"
                @click="toggleOne(i)"
              >
                <div class="recipient-check">
                  <div class="custom-check" :class="{ checked: selectedIds.has(i) }">
                    <CIcon v-if="selectedIds.has(i)" icon="cil-check" class="check-icon" />
                  </div>
                </div>
                <div class="recipient-info">
                  <span class="recipient-name">{{ c.name || '—' }}</span>
                  <span class="recipient-phone">{{ c.phone }}</span>
                </div>
              </div>
            </div>
          </div>
          <div class="sms-card-footer">
            <span class="selected-summary">
              <strong>{{ selectedCount }}</strong> of {{ contacts.length }} selected
            </span>
          </div>
        </div>

      </div>

      <!-- Right column -->
      <div class="sms-col-right">

        <!-- Campaign Info -->
        <div class="sms-card">
          <div class="sms-card-header">
            <CIcon icon="cil-description" class="card-header-icon" />
            <span>Campaign Details</span>
          </div>
          <div class="sms-card-body">
            <div class="field-group">
              <label class="field-label">Campaign Title</label>
              <input
                v-model="campaignTitle"
                class="field-input"
                placeholder="e.g. Christmas Greetings 2026"
                maxlength="255"
              />
            </div>
          </div>
        </div>

        <!-- Compose -->
        <div class="sms-card mt-3">
          <div class="sms-card-header">
            <CIcon icon="cil-comment-square" class="card-header-icon" />
            <span>Message</span>
          </div>
          <div class="sms-card-body">
            <div class="field-group">
              <label class="field-label">SMS Content</label>
              <textarea
                v-model="smsText"
                class="field-textarea"
                :maxlength="MAX_SMS"
                placeholder="Type your message here… e.g. Happy Christmas from MNH! Wishing you good health and joy."
                rows="6"
              ></textarea>
              <div class="sms-meta">
                <span :class="smsLen > 160 ? 'text-warning' : 'text-muted'">
                  {{ smsLen }} / {{ MAX_SMS }} chars
                </span>
                <span class="text-muted">· {{ smsParts }} SMS part{{ smsParts > 1 ? 's' : '' }}</span>
              </div>
            </div>

            <!-- Preview -->
            <div v-if="smsText" class="sms-preview">
              <div class="preview-bubble">{{ smsText }}</div>
            </div>
          </div>
        </div>

        <!-- Send -->
        <div class="sms-card mt-3">
          <div class="sms-card-body">
            <div v-if="sendError" class="alert-mini danger mb-3">{{ sendError }}</div>
            <div class="send-summary">
              <div class="summary-pill">
                <CIcon icon="cil-people" /> {{ selectedCount }} recipients
              </div>
              <div class="summary-pill">
                <CIcon icon="cil-comment-square" /> {{ smsParts }} part{{ smsParts > 1 ? 's' : '' }} each
              </div>
            </div>
            <button
              class="send-btn"
              :disabled="isSending || selectedCount === 0 || !smsText.trim() || !campaignTitle.trim()"
              @click="sendSms"
            >
              <span v-if="!isSending">
                <CIcon icon="cil-send" class="me-2" />
                Send to {{ selectedCount }} Recipient{{ selectedCount !== 1 ? 's' : '' }}
              </span>
              <span v-else class="d-flex align-items-center gap-2">
                <span class="spinner-border spinner-border-sm"></span> Queuing…
              </span>
            </button>
          </div>
        </div>

        <!-- Live Delivery Report -->
        <div v-if="activeCampaign" class="sms-card mt-3">
          <div class="sms-card-header">
            <CIcon icon="cil-chart" class="card-header-icon" />
            <span>Delivery Report</span>
            <span :class="`status-dot ${activeCampaign.status}`"></span>
            <span class="ms-1 text-capitalize small">{{ activeCampaign.status }}</span>
            <button class="ms-auto select-all-btn" @click="newCampaign">+ New Campaign</button>
          </div>
          <div class="sms-card-body">
            <!-- Progress bar -->
            <div class="progress-wrap">
              <div class="progress-bar-bg">
                <div
                  class="progress-bar-fill delivered"
                  :style="{ width: activeCampaign.total ? (activeCampaign.delivered / activeCampaign.total * 100) + '%' : '0%' }"
                ></div>
                <div
                  class="progress-bar-fill failed"
                  :style="{ width: activeCampaign.total ? (activeCampaign.failed / activeCampaign.total * 100) + '%' : '0%', left: activeCampaign.total ? (activeCampaign.delivered / activeCampaign.total * 100) + '%' : '0%' }"
                ></div>
              </div>
            </div>
            <div class="report-stats">
              <div class="report-stat delivered">
                <span class="stat-num">{{ activeCampaign.delivered }}</span>
                <span class="stat-label">Delivered</span>
              </div>
              <div class="report-stat failed">
                <span class="stat-num">{{ activeCampaign.failed }}</span>
                <span class="stat-label">Failed</span>
              </div>
              <div class="report-stat pending">
                <span class="stat-num">{{ activeCampaign.pending }}</span>
                <span class="stat-label">Pending</span>
              </div>
              <div class="report-stat total">
                <span class="stat-num">{{ activeCampaign.total }}</span>
                <span class="stat-label">Total</span>
              </div>
            </div>

            <!-- Logs table -->
            <div v-if="activeCampaign.logs.length" class="logs-table-wrap mt-3">
              <table class="logs-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Error</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(log, i) in activeCampaign.logs" :key="log.id">
                    <td>{{ i + 1 }}</td>
                    <td>{{ log.recipient_name || '—' }}</td>
                    <td>{{ log.phone }}</td>
                    <td>
                      <span :class="`log-badge ${log.status}`">{{ log.status }}</span>
                    </td>
                    <td class="text-muted small">{{ log.error_message || '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── HISTORY TAB ── -->
    <div v-if="activeTab === 'history'" class="sms-card mt-3">
      <div class="sms-card-header">
        <CIcon icon="cil-history" class="card-header-icon" />
        <span>Campaign History</span>
      </div>
      <div class="sms-card-body no-pad">
        <div v-if="loadingHistory" class="empty-state">
          <span class="spinner-border text-primary"></span>
        </div>
        <div v-else-if="!campaigns.length" class="empty-state">No campaigns yet.</div>
        <table v-else class="logs-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Title</th>
              <th>Total</th>
              <th>Delivered</th>
              <th>Failed</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(c, i) in campaigns" :key="c.id">
              <td>{{ i + 1 }}</td>
              <td>{{ c.title }}</td>
              <td>{{ c.total_recipients }}</td>
              <td class="text-success fw-bold">{{ c.delivered_count }}</td>
              <td class="text-danger fw-bold">{{ c.failed_count }}</td>
              <td><span :class="`log-badge ${c.status}`">{{ c.status }}</span></td>
              <td class="text-muted small">{{ new Date(c.created_at).toLocaleString() }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Page ───────────────────────────────────────────────────────────────── */
.sms-page {
  padding: 1.5rem;
  max-width: 1200px;
  margin: 0 auto;
  font-family: 'Inter', system-ui, sans-serif;
}

/* ── Header ─────────────────────────────────────────────────────────────── */
.sms-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1.5rem;
  padding: 1rem 1.25rem;
  background: white;
  border-radius: 14px;
  box-shadow: 0 2px 12px rgba(0,0,0,.07);
}
.dark-theme .sms-header { background: #1e2a3a; }

.sms-header-left { display: flex; align-items: center; gap: 1rem; }
.sms-icon-wrap {
  width: 46px; height: 46px; border-radius: 12px;
  background: linear-gradient(135deg, #007f3e, #00b359);
  display: flex; align-items: center; justify-content: center;
}
.sms-icon { width: 22px; height: 22px; color: white; }
.sms-title { font-size: 1.2rem; font-weight: 700; margin: 0; color: #1a2533; }
.sms-subtitle { font-size: 0.8rem; color: #6c757d; margin: 0; }

.sms-header-tabs { display: flex; gap: 0.5rem; }
.tab-btn {
  padding: 0.45rem 1rem; border-radius: 8px; border: 1.5px solid #dee2e6;
  background: transparent; font-size: 0.85rem; font-weight: 500;
  color: #6c757d; cursor: pointer; transition: all .2s;
  display: flex; align-items: center; gap: 0.3rem;
}
.tab-btn.active { background: #007f3e; border-color: #007f3e; color: white; }
.tab-btn:hover:not(.active) { background: #f8f9fa; color: #1a2533; }

/* ── Grid ───────────────────────────────────────────────────────────────── */
.sms-grid { display: grid; grid-template-columns: 1fr 1.4fr; gap: 1.25rem; align-items: start; }
.sms-col-left, .sms-col-right { display: flex; flex-direction: column; }

/* ── Cards ──────────────────────────────────────────────────────────────── */
.sms-card {
  background: white;
  border-radius: 14px;
  box-shadow: 0 2px 12px rgba(0,0,0,.07);
  overflow: hidden;
}
.dark-theme .sms-card { background: #1e2a3a; }
.mt-3 { margin-top: 1rem; }

.sms-card-header {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.75rem 1rem;
  background: linear-gradient(to right, #f8f9fa, #ffffff);
  border-bottom: 1px solid #f0f0f0;
  font-size: 0.85rem; font-weight: 600; color: #1a2533;
}
.dark-theme .sms-card-header { background: #243447; border-color: #2d3f55; color: #e2e8f0; }
.card-header-icon { width: 16px; height: 16px; color: #007f3e; }

.badge-count {
  margin-left: 0.25rem;
  background: #007f3e; color: white;
  border-radius: 20px; padding: 0 8px;
  font-size: 0.72rem; font-weight: 700;
}

.select-all-btn {
  background: none; border: 1.5px solid #007f3e; color: #007f3e;
  border-radius: 6px; padding: 0.2rem 0.6rem;
  font-size: 0.75rem; font-weight: 600; cursor: pointer; transition: all .2s;
}
.select-all-btn:hover { background: #007f3e; color: white; }

.sms-card-body { padding: 1rem; }
.sms-card-body.no-pad { padding: 0; }

.sms-card-footer {
  padding: 0.5rem 1rem;
  border-top: 1px solid #f0f0f0;
  background: #f8f9fa;
  font-size: 0.78rem; color: #6c757d;
}

/* ── Drop Zone ──────────────────────────────────────────────────────────── */
.drop-zone {
  border: 2px dashed #c8d8e0;
  border-radius: 10px;
  padding: 1.5rem;
  text-align: center;
  cursor: pointer;
  transition: all .25s;
  background: #f8fbff;
}
.drop-zone:hover, .drop-zone.dragging {
  border-color: #007f3e;
  background: #f0fff6;
}
.drop-icon { width: 32px; height: 32px; color: #007f3e; margin-bottom: 0.5rem; }
.drop-text { font-size: 0.88rem; font-weight: 500; color: #344767; margin: 0.2rem 0; }
.drop-link { color: #007f3e; text-decoration: underline; }
.drop-hint { font-size: 0.75rem; color: #6c757d; margin: 0.25rem 0 0; }

/* ── Recipients ─────────────────────────────────────────────────────────── */
.recipients-list { max-height: 280px; overflow-y: auto; }
.recipient-row {
  display: flex; align-items: center; gap: 0.75rem;
  padding: 0.55rem 1rem; border-bottom: 1px solid #f4f4f4;
  cursor: pointer; transition: background .15s;
}
.recipient-row:hover { background: #f8fff9; }
.recipient-row.selected { background: #f0fff5; }
.custom-check {
  width: 18px; height: 18px; border-radius: 5px;
  border: 2px solid #c5d0d8;
  display: flex; align-items: center; justify-content: center;
  transition: all .2s; flex-shrink: 0;
}
.custom-check.checked { background: #007f3e; border-color: #007f3e; }
.check-icon { width: 10px; height: 10px; color: white; }
.recipient-info { display: flex; flex-direction: column; min-width: 0; }
.recipient-name { font-size: 0.82rem; font-weight: 600; color: #1a2533; }
.recipient-phone { font-size: 0.75rem; color: #6c757d; }
.selected-summary { font-size: 0.8rem; }

/* ── Fields ─────────────────────────────────────────────────────────────── */
.field-group { display: flex; flex-direction: column; gap: 0.35rem; }
.field-label { font-size: 0.8rem; font-weight: 600; color: #344767; }
.field-input {
  padding: 0.6rem 0.85rem;
  border: 1.5px solid #e2e8f0; border-radius: 8px;
  font-size: 0.88rem; color: #1a2533;
  transition: border-color .2s;
  background: white;
}
.field-input:focus { outline: none; border-color: #007f3e; box-shadow: 0 0 0 3px rgba(0,127,62,.1); }
.field-textarea {
  padding: 0.6rem 0.85rem;
  border: 1.5px solid #e2e8f0; border-radius: 8px;
  font-size: 0.88rem; color: #1a2533; resize: vertical;
  font-family: inherit; width: 100%; transition: border-color .2s;
  background: white;
}
.field-textarea:focus { outline: none; border-color: #007f3e; box-shadow: 0 0 0 3px rgba(0,127,62,.1); }
.sms-meta { display: flex; gap: 0.5rem; font-size: 0.75rem; margin-top: 0.3rem; }

/* ── Preview Bubble ─────────────────────────────────────────────────────── */
.sms-preview { margin-top: 0.85rem; }
.preview-bubble {
  background: #e9f5ee;
  border-radius: 12px 12px 12px 0;
  padding: 0.65rem 0.9rem;
  font-size: 0.83rem; color: #1a4d2e;
  border-left: 3px solid #007f3e;
  white-space: pre-wrap; word-break: break-word;
  max-height: 100px; overflow-y: auto;
}

/* ── Send ───────────────────────────────────────────────────────────────── */
.send-summary { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 0.85rem; }
.summary-pill {
  display: flex; align-items: center; gap: 0.4rem;
  background: #f0f4f8; border-radius: 20px;
  padding: 0.3rem 0.75rem; font-size: 0.8rem; color: #344767; font-weight: 500;
}
.send-btn {
  width: 100%; padding: 0.85rem;
  background: linear-gradient(135deg, #007f3e, #005c3e);
  color: white; border: none; border-radius: 10px;
  font-size: 1rem; font-weight: 600; cursor: pointer;
  transition: all .25s; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 6px 16px rgba(0,127,62,.25);
}
.send-btn:hover:not(:disabled) { background: linear-gradient(135deg, #009949, #007f3e); transform: translateY(-1px); }
.send-btn:disabled { opacity: .55; cursor: not-allowed; }

/* ── Delivery Report ────────────────────────────────────────────────────── */
.status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.status-dot.sending { background: #3b82f6; animation: pulse 1s infinite; }
.status-dot.completed { background: #22c55e; }
.status-dot.failed { background: #ef4444; }

.progress-wrap { margin-bottom: 1rem; }
.progress-bar-bg { position: relative; height: 8px; background: #e9ecef; border-radius: 99px; overflow: hidden; }
.progress-bar-fill { position: absolute; top: 0; height: 100%; border-radius: 99px; transition: width .4s ease; }
.progress-bar-fill.delivered { background: #22c55e; left: 0; z-index: 2; }
.progress-bar-fill.failed { background: #ef4444; z-index: 1; }

.report-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
.report-stat {
  text-align: center; padding: 0.6rem;
  border-radius: 8px; background: #f8f9fa;
}
.report-stat.delivered { background: #f0fff4; }
.report-stat.failed { background: #fff5f5; }
.report-stat.pending { background: #fffbeb; }
.stat-num { display: block; font-size: 1.3rem; font-weight: 700; }
.report-stat.delivered .stat-num { color: #22c55e; }
.report-stat.failed .stat-num { color: #ef4444; }
.report-stat.pending .stat-num { color: #f59e0b; }
.stat-label { font-size: 0.7rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }

/* ── Logs Table ─────────────────────────────────────────────────────────── */
.logs-table-wrap { max-height: 220px; overflow-y: auto; border-radius: 8px; }
.logs-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
.logs-table th {
  position: sticky; top: 0; background: #f8f9fa;
  padding: 0.5rem 0.75rem; text-align: left;
  font-size: 0.72rem; text-transform: uppercase;
  color: #6c757d; font-weight: 700; border-bottom: 1px solid #e9ecef;
}
.logs-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #f4f4f4; vertical-align: middle; }
.logs-table tbody tr:hover { background: #f8f9fa; }

.log-badge {
  display: inline-block; padding: 0.2rem 0.55rem;
  border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: capitalize;
}
.log-badge.delivered, .log-badge.completed { background: #dcfce7; color: #166534; }
.log-badge.sent { background: #dbeafe; color: #1e40af; }
.log-badge.failed { background: #fee2e2; color: #991b1b; }
.log-badge.pending, .log-badge.sending { background: #fef3c7; color: #92400e; }

/* ── Alert ──────────────────────────────────────────────────────────────── */
.alert-mini {
  padding: 0.55rem 0.85rem;
  border-radius: 8px; font-size: 0.82rem;
}
.alert-mini.danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.mt-2 { margin-top: 0.5rem; }
.ms-auto { margin-left: auto; }

/* ── Empty ──────────────────────────────────────────────────────────────── */
.empty-state {
  padding: 2rem; text-align: center; color: #6c757d; font-size: 0.88rem;
}

/* ── Animation ──────────────────────────────────────────────────────────── */
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: .4; }
}

/* ── Responsive ─────────────────────────────────────────────────────────── */
@media (max-width: 768px) {
  .sms-grid { grid-template-columns: 1fr; }
  .report-stats { grid-template-columns: repeat(2, 1fr); }
  .sms-header { flex-direction: column; align-items: flex-start; }
}
</style>
