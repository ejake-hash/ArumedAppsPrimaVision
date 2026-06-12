// UUID kecil aman-konteks. `crypto.randomUUID()` HANYA tersedia di secure
// context (HTTPS atau http://localhost) — di deploy LAN klinik (http://192.168.x.x)
// nilainya `undefined` sehingga pemanggilan langsung melempar TypeError dan
// membatalkan handler (mis. "Tambah Baris" tak menambah baris). Helper ini punya
// fallback Math.random sehingga aman di semua konteks. Cukup untuk _key list Vue.
export function uid() {
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID()
  }
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}
