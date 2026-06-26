<script setup>
/**
 * Papan ketersediaan tempat tidur untuk Antrean TV (publik, TANPA PII).
 * Hanya menerima data agregat dari antreanTvApi.bedAvailability() — per kamar:
 * { room_name, kelas_rawat, type, total, occupied, available, beds:[{label,status}] }.
 * Tidak pernah menyentuh nama/No. RM pasien (data tsb memang tak dikirim backend).
 *
 * Gaya "gabungan": header ringkasan per kelas + grid kotak bed berwarna per kamar.
 * variant 'full'  → layar mode bed (font/padding besar).
 * variant 'compact' → diselipkan di panel media saat rotasi (lebih rapat).
 */
import { computed } from 'vue'

const props = defineProps({
  rooms: { type: Array, default: () => [] },
  variant: { type: String, default: 'full' }, // 'full' | 'compact'
})

// Ringkasan per kelas rawat: { kelas, total, available }.
const classSummary = computed(() => {
  const map = {}
  for (const r of props.rooms) {
    const k = r.kelas_rawat || '—'
    const s = (map[k] ??= { kelas: k, total: 0, available: 0 })
    s.total += Number(r.total || 0)
    s.available += Number(r.available || 0)
  }
  return Object.values(map).sort((a, b) =>
    String(a.kelas).localeCompare(String(b.kelas), undefined, { numeric: true }))
})

// Kamar yang punya bed aktif saja (hindari kartu kosong).
const visibleRooms = computed(() => props.rooms.filter((r) => Number(r.total || 0) > 0))

// Status → kelas warna sel.
function cellClass(status) {
  switch (status) {
    case 'AVAILABLE': return 'av'
    case 'OCCUPIED':  return 'oc'
    case 'CLEANING':  return 'cl'
    default:          return 'na' // MAINTENANCE / RESERVED / lainnya
  }
}
</script>

<template>
  <div :class="['bed-board', variant === 'compact' ? 'compact' : 'full']">
    <div class="bb-head">
      <span class="bb-title">Ketersediaan Tempat Tidur</span>
      <div class="bb-summary">
        <span v-for="s in classSummary" :key="s.kelas" class="bb-chip">
          <span class="bb-chip-k">{{ s.kelas }}</span>
          <span class="bb-chip-v"><b :class="{ zero: s.available === 0 }">{{ s.available }}</b>/{{ s.total }}</span>
        </span>
      </div>
    </div>

    <div class="bb-rooms">
      <div v-for="r in visibleRooms" :key="r.room_id" class="bb-room">
        <div class="bb-room-head">
          <span class="bb-room-name">{{ r.room_name }}</span>
          <span class="bb-room-kelas">{{ r.kelas_rawat }}</span>
          <span class="bb-room-avail"><b :class="{ zero: r.available === 0 }">{{ r.available }}</b>/{{ r.total }}</span>
        </div>
        <div class="bb-cells">
          <span v-for="(b, i) in r.beds" :key="i" :class="['bb-cell', cellClass(b.status)]" :title="b.label">
            {{ b.label }}
          </span>
        </div>
      </div>
      <p v-if="!visibleRooms.length" class="bb-empty">Belum ada data kamar.</p>
    </div>

    <div class="bb-legend">
      <span><i class="lg av"></i>Tersedia</span>
      <span><i class="lg oc"></i>Terisi</span>
      <span><i class="lg cl"></i>Dibersihkan</span>
      <span><i class="lg na"></i>Tidak tersedia</span>
    </div>
  </div>
</template>

<style scoped>
.bed-board {
  display: flex;
  flex-direction: column;
  height: 100%;
  width: 100%;
  box-sizing: border-box;
  background: #0e1726;
  color: #e7eef7;
  border-radius: 14px;
  padding: 1rem 1.25rem;
  gap: .8rem;
  overflow: hidden;
}
.bed-board.compact { padding: .6rem .7rem; gap: .5rem; border-radius: 10px; }

/* ── Header + ringkasan kelas ── */
.bb-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; flex: 0 0 auto; }
.bb-title { font-weight: 800; letter-spacing: .02em; color: #fff; font-size: 1.35rem; }
.compact .bb-title { font-size: 1rem; }
.bb-summary { display: flex; gap: .5rem; flex-wrap: wrap; }
.bb-chip { display: inline-flex; align-items: baseline; gap: .4rem; background: #16233a; border: 1px solid #24344f; border-radius: 999px; padding: .25rem .7rem; }
.bb-chip-k { font-weight: 700; color: #9fb6d4; font-size: .9rem; }
.compact .bb-chip-k { font-size: .72rem; }
.bb-chip-v { font-size: 1.05rem; color: #cfe0f4; }
.compact .bb-chip-v { font-size: .82rem; }
.bb-chip-v b { color: #34d27b; font-size: 1.2em; }
.bb-chip-v b.zero { color: #ff6b6b; }

/* ── Daftar kamar ── */
.bb-rooms { flex: 1 1 auto; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: .7rem; align-content: start; }
.compact .bb-rooms { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: .45rem; }
.bb-room { background: rgba(255,255,255,.03); border: 1px solid #1e2d45; border-radius: 10px; padding: .55rem .6rem; }
.bb-room-head { display: flex; align-items: baseline; gap: .5rem; margin-bottom: .45rem; }
.bb-room-name { font-weight: 700; color: #fff; font-size: 1rem; flex: 1 1 auto; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.compact .bb-room-name { font-size: .82rem; }
.bb-room-kelas { font-size: .72rem; color: #8aa1c0; background: #1c2c45; padding: .05rem .4rem; border-radius: 6px; }
.compact .bb-room-kelas { font-size: .62rem; }
.bb-room-avail { font-size: .85rem; color: #cfe0f4; }
.bb-room-avail b { color: #34d27b; }
.bb-room-avail b.zero { color: #ff6b6b; }

.bb-cells { display: flex; flex-wrap: wrap; gap: .35rem; }
.bb-cell {
  min-width: 2.4rem; padding: .3rem .45rem; border-radius: 7px;
  font-size: .82rem; font-weight: 700; text-align: center; color: #06210f;
  white-space: nowrap;
}
.compact .bb-cell { min-width: 1.7rem; padding: .18rem .3rem; font-size: .66rem; border-radius: 5px; }
.bb-cell.av { background: #34d27b; }                 /* tersedia */
.bb-cell.oc { background: #ff6b6b; color: #2a0606; } /* terisi */
.bb-cell.cl { background: #f5c451; color: #2c2100; } /* dibersihkan */
.bb-cell.na { background: #5a6b82; color: #0c121c; } /* maintenance/reserved */

.bb-empty { color: #8aa1c0; font-style: italic; }

/* ── Legenda ── */
.bb-legend { display: flex; gap: 1rem; flex-wrap: wrap; flex: 0 0 auto; font-size: .8rem; color: #9fb6d4; }
.compact .bb-legend { font-size: .64rem; gap: .6rem; }
.bb-legend span { display: inline-flex; align-items: center; gap: .35rem; }
.bb-legend i.lg { width: .85rem; height: .85rem; border-radius: 3px; display: inline-block; }
.lg.av { background: #34d27b; }
.lg.oc { background: #ff6b6b; }
.lg.cl { background: #f5c451; }
.lg.na { background: #5a6b82; }
</style>
