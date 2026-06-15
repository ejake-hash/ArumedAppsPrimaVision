<script setup>
/**
 * Kwitansi/Rincian Biaya A4 — SUMBER TUNGGAL tampilan cetak kasir.
 * Dipakai KasirView (Cetak Rincian) & IgdView (self-checkout hari libur) supaya
 * kop/header + struktur kwitansi IDENTIK. Input `data` = hasil generateReceipt()
 * backend (KasirService). Teleport ke <body> agar @media print global bekerja
 * (#app disembunyikan saat cetak).
 */
import { computed } from 'vue'

const props = defineProps({
  data: { type: Object, default: null },
})

const FALLBACK_CATEGORY = 'Lainnya'
const PAKET_DISCOUNT_TYPE = 'DISKON_PAKET'

function rupiah(v) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID') }
function penjaminLabel(g) {
  const t = (g ?? '').toUpperCase()
  if (t === 'BPJS') return 'BPJS Kesehatan'
  if (t === 'ASURANSI') return 'Asuransi'
  if (t === 'PERUSAHAAN') return 'Perusahaan'
  return 'Umum'
}
function cobName(name, type) {
  const s = (name ?? '').trim().replace(/^.*\bCOB\b\s+/i, '').trim()
  return s || penjaminLabel(type)
}
function penjaminFull(p) {
  const base = penjaminLabel(p?.guarantor_type)
  const ins  = (p?.insurer ?? '').trim()
  let label = base
  if (ins) {
    const g = (p?.guarantor_type ?? '').toUpperCase()
    const insU = ins.toUpperCase()
    if (insU !== g && insU !== base.toUpperCase()) label = `${base} — ${ins}`
  }
  const cob = p?.cob
  if (cob && (cob.insurer || cob.guarantor_type)) {
    label += ` — COB ${cobName(cob.insurer, cob.guarantor_type)}`
  }
  return label
}
function svcCode(t)  { return (t ?? 'RAJAL').toUpperCase() }
function svcTitle(t) { return ({ RANAP: 'KWITANSI RAWAT INAP', IGD: 'KWITANSI GAWAT DARURAT (IGD)', RAJAL: 'KWITANSI RAWAT JALAN' })[svcCode(t)] ?? 'RINCIAN BIAYA PELAYANAN' }
function svcLabel(t) { return ({ RANAP: 'Rawat Inap', IGD: 'Gawat Darurat (IGD)', RAJAL: 'Rawat Jalan' })[svcCode(t)] ?? 'Rawat Jalan' }
function metodeLabel(code) {
  return ({ CASH: 'Tunai', CREDIT_CARD: 'Debit/Kredit', TRANSFER: 'Transfer', BPJS: 'BPJS', INSURANCE: 'Ditanggung Asuransi', WAIVED: 'Gratis / Diskon 100%' })[code] ?? (code ?? '—')
}

function groupItemsByCategory(items, categories) {
  if (!Array.isArray(items) || !items.length) return []
  const orderMap = new Map()
  for (const cat of (categories ?? [])) {
    if (cat?.name) orderMap.set(String(cat.name).toLowerCase(), cat.sort_order ?? 100)
  }
  const buckets = new Map()
  for (const it of items) {
    const rawCat   = (it.category && String(it.category).trim()) || FALLBACK_CATEGORY
    const inMaster = orderMap.has(rawCat.toLowerCase())
    const bucketKey = inMaster ? rawCat : FALLBACK_CATEGORY
    if (!buckets.has(bucketKey)) buckets.set(bucketKey, [])
    buckets.get(bucketKey).push(it)
  }
  const groups = Array.from(buckets.entries()).map(([name, rows]) => ({
    name,
    sort_order: orderMap.get(name.toLowerCase()) ?? 99999,
    items: rows,
    subtotal: rows.reduce((a, r) => a + Number(r.net_price ?? r.total_price ?? 0), 0),
  }))
  groups.sort((a, b) => {
    if (a.name === FALLBACK_CATEGORY) return 1
    if (b.name === FALLBACK_CATEGORY) return -1
    return a.sort_order - b.sort_order
  })
  return groups
}

