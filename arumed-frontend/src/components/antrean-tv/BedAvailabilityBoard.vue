<script setup>
/**
 * Papan ketersediaan tempat tidur untuk Antrean TV (publik, TANPA PII).
 * Hanya menerima data agregat dari antreanTvApi.bedAvailability() — per kamar:
 * { room_name, kelas_rawat, type, total, occupied, available, beds:[{label,status}] }.
 * Tidak pernah menyentuh nama/No. RM pasien (data tsb memang tak dikirim backend).
 *
 * Gaya "gabungan": ringkasan total + per kelas + grid kotak bed berwarna per kamar.
 * variant 'full'  → layar mode bed / panel besar (font/padding besar, mengisi ruang).
 * variant 'compact' → diselipkan di panel media saat rotasi (lebih rapat).
 */
import { computed } from 'vue'

const props = defineProps({
  rooms: { type: Array, default: () => [] },
  variant: { type: String, default: 'full' }, // 'full' | 'compact'
})

// Total keseluruhan: total bed, terisi, tersedia, lainnya (cleaning/maintenance).
const totals = computed(() => {
  let total = 0, occupied = 0, available = 0, rooms = 0
  for (const r of props.rooms) {
    const t = Number(r.total || 0)
    if (t <= 0) continue
    rooms += 1
    total += t
    occupied += Number(r.occupied || 0)
    available += Number(r.available || 0)
  }
  return { rooms, total, occupied, available, other: Math.max(0, total - occupied - available) }
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
    <!-- Header: judul + ringkasan TOTAL (besar) -->
    <div class="bb-head">
      <span class="bb-title">Ketersediaan Tempat Tidur</span>
      <div class="bb-totals">
        <div class="bb-stat"><b>{{ totals.total }}</b><span>Total Bed</span></div>
        <div class="bb-stat av"><b>{{ totals.available }}</b><span>Tersedia</span></div>
        <div class="bb-stat oc"><b>{{ totals.occupied }}</b><span>Terisi</span></div>
        <div v-if="totals.other > 0" class="bb-stat ot"><b>{{ totals.other }}</b><span>Lainnya</span></div>
      </div>
    </div>

    <!-- Ringkasan per kelas -->
    <div v-if="classSummary.length" class="bb-classes">
      <span v-for="s in classSummary" :key="s.kelas" class="bb-chip">
        <span class="bb-chip-k">Kelas {{ s.kelas }}</span>
        <span class="bb-chip-v"><b :class="{ zero: s.available === 0 }">{{ s.available }}</b> / {{ s.total }} tersedia</span>
      </span>
    </div>

    <!-- Grid kamar (mengisi ruang) -->
    <div class="bb-rooms">
      <div v-for="r in visibleRooms" :key="r.room_id" class="bb-room">
        <div class="bb-room-head">
          <span class="bb-room-name">{{ r.room_name }}</span>
          <span class="bb-room-kelas">Kls {{ r.kelas_rawat }}</span>
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

    <!-- Legenda -->
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
  padding: 1.25rem 1.5rem;
  gap: 1rem;
  overflow: hidden;
}
.bed-board.compact { padding: .6rem .7rem; gap: .5rem; border-radius: 10px; }

/* ── Header: judul + total ── */
.bb-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; flex: 0 0 auto; }
.bb-title { font-weight: 800; letter-spacing: .02em; color: #fff; font-size: 1.7rem; }
.compact .bb-title { font-size: 1rem; }

.bb-totals { display: flex; gap: .6rem; flex-wrap: wrap; }
.bb-stat {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  min-width: 5.2rem; padding: .45rem .9rem; border-radius: 12px;
  background: #16233a; border: 1px solid #24344f;
}
.bb-stat b { font-size: 2.2rem; line-height: 1; font-weight: 800; color: #fff; font-variant-numeric: tabular-nums; }
.bb-stat span { font-size: .82rem; color: #9fb6d4; margin-top: .2rem; letter-spacing: .02em; }
.bb-stat.av { background: rgba(52,210,123,.14); border-color: rgba(52,210,123,.4); }
.bb-stat.av b { color: #34d27b; }
.bb-stat.oc { background: rgba(255,107,107,.14); border-color: rgba(255,107,107,.4); }
.bb-stat.oc b { color: #ff6b6b; }
.bb-stat.ot b { color: #f5c451; }
.compact .bb-stat { min-width: 3.4rem; padding: .25rem .5rem; border-radius: 8px; }
.compact .bb-stat b { font-size: 1.25rem; }
.compact .bb-stat span { font-size: .58rem; }

/* ── Ringkasan per kelas ── */
.bb-classes { display: flex; gap: .5rem; flex-wrap: wrap; flex: 0 0 auto; }
.bb-chip { display: inline-flex; align-items: baseline; gap: .45rem; background: #16233a; border: 1px solid #24344f; border-radius: 999px; padding: .3rem .85rem; }
.bb-chip-k { font-weight: 700; color: #cfe0f4; font-size: 1rem; }
.compact .bb-chip-k { font-size: .72rem; }
.bb-chip-v { font-size: .95rem; color: #9fb6d4; }
.compact .bb-chip-v { font-size: .68rem; }
.bb-chip-v b { color: #34d27b; font-size: 1.15em; font-weight: 800; }
.bb-chip-v b.zero { color: #ff6b6b; }

/* ── Daftar kamar (mengisi ruang vertikal) ── */
.bb-rooms {
  flex: 1 1 auto; min-height: 0; overflow: hidden; display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  grid-auto-rows: minmax(130px, 1fr);
  gap: .9rem;
}
.compact .bb-rooms { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); grid-auto-rows: min-content; align-content: start; gap: .5rem; }

.bb-room { height: 100%; display: flex; flex-direction: column; background: rgba(255,255,255,.04); border: 1px solid #1e2d45; border-radius: 12px; padding: .9rem 1rem; }
.compact .bb-room { height: auto; }
.compact .bb-room { padding: .5rem .55rem; border-radius: 9px; }
.bb-room-head { display: flex; align-items: baseline; gap: .5rem; margin-bottom: .6rem; flex: 0 0 auto; }
.bb-room-name { font-weight: 800; color: #fff; font-size: 1.3rem; flex: 1 1 auto; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.compact .bb-room-name { font-size: .85rem; }
.bb-room-kelas { font-size: .82rem; color: #8aa1c0; background: #1c2c45; padding: .1rem .5rem; border-radius: 6px; }
.compact .bb-room-kelas { font-size: .6rem; }
.bb-room-avail { font-size: 1.05rem; color: #cfe0f4; font-variant-numeric: tabular-nums; }
.compact .bb-room-avail { font-size: .78rem; }
.bb-room-avail b { color: #34d27b; font-weight: 800; }
.bb-room-avail b.zero { color: #ff6b6b; }

.bb-cells { display: flex; flex-wrap: wrap; gap: .6rem; flex: 1 1 auto; align-content: center; }
.compact .bb-cells { gap: .3rem; align-content: flex-start; }
.bb-cell {
  min-width: 4rem; padding: .7rem 1rem; border-radius: 10px;
  font-size: 1.35rem; font-weight: 800; text-align: center; color: #06210f;
  white-space: nowrap; display: flex; align-items: center; justify-content: center;
}
.compact .bb-cell { min-width: 1.7rem; padding: .18rem .32rem; font-size: .66rem; border-radius: 5px; }
.bb-cell.av { background: #34d27b; }                 /* tersedia */
.bb-cell.oc { background: #ff6b6b; color: #2a0606; } /* terisi */
.bb-cell.cl { background: #f5c451; color: #2c2100; } /* dibersihkan */
.bb-cell.na { background: #5a6b82; color: #0c121c; } /* maintenance/reserved */

.bb-empty { color: #8aa1c0; font-style: italic; }

/* ── Legenda ── */
.bb-legend { display: flex; gap: 1.2rem; flex-wrap: wrap; flex: 0 0 auto; font-size: .95rem; color: #9fb6d4; }
.compact .bb-legend { font-size: .62rem; gap: .6rem; }
.bb-legend span { display: inline-flex; align-items: center; gap: .4rem; }
.bb-legend i.lg { width: 1rem; height: 1rem; border-radius: 4px; display: inline-block; }
.compact .bb-legend i.lg { width: .7rem; height: .7rem; }
.lg.av { background: #34d27b; }
.lg.oc { background: #ff6b6b; }
.lg.cl { background: #f5c451; }
.lg.na { background: #5a6b82; }
</style>
