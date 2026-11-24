<script setup>
import { computed } from 'vue'
import { useTvI18n } from './i18n'
import { planLayout, splitRows } from './layout'

const props = defineProps({
  items: { type: Array, required: true },
  // set by the rotator from the device width / height so the cards always fill the screen
  maxCols: { type: Number, default: 4 },
  aspect: { type: Number, default: 2.3 },
})

const { t } = useTvI18n()


// Rows and columns follow the number of clinics so the cards always fill the screen.
const lines = computed(() =>
  splitRows(
    props.items,
    planLayout(props.items.length, { maxCols: props.maxCols, maxRows: 3, targetAspect: 2.2, stageAspect: props.aspect }).counts,
  ),
)

// Longest doctor list on this screen: the text is sized so that this many rows fill the card height.
const rowCount = computed(() =>
  Math.max(1, ...props.items.map((clinic) => clinic.doctors.length)),
)

// Doctors come sorted busiest first (then idle ones, switched-off ones last). Each card holds a few of them;
// a clinic with more doctors continues on the next card.
// A known zero (the data is available and the doctor has no patients), not "data unavailable".
const hasNoPatients = (doctor) => doctor.booked === 0

// null means the patient data is unavailable (different from zero)
const count = (value) => (value === null || value === undefined ? '—' : value)
</script>

<template>
  <div v-if="items.length" class="tv-grid" :style="{ '--rows': rowCount }">
    <div v-for="(line, index) in lines" :key="index" class="tv-line">
      <article v-for="clinic in line" :key="clinic.key || clinic.code" class="tv-card">
        <div class="tv-inner">
          <header class="head">
            <h3 class="tv-name">
              {{ clinic.name }}<small v-if="clinic.parts > 1" class="tv-part"> {{ clinic.part }}/{{ clinic.parts }}</small>
            </h3>
            <span class="tv-count">({{ count(clinic.patients) }}<small>{{ ' ' + t('waitingShort') }}</small>)</span>
          </header>

          <div class="tv-row tv-cols">
            <span>{{ t('colDoctor') }}</span>
            <span class="is-booked">{{ t('colBooked') }}</span>
            <span class="is-waiting">{{ t('colWaiting') }}</span>
            <span class="is-seen">{{ t('colSeen') }}</span>
          </div>

          <ul class="tv-list">
            <li
              v-for="doctor in clinic.doctors"
              :key="doctor.code"
              class="tv-row"
              :class="{ 'is-absent': doctor.absent }"
            >
              <span class="tv-doctor">{{ doctor.name }}</span>
              <span v-if="doctor.absent" class="tv-gone">{{ t('absent') }}</span>
              <span v-else-if="hasNoPatients(doctor)" class="tv-idle">{{ t('noPatients') }}</span>
              <template v-else>
                <b class="is-booked">{{ count(doctor.booked) }}</b>
                <b class="is-waiting">{{ count(doctor.waiting) }}</b>
                <b class="is-seen">{{ count(doctor.seen) }}</b>
              </template>
            </li>
            <li v-if="!clinic.doctors.length" class="tv-more">{{ t('noDoctors') }}</li>
          </ul>
        </div>
      </article>
    </div>
  </div>
  <div v-else class="tv-empty">{{ t('emptyDoctors') }}</div>
</template>

<style scoped>
.tv-grid {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.9em;
}

.tv-line {
  flex: 1 1 0;
  min-height: 0;
  display: flex;
  gap: 0.9em;
}

/* Each card is a size container so its text can scale with the card (cqh / cqw units). */
.tv-card {
  flex: 1 1 0;
  min-width: 0;
  min-height: 0;
  container-type: size;
  background: var(--tv-card);
  border: 1px solid var(--tv-line);
  border-top: 0.3em solid var(--tv-red);
  border-radius: 0.5em;
  overflow: hidden;
}

.tv-inner {
  height: 100%;
  display: flex;
  flex-direction: column;
  padding: 0.8em 1.1em 0.7em;
  font-size: 1em;
  /* as big as the card allows: limited by its height (header + rows) or by its width */
  font-size: min(calc(100cqh / (5.4 + 2 * var(--rows, 4))), 3cqw);
}

.head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.6em;
}

.tv-name {
  margin: 0;
  min-width: 0;
  color: var(--tv-text);
  font-size: 1.45em;
  font-weight: 700;
  letter-spacing: 0.01em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tv-part {
  color: var(--tv-muted);
  font-size: 0.6em;
  font-weight: 700;
}

.tv-count {
  color: var(--tv-accent);
  font-size: 1.45em;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.tv-count small {
  font-size: 0.55em;
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
}

.tv-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) repeat(3, 5.9em);
  align-items: center;
  text-align: right;
  gap: 0.3em;
}
.tv-row > :first-child {
  text-align: left;
}

.tv-cols {
  margin-top: 0.45em;
  padding-bottom: 0.3em;
  border-bottom: 1px solid var(--tv-line);
  color: var(--tv-muted);
  font-weight: 700;
  white-space: nowrap;
}
.tv-cols > span {
  font-size: 0.8em;
  letter-spacing: 0.04em;
}

.tv-list {
  flex: 1;
  min-height: 0;
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  justify-content: space-evenly;
}

.tv-doctor {
  min-width: 0;
  font-size: 1.2em;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
b {
  font-size: 1.45em;
  font-variant-numeric: tabular-nums;
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

.is-absent .tv-doctor {
  color: var(--tv-muted);
}
.tv-gone {
  grid-column: 2 / -1;
  justify-self: center;
  display: inline-flex;
  align-items: center;
  color: var(--tv-absent);
  font-size: 1.35em;
  font-weight: 800;
  letter-spacing: 0.1em;
  white-space: nowrap;
  /* fades in and out non-stop, like a monitor beeping */
  animation: gone-fade 1s ease-in-out infinite;
}
/* doctor with no patients right now: a calm, muted status instead of three zeros */
.tv-idle {
  grid-column: 2 / -1;
  justify-self: end;
  color: var(--tv-muted);
  font-size: 0.85em;
  font-weight: 700;
  letter-spacing: 0.08em;
  white-space: nowrap;
}
@keyframes gone-fade {
  0%, 100% {
    opacity: 0.2;
    text-shadow: none;
  }
  50% {
    opacity: 1;
    text-shadow: 0 0 0.6em rgba(255, 140, 140, 0.95);
  }
}
@media (prefers-reduced-motion: reduce) {
  .tv-gone {
    animation: none;
  }
}

.tv-more {
  color: var(--tv-muted);
  font-size: 0.95em;
}

.tv-empty {
  height: 100%;
  display: grid;
  place-items: center;
  color: var(--tv-muted);
  font-size: 2.4em;
}
</style>
