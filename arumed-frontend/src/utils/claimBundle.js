// Perakit Berkas Klaim BPJS (Vedika) — gabung semua berkas pendukung satu
// kunjungan jadi 1 PDF (siap unggah ke verifikasi digital BPJS/Vidi).
//
// Sumber & cara ambil:
//  - SEP            : GET /admisi/bpjs/cetak-sep/{visitId} (PDF, via api/Bearer)
//  - Dokumen RM     : GET /klaim/dokumen/{docId}/pdf (hanya FINALIZED, via api/Bearer)
//  - Kwitansi       : GET /klaim/kwitansi/{visitId}/pdf (via api/Bearer)
//  - Penunjang/lampiran: file storage publik (fetch langsung) — PDF di-merge,
//    gambar di-embed ke halaman A4.
//
// Item yang gagal diambil / format tak didukung → DILEWATI (dicatat ke `skipped`),
// tidak menggagalkan seluruh bundel — petugas dapat melengkapi manual sebelum upload.
import { PDFDocument } from 'pdf-lib'
import api from '@/services/api'

const A4 = [595.28, 841.89] // titik (pt) — A4 potret

async function fetchApiBlob(url) {
  const res = await api.get(url, { responseType: 'blob' })
  return res.data
}

async function appendPdf(out, blob, label, skipped) {
  try {
    const buf = await blob.arrayBuffer()
    const src = await PDFDocument.load(buf, { ignoreEncryption: true })
    const pages = await out.copyPages(src, src.getPageIndices())
    pages.forEach((p) => out.addPage(p))
    return true
  } catch (e) {
    skipped.push(label)
    return false
  }
}

async function appendImage(out, blob, label, skipped) {
  try {
    const buf = await blob.arrayBuffer()
    const head = new Uint8Array(buf.slice(0, 4))
    const isPng = head[0] === 0x89 && head[1] === 0x50
    const img = isPng ? await out.embedPng(buf) : await out.embedJpg(buf)
    const page = out.addPage(A4)
    const maxW = A4[0] - 40
    const maxH = A4[1] - 40
    const scale = Math.min(maxW / img.width, maxH / img.height, 1)
    const w = img.width * scale
    const h = img.height * scale
    page.drawImage(img, { x: (A4[0] - w) / 2, y: (A4[1] - h) / 2, width: w, height: h })
    return true
  } catch (e) {
    skipped.push(label)
    return false
  }
}

// File storage publik (penunjang/lampiran): unduh lalu merge/embed sesuai jenis.
async function appendFileUrl(out, url, label, skipped) {
  try {
    const resp = await fetch(url)
    if (!resp.ok) throw new Error('fetch gagal')
    const blob = await resp.blob()
    const isPdf = (blob.type || '').includes('pdf') || url.toLowerCase().split('?')[0].endsWith('.pdf')
    return isPdf
      ? appendPdf(out, blob, label, skipped)
      : appendImage(out, blob, label, skipped)
  } catch (e) {
    skipped.push(label)
    return false
  }
}

/**
 * Rakit 1 PDF gabungan untuk satu kunjungan, urutan Vedika:
 * SEP → dokumen RM (resume, laporan operasi, dll) → penunjang → kwitansi → lampiran.
 * @returns {Promise<{bytes: Uint8Array, skipped: string[], pages: number}>}
 */
export async function buildVisitBundle(visitId) {
  const skipped = []
  const out = await PDFDocument.create()

  // Manifest berkas kunjungan (documents + penunjang + manual).
  let m = {}
  try {
    const { data } = await api.get(`/klaim/rekap/${visitId}/berkas`)
    m = data.data ?? {}
  } catch (e) {
    throw new Error('Gagal memuat daftar berkas kunjungan')
  }

  // 1) SEP
  try {
    await appendPdf(out, await fetchApiBlob(`/admisi/bpjs/cetak-sep/${visitId}`), 'SEP', skipped)
  } catch (e) {
    skipped.push('SEP')
  }

  // 2) Dokumen RM (hanya yang sudah FINALIZED/ber-TTD). Manifest terurut
  //    template_code lalu created_at DESC → ambil revisi TERBARU saja per jenis
  //    (cegah resume/laporan ganda bila dokumen pernah diregenerasi).
  const seenTpl = new Set()
  for (const d of m.documents ?? []) {
    const label = d.type_label || 'Dokumen RM'
    if (!d.signed) {
      skipped.push(`${label} (belum TTD)`)
      continue
    }
    if (d.template_code && seenTpl.has(d.template_code)) continue
    if (d.template_code) seenTpl.add(d.template_code)
    try {
      await appendPdf(out, await fetchApiBlob(`/klaim/dokumen/${d.id}/pdf`), label, skipped)
    } catch (e) {
      skipped.push(label)
    }
  }

  // 3) Hasil penunjang (file gambar/PDF)
  for (const p of m.penunjang ?? []) {
    const label = p.test_name || 'Penunjang'
    if (!p.attachment_url) {
      skipped.push(`${label} (tanpa berkas)`)
      continue
    }
    await appendFileUrl(out, p.attachment_url, label, skipped)
  }

  // 4) Kwitansi / tagihan
  try {
    await appendPdf(out, await fetchApiBlob(`/klaim/kwitansi/${visitId}/pdf`), 'Kwitansi', skipped)
  } catch (e) {
    skipped.push('Kwitansi')
  }

  // 5) Lampiran manual lain
  for (const a of m.manual ?? []) {
    const url = a.file_url || a.attachment_url
    const label = a.title || a.file_name || 'Lampiran'
    if (!url) continue
    await appendFileUrl(out, url, label, skipped)
  }

  const bytes = await out.save()
  return { bytes, skipped, pages: out.getPageCount() }
}

/** Unduh bytes PDF sebagai file (pola blob — tanpa lib eksternal). */
export function downloadPdf(bytes, filename) {
  const blob = new Blob([bytes], { type: 'application/pdf' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** Sanitasi nama file: huruf/angka/.-_ saja. */
export function safeFilename(s) {
  return String(s || 'berkas').replace(/[^A-Za-z0-9._-]+/g, '_').slice(0, 80)
}
