// Zona waktu pengguna (mis. "Asia/Makassar") untuk server: cookie tk_tz + field tersembunyi di form waktu.
// Server menyimpan UTC dan menampilkan jam dalam zona ini; bila kosong dipakai Asia/Jakarta.
export const detectTimezone = () => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
    } catch {
        return '';
    }
};

const timezone = detectTimezone();
if (timezone) {
    try {
        const current = document.cookie.split('; ').find((item) => item.startsWith('tk_tz='))?.slice(6);
        if (decodeURIComponent(current || '') !== timezone) {
            document.cookie = `tk_tz=${encodeURIComponent(timezone)}; path=/; max-age=31536000; SameSite=Lax`;
            // Halaman pertama dirender dengan zona cadangan; muat ulang sekali agar jam tampil di zona pengguna.
            if (!current && document.querySelector('[data-timezone-sensitive]')) window.location.reload();
        }
    } catch {
        // Cookie diblokir: field tersembunyi di form tetap mengirim zona.
    }
    document.querySelectorAll('input[name="timezone"][data-user-timezone]').forEach((input) => { input.value = timezone; });
}
