<script setup>
/**
 * MetodeBayarDetailView — halaman detail satu penjamin (insurer).
 *
 * Route: /tarif-paket/metode-bayar/:id
 *
 * Tarif yang dikelola hanya **Tindakan** (procedures). Harga Obat/BHP/IOL
 * ambil langsung dari master masing-masing — tidak ada override per insurer.
 *
 * - Hero card: avatar + nama + tipe + status + parent/children
 * - Banner "Tarif bersumber dari {parent}" kalau child TPA (read-only)
 * - Tabel tarif Tindakan langsung di bawah hero card (tanpa tab switcher)
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { tarifPaketApi } from '@/services/api'
import MetodeBayarTarifTab from './MetodeBayarTarifTab.vue'

const route = useRoute()
const router = useRouter()

const insurerId = computed(() => route.params.id)

const detail = ref(null)
const loading = ref(false)
const error = ref(null)

const insurer = computed(() => detail.value?.insurer)
const isChild = computed(() => !!detail.value?.is_child_tpa)
const targetInsurerId = computed(() => detail.value?.tariff_insurer_id ?? insurerId.value)

async function loadDetail() {
  loading.value = true
  error.value = null
  try {
    const res = await tarifPaketApi.metodeBayar.detail(insurerId.value)
    detail.value = res.data?.data ?? null
  } catch (e) {
    error.value = e.response?.data?.message ?? 'Gagal memuat detail penjamin'
  } finally {
    loading.value = false
  }
}

function onChanged() { loadDetail() }

onMounted(loadDetail)
</script>

<template>
  <div class="mbd-wrap">
    <!-- Breadcrumb / back -->
    <div class="mbd-back">
      <button class="mbd-back-btn" @click="router.push({ name: 'tarif-paket-metode-bayar' })">
        <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        Kembali ke Metode Bayar
      </button>
    </div>

    <div v-if="loading" class="mbd-loading">Memuat…</div>
    <div v-else-if="error" class="mbd-error">{{ error }}</div>

    <template v-else-if="insurer">
      <!-- Hero card insurer info (compact, full-width) -->
      <header class="mbd-hero">
        <div class="mbd-hero-main">
          <div class="mbd-hero-avatar">{{ (insurer.name ?? '?').charAt(0).toUpperCase() }}</div>
          <div class="mbd-hero-text">
            <div class="mbd-title-row">
              <h1>{{ insurer.name }}</h1>
              <span v-if="insurer.is_system" class="mbd-badge-system" title="Insurer sistem (immutable)">SISTEM</span>
            </div>
            <div class="mbd-tags-row">
              <span class="mbd-type-pill" :data-t="insurer.type">{{ insurer.type }}</span>
              <span class="mbd-status" :class="insurer.is_active ? 'on' : 'off'">
                <span class="mbd-dot"></span>
                {{ insurer.is_active ? 'Aktif' : 'Nonaktif' }}
              </span>
              <span v-if="insurer.parent" class="mbd-parent-pill" :title="`Inherit tarif dari ${insurer.parent.name}`">
                ← {{ insurer.parent.name }}
              </span>
              <span v-if="insurer.children && insurer.children.length > 0" class="mbd-children-pill">
                {{ insurer.children.length }} child TPA
              </span>
            </div>
            <div v-if="insurer.phone || insurer.email || insurer.address" class="mbd-contact-row">
              <span v-if="insurer.phone" class="mbd-meta">
                <svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                {{ insurer.phone }}
              </span>
              <span v-if="insurer.email" class="mbd-meta">
                <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22 6 12 13 2 6"/></svg>
                {{ insurer.email }}
              </span>
              <span v-if="insurer.address" class="mbd-meta mbd-meta-addr">
                <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                {{ insurer.address }}
              </span>
            </div>
          </div>
        </div>
      </header>

      <!-- Child TPA banner -->
      <div v-if="isChild && insurer.parent" class="mbd-banner mbd-banner-info">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <div>
          <strong>Tarif bersumber dari {{ insurer.parent.name }}</strong>
          <p>Insurer ini adalah child TPA — mewarisi tarif Tindakan dari parent. Tabel di bawah read-only. Untuk mengubah tarif, kelola di insurer parent.</p>
        </div>
      </div>

      <!-- Tarif Tindakan (single section, langsung tanpa tab) -->
      <section class="mbd-section">
        <div class="mbd-section-head">
          <div>
            <h2>Tarif Tindakan</h2>
            <p>Override harga tindakan untuk penjamin ini. Harga obat / BHP / IOL ambil dari master masing-masing.</p>
          </div>
        </div>
        <MetodeBayarTarifTab
          type="tindakan"
          :insurer-id="targetInsurerId"
          :insurer-code="insurer.code ?? ''"
          :read-only="isChild"
          @changed="onChanged"
        />
      </section>
    </template>
  </div>
</template>

<style scoped>
.mbd-wrap { display: flex; flex-direction: column; gap: 1rem; }

.mbd-back { margin-bottom: -0.3rem; }
.mbd-back-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 12px; cursor: pointer; }
.mbd-back-btn:hover { background: var(--bs); color: var(--td); }
.mbd-back-btn svg { width: 13px; height: 13px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

.mbd-loading, .mbd-error { padding: 2rem; text-align: center; color: var(--tm); font-size: 14px; }
.mbd-error { color: var(--et); background: var(--eb); border: 1px solid var(--ebd); border-radius: 10px; }

/* ─── Hero card ─── */
.mbd-hero {
  background: var(--bc);
  border: 1px solid var(--gb);
  border-radius: 12px;
  padding: 1rem 1.2rem;
  width: 100%;
}