const paketDiscountPrint = computed(() =>
  (props.data?.items ?? [])
    .filter((it) => it.item_type === PAKET_DISCOUNT_TYPE)
    .reduce((a, it) => a + Math.abs(Number(it.net_price ?? it.total_price ?? 0)), 0),
)
const groupedPrintItems = computed(() =>
  groupItemsByCategory(
    (props.data?.items ?? []).filter((it) => it.item_type !== PAKET_DISCOUNT_TYPE),
    props.data?.categories ?? [],
  ),
)
</script>

<template>
  <Teleport to="body">
    <div v-if="data" class="rincian-print">
      <div v-if="data.clinic?.watermark_type" class="rp-watermark">{{ data.clinic.watermark_type }}</div>

      <!-- Kop kanonik (sumber tunggal) — identik dgn pratinjau Profil Institusi -->
      <div v-if="data.clinic?.letterhead_html" class="rp-kop-canon" v-html="data.clinic.letterhead_html"></div>
      <header v-else class="rp-kop">
        <img v-if="data.clinic?.logo_url" :src="data.clinic.logo_url" alt="Logo" class="rp-logo" />
        <div class="rp-kop-text">
          <div class="rp-clinic">{{ data.clinic?.name ?? 'Rumah Sakit' }}</div>
          <div v-if="data.clinic?.address" class="rp-line">{{ data.clinic.address }}</div>
          <div class="rp-line">
            <span v-if="data.clinic?.phone">Telp: {{ data.clinic.phone }}</span>
            <span v-if="data.clinic?.email"> · Email: {{ data.clinic.email }}</span>
          </div>
        </div>
      </header>

      <h1 :class="['rp-title', `rp-svc-${svcCode(data.service_type).toLowerCase()}`]">{{ svcTitle(data.service_type) }}</h1>
      <div class="rp-subtitle">No. {{ data.invoice?.number ?? '—' }}</div>

      <table class="rp-meta">
        <tbody>
          <tr>
            <td class="k">No. Rekam Medis</td><td class="s">:</td><td class="v">{{ data.patient?.no_rm ?? '—' }}</td>
            <td class="k">Tgl Kunjungan</td><td class="s">:</td><td class="v">{{ data.invoice?.visit_date ?? data.invoice?.date ?? '—' }}</td>
          </tr>
          <tr>
            <td class="k">Nama Pasien</td><td class="s">:</td><td class="v">{{ data.patient?.name ?? '—' }}</td>
            <td class="k">Metode Bayar</td><td class="s">:</td><td class="v">{{ data.invoice?.payment_method ? metodeLabel(data.invoice.payment_method) : '—' }}</td>
          </tr>
          <tr>
            <td class="k">NIK</td><td class="s">:</td><td class="v">{{ data.patient?.nik ?? '—' }}</td>
            <td class="k">Penjamin</td><td class="s">:</td>
            <td class="v">{{ penjaminFull(data.patient) }}</td>
          </tr>
          <tr>
            <td class="k">Dokter (DPJP)</td><td class="s">:</td><td class="v">{{ data.patient?.dpjp ?? '—' }}</td>
            <td class="k">Jenis Layanan</td><td class="s">:</td><td class="v">{{ svcLabel(data.service_type) }}</td>
          </tr>
          <tr>
            <td class="k">Tgl Invoice</td><td class="s">:</td><td class="v">{{ data.invoice?.date ?? '—' }}</td>
            <td class="k"></td><td class="s"></td><td class="v"></td>
          </tr>
        </tbody>
      </table>

      <!-- BLOK RAWAT INAP (hanya untuk kwitansi RI) -->
      <table v-if="data.inpatient" class="rp-meta rp-meta-ranap">
        <tbody>
          <tr>
            <td class="k">Ruang / Bed</td><td class="s">:</td>
            <td class="v">{{ data.inpatient.room || '—' }}<span v-if="data.inpatient.bed"> / {{ data.inpatient.bed }}</span></td>
            <td class="k">Kelas Hak</td><td class="s">:</td>
            <td class="v">{{ data.inpatient.kelas_rawat_hak || '—' }}<span v-if="data.inpatient.titip_note"> ({{ data.inpatient.titip_note }})</span></td>
          </tr>
          <tr>
            <td class="k">Tgl Masuk</td><td class="s">:</td><td class="v">{{ data.inpatient.admission_at || '—' }}</td>
            <td class="k">Tgl Keluar</td><td class="s">:</td><td class="v">{{ data.inpatient.discharge_at || '—' }}</td>
          </tr>
          <tr>
            <td class="k">Lama Rawat</td><td class="s">:</td><td class="v"><strong>{{ data.inpatient.los ?? '—' }} malam</strong></td>
            <td class="k">Cara Keluar</td><td class="s">:</td><td class="v">{{ data.inpatient.discharge_type || '—' }}</td>
          </tr>
        </tbody>
      </table>

      <div class="rp-items">
        <div v-for="grp in groupedPrintItems" :key="grp.name" class="rp-group">
          <div class="rp-group-head">
            <span class="rp-group-name">{{ grp.name }}</span>
            <span class="rp-group-sub">{{ rupiah(grp.subtotal) }}</span>
          </div>
          <div v-for="(item, i) in grp.items" :key="item.id ?? `${grp.name}-${i}`" class="rp-row">
            <span class="rp-row-desc">
              {{ item.description }}<span v-if="Number(item.quantity) > 1" class="rp-row-qty"> ({{ item.quantity }}×)</span>
              <span v-if="Number(item.discount_amount) > 0" class="rp-row-disc">
                diskon −{{ rupiah(item.discount_amount) }}<span v-if="Number(item.discount_percent) > 0"> ({{ Number(item.discount_percent) }}%)</span>
              </span>
            </span>
            <span class="rp-dots"></span>
            <span class="rp-row-amt">
              <span v-if="Number(item.discount_amount) > 0" class="rp-row-gross">{{ rupiah(item.total_price) }}</span>
              {{ rupiah(item.net_price ?? item.total_price) }}
            </span>
          </div>
        </div>
        <div v-if="!(data.items ?? []).length" class="rp-empty">Tidak ada item</div>
      </div>

      <div class="rp-summary">
        <table>
          <tbody>
            <tr><td>Subtotal</td><td class="c-num">{{ rupiah(Number(data.summary?.subtotal || 0) + paketDiscountPrint) }}</td></tr>
            <tr v-if="paketDiscountPrint" class="rp-disc-paket"><td>Diskon Paket</td><td class="c-num">− {{ rupiah(paketDiscountPrint) }}</td></tr>
            <tr v-if="Number(data.summary?.item_discount)"><td>Diskon Item</td><td class="c-num">− {{ rupiah(data.summary?.item_discount) }}</td></tr>
            <tr v-if="Number(data.summary?.discount)">
              <td>Diskon Global<span v-if="Number(data.summary?.discount_percent) > 0"> ({{ Number(data.summary?.discount_percent) }}%)</span></td>
              <td class="c-num">− {{ rupiah(data.summary?.discount) }}</td>
            </tr>
            <tr v-if="Number(data.summary?.tax)"><td>Pajak</td><td class="c-num">{{ rupiah(data.summary?.tax) }}</td></tr>
            <tr class="rp-grand"><td>TOTAL TAGIHAN</td><td class="c-num">{{ rupiah(data.summary?.total) }}</td></tr>
            <tr v-if="Number(data.summary?.covered_amount)"><td>{{ (data.patient?.guarantor_type ?? '').toUpperCase() === 'BPJS' ? 'Ditanggung BPJS Kesehatan (klaim INA-CBG)' : 'Ditanggung Asuransi' }}</td><td class="c-num">{{ (data.patient?.guarantor_type ?? '').toUpperCase() === 'BPJS' ? '' : '− ' + rupiah(data.summary?.covered_amount) }}</td></tr>
            <tr><td>Dibayar Pasien</td><td class="c-num">{{ rupiah(data.summary?.paid_amount) }}</td></tr>
            <tr v-if="data.invoice?.is_paid && Number(data.summary?.change)"><td>Kembalian</td><td class="c-num">{{ rupiah(data.summary?.change) }}</td></tr>
            <tr v-if="Number(data.summary?.sisa)" class="rp-sisa"><td>Sisa Tagihan</td><td class="c-num">{{ rupiah(data.summary?.sisa) }}</td></tr>
          </tbody>
        </table>
      </div>

      <div :class="['rp-status', data.invoice?.is_paid ? 'lunas' : 'belum']">
        {{ data.invoice?.is_paid ? 'LUNAS' : 'BELUM LUNAS / PRO FORMA' }}
      </div>

      <div class="rp-sign">
        <div class="rp-sign-col">
          <div class="rp-sign-lbl">Kasir</div>
          <div v-if="data.print_settings?.show_esign !== false && data.cashier" class="rp-esign">
            <span class="rp-esign-badge">✓ Ditandatangani elektronik</span>
            <div class="rp-esign-name">{{ data.cashier }}</div>
            <div class="rp-esign-meta">
              {{ data.invoice?.number }}<span v-if="data.invoice?.paid_at"> · {{ data.invoice.paid_at }}</span>
            </div>
          </div>
          <template v-else>
            <div class="rp-sign-space"></div>
            <div class="rp-sign-name">( ......................................... )</div>
          </template>
        </div>
      </div>

      <footer class="rp-footer">
        <span v-if="data.invoice?.is_paid && data.invoice?.paid_at">
          Tgl Bayar: {{ data.invoice.paid_at }} ·
        </span>
        <span v-if="data.print_settings?.show_footer !== false && data.clinic?.director_name">
          Penanggung Jawab Rumah Sakit: {{ data.clinic.director_name }}<span v-if="data.clinic?.director_sip"> · SIP: {{ data.clinic.director_sip }}</span> ·
        </span>
        Dicetak: {{ new Date().toLocaleString('id-ID') }} · RS. Mata Prima Vision - PT. Karya Sistem Nusantara
      </footer>
    </div>
  </Teleport>
