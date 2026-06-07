/**
 * useUiShell — state tata letak cangkang aplikasi (sidebar drawer di mobile).
 *
 * Pada layar lebar (> 1024px) sidebar selalu tampil sebagai kolom tetap, jadi
 * state ini tidak berpengaruh. Pada tablet/HP sidebar berubah jadi drawer
 * off-canvas yang dibuka/ditutup lewat tombol hamburger di topbar dan ditutup
 * dengan menekan scrim (lapisan gelap) atau saat pindah halaman.
 *
 * State singleton reaktif — sama untuk semua pemanggil (AppTopbar buka,
 * AppSidebar tampilkan, AppLayout tutup saat navigasi). Pola mengikuti
 * useAppearance.js (singleton di luar fungsi).
 */
import { ref } from 'vue'

const mobileNavOpen = ref(false)

export function useUiShell() {
  return {
    mobileNavOpen,
    openMobileNav() { mobileNavOpen.value = true },
    closeMobileNav() { mobileNavOpen.value = false },
    toggleMobileNav() { mobileNavOpen.value = !mobileNavOpen.value },
  }
}
