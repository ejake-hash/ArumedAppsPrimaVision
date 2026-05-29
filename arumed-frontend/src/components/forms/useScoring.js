/**
 * useScoring — composable untuk live compute SCORED_FORM di frontend.
 *
 * Mirror ScoringEngine PHP di backend. Single-source-of-truth tetap backend
 * (saat submit, server re-compute). Frontend live-compute untuk UX responsif.
 *
 * Usage:
 *   const { computed } = useScoring(schema, formData)
 *   computed.value.total_score      // → number
 *   computed.value.interpretasi     // → string
 */
import { computed } from 'vue'

function flattenFields(schema) {
  if (Array.isArray(schema?.fields)) return schema.fields
  if (Array.isArray(schema?.pages)) {
    const all = []
    for (const p of schema.pages) if (Array.isArray(p.fields)) all.push(...p.fields)
    return all
  }
  return []
}

function computeSum(field, data) {
  const sumOf = field.sum_of ?? []
  if (!Array.isArray(sumOf)) return 0
  return sumOf.reduce((acc, k) => {
    const v = data[k]
    return typeof v === 'number' && isFinite(v) ? acc + v : acc
  }, 0)
}

function computeThreshold(field, data) {
  const basedOn = field.based_on
  const thresholds = field.thresholds ?? []
  if (!basedOn || !Array.isArray(thresholds)) return null
  const value = data[basedOn]
  if (typeof value !== 'number' || !isFinite(value)) return null

  const sorted = [...thresholds].sort((a, b) => (a.max ?? Infinity) - (b.max ?? Infinity))
  for (const t of sorted) {
    if (t.max == null) continue
    if (value <= t.max) return t.label ?? ''
  }
  return null
}

function computeDuration(field, data) {
  const from = field.from
  const to = field.to
  if (!from || !to) return null
  const f = data[from]
  const t = data[to]
  if (!f || !t) return null
  try {
    // HH:MM format
    const [fh, fm] = String(f).split(':').map(Number)
    const [th, tm] = String(t).split(':').map(Number)
    if (isNaN(fh) || isNaN(th)) return null
    const fMin = fh * 60 + (fm || 0)
    const tMin = th * 60 + (tm || 0)
    return Math.max(0, tMin - fMin)
  } catch {
    return null
  }
}

function computeField(field, data) {
  switch (field.type) {
    case 'computed_sum':       return computeSum(field, data)
    case 'computed_threshold': return computeThreshold(field, data)
    case 'computed_duration':  return computeDuration(field, data)
    default:                   return null
  }
}

export function useScoring(schemaRef, dataRef) {
  // Multi-pass karena threshold depend on sum.
  const computedValues = computed(() => {
    const schema = typeof schemaRef === 'function' ? schemaRef() : (schemaRef.value ?? schemaRef)
    const data   = typeof dataRef   === 'function' ? dataRef()   : (dataRef.value   ?? dataRef)
    const fields = flattenFields(schema)
    const result = { ...data }

    for (let pass = 0; pass < 3; pass++) {
      let changed = false
      for (const f of fields) {
        if (!f.type?.startsWith('computed_')) continue
        const val = computeField(f, result)
        if (result[f.key] !== val) {
          result[f.key] = val
          changed = true
        }
      }
      if (!changed) break
    }

    // Filter out: hanya computed fields yang dikembalikan.
    const out = {}
    for (const f of fields) {
      if (f.type?.startsWith('computed_')) out[f.key] = result[f.key] ?? null
    }
    return out
  })

  return { computed: computedValues }
}
