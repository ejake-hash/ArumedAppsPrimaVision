<script setup>
/**
 * MetodeBayarDetailView — halaman detail satu penjamin (insurer).
 *
 * Route: /tarif-paket/metode-bayar/:id
 *
 * Tarif yang dikelola hanya **Tindakan** (procedures). Harga Obat/BHP/IOL
 * ambil langsung dari master masing-masing — tidak ada override per insurer.
 *
 * - Hero card: avatar + nama + tipe + status + relasi TPA
 * - Badge "Bagian dari {TPA}" kalau anggota TPA + banner read-only
 * - Panel "Anggota TPA" (kalau TPA induk): tambah/keluarkan anggota
 * - Tabel tarif Tindakan langsung di bawah (tanpa tab switcher)
 */
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { tarifPaketApi, masterApi } from '@/services/api'
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

// ─── Anggota TPA ────────────────────────────────────────────────────────────
const canManageMembers = computed(() => !!detail.value?.can_manage_members)
const members = computed(() => insurer.value?.children ?? [])

const addModal = ref({ open: false, candidates: [], selectedId: '', newName: '', loading: false, busy: false })
const confirmRemove = ref({ open: false, member: null, busy: false })
const toast = ref(null)

function showToast(type, msg) {
  toast.value = { type, msg }
  setTimeout(() => { if (toast.value?.msg === msg) toast.value = null }, 3500)
}

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

// ─── Tambah anggota ─────────────────────────────────────────────────────────
async function openAddMember() {
  addModal.value = { open: true, candidates: [], selectedId: '', newName: '', loading: true, busy: false }
  try {
    const res = await masterApi.penjamin.memberCandidates(insurerId.value)
    addModal.value.candidates = res.data?.data ?? []
  } catch (e) {
    showToast('e', 'Gagal memuat kandidat penjamin')
  } finally {
    addModal.value.loading = false
  }
}

const selectedCandidate = computed(() =>
  addModal.value.candidates.find((c) => c.id === addModal.value.selectedId) ?? null,
)

// Pilih kandidat existing & ketik nama baru saling eksklusif.
function pickCandidate(id) {
  addModal.value.selectedId = addModal.value.selectedId === id ? '' : id
  if (addModal.value.selectedId) addModal.value.newName = ''
}
function onTypeNewName() {
  if (addModal.value.newName.trim()) addModal.value.selectedId = ''
}

const canSubmitAdd = computed(() =>
  !!addModal.value.selectedId || !!addModal.value.newName.trim(),
)

async function confirmAddMember() {
  if (!canSubmitAdd.value) return
  addModal.value.busy = true
  try {
    const arg = addModal.value.selectedId
      ? { insurerId: addModal.value.selectedId }
      : { newName: addModal.value.newName.trim() }
    await masterApi.penjamin.addMember(insurerId.value, arg)
    showToast('s', 'Anggota TPA ditambahkan')
    addModal.value.open = false
    await loadDetail()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal menambah anggota')
  } finally {
    addModal.value.busy = false
  }
}

// ─── Keluarkan anggota ──────────────────────────────────────────────────────
function askRemoveMember(member) {
  confirmRemove.value = { open: true, member, busy: false }
}

