/**
 * Wilayah Indonesia — API eksternal (emsifa/api-wilayah-indonesia)
 *
 * Read-only, public, no auth. Pakai native fetch (bukan axios instance api.js)
 * supaya:
 *   - tidak inject Bearer token Arumed ke domain pihak ketiga
 *   - tidak ter-trigger response interceptor (session-expired/forbidden) untuk error eksternal
 *   - tidak ditangkap proxy /api Vite
 *
 * Source: https://github.com/emsifa/api-wilayah-indonesia
 * Format response: array of { id, name } untuk semua endpoint.
 *
 * Endpoint:
 *   GET /provinces.json
 *   GET /regencies/{provinceId}.json
 *   GET /districts/{regencyId}.json
 *   GET /villages/{districtId}.json
 */

const BASE = 'https://www.emsifa.com/api-wilayah-indonesia/api'

async function fetchJson(url) {
  const res = await fetch(url)
  if (!res.ok) {
    throw new Error(`Wilayah API ${res.status}: ${url}`)
  }
  return res.json()
}

export const wilayahApi = {
  provinces:  ()             => fetchJson(`${BASE}/provinces.json`),
  regencies:  (provinceId)   => fetchJson(`${BASE}/regencies/${provinceId}.json`),
  districts:  (regencyId)    => fetchJson(`${BASE}/districts/${regencyId}.json`),
  villages:   (districtId)   => fetchJson(`${BASE}/villages/${districtId}.json`),
}

export default wilayahApi