.mbd-hero-main { display: flex; gap: 0.9rem; align-items: center; min-width: 0; }

.mbd-hero-avatar {
  width: 44px; height: 44px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--ga), var(--gm));
  color: white;
  font-family: 'Space Grotesk', serif;
  font-size: 20px;
}

.mbd-hero-text { display: flex; flex-direction: column; gap: 4px; min-width: 0; flex: 1; }

.mbd-title-row { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
.mbd-title-row h1 {
  font-family: 'Space Grotesk', serif;
  font-size: 20px;
  color: var(--td);
  margin: 0;
  line-height: 1.15;
}
.mbd-badge-system { font-size: 10px; font-weight: 700; letter-spacing: 0.06em; padding: 3px 8px; border-radius: 4px; background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.mbd-tags-row { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

.mbd-type-pill { display: inline-block; padding: 3px 11px; border-radius: 999px; font-size: 11px; font-weight: 600; letter-spacing: 0.04em; }
.mbd-type-pill[data-t="UMUM"]       { background: var(--bs); color: var(--tm); border: 1px solid var(--gb); }
.mbd-type-pill[data-t="BPJS"]       { background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }
.mbd-type-pill[data-t="ASURANSI"]   { background: var(--ib); color: var(--it); border: 1px solid var(--ibd); }
.mbd-type-pill[data-t="PERUSAHAAN"] { background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.mbd-type-pill[data-t="SOSIAL"]     { background: var(--wb); color: var(--wt); border: 1px solid var(--wbd); }

.mbd-parent-pill   { display: inline-flex; align-items: center; padding: 3px 11px; border-radius: 999px; font-size: 11px; font-weight: 500; background: var(--pb); color: var(--pt); border: 1px solid var(--pbd); }
.mbd-children-pill { display: inline-flex; align-items: center; padding: 3px 11px; border-radius: 999px; font-size: 11px; font-weight: 500; background: var(--sb); color: var(--st); border: 1px solid var(--sbd); }

.mbd-status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 11px; border-radius: 999px; font-size: 11px; font-weight: 500; }
.mbd-status.on { background: var(--sb); color: var(--st); }
.mbd-status.off { background: var(--eb); color: var(--et); }
.mbd-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

.mbd-contact-row { display: flex; gap: 1rem; margin-top: 2px; font-size: 11.5px; color: var(--tm); flex-wrap: wrap; }
.mbd-meta { display: inline-flex; align-items: center; gap: 5px; }
.mbd-meta svg { width: 11px; height: 11px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; opacity: 0.7; }
.mbd-meta-addr { max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ─── Section (kartu utama: Tarif Tindakan) ─── */
.mbd-section {
  display: flex;
  flex-direction: column;
  gap: 1.4rem;
  background: var(--bc);
  border: 1px solid var(--gb);
  border-radius: 16px;
  padding: 1.8rem 2rem;
  box-shadow: 0 2px 10px rgba(0,0,0,0.04);
}
@media (max-width: 900px) {
  .mbd-section { padding: 1.2rem 1.3rem; }
}

.mbd-section-head { padding-bottom: 0.9rem; border-bottom: 1px solid var(--gb); }
.mbd-section-head h2 {
  font-family: 'Space Grotesk', serif;
  font-size: 26px;
  color: var(--td);
  margin: 0;
  line-height: 1.15;
}
.mbd-section-head p { font-size: 13px; color: var(--tm); margin: 6px 0 0; max-width: 700px; }

.mbd-banner { display: flex; align-items: flex-start; gap: 0.8rem; padding: 0.9rem 1.1rem; border-radius: 10px; border: 1px solid; }
.mbd-banner-info { background: var(--ib); color: var(--it); border-color: var(--ibd); }
.mbd-banner svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; flex-shrink: 0; margin-top: 1px; }
.mbd-banner strong { display: block; font-size: 13px; }
.mbd-banner p { margin: 3px 0 0; font-size: 12px; line-height: 1.5; }

</style>