async function doRemoveMember() {
  confirmRemove.value.busy = true
  try {
    await masterApi.penjamin.removeMember(insurerId.value, confirmRemove.value.member.id)
    showToast('s', 'Anggota dikeluarkan dari TPA')
    confirmRemove.value.open = false
    await loadDetail()
  } catch (e) {
    showToast('e', e.response?.data?.message ?? 'Gagal mengeluarkan anggota')
  } finally {
    confirmRemove.value.busy = false
  }
}

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
              <span v-if="insurer.parent" class="mbd-parent-pill" :title="`Mengikuti tarif TPA ${insurer.parent.name}`">
                Bagian dari {{ insurer.parent.name }}
              </span>
              <span v-if="insurer.children && insurer.children.length > 0" class="mbd-children-pill">
                Induk TPA · {{ insurer.children.length }} anggota
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
          <strong>Tarif mengikuti TPA {{ insurer.parent.name }}</strong>
          <p>Penjamin ini adalah anggota TPA — mewarisi tarif Tindakan dari TPA induk. Tabel di bawah read-only. Untuk mengubah tarif, kelola di halaman TPA induk.</p>
        </div>
      </div>

      <!-- Anggota TPA (kelola dari sisi TPA induk) -->
      <section v-if="canManageMembers" class="mbd-section">
        <div class="mbd-section-head mbd-members-head">
          <div>
            <h2>Anggota TPA</h2>
            <p>Penjamin yang mengikuti tarif TPA ini 100%. Anggota tidak tampil di Daftar Penjamin & tarifnya otomatis ikut TPA.</p>
          </div>
          <button class="mbd-btn-primary" @click="openAddMember">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Anggota
          </button>
        </div>

        <div v-if="!members.length" class="mbd-members-empty">
          Belum ada anggota. Klik “Tambah Anggota” untuk memasukkan asuransi/perusahaan ke TPA ini.
        </div>
        <ul v-else class="mbd-members-list">
          <li v-for="m in members" :key="m.id" class="mbd-member-row">
            <div class="mbd-member-info">
              <span class="mbd-member-name">{{ m.name }}</span>
              <span class="mbd-type-pill" :data-t="m.type">{{ m.type }}</span>
            </div>
            <button class="mbd-btn-danger-ghost" @click="askRemoveMember(m)">Keluarkan dari TPA</button>
          </li>
        </ul>
      </section>

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

    <!-- Modal: Tambah Anggota -->
    <Teleport to="body">
      <div v-if="addModal.open" class="mbd-overlay" @click.self="addModal.open = false">
        <div class="mbd-modal">
          <div class="mbd-modal-head">
            <h3>Tambah Anggota TPA</h3>
            <button class="mbd-modal-x" @click="addModal.open = false">×</button>
          </div>
          <div class="mbd-modal-body">
            <p class="mbd-modal-sub">Pilih dari daftar, atau ketik nama asuransi baru. Anggota akan mengikuti tarif TPA <strong>{{ insurer?.name }}</strong>.</p>
            <div v-if="addModal.loading" class="mbd-modal-state">Memuat kandidat…</div>
            <template v-else>
              <!-- List kandidat existing (klik untuk pilih) -->
              <div v-if="addModal.candidates.length" class="mbd-cand-wrap">
                <label class="mbd-modal-label">Pilih dari penjamin yang ada</label>
                <ul class="mbd-cand-list">
                  <li
                    v-for="c in addModal.candidates"
                    :key="c.id"
                    class="mbd-cand-item"
                    :class="{ 'is-selected': addModal.selectedId === c.id }"
                    @click="pickCandidate(c.id)"
                  >
                    <span class="mbd-cand-name">{{ c.name }}</span>
                    <span class="mbd-type-pill" :data-t="c.type">{{ c.type }}</span>
                  </li>
                </ul>
              </div>
              <div v-else class="mbd-modal-state">
                Tidak ada kandidat existing — kamu masih bisa membuat asuransi baru di bawah.
              </div>

              <!-- Separator -->
              <div class="mbd-or"><span>atau</span></div>

              <!-- Input nama baru -->
              <label class="mbd-modal-label">Buat asuransi baru</label>
              <input
                v-model="addModal.newName"
                class="mbd-modal-input"
                type="text"
                placeholder="ketik nama asuransi baru…"
                @input="onTypeNewName"
              />

              <!-- Peringatan / info -->
              <div v-if="selectedCandidate" class="mbd-warn-box">
                ⚠ Tarif lama <strong>{{ selectedCandidate.name }}</strong> (jika ada) akan <strong>DIHAPUS</strong>.
                Setelah jadi anggota, ia mengikuti tarif TPA {{ insurer?.name }} 100%.
              </div>
              <div v-else-if="addModal.newName.trim()" class="mbd-info-box">
                Penjamin baru <strong>{{ addModal.newName.trim() }}</strong> dibuat sebagai anggota TPA (tipe Asuransi, aktif).
              </div>
            </template>
          </div>
          <div class="mbd-modal-foot">
            <button class="mbd-btn-secondary" :disabled="addModal.busy" @click="addModal.open = false">Batal</button>
            <button class="mbd-btn-primary" :disabled="addModal.busy || !canSubmitAdd" @click="confirmAddMember">
              {{ addModal.busy ? 'Menyimpan…' : 'Jadikan Anggota' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Konfirmasi: Keluarkan Anggota -->
    <Teleport to="body">
      <div v-if="confirmRemove.open" class="mbd-overlay" @click.self="confirmRemove.open = false">
        <div class="mbd-confirm">
          <div class="mbd-confirm-icon">
            <svg viewBox="0 0 24 24"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </div>
          <h3>Keluarkan dari TPA?</h3>
          <p>
            <strong>{{ confirmRemove.member?.name }}</strong> akan keluar dari TPA dan <strong>tidak punya tarif</strong>
            (harus diisi manual setelahnya).
          </p>
          <div class="mbd-confirm-actions">
            <button class="mbd-btn-secondary" :disabled="confirmRemove.busy" @click="confirmRemove.open = false">Batal</button>
            <button class="mbd-btn-danger" :disabled="confirmRemove.busy" @click="doRemoveMember">
              {{ confirmRemove.busy ? 'Memproses…' : 'Keluarkan' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast -->
    <Teleport to="body">
      <div v-if="toast" class="mbd-toast-wrap">
        <div class="mbd-toast" :class="`mbd-toast-${toast.type}`">{{ toast.msg }}</div>
      </div>
    </Teleport>
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

/* ─── Panel Anggota TPA ─── */
.mbd-members-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; }
.mbd-btn-primary { display: inline-flex; align-items: center; gap: 7px; padding: 9px 16px; border-radius: 9px; border: 1px solid var(--ga); background: var(--ga); color: white; font-size: 13px; font-weight: 500; cursor: pointer; flex-shrink: 0; transition: background 0.15s; }
.mbd-btn-primary:hover:not(:disabled) { background: var(--gm); border-color: var(--gm); }
.mbd-btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.mbd-btn-primary svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2.5; stroke-linecap: round; }

.mbd-members-empty { padding: 1.3rem; text-align: center; color: var(--tm); font-size: 13px; background: var(--bs); border: 1px dashed var(--gb); border-radius: 10px; }
.mbd-members-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 0.5rem; }
.mbd-member-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.7rem 1rem; border: 1px solid var(--gb); border-radius: 10px; background: var(--bc); }
.mbd-member-row:hover { background: var(--bs); }
.mbd-member-info { display: flex; align-items: center; gap: 0.6rem; min-width: 0; }
.mbd-member-name { font-weight: 500; color: var(--td); font-size: 13.5px; }
.mbd-btn-danger-ghost { padding: 6px 12px; border-radius: 8px; border: 1px solid var(--ebd); background: var(--bc); color: var(--et); font-size: 12px; font-weight: 500; cursor: pointer; flex-shrink: 0; transition: background 0.15s; }
.mbd-btn-danger-ghost:hover { background: var(--eb); }

