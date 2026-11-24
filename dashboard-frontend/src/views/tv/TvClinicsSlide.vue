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
    planLayout(props.items.length, { maxCols: props.maxCols, maxRows: 8, targetAspect: 2.6, stageAspect: props.aspect }).counts,
  ),
)

// Long single words (GASTROENTEROLOGY, OTORHINOLARYNGOLOGY) get a smaller size so they fit on one line
// instead of being cut in the middle of the word.
const nameSize = (name) => {
  const longest = Math.max(...name.split(/\s+/).map((word) => word.length))
  if (longest >= 17) return 'tv-name--xlong'
  if (longest >= 12 || name.length > 22) return 'tv-name--long'
  return ''
}

// null means the patient data is unavailable (different from zero waiting)
const count = (value) => (value === null || value === undefined ? '—' : value)
</script>

<template>
  <div v-if="items.length" class="tv-grid">
    <div v-for="(line, index) in lines" :key="index" class="tv-line">
      <article v-for="clinic in line" :key="clinic.code" class="tv-card">
        <div class="tv-inner">
          <div class="tv-info">
            <h3 class="tv-name" :class="nameSize(clinic.name)">{{ clinic.name }}</h3>
          </div>

          <div class="tv-total">
            <span class="tv-count">({{ count(clinic.patients) }})</span>
            <span class="tv-total-label">{{ clinic.patients === 1 ? t('waitingPatient') : t('waitingPatients') }}</span>
          </div>
        </div>
      </article>
    </div>
  </div>
  <div v-else class="tv-empty">{{ t('emptyClinics') }}</div>
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
  border-left: 0.35em solid var(--tv-red);
  border-radius: 0.5em;
  overflow: hidden;
}

.tv-inner {
  height: 100%;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  gap: 0.9em;
  padding: 0 1.3em 0 1.2em;
  font-size: 1em;
  font-size: min(20cqh, 4cqw);
}

.tv-info {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35em;
}

.tv-name {
  margin: 0;
  color: var(--tv-text);
  font-size: 1.55em;
  font-weight: 700;
  line-height: 1.1;
  letter-spacing: 0.01em;
  overflow-wrap: anywhere;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.tv-name--long {
  font-size: 1.2em;
}
.tv-name--xlong {
  font-size: 1em;
}
.tv-total {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25em;
}
.tv-count {
  color: var(--tv-accent);
  font-size: 2.1em;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.tv-total-label {
  color: var(--tv-muted);
  font-size: 0.65em;
  font-weight: 700;
  letter-spacing: 0.08em;
  white-space: nowrap;
}

.tv-empty {
  height: 100%;
  display: grid;
  place-items: center;
  color: var(--tv-muted);
  font-size: 2.4em;
}
</style>