</template>

<!-- Gaya GLOBAL (tidak scoped) supaya rule @media print bekerja -->
<style>
.rincian-print { display: none; }

@media print {
  @page { size: A4 portrait; margin: 14mm 15mm; }

  html, body {
    width: auto !important;
    min-width: 0 !important;
    height: auto !important;
    overflow: visible !important;
    background: #fff !important;
  }

  #app { display: none !important; }

  .rincian-print {
    display: block !important;
    position: relative;
    color: #000;
    font-family: 'Inter', Arial, sans-serif;
    font-size: 11px;
    line-height: 1.5;
  }

  .rincian-print .rp-watermark {
    position: fixed; top: 45%; left: 50%;
    transform: translate(-50%, -50%) rotate(-30deg);
    font-size: 92px; font-weight: 800; letter-spacing: .12em;
    color: rgba(0, 0, 0, 0.06); z-index: 0; pointer-events: none;
  }

  .rincian-print .rp-kop { display: flex; align-items: center; gap: 14px; border-bottom: 3px double #000; padding-bottom: 9px; }
  .rincian-print .rp-logo { height: 62px; width: auto; object-fit: contain; }
  .rincian-print .rp-clinic { font-size: 19px; font-weight: 800; letter-spacing: .02em; }
  .rincian-print .rp-line { font-size: 10.5px; }

  .rincian-print .rp-title { text-align: center; font-size: 14px; font-weight: 800; letter-spacing: .06em; text-decoration: underline; margin: 12px 0 1px; }
  .rincian-print .rp-title.rp-svc-ranap { color: #14532d; }
  .rincian-print .rp-title.rp-svc-igd   { color: #9a3412; }
  .rincian-print .rp-title.rp-svc-rajal { color: #1e3a8a; }
  .rincian-print .rp-subtitle { text-align: center; font-size: 11px; margin-bottom: 12px; }

  .rincian-print .rp-meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  .rincian-print .rp-meta td { padding: 1.5px 0; vertical-align: top; font-size: 11px; }
  .rincian-print .rp-meta .k { width: 15%; color: #333; }
  .rincian-print .rp-meta .s { width: 10px; }
  .rincian-print .rp-meta .v { width: 35%; font-weight: 600; }

  .rincian-print .rp-items { margin-bottom: 12px; }
  .rincian-print .rp-group { margin-bottom: 9px; page-break-inside: avoid; }
  .rincian-print .rp-group-head { display: flex; align-items: baseline; justify-content: space-between; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
  .rincian-print .rp-group-sub { font-weight: 700; white-space: nowrap; }
  .rincian-print .rp-row { display: flex; align-items: baseline; font-size: 10.8px; padding: 1.5px 0 1.5px 12px; }
  .rincian-print .rp-row-desc { flex: 0 1 auto; }
  .rincian-print .rp-row-qty { color: #555; }
  .rincian-print .rp-row-disc { color: #b45309; font-size: 9.5px; margin-left: 6px; }
  .rincian-print .rp-dots { flex: 1 1 auto; border-bottom: 1px dotted #bbb; margin: 0 6px; transform: translateY(-2px); min-width: 14px; }
  .rincian-print .rp-row-amt { flex: 0 0 auto; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .rincian-print .rp-row-gross { color: #999; text-decoration: line-through; font-size: 9.5px; margin-right: 5px; }
  .rincian-print .rp-empty { font-style: italic; color: #777; padding: 4px 12px; }

  .rincian-print .rp-summary { display: flex; justify-content: flex-end; margin-bottom: 16px; }
  .rincian-print .rp-summary table { border-collapse: collapse; min-width: 280px; }
  .rincian-print .rp-summary td { padding: 2.5px 7px; font-size: 11px; }
  .rincian-print .rp-summary td.c-num { text-align: right; white-space: nowrap; }
  .rincian-print .rp-summary .rp-grand td { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; font-weight: 800; font-size: 12.5px; }
  .rincian-print .rp-summary .rp-sisa td { font-weight: 700; }
  .rincian-print .rp-summary .rp-disc-paket td { color: #b45309; }

  .rincian-print .rp-status { display: inline-block; border: 2px solid #000; padding: 3px 14px; font-weight: 800; letter-spacing: .08em; font-size: 12px; margin-bottom: 24px; }
  .rincian-print .rp-status.lunas { color: #15803d; border-color: #15803d; }
  .rincian-print .rp-status.belum { color: #b45309; border-color: #b45309; }

  .rincian-print .rp-sign { display: flex; justify-content: flex-end; page-break-inside: avoid; }
  .rincian-print .rp-sign-col { width: 45%; text-align: center; }
  .rincian-print .rp-sign-lbl { font-size: 11px; margin-bottom: 4px; }
  .rincian-print .rp-sign-space { height: 62px; }
  .rincian-print .rp-sign-name { font-size: 11px; }
  .rincian-print .rp-esign { display: inline-block; padding-top: 6px; }
  .rincian-print .rp-esign-badge { display: inline-block; font-size: 9px; font-weight: 700; color: #15803d; border: 1px solid #15803d; border-radius: 4px; padding: 2px 8px; letter-spacing: .02em; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  .rincian-print .rp-esign-name { font-size: 11.5px; font-weight: 700; margin-top: 5px; }
  .rincian-print .rp-esign-meta { font-size: 8.5px; color: #555; margin-top: 1px; }

  .rincian-print .rp-footer { margin-top: 28px; padding-top: 7px; border-top: 1px solid #999; text-align: center; font-size: 9px; color: #444; }
}
</style>