/* ─── Modal Tambah Anggota ─── */
.mbd-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.45); display: flex; align-items: center; justify-content: center; z-index: 9100; backdrop-filter: blur(3px); padding: 1rem; }
.mbd-modal { background: var(--bc); border-radius: 16px; width: 520px; max-width: 95vw; border: 1px solid var(--gb); display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.mbd-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.3rem; background: linear-gradient(135deg, var(--gm), var(--gd)); color: #fff; }
.mbd-modal-head h3 { margin: 0; font-family: 'Space Grotesk', serif; font-size: 17px; }
.mbd-modal-x { background: transparent; border: none; color: #fff; font-size: 22px; cursor: pointer; line-height: 1; }
.mbd-modal-body { padding: 1.2rem 1.3rem; display: flex; flex-direction: column; gap: 0.6rem; }
.mbd-modal-sub { font-size: 12.5px; color: var(--tm); margin: 0 0 0.3rem; }
.mbd-modal-state { padding: 1rem; text-align: center; color: var(--tm); font-size: 12.5px; background: var(--bs); border-radius: 8px; }
.mbd-modal-label { font-size: 11px; font-weight: 600; color: var(--tm); }
.mbd-modal-select { padding: 8px 11px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); font-size: 13px; }
.mbd-modal-select:focus { outline: none; border-color: var(--ga); }
.mbd-modal-input { padding: 8px 11px; border: 1px solid var(--gb); border-radius: 8px; background: var(--bc); color: var(--td); font-size: 13px; width: 100%; box-sizing: border-box; }
.mbd-modal-input:focus { outline: none; border-color: var(--ga); }

/* List kandidat */
.mbd-cand-wrap { display: flex; flex-direction: column; gap: 0.35rem; }
.mbd-cand-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 4px; max-height: 220px; overflow-y: auto; border: 1px solid var(--gb); border-radius: 9px; padding: 5px; }
.mbd-cand-item { display: flex; align-items: center; justify-content: space-between; gap: 0.6rem; padding: 8px 10px; border-radius: 7px; cursor: pointer; border: 1px solid transparent; transition: background 0.12s, border-color 0.12s; }
.mbd-cand-item:hover { background: var(--bs); }
.mbd-cand-item.is-selected { background: var(--gl); border-color: var(--ga); }
.mbd-cand-name { font-size: 13px; color: var(--td); font-weight: 500; }

