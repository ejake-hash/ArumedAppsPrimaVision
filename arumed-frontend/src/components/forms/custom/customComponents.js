/**
 * Registry CUSTOM_COMPONENT Form Registry (Fase 5).
 *
 * Mapping `template.custom_component_name` → Vue component (lazy import).
 * Tambah entry baru → import per file (lazy) supaya bundle wizard tidak ke-include.
 *
 * Contract komponen:
 *   - Props : { template: Object, readonly: Boolean }
 *   - v-model: Object (form data)
 *   - Tidak handle submit sendiri — parent FormRMRenderer yang submit.
 *
 * Untuk SCORED_FORM yang struktur fields-nya udah cukup deklaratif, sebaiknya
 * tetap pakai complexity=SCORED_FORM dengan field_schema standar (scored_radio +
 * computed_sum/threshold). CUSTOM_COMPONENT untuk kasus yang benar-benar
 * butuh logika UI khusus (mis. body diagram interaktif, slider visual nyeri).
 */

const REGISTRY = {
  // Contoh untuk demo: MorseFallScale via custom component.
  // Tapi MORSE_FALL_SCALE template aslinya pakai SCORED_FORM (declarative) —
  // ini cuma demo registry kalau user mau bikin component custom.
  MorseFallScale: () => import('./MorseFallScale.vue'),
}

/**
 * Lookup + return component (lazy). Return null kalau name tidak terdaftar.
 */
export function resolveCustomComponent(name) {
  if (!name) return null
  const loader = REGISTRY[name]
  if (!loader) {
    console.warn(`[Form Registry] CUSTOM_COMPONENT "${name}" tidak terdaftar di customComponents.js`)
    return null
  }
  // defineAsyncComponent biar Vue handle loading state.
  return loader
}

export function listAvailableComponents() {
  return Object.keys(REGISTRY)
}
