// Works out how to lay `count` cards out so they fill the whole stage with no empty area.
// It picks the number of rows whose cards come closest to `targetAspect` (width / height),
// then spreads the cards over those rows as evenly as possible (13 cards -> 4, 3, 3, 3).
// Rows with fewer cards simply grow wider, so the screen is always covered.
const DEFAULT_STAGE_ASPECT = 2.3 // roughly the stage width / height on a 16:9 TV

export const planLayout = (count, { maxCols, maxRows, targetAspect, stageAspect = DEFAULT_STAGE_ASPECT }) => {
  if (count <= 0) return { counts: [] }

  let best = null
  for (let rows = Math.ceil(count / maxCols); rows <= Math.min(maxRows, count); rows += 1) {
    const cols = Math.ceil(count / rows)
    const score = Math.abs(Math.log((stageAspect * rows) / cols / targetAspect))
    if (!best || score < best.score) best = { rows, score }
  }
  const rows = best ? best.rows : Math.min(maxRows, count)

  const base = Math.floor(count / rows)
  const extra = count % rows
  return { counts: Array.from({ length: rows }, (_, i) => base + (i < extra ? 1 : 0)) }
}

export const splitRows = (items, counts) => {
  const lines = []
  let start = 0
  counts.forEach((size) => {
    lines.push(items.slice(start, start + size))
    start += size
  })
  return lines
}

export const chunk = (list, size) => {
  const pages = []
  for (let i = 0; i < list.length; i += size) pages.push(list.slice(i, i + size))
  return pages
}

// Splits a list into the fewest pages of at most `max` items, with pages of near-equal size
// (e.g. 40 items with max 28 -> 20 + 20, not 28 + 12).
export const balancedChunks = (list, max) => {
  if (!list.length) return []
  const pages = Math.ceil(list.length / max)
  return chunk(list, Math.ceil(list.length / pages))
}