/* Separator "atau" */
.mbd-or { display: flex; align-items: center; gap: 0.6rem; margin: 0.2rem 0; color: var(--tu); font-size: 11px; }
.mbd-or::before, .mbd-or::after { content: ''; flex: 1; height: 1px; background: var(--gb); }

.mbd-warn-box { margin-top: 0.3rem; padding: 9px 12px; background: var(--wb); border: 1px solid var(--wbd); border-radius: 8px; color: var(--wt); font-size: 11.5px; line-height: 1.5; }
.mbd-info-box { margin-top: 0.3rem; padding: 9px 12px; background: var(--ib); border: 1px solid var(--ibd); border-radius: 8px; color: var(--it); font-size: 11.5px; line-height: 1.5; }
.mbd-modal-foot { padding: 0.85rem 1.3rem; border-top: 1px solid var(--gb); display: flex; justify-content: flex-end; gap: 0.5rem; background: var(--bs); }

/* ─── Konfirmasi keluarkan ─── */
.mbd-confirm { background: var(--bc); border-radius: 16px; width: 420px; max-width: 95vw; border: 1px solid var(--gb); padding: 1.6rem 1.5rem 1.3rem; display: flex; flex-direction: column; align-items: center; text-align: center; gap: 0.7rem; box-shadow: 0 20px 60px rgba(0,0,0,0.22); }
.mbd-confirm-icon { width: 48px; height: 48px; border-radius: 50%; background: var(--eb); display: flex; align-items: center; justify-content: center; }
.mbd-confirm-icon svg { width: 24px; height: 24px; fill: none; stroke: var(--et); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.mbd-confirm h3 { font-family: 'Space Grotesk', serif; font-size: 18px; color: var(--td); margin: 0; }
.mbd-confirm p { font-size: 13px; color: var(--tm); margin: 0; line-height: 1.5; }
.mbd-confirm-actions { display: flex; gap: 0.6rem; margin-top: 0.5rem; width: 100%; justify-content: center; }

.mbd-btn-secondary { padding: 8px 16px; border-radius: 8px; border: 1px solid var(--gb); background: var(--bc); color: var(--tm); font-size: 13px; cursor: pointer; font-weight: 500; }
.mbd-btn-secondary:hover { background: var(--bs); }
.mbd-btn-secondary:disabled { opacity: 0.5; cursor: not-allowed; }
.mbd-btn-danger { padding: 8px 18px; border-radius: 8px; border: 1px solid var(--et); background: var(--et); color: white; font-size: 13px; cursor: pointer; font-weight: 500; }
.mbd-btn-danger:hover:not(:disabled) { background: #b91c1c; border-color: #b91c1c; }
.mbd-btn-danger:disabled { opacity: 0.6; cursor: not-allowed; }

/* ─── Toast ─── */
.mbd-toast-wrap { position: fixed; top: 1rem; right: 1rem; z-index: 9999; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
.mbd-toast { padding: 9px 14px; border-radius: 10px; font-size: 12px; font-weight: 500; border: 1px solid; box-shadow: 0 4px 14px rgba(0,0,0,0.1); min-width: 240px; }
.mbd-toast-s { background: var(--sb); color: var(--st); border-color: var(--sbd); }
.mbd-toast-e { background: var(--eb); color: var(--et); border-color: var(--ebd); }
.mbd-toast-w { background: var(--wb); color: var(--wt); border-color: var(--wbd); }
</style>
