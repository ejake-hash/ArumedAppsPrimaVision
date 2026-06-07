/**
 * Kompres gambar di sisi browser agar ukuran ≤ maxBytes, dengan downscale + turun
 * kualitas JPEG bertahap. Dipakai untuk unggah berkas identitas (KTP) maks 2 MB.
 *
 * - File NON-gambar (mis. PDF) dikembalikan apa adanya (PDF tak dikompres di browser).
 * - Gambar yang sudah ≤ maxBytes dikembalikan apa adanya (hindari re-encode tak perlu).
 * - Orientasi EXIF dihormati (createImageBitmap imageOrientation:'from-image') supaya
 *   foto KTP dari kamera HP tidak terputar.
 */

function canvasToBlob(canvas, type, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality))
}

async function loadBitmap(file) {
  if (window.createImageBitmap) {
    try {
      return await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch {
      /* fallback ke <img> di bawah */
    }
  }
  const url = URL.createObjectURL(file)
  try {
    return await new Promise((resolve, reject) => {
      const img = new Image()
      img.onload = () => resolve(img)
      img.onerror = reject
      img.src = url
    })
  } finally {
    URL.revokeObjectURL(url)
  }
}

/**
 * @param {File} file
 * @param {{maxBytes?:number, maxDim?:number}} opts
 * @returns {Promise<File>} file terkompres (atau file asli bila tak perlu/ bukan gambar)
 */
export async function compressImageToUnder(file, { maxBytes = 2 * 1024 * 1024, maxDim = 1600 } = {}) {
  if (!file.type?.startsWith('image/')) return file
  if (file.size <= maxBytes) return file

  const bitmap = await loadBitmap(file)
  let w = bitmap.width
  let h = bitmap.height

  const canvas = document.createElement('canvas')
  const ctx = canvas.getContext('2d')

  const draw = (cw, ch) => {
    canvas.width = cw
    canvas.height = ch
    ctx.drawImage(bitmap, 0, 0, cw, ch)
  }

  // Downscale awal ke sisi terpanjang ≤ maxDim.
  const scale = Math.min(1, maxDim / Math.max(w, h))
  w = Math.round(w * scale)
  h = Math.round(h * scale)
  draw(w, h)

  // Turunkan kualitas sampai ≤ maxBytes (lantai 0.5).
  let quality = 0.85
  let blob = await canvasToBlob(canvas, 'image/jpeg', quality)
  while (blob && blob.size > maxBytes && quality > 0.5) {
    quality = Math.round((quality - 0.1) * 100) / 100
    blob = await canvasToBlob(canvas, 'image/jpeg', quality)
  }

  // Masih kebesaran → turunkan dimensi bertahap (sampai ≥ 800px sisi terpanjang).
  while (blob && blob.size > maxBytes && Math.max(canvas.width, canvas.height) > 800) {
    draw(Math.round(canvas.width * 0.85), Math.round(canvas.height * 0.85))
    blob = await canvasToBlob(canvas, 'image/jpeg', 0.7)
  }

  if (typeof bitmap.close === 'function') bitmap.close()
  if (!blob) return file // toBlob gagal → biar server yang validasi

  const baseName = file.name.replace(/\.\w+$/, '') || 'ktp'
  return new File([blob], `${baseName}.jpg`, { type: 'image/jpeg' })
}
