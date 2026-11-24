# TutorKita — panduan untuk Claude

Platform bimbingan belajar gratis: mahasiswa relawan (Tutor) mengajar siswa SD/SMP/SMA (Student/Siswa), diawasi Admin.
Stack: Laravel 12 (PHP 8.2), Blade, Tailwind CSS 4, Vite, JavaScript murni, SQLite. Bahasa antarmuka & pesan error: Indonesia.
Root proyek = folder ini (git root). GitHub: https://github.com/attagetto/tutorkita

**Kode adalah acuan. Jira hanya referensi.** Kartu Jira banyak yang salah status/peran; pakai ROADMAP di bawah.

## Perintah penting
- `php artisan test` — seluruh tes (SQLite :memory:, user store sementara; aman dijalankan).
- `npm test` — tes JS (`tests/js/*.test.js`, vitest + jsdom; konfigurasi `vitest.config.js`). Jalankan bila JS berubah.
- `npm run build` — wajib setelah mengubah CSS/JS.
- `php artisan route:list --except-vendor -v` — route + middleware.
- `php artisan migrate:status` — cek migrasi (read-only).
- `composer run dev` — server + queue + Vite.
- DILARANG tanpa izin: `php artisan tutorkita:admin`, `git commit`, `git push`, `migrate:fresh|reset`, `db:wipe`.

## Data lokal (data uji, JANGAN di-commit)
- `database/database.sqlite` — database uji lokal.
- `storage/app/private/users.json` — akun uji (LocalUserStore) beserta hash password.
- `storage/app/private/*` lainnya — unggahan (foto profil, PDF materi, jawaban tugas, kartu mahasiswa `tutor-documents/`).
- users.json kini bisa berisi kunci opsional `occupation` (Pekerjaan, maks. 60), hanya bila pemilik akun mengisinya.
- `database/*.sqlite*`, `database/backup-*/`, `database/recovery-*.json` — backup/recovery.
- Semua di atas di-ignore git. Jangan pernah `git add -f` file-file ini. Akun lama di users.json tidak boleh diubah/dihapus.
- Akun uji (password TIDAK ditulis di repo; tanyakan pemilik proyek):
  - `admin@tutorkita.test` — Admin (id 3, dibuat via `php artisan tutorkita:admin`)
  - `tutor@tutorkita.test` — Tutor (id 5, daftar sebagai Siswa → ajukan tutor → disetujui Admin)
  - `siswa@tutorkita.test` — Siswa (id 4, daftar via `/register`)
  - `gustitest@gmail.com` ("gudti") — Tutor (id 6, dibuat 2026-11-17; daftar sebagai Siswa → disetujui jadi Tutor)
- Akun lama id 1 dan 2 (data teman) — jangan diubah.

## Status langkah persiapan (2026-11-16)
- P1 selesai: CLAUDE.md dibuat.
- P2 selesai: `database/recovery-20261110-115933-users.json` dihapus dari repo (riwayat git tidak ditulis ulang),
  `.gitignore` ditambah pola data lokal, 2 backup SQLite 0 byte dihapus.
- P3 selesai: backup di `database/backup-20261116-154938/` (database.sqlite + users.json); 3 akun uji dibuat.
- Alur pengajuan tutor (Siswa mengajukan → Admin menyetujui → login sebagai Tutor) sudah teruji manual di browser
  pada 2026-11-16; data DB konsisten (pengajuan id 1 approved, `user_role_changes` source `tutor_application_approval`).
- Perbaikan bug dashboard (2026-11-18, diuji pemilik di browser; commit 62594ce dan f6ba5d1, belum di-push):
  - Penyebab: kartu sesi dan `<select>` tutor sama-sama memakai `data-session-tutor`; `dashboard.js` mengambil kartu
    sebagai select → TypeError saat inisialisasi → Lihat Detail, Batalkan, menu profil, logout, notifikasi ikut mati
    bagi SEMUA akun yang punya ≥1 sesi. Select kini `data-session-tutor-select` dan dicari di dalam form.
  - Ketahanan: kerangka (sidebar, notifikasi, menu profil/logout, klik global, Esc) dipasang paling awal di
    `dashboard.js`; render daftar sesi/riwayat dibungkus `safeInit`. Tombol ⌄ sidebar kini membuka menu profil.
  - Ruang kosong di atas judul halaman: `.spa-page` diberi `align-content: start`.
  - Pengajuan/pembatalan sesi hanya Siswa (`role:student`); tombol & dialog pengajuan disembunyikan untuk Tutor/Admin.
  - Backup sebelum perbaikan: `database/backup-20261118-074917/`.
  - Sesi uji `learning_sessions` id 1 milik gudti sudah dihapus (backup: `database/backup-20261118-081652/`).
    Notifikasinya (`user_notifications` key `session:1:pending`) juga dihapus (backup: `database/backup-20261118-083231/`).
  - Alur Siswa teruji di browser: sesi id 2 milik siswa id 4 diajukan lalu dibatalkan (status `cancelled`, sengaja dibiarkan).
- Audit ulang setelah jeda (2026-11-24, hanya baca):
  - Git: `main` bersih, 4 commit lokal belum di-push (e7f5090, 62594ce, f6ba5d1, 1058b51); `origin/main` tidak punya
    commit baru. Tidak ada perubahan kode sejak 1058b51.
  - Tes: `php artisan test` 49 lulus (501 asersi); `npm test` 6 lulus.
  - 34 route dan 11 migrasi (semua Ran) sama dengan audit 2026-11-16; status ROADMAP di bawah masih berlaku.
    Semua butir "Masalah yang diketahui" masih terbukti di kode.
  - Daftar kartu Jira Sprint 1 dicatat di bagian "Kartu Jira" di bawah, beserta status menurut kode.
- ROADMAP 0.1 dikerjakan dan di-commit 2026-11-24 ("Dashboard: tampilan menu dan beranda per role (belum diuji browser)").
  **UJI BROWSER 0.1 MASIH TERTUNDA** — pemilik akan menguji dengan daftar cek 3 akun (admin/tutor/siswa, tombol ⌄,
  tab "Semua Sesi"). Bila ada temuan, perbaiki di commit terpisah. Keputusan pemilik: menu Tutor = Beranda, Materi,
  Notifikasi (Sesi/Tugas ditambah di 3.3/4.1); aksi Admin hanya di sidebar; TT-25 ditunda.
- Fitur "daftar sebagai tutor + verifikasi tutor + kolom pekerjaan" dikerjakan dan di-commit 2026-11-24
  ("Daftar sebagai tutor, verifikasi tutor oleh admin, dan kolom pekerjaan (belum diuji browser)").
  **UJI BROWSER MASIH TERTUNDA** — lihat bagian "Daftar cek uji browser gabungan" di bawah.
  Keputusan pemilik: isian wajib = pendidikan, tahun lulus, bio ≥20, kampus, prodi, mapel, foto, kartu; siswa yang
  mengajukan juga wajib kartu; Verifikasi Tutor = halaman (bukan dialog); tutor terverifikasi boleh ubah profil tanpa
  verifikasi ulang, tetapi kartu terkunci. Backup sebelum migrasi: `database/backup-20261124-092124/`.
- 2026-11-25 (semua di-commit, BELUM diuji browser): label peran Indonesia (Siswa/Tutor/Admin); 1.3 nonaktif/aktif tutor;
  5.1 profil & dokumen siswa + wajib terverifikasi sebelum mengajukan sesi; 5.2 Admin memverifikasi siswa.
  Backup sebelum migrasi: `database/backup-*-sebelum-1.3/`, `database/backup-*-sebelum-5.1/`. Akun siswa lama (id 1,
  id 4 `siswa@tutorkita.test`) kini berstatus Belum lengkap → harus melengkapi profil sebelum bisa mengajukan sesi.
- 2026-11-26 TAHAP 1 selesai (BUB): konversi data lama, A slot tutor, B katalog asli, C pesan/batal/ganti jadwal + bentrok.
- LANJUTKAN DI SESI BARU dari bagian "Rencana kerja mandiri (Tahap 2–6)" di bawah: pemilik mengizinkan kerja mandiri tanpa
  persetujuan per bagian (berhenti hanya untuk keputusan penting baru atau risiko data). Mulai dari TAHAP 2 (TT-15).

## Daftar cek uji browser gabungan (TERTUNDA — 0.1, verifikasi tutor, pekerjaan, 1.3, 5.1, 5.2)
Persiapan: `composer run dev`; migrasi lokal sudah dijalankan; siapkan file JPG/PNG/PDF <2 MB (kartu/dokumen) dan satu file
.gif atau >2 MB. Uji di desktop dan mode HP (DevTools 390px). Setiap langkah: lihat Console, tidak boleh ada error merah.
A. 0.1 tampilan per role
  1. admin@: menu Beranda · Verifikasi Tutor · Verifikasi Siswa · Pengguna · Notifikasi; tombol ⌄ kiri bawah membuka menu
     tepat di atasnya (desktop), Esc/klik luar menutup; di HP ⌄ menutup laci lalu menu muncul kanan atas.
  2. tutor@ (lama): tetap terverifikasi & aktif: Beranda · Materi · Notifikasi; angka beranda cocok; tambah/edit/hapus materi.
  3. siswa@: 8 menu (termasuk Profil & Verifikasi); Sesi Belajar: tab "Semua Sesi" berjudul "Semua Sesi"; Lihat Detail sesi.
  4. Label di bawah nama (topbar, sidebar, menu profil) = Siswa/Tutor/Admin bila Pekerjaan kosong.
B. Daftar sebagai tutor → verifikasi (TT-4, TT-6)
  1. /register: default "Saya ingin belajar"; pilih "Saya ingin mengajar" → catatan muncul. Daftar tutor baru.
  2. Login tutor baru: menu Beranda · Profil & Verifikasi · Notifikasi; `#materials` → Beranda; status Belum lengkap.
  3. Isi profil (kosong → error), unggah kartu (.gif/>2 MB ditolak di browser), Kirim → Menunggu, form terkunci.
  4. admin@ → Verifikasi Tutor tab Pengajuan: cari nama/kampus, filter, pratinjau kartu, Tolak (tanpa alasan gagal).
  5. Tutor: notifikasi + alasan tampil; ganti kartu, Kirim Ulang. Admin Setujui → tutor dapat menu Materi, bisa tambah materi.
  6. Buka URL kartu sebagai siswa (403) dan saat logout (ke /login).
  7. Siswa baru ajukan jadi tutor (menu profil): tanpa kartu ditolak; asal di admin "Siswa mengajukan diri".
C. Pekerjaan: Edit Profil isi "Driver Gojek" → tampil di bawah nama (3 tempat), badge peran tetap; kosongkan → kembali ke
   peran; 61 karakter ditolak; `<b>tes</b>` tampil sebagai teks; admin melihatnya di Pengguna & Verifikasi Tutor.
D. 1.3 nonaktif/aktif tutor
  1. admin@ → Verifikasi Tutor tab Tutor Terverifikasi: cari, filter Aktif/Nonaktif; Nonaktifkan tanpa alasan gagal.
  2. Nonaktifkan dengan alasan → kembali ke tab ini, badge Nonaktif, perubahan terakhir + alasan tampil.
  3. Login tutor itu: beranda "Akun dinonaktifkan" + alasan; menu Materi masih bisa dibaca; tombol Tambah/Edit/Hapus hilang.
  4. Admin Aktifkan kembali (alasan wajib) → tutor bisa kelola materi lagi; notifikasi diterima di kedua aksi.
E. 5.1 profil siswa (TT-9) — pakai siswa@ atau siswa baru
  1. Beranda menampilkan kotak "Lengkapi profil untuk mengajukan sesi"; di Sesi Belajar tombol "Ajukan Sesi Baru" nonaktif +
     penjelasan + tombol ke Profil & Verifikasi; dialog pengajuan tidak bisa dibuka.
  2. Materi, Mata Pelajaranku, Tugas tetap bisa dipakai.
  3. Profil: kosong → error; kontak wali tidak valid → error; Kecamatan/Kota >100 karakter ditolak; simpan → langkah 1 ✓.
  4. Dokumen: pilih jenis, .gif/>2 MB ditolak; unggah → pratinjau; Kirim → Menunggu, form terkunci.
F. 5.2 Verifikasi Siswa (TT-10) — admin@
  1. Menu Verifikasi Siswa berangka; daftar semua siswa termasuk "Belum lengkap"; cari nama/kontak/sekolah; filter status.
  2. Detail wali/kontak/kecamatan + pratinjau dokumen; Tolak (alasan wajib) → siswa lihat alasan, perbaiki, Kirim Ulang.
  3. Setujui → siswa dapat notifikasi; tombol Ajukan Sesi aktif dan pengajuan sesi berhasil; profil masih bisa diubah,
     bagian dokumen hilang (terkunci).
  4. PRIVASI: login sebagai tutor → tidak ada data wali/kecamatan/dokumen siswa di halaman mana pun; URL dokumen siswa → 403.
G. Tahap 1: slot, katalog, sesi (2.2, 3.1, 3.2)
  1. Zona waktu: Windows Settings → Time & language → Time zone (mis. "(UTC+08:00) Makassar"), ATAU Chrome DevTools → ⋮ →
     More tools → Sensors → Location → pilih lokasi/zona (mis. Asia/Makassar). Muat ulang 2× (cookie tk_tz diperbarui).
  2. tutor@ (WITA) → Jadwal Saya: tambah slot besok 16.00–17.00 kapasitas 2 → kartu "16.00–17.00 WITA, Terisi 0/2".
  3. Ulangi tiap minggu 4 minggu dengan satu tanggal sengaja bentrok → Pratinjau: tanggal bentrok MERAH + alasan; Simpan →
     pesan "N slot dibuat. Dilewati: …" + notifikasi.
  4. Ganti zona ke Asia/Jakarta → siswa terverifikasi buka Katalog: slot tampil "15.00–16.00 WIB", foto tutor tampil,
     "Belum ada ulasan"; filter mapel/jenjang/Ada slot kosong; Lihat Profil → Pilih slot ini → form terisi tutor & slot.
  5. Slot penuh: dua siswa memesan slot kapasitas 2 → siswa ketiga mendapat pesan "Slot ini sudah penuh"; tutor melihat
     "Terisi 2/2" + nama, mapel, topik (TANPA data wali).
  6. Bentrok: siswa memesan dua slot yang tumpang tindih → peringatan merah sebelum Ajukan, tetap bisa lanjut; label "Bentrok"
     di Sesi Belajar, Jadwal Hari Ini/Minggu Ini, beranda; notifikasi "Jadwal bentrok".
  7. Lihat Detail sesi → Ganti jadwal → hanya slot lain tutor yang sama → simpan → label Bentrok hilang bila sudah tidak tumpang tindih.
  8. Batalkan sesi → kursi kembali (Terisi turun); tutor dapat notifikasi. Tutor Batalkan slot (alasan) → semua siswa notifikasi.
  9. Admin nonaktifkan tutor yang punya sesi mendatang → peringatan "X sesi … Y siswa" + centang "Saya paham" wajib.
  10. Slot < 3 jam lagi tidak muncul di katalog / ditolak.

## Arsitektur
- Akun (id, nama, email/HP, hash password, avatar_path) ada di `storage/app/private/users.json` lewat
  `App\Support\LocalUserStore` + `App\Auth\FileUserProvider`. Tabel `users` TIDAK dipakai.
- Role ada di SQLite `user_roles` (student|tutor|admin); log di `user_role_changes`. `FileUserProvider::makeUser`
  membaca role tiap request dan membuat baris `student` bila belum ada.
- ID akun disimpan sebagai string di tabel lain (student_id, user_id, tutor_id) tanpa foreign key.
- Middleware role: alias `role` → `App\Http\Middleware\EnsureRole` (`->middleware('role:admin')`).
  Fitur baru WAJIB memakainya; banyak controller lama masih memakai `abort_unless` di dalam controller.
- Dashboard = satu halaman SPA: `DashboardController` → `dashboard/index.blade.php` + `dashboard/pages/workspace.blade.php`;
  `resources/js/dashboard.js` mengganti `data-spa-page` dan memanggil endpoint JSON. Sidebar/topbar di `dashboard/partials`.
- Data dashboard dirakit di `app/Support/*Data.php`. `DashboardMockData` masih menyuplai tutor katalog (MOCK).
- File unggahan di disk `local` (`storage/app/private`), disajikan lewat route yang dicek pemiliknya.
- Desain: biru `#0866F5`, navy `#0D2B5E`, latar `#EFF6FE`/putih; logo resmi di `public/assets/tutorkita/` (jangan diubah).
- "Mata Pelajaranku" siswa (`student_subjects`, `SubjectsController`) sudah selesai — pertahankan.

## Pemisahan konsep jadwal (jangan dicampur)
- Slot ketersediaan tutor — belum ada tabel; sementara hanya `tutor_profiles.availability` (JSON string). Target: tabel sendiri (ROADMAP 2.2).
- Sesi/pemesanan — `learning_sessions` (belum punya tutor_id/slot_id; tutor masih dari mock). "Jadwal belajar" di beranda siswa dibaca dari sini.

## ROADMAP (kerjakan satu langkah per instruksi; status per audit 2026-11-16)
Urutan: Admin → Tutor → Siswa, menghormati ketergantungan logis.

### Tahap 0: Fondasi
- 0.1 Tampilan per role (sidebar, beranda, menu; satu sumber definisi menu per role) — **Selesai, di-commit, uji browser tertunda**:
  `App\Support\DashboardNavigation` = satu sumber menu; `dashboard/home/{student,tutor,admin}.blade.php`; halaman SPA di luar
  menu role tidak dirender (bukan pengaman data — itu 0.2); tes `DashboardNavigationTest` + `tests/js/dashboard-roles.test.js`.
- 0.2 Pengecekan role via middleware `role:` di route sesi/materi/tugas/mapel + tes diizinkan/ditolak — **Sebagian** (pengajuan tutor, keputusan admin, dan pengajuan/pembatalan sesi `role:student` + tes; materi/tugas/mapel belum).
- 0.3 Keamanan login: pesan error seragam + rate limit (mis. 5/menit per kontak+IP) — **Belum**.

### Tahap 1: Admin
- 1.1 Katalog mapel & jenjang (CRUD, jenjang per mapel, cari; mapel terpakai hanya diarsip) — **Belum** (mapel hanya dari seeder).
- 1.2 Pengajuan tutor lengkap (unggah kartu mahasiswa privat; daftar + filter + cari; detail & pratinjau aman; setujui/tolak) — **Hampir selesai, uji browser tertunda**:
  daftar sebagai tutor (`account_type` student|tutor), halaman Profil & Verifikasi (`TutorVerificationController`),
  kartu privat `tutor-documents/{id}` (route hanya Admin/pemilik, header nosniff + CSP sandbox), halaman admin
  Verifikasi Tutor + filter status, `decide` menerima pemohon siswa/tutor. Status = `tutor_applications.status`
  (`App\Support\TutorVerification`); middleware `tutor.verified` di route tulis materi/tugas. Pencarian pengajuan
  ditambahkan 2026-11-25 (cari di sisi klien, `data-admin-list`).
- 1.3 Kelola tutor (daftar, nonaktif/aktif + alasan + audit; tutor nonaktif tak bisa kelola materi/tugas; notifikasi) — **Selesai, uji browser tertunda**:
  tab "Tutor Terverifikasi" di halaman Verifikasi Tutor (cari + filter), `AdminTutorController@updateStatus`, log
  `tutor_status_changes`, middleware `tutor.verified` = terverifikasi DAN aktif, scope `TutorProfile::verified()->active()`
  untuk katalog. `is_active` hanya berubah lewat persetujuan verifikasi atau aksi admin ini.
- 1.4 Kelola pengguna (daftar, cari, filter role, detail; nonaktifkan akun = tak bisa login; perbaiki N+1) — **Sebagian** (dialog daftar read-only, N+1).

### Tahap 2: Tutor
- 2.1 Profil tutor (mapel dari katalog 1.1, foto bisa dilihat siswa, status jelas) — **Sebagian**.
- 2.2 Slot ketersediaan (tabel baru, CRUD, validasi bentrok, migrasi/usangkan `availability`; terpisah dari `learning_sessions`) — **Selesai (BUB), 2026-11-26**: `tutor_availability_slots` (UTC, capacity 1–20, booked_count, open/full/cancelled), `TutorSlotController`, `App\Support\{SlotPlanner,SlotBooking,UserTime,Notifier}`, halaman Jadwal Saya, pratinjau mingguan di server, cookie `tk_tz` + middleware `ResolveUserTimezone`, `config/tutorkita.php`. `availability` usang. Kolom sesi `tutor_id/slot_id/cancelled_by/cancel_reason` + index unik parsial sudah dibuat di A (dipindah dari C karena pembatalan slot butuh). Sesi lama id 2 sudah dikonversi ke UTC (backup `database/backup-*-sebelum-tahap1/`).
- 2.3 Materi (tutor lihat miliknya termasuk draf; mapel/jenjang dari katalog; data lama tetap terbaca; buang jenjang di luar `grade_levels`) — **Sebagian** (CRUD materi selesai; draf tak terlihat; mapel/jenjang teks).

### Tahap 3: Alur sesi (urutan wajib)
- 3.1 Katalog tutor asli + slot kosong — **Selesai (BUB), 2026-11-26**: `App\Support\TutorCatalog` (verified+active, jenjang dari mapel, slot open ≥3 jam & ≤14 hari), partial `dashboard/pages/tutor-catalog.blade.php`, foto tutor aktif boleh dilihat pengguna login, "Belum ada ulasan", favorit per id (`tutorkita-favorite-tutor-ids`).
- 3.2 Ajukan/batalkan sesi — **Selesai (BUB), 2026-11-26**: `App\Support\SessionBooking` (pesan/batal/ganti jadwal, kursi atomik,
  bentrok ditandai), `LearningSessionController` (store/show/cancel/reschedule), form slot (tutor→slot→mapel→jenjang) + peringatan
  bentrok merah + "Pilih slot ini" dari katalog, label Bentrok di kartu/jadwal/beranda, notifikasi tutor & siswa. Tes konkuren
  proses paralel `SessionBookingTest::test_parallel_processes_cannot_overbook_a_slot` (skrip `tests/Support/concurrent-booking.php`).
- 3.3 Tutor terima/tolak pengajuan + alasan + notifikasi siswa — **Belum**.
- 3.4 Presensi, catatan, tandai selesai (status `completed` HANYA dari tutor) — **Belum**.

### Tahap 4: Tugas & materi untuk siswa
- 4.1 UI tutor untuk tugas (hanya siswa yang pernah sesi dengannya; daftar pengumpulan; unduh; nilai + umpan balik) — **Sebagian** (backend `create`/`grade` saja).
- 4.2 Siswa mengerjakan tugas & melihat nilai (sesuaikan dengan 4.1) — **Sebagian besar ada**.
- 4.3 Siswa melihat/mengunduh materi + gate role — **Ada, tanpa gate role**.

### Tahap 5: Siswa
- 5.1 Profil siswa lengkap + dokumen pendukung privat — **Selesai, uji browser tertunda** (2026-11-25). Keputusan pemilik:
  verifikasi WAJIB sebelum mengajukan sesi (middleware `student.verified` di `sessions.store`; materi/mapel/tugas tetap
  bebas); akun siswa lama (id 1, id 4) wajib melengkapi dulu — tanpa backfill; siswa terverifikasi boleh ubah profil
  tanpa verifikasi ulang, dokumen terkunci; isian "Kecamatan / Kota" (maks. 100), BUKAN alamat rumah.
  Tabel `student_profiles` + `student_verification_reviews`; `App\Support\StudentVerification`, `StudentVerificationController`,
  dokumen privat `student-documents/{id}` lewat `App\Support\PrivateDocument` (pola sama dengan kartu mahasiswa).
- 5.2 Admin memverifikasi siswa — **Selesai, uji browser tertunda** (2026-11-25): menu Admin "Verifikasi Siswa" (semua akun
  siswa termasuk Belum lengkap; cari nama/kontak/sekolah; filter; detail; pratinjau dokumen aman; Setujui/Tolak + alasan;
  notifikasi; log `student_verification_reviews`), `AdminStudentController@decide`. Partial bersama:
  `dashboard/partials/{admin-list-tools,document-preview,decision-forms}`; JS `[data-admin-list]` (cari klien).
- 5.3 Rating & ulasan (hanya sesi selesai dari 3.4, satu per sesi) — **Belum**.
- 5.4 Tutor lihat ulasan; Admin sembunyikan/tampilkan + alasan; rating katalog dari ulasan tak tersembunyi — **Belum**.

### Tahap 6: Penutup
- 6.1 Dashboard ringkasan & grafik per role dengan data asli — **Sebagian** (siswa saja).
- 6.2 Bersih-bersih kode mati, DatabaseSeeder "Test User", README.md, FOR INFO.md — **Belum**.

## Kartu Jira (TT Sprint 1; status Jira per 2026-11-25, status kode per audit 2026-11-25)
Kode tetap acuan. ✅ selesai, 🟡 sebagian, ❌ belum. "(BUB)" = belum diuji browser. TT-14, TT-23, TT-24 tidak ada di board.

| Kartu | Judul | Status Jira | Kode | Catatan singkat | Usulan Jira |
|---|---|---|---|---|---|
| TT-2 | Fitur login dan logout | Done | ✅ | Ada tes. Keamanan (pesan seragam, rate limit) di ROADMAP 0.3 | Done |
| TT-3 | Menambah & mengubah mapel & jenjang (Mata Pelajaranku) | Done | ✅ | `SubjectsController` + `SubjectSelectionTest` | Done |
| TT-5 | Melihat, mencari, menghapus mapel (Mata Pelajaranku) | Done | ✅ | Cari + hapus, ada tes | Done |
| TT-4 | [Tutor] Profil + unggah kartu mahasiswa | Done | ✅ | Daftar sebagai tutor, kartu privat, `TutorVerificationTest` | Done (BUB) |
| TT-6 | [Admin] Setujui, tolak, nonaktifkan tutor | Done | ✅ | Verifikasi + nonaktif/aktif + audit, `TutorStatusTest` | Done (BUB) |
| TT-9 | [Siswa] Profil + dokumen pendukung | Done | ✅ | `StudentVerificationTest` (termasuk privasi) | Done (BUB) |
| TT-11 | [Siswa] Cari tutor, profil, slot kosong | Done | ✅ | Katalog asli (`TutorCatalog`), foto tutor, slot 14 hari, `TutorCatalogTest` | Done (BUB) |
| TT-13 | [Semua role] Dashboard ringkasan & grafik | Done | 🟡 | Siswa: stat + grafik. Tutor/Admin: stat tanpa grafik → ROADMAP 6.1 | In Progress |
| TT-25 | [Siswa] Menambah/mengubah/menghapus jadwal pelajaran | Done | ✅ | Ganti jadwal sesi ke slot lain tutor yang sama + batal + bentrok merah, `SessionBookingTest` | Done (BUB) |
| TT-7 | [Tutor] Menambah slot jadwal | In Progress | ✅ | Slot + kapasitas + mingguan + zona waktu, `TutorSlotTest` | Done (BUB) |
| TT-8 | [Tutor] Melihat/mengubah/menghapus slot | In Progress | ✅ | Jadwal Saya: tab, filter, ubah/hapus/batalkan, `TutorSlotTest` | Done (BUB) |
| TT-17 | [Tutor] Unggah/ubah/hapus materi | In Progress | 🟡 | CRUD + tes ada, tetapi materi DRAF tidak muncul di halaman Materi (tak bisa diedit); mapel/jenjang teks (ROADMAP 2.3) | In Progress |
| TT-10 | [Admin] Lihat, cari, verifikasi siswa | To Do | ✅ | Menu Verifikasi Siswa, `AdminStudentVerificationTest` | Done (BUB) |
| TT-12 | [Siswa] Ajukan & batalkan sesi | To Do | ✅ | Pesan slot (kapasitas, atomik, konkuren dites), batal membebaskan kursi | Done (BUB) |
| TT-15 | [Tutor] Terima/tolak pengajuan sesi | To Do | ❌ | ROADMAP 3.3 | To Do |
| TT-16 | [Tutor] Presensi, catatan, tandai selesai | To Do | ❌ | Status selesai masih otomatis dari waktu (ROADMAP 3.4) | To Do |
| TT-18 | [Siswa] Lihat & unduh materi | To Do | ✅ | Lihat, unduh PDF privat, bookmark, progres; ada tes. Gate role route baca = ROADMAP 0.2 | Done |
| TT-19 | [Tutor] Buat tugas & menilai | To Do | 🟡 | Backend `create`/`grade` + tes; BELUM ada UI tutor; student_id bebas (ROADMAP 4.1) | In Progress |
| TT-20 | [Siswa] Kumpulkan tugas & lihat nilai | To Do | ✅ | UI + tes lengkap di sisi siswa; baru terasa penuh setelah UI tutor (TT-19) | Done |
| TT-21 | [Siswa] Rating & ulasan sesi selesai | To Do | ❌ | ROADMAP 5.3 (butuh TT-16) | To Do |
| TT-22 | [Tutor/Admin] Lihat & sembunyikan ulasan | To Do | ❌ | ROADMAP 5.4 (butuh TT-21) | To Do |

Perpindahan Jira yang diusulkan: TT-11, TT-13, TT-25 Done → In Progress; TT-10, TT-18, TT-20 To Do → Done;
TT-12, TT-19 To Do → In Progress. Lainnya tetap.
Selesai setelah rencana A → B → C: TT-7, TT-8 (A), TT-11 (B), TT-12, TT-25 (C).
Sisa setelah itu (urutan kerja): TT-15 (3.3) → TT-16 (3.4) → TT-19 UI tutor + TT-17 sisa draf (4.1, 2.3) → TT-21 (5.3) →
TT-22 (5.4) → TT-13 grafik tutor/admin (6.1). Di luar Jira: ROADMAP 0.2, 0.3, 6.2.

## Rencana berikutnya (menunggu persetujuan) — slot, katalog, sesi (TT-7, TT-8, TT-11, TT-12, TT-25)
Disusun 2026-11-25; DISETUJUI pemilik 2026-11-26 (termasuk konversi data lama). Progres: SEMUA SELESAI 2026-11-26 (konversi ✅, A ✅, B ✅, C ✅). Bagian di bawah dipertahankan sebagai dokumentasi desain.
Urutan: konversi data lama → A → B → C, masing-masing backup DB, semua tes + build lulus, commit "(belum diuji browser)".

### Zona waktu (mengikuti laptop pengguna)
- DB tetap UTC (`config/app.php` tidak diubah). `app.js` menulis cookie `tk_tz` = `Intl.DateTimeFormat().resolvedOptions().timeZone`;
  form slot/sesi juga mengirim field tersembunyi `timezone`. Cookie dikecualikan dari enkripsi (`encryptCookies(except: ['tk_tz'])`).
- Middleware `ResolveUserTimezone`: field/cookie divalidasi dengan daftar zona PHP; kosong/tidak valid → `Asia/Jakarta`.
- Helper `App\Support\UserTime`: input lokal → UTC, UTC → zona pengguna, label "Rabu, 25 Nov · 15.00–16.00 WIB".
  Singkatan dari PHP (Jakarta WIB, Makassar WITA, Jayapura WIT); singkatan angka → "UTC+8".
- Konversi tampilan: sesi, slot, jadwal hari ini/minggu ini (batas hari/minggu dalam zona pengguna), riwayat, deadline tugas.
- Batas pemesanan & bentrok dihitung di UTC. `config/tutorkita.php`: `booking_lead_minutes = 180` (3 jam).
- Tes: tutor `Asia/Makassar` buat slot 16.00 → DB 08.00 UTC → siswa `Asia/Jakarta` melihat "15.00 WIB"; zona palsu → Jakarta.

### Konversi data lama (MENUNGGU PERSETUJUAN)
- Hanya `learning_sessions.starts_at` sesi lama (`slot_id IS NULL AND tutor_id IS NULL`) yang tersimpan sebagai jam WIB:
  saat ini 1 baris (id 2, siswa id 4, cancelled, `2026-11-20 08:31:00`). `student_assignments` kosong; timestamp lain sudah UTC.
- Migrasi `convert_legacy_session_times_to_utc`: kurangi 7 jam (id 2 → `2026-11-20 01:31:00` UTC, tetap tampil 08.31 WIB);
  `down()` menambah 7 jam dengan kriteria sama. Backup `database.sqlite` dulu, bandingkan sebelum/sesudah.

### A. Slot ketersediaan tutor (TT-7, TT-8 · ROADMAP 2.2)
- Tabel `tutor_availability_slots`: `tutor_id`, `starts_at`, `ends_at` (UTC), `capacity` (default 20, 1–20), `booked_count`,
  `status` open|full|cancelled, `cancel_reason`, timestamps; index `(tutor_id, starts_at)`. Form tetap Tanggal + Jam mulai/selesai.
- Route `TutorSlotController`: tambah/ubah/hapus/pratinjau `tutor.verified`; batalkan slot terpesan `role:tutor` + cek
  terverifikasi (tutor nonaktif tetap boleh membatalkan). Slot milik orang lain → 404.
- Validasi: mulai di masa depan, durasi 30–180 menit, tidak bentrok dengan slot open/full tutor itu sendiri (cek di UTC).
- Ulangi tiap minggu sampai tanggal X (maks. 12 minggu): tombol Pratinjau → `POST /dashboard/tutor/slots/preview`
  (hitungan sama dengan simpan, tanpa menulis): hijau = dibuat, merah = dilewati + alasan ("Bentrok dengan slot …",
  "Sudah lewat"). Simpan hanya tanggal aman; pesan + notifikasi berisi jumlah dibuat dan tanggal yang dilewati.
- Slot berpemesan: tanggal/jam terkunci; kapasitas boleh diubah ≥ jumlah pemesan; hapus hanya bila 0 pemesan.
  Batalkan slot (alasan wajib): semua sesi aktif ikut cancelled (`cancelled_by=tutor`) + notifikasi ke semua siswanya.
- Menu tutor terverifikasi: Beranda · Jadwal Saya · Materi · Notifikasi. Jadwal Saya: form + pratinjau, tab Mendatang/Lewat,
  filter status, "terisi X/Y", daftar siswa per slot (nama, mapel, topik SAJA — Aturan no. 11). Tutor nonaktif: hanya lihat.
- Checkbox `tutor_profiles.availability` USANG: dihapus dari Edit Profil, `updateProfile` berhenti menulisnya, data tidak diubah.
- Admin menonaktifkan tutor yang punya sesi aktif mendatang: kartu tutor menampilkan "X sesi mendatang, Y siswa terdampak",
  centang "Saya paham" wajib (divalidasi server); setelah nonaktif, siswa terdampak mendapat notifikasi (sesi tidak otomatis batal).

### B. Katalog tutor asli (TT-11 · ROADMAP 3.1)
- Sumber: `TutorProfile::verified()->active()` + mapel + akun (nama, pekerjaan) + slot open 14 hari; jenjang tutor = jenjang
  dari mapel yang diajarkan. Mock `catalog_tutors`/`tutors` dihapus dari `DashboardMockData` (landing publik tidak disentuh).
- Foto: route `dashboard.profile.photo` boleh dibuka pengguna login untuk tutor terverifikasi DAN aktif (foto siswa tetap privat).
- Filter mapel (`subjects`), jenjang (`grade_levels`), cari nama/kampus/mapel, "Ada slot kosong"; urutan Slot terdekat/Nama/Terbaru.
  Lokasi, rating, dan angka palsu dihapus → "Belum ada ulasan". Favorit localStorage kunci baru berbasis id tutor.
- Dialog: foto, nama, pekerjaan, kampus, prodi, bio, mapel, slot kosong 14 hari dengan "terisi X/Y" + "Pilih slot ini"
  (siswa belum terverifikasi: nonaktif + penjelasan). Keadaan kosong katalog harus jelas (tutor asli baru dua).
- Beranda siswa "Kenali tutor relawan": maks. 3 tutor asli atau keadaan kosong.

### C. Ajukan/batal/ganti jadwal sesi + bentrok (TT-12, TT-25 · ROADMAP 3.2)
- Migrasi aditif `learning_sessions`: `tutor_id`, `slot_id` (FK), `cancelled_by`, `cancel_reason`; index unik parsial SQLite
  `UNIQUE(slot_id, student_id) WHERE slot_id IS NOT NULL AND status IN ('pending','confirmed')`. Sesi lama tetap tampil.
- Pemesanan (`role:student` + `student.verified`): slot open, tutor terverifikasi+aktif, ≥ 3 jam lagi, mapel milik tutor,
  jenjang milik mapel. Transaksi + kursi diambil atomik:
  `UPDATE … SET booked_count = booked_count + 1, status = CASE WHEN booked_count + 1 >= capacity THEN 'full' ELSE 'open' END
  WHERE id = ? AND status = 'open' AND booked_count < capacity` → 0 baris = 409 "Slot sudah penuh". Pelanggaran unik → 409.
  Notifikasi tutor: nama siswa, mapel, topik, waktu (tanpa data privat).
- Batal (siswa): sesi cancelled, kursi bebas (status kembali open), notifikasi tutor. Sesi lama tanpa slot: perilaku lama.
- Bentrok TIDAK ditolak: peringatan merah sebelum memesan ("Bentrok dengan sesi Matematika, Rabu 15.00 WIB"), siswa boleh
  lanjut + notifikasi "Jadwal bentrok"; label merah "Bentrok" (dihitung ulang tiap muat halaman, hilang sendiri) di kartu
  Sesi Belajar, Jadwal Hari Ini/Minggu Ini, dan beranda.
- Ganti jadwal (= TT-25): `POST /dashboard/sessions/{id}/reschedule` (slot_id): sesi slot aktif mendatang → slot lain tutor yang
  sama, ada kursi, ≥ 3 jam, belum terdaftar; satu transaksi (kursi lama bebas, kursi baru atomik); notifikasi tutor.
- Privasi: siswa hanya melihat "terisi X/Y"; tutor hanya nama siswa + detail sesi (Aturan no. 11, dites).
- Tes: kapasitas 2 + tiga siswa → ketiga 409; daftar ganda → 409; UPDATE atomik 0 baris saat penuh; TES KONKUREN proses PHP
  paralel (Symfony Process) ke SQLite sementara → tepat 2 sesi (laporkan jujur bila tidak stabil di Windows); batal membebaskan
  kursi; ganti jadwal; bentrok ditandai lalu hilang; beda zona waktu. Tes lama yang diubah: `LearningSessionTest`,
  `AuthenticationTest` (katalog mock), `DashboardSessionRenderTest`, `tests/js/dashboard.test.js`, `DashboardNavigationTest`,
  `TutorRoleWorkflowTest` (availability).

### Kalau pindah ke MySQL (hal khusus SQLite)
- Index unik parsial (`… WHERE status IN (...)`) tidak didukung MySQL → pakai kolom generated, mis.
  `active_slot_key = IF(status IN ('pending','confirmed'), CONCAT(slot_id,'-',student_id), NULL)` + UNIQUE.
- SQLite menyimpan datetime sebagai teks tanpa zona; tetap simpan UTC dan set zona koneksi MySQL `+00:00`.
- UPDATE atomik dengan `CASE` + kondisi `booked_count < capacity` aman di keduanya (MySQL InnoDB mengunci baris).
- Migrasi `->after()` diabaikan SQLite (kolom ditambah di akhir); tidak masalah di MySQL.
- `config/database.php` sqlite: `transaction_mode=IMMEDIATE` + `busy_timeout=5000`. PENTING: di PHP < 8.4 Laravel MENGABAIKAN
  `transaction_mode` (selalu DEFERRED). Karena itu di `SessionBooking`/`TutorSlotController` UPDATE atomik dijadikan pernyataan
  PERTAMA dalam transaksi dan `DB::transaction(..., 5)` mencoba ulang "database is locked". Di MySQL tidak perlu.
- Konversi `convert_legacy_session_times_to_utc` khusus data SQLite lokal (sesi lama jam WIB); jangan dijalankan di DB lain.
- `FileUserProvider`/LocalUserStore (users.json) tidak terkait DB, tetap sama.

### Menunggu persetujuan pemilik
1. Konversi data lama: kurangi 7 jam dari `starts_at` sesi lama (saat ini hanya id 2), dengan backup dulu.
2. Revisi rencana di atas (cookie `tk_tz`, `booked_count` atomik, pratinjau di server, centang "Saya paham" untuk Admin).

## Rencana kerja mandiri (Tahap 2–6) — DISETUJUI pemilik 2026-11-26, lanjutkan di sesi baru
Pemilik mengizinkan kerja panjang & mandiri TANPA menunggu persetujuan per bagian, selama mengikuti keputusan di sini.
Berhenti & tanya HANYA bila ada keputusan penting yang tidak tercakup, atau risiko kehilangan/merusak data. Hal kecil:
ambil pilihan paling sederhana & aman, catat di laporan.
Aturan tiap bagian: (1) baca kode terkait, tulis rencana singkat di laporan; (2) backup `database/database.sqlite` +
`storage/app/private/users.json` sebelum migrasi/perubahan data; (3) akses dijaga di SERVER (middleware/route), Aturan no. 11
berlaku; (4) `php artisan test`, `npm test`, `npm run build` harus lulus — jangan melemahkan/menghapus tes; ubah tes lama hanya
bila perilaku sengaja berubah, catat alasannya; (5) commit terpisah per bagian, bahasa Indonesia, label "(belum diuji browser)",
JANGAN push; (6) perbarui CLAUDE.md (ROADMAP, tabel Jira, masalah, daftar cek uji browser); (7) git bundle ke D:\ setelah tiap
TAHAP; (8) bila percakapan terlalu panjang: berhenti di batas tahap, pastikan CLAUDE.md mutakhir, minta lanjut di sesi baru.

### TAHAP 2: alur sesi sampai selesai
- TT-15 (ROADMAP 3.3): tutor melihat pengajuan per slot, Terima/Tolak PER SISWA; tolak wajib alasan + kursi bebas
  (`SlotBooking::releaseSeat`). Saat menerima atau sesudahnya (sebelum mulai) tutor mengisi tautan kelas (Meet/Zoom, validasi
  URL https). "Masuk Kelas" siswa aktif hanya bila confirmed + tautan ada + ≤ `join_window_minutes` (15) sebelum mulai.
  Pengajuan tak dijawab sampai waktu mulai → "Kedaluwarsa" (dihitung saat halaman dimuat, tanpa scheduler) + kursi bebas.
  Notifikasi siswa tiap keputusan. Menu tutor tambah "Sesi Mengajar".
- TT-16 (ROADMAP 3.4): hanya setelah waktu mulai: presensi per siswa (Hadir/Tidak hadir/Izin), catatan sesi (+ catatan per
  siswa opsional), "Tandai selesai". HAPUS logika completed otomatis dari waktu (`DashboardController`, `LearningSessionController`,
  `SessionHistoryData`); sesi lewat yang belum ditandai = "Menunggu konfirmasi tutor". Siswa melihat kehadiran & catatan di Riwayat.
### TAHAP 3: tugas & materi
- TT-19 + sisa TT-17: tutor membuat tugas (judul, instruksi, lampiran opsional via `PrivateDocument`, tenggat dengan zona pengguna)
  HANYA untuk siswa yang punya sesi confirmed/completed dengannya; untuk satu siswa atau semua siswa satu slot. Daftar
  pengumpulan, unduh jawaban, nilai 0–100 + umpan balik, notifikasi siswa. Perbaiki backend `AssignmentController::create`
  (tolak siswa tanpa sesi). TT-17: tutor melihat & mengedit draf materinya; mapel/jenjang materi dari katalog (data lama tetap
  terbaca). Menu tutor tambah "Tugas & Penilaian".
### TAHAP 4: ulasan
- TT-21 (5.3): hanya sesi completed dengan siswa tercatat Hadir; satu ulasan per sesi: rating 1–5 + komentar opsional ≤500;
  bisa diubah dalam 7 hari.
- TT-22 (5.4): tutor melihat ulasan tentang dirinya (nama siswa = nama depan saja). Admin melihat semua ulasan (cari, filter),
  sembunyikan/tampilkan dengan alasan wajib + log audit. Rata-rata & jumlah di katalog hanya dari ulasan tidak tersembunyi;
  "Belum ada ulasan" bila kosong (ganti teks statis di `tutor-catalog.blade.php`).
### TAHAP 5: dashboard & keamanan
- TT-13 (6.1): grafik per role dengan data asli, gaya grafik yang ada (tanpa library baru). Admin: pengguna per role,
  verifikasi menunggu, sesi per minggu. Tutor: sesi per minggu, kehadiran, tugas belum dinilai, rating rata-rata. Siswa: tetap,
  disesuaikan data sesi baru.
- 0.2: pindahkan semua `abort_unless` role di controller ke middleware route + tes diizinkan/ditolak per role.
- 0.3: pesan error login sama untuk akun tidak ada & password salah; rate limit 5/menit per kontak+IP; tes.
### TAHAP 6: e2e & bersih-bersih
- Playwright (devDependency, `npm run test:e2e`): (1) daftar tutor → verifikasi admin; (2) siswa lengkapi profil → verifikasi;
  (3) tutor buat slot → siswa pesan → tutor terima → presensi → selesai → ulasan; (4) menu per role + logout.
  WAJIB DB & users.json SEMENTARA terpisah (mis. `.env.e2e`, sqlite sementara, `AUTH_USERS_FILE`); JANGAN PERNAH menyentuh
  `database/database.sqlite` atau `storage/app/private/users.json` asli — buktikan dengan md5 sebelum/sesudah.
- 6.2: hapus kode mati (file `[` di root, `welcome.blade.php`, `SubjectsMockData`, `components/subject-card`, sisa
  `DashboardMockData` tak terpakai termasuk `catalog_tutors`/`tutors` mock, "Test User" di DatabaseSeeder). Landing publik: ganti
  tutor palsu; statistik asli (tutor aktif, siswa, sesi selesai) + maks. 5 tutor asli yang mencentang opsi baru "Tampilkan profil
  saya di halaman depan" (default tidak); sembunyikan bagian bila kosong. Tulis ulang README.md (penjelasan, instal, jalankan,
  akun uji TANPA password, cara tes) dan perbarui FOR INFO.md.
### Laporan akhir (setelah semua tahap / saat berhenti)
Tabel commit; hasil `php artisan test`, `npm test`, `npm run test:e2e`, `npm run build`; tabel Jira + usulan pemindahan;
keputusan kecil yang diambil sendiri; daftar cek uji browser manual SINGKAT (≤15 langkah); lokasi git bundle terakhir.

## Cara kerja setiap langkah ROADMAP
1. Saat diminta "kerjakan X.Y": baca kode terkait, tunjukkan rencana singkat (file diubah/dibuat, migrasi, route & role, tes, risiko), tunggu persetujuan.
2. Setelah disetujui: backup `database/database.sqlite` sebelum migrasi, kerjakan, jalankan `php artisan test` (+ `npm run build` bila CSS/JS berubah).
3. Laporkan: apa yang berubah, hasil tes apa adanya, langkah uji manual di browser dengan akun uji, kartu Jira terkait.
4. Jangan commit sebelum diminta. Bila diminta: satu langkah ROADMAP = satu commit, pesan berbahasa Indonesia
   (mis. "Admin: kelola katalog mapel dan jenjang").
5. Usulkan dulu (dengan alasan) bila ingin mengubah urutan ROADMAP.

## Masalah yang diketahui
- Pesan login membocorkan akun terdaftar ("belum terdaftar" vs "password salah") — ROADMAP 0.3.
- Login tanpa rate limit/throttle — ROADMAP 0.3.
- Label peran siswa masih "Student" (bukan "Siswa") di topbar/sidebar/badge; belum diubah agar tes & tampilan lama stabil.
- `UploadedFile::fake()` menebak MIME dari NAMA file; untuk menguji validasi isi file (mis. .exe bernama .pdf) pakai
  `new UploadedFile($path, $name, null, null, true)` (lihat `TutorVerificationTest::realUpload`).
- Pendaftaran kini membuat baris `user_roles` saat itu juga (bukan saat login pertama); mengubah `role` di users.json
  tidak lagi mengubah role akun.
- Status sesi `completed` dihitung otomatis dari waktu, bukan ditandai tutor — ROADMAP 3.4.
- Mapel/jenjang di `learning_materials` dan `learning_sessions` disimpan sebagai teks, bukan FK; materi punya jenjang
  "Perguruan Tinggi" yang tidak ada di `grade_levels` — ROADMAP 2.3/3.2.
- Route materi/tugas/mapel belum memakai `role:` (sesi sudah) — ROADMAP 0.2. (Sidebar per role & posisi menu ⌄ sudah di 0.1.)
- Kotak cari topbar di Beranda Tutor/Admin belum punya isi untuk difilter (hanya berguna di halaman Materi/Notifikasi).
- jsdom tidak punya `innerText`; tes JS yang memicu pencarian perlu polyfill (lihat `tests/js/dashboard-roles.test.js`).
- Halaman Riwayat Sesi: kolom kanan menumpuk di atas hero/statistik pada lebar ±1440px (sudah ada sebelum 2026-11-18).
- `npm audit`: advisory ReDoS `picomatch` (high, hanya dev tooling Vite/vitest); perbaikannya butuh `--force` (breaking).
- Blade: jangan campur `@php(...)` inline dengan blok `@php … @endphp` di file yang sama (salah parse →
  "Undefined variable"). `workspace.blade.php` memakai blok.
- Tes yang mengubah role lewat `DB::table('user_roles')` harus memanggil `Auth::logout()` lalu
  `Auth::login(Auth::guard()->getProvider()->retrieveById($id))`; tanpa itu user lama (role lama) tetap di-cache.
- `AssignmentController::create` menerima student_id siapa pun — ROADMAP 4.1.
- `FileUserProvider` query role tiap request — ROADMAP 1.4. (N+1 role di daftar pengguna admin sudah jadi satu query di 0.1.)
- Kode mati: `SubjectsMockData`, `welcome.blade.php`, `components/subject-card.blade.php`, file `[` di root — ROADMAP 6.2.
- `FOR INFO.md` dan `README.md` usang — ROADMAP 6.2.
- Riwayat git masih memuat `database/recovery-20261110-115933-users.json` (email + hash); file sudah dihapus dari
  repo tanpa menulis ulang riwayat.

- Notifikasi tutor/siswa memuat jam dalam zona default (Asia/Jakarta, WIB) karena dibuat di server tanpa zona penerima.
- `DashboardMockData` masih berisi `catalog_tutors`/`tutors` mock (tidak dipakai; ditimpa data asli) — hapus di 6.2.
- Sesi yang dipindah (ganti jadwal) kembali `pending` sampai alur terima/tolak (3.3) ada.
- Status `completed` masih otomatis dari waktu sampai TT-16 (3.4).

## ATURAN WAJIB
1. Jangan pernah membuang perubahan yang belum di-commit. Dilarang `git checkout -- .`, `git reset --hard`,
   `git clean`, `git stash drop`, atau menimpa file tanpa membaca isinya dulu.
2. Jangan jalankan `migrate:fresh`, `migrate:reset`, `db:wipe`, atau apa pun yang menghapus data. Sebelum migrasi
   baru, backup dulu `database/database.sqlite` dan beri tahu pemilik proyek.
3. Jangan mengubah atau menghapus akun yang sudah ada di LocalUserStore.
4. Jangan menjalankan `php artisan tutorkita:admin`, `git commit`, atau `git push` tanpa izin.
5. Sebelum mengedit file, baca dulu isinya dan file yang terkait.
6. Setiap selesai mengubah kode, jalankan `php artisan test` (dan `npm run build` kalau CSS/JS berubah), lalu
   laporkan hasilnya apa adanya. Jangan bilang sesuatu sudah teruji kalau belum dijalankan.
7. Ikuti gaya kode yang ada: struktur folder, penamaan, komponen Blade, pesan error berbahasa Indonesia,
   dan desain warna TutorKita.
8. Setiap fitur baru harus dicek hak aksesnya per role (Admin/Tutor/Siswa), memakai middleware `EnsureRole`,
   dan punya tes.
9. Kalau permintaan ambigu atau berisiko, tanya dulu sebelum mengerjakan.
10. Saat diminta mengerjakan langkah ROADMAP, jelaskan rencana singkat dulu (file, tabel/migrasi, tes) dan tunggu persetujuan.
11. PRIVASI DATA ANAK: data profil siswa (`student_profiles`: wali, kontak wali, kecamatan/kota, sekolah, kelas,
    dokumen pendukung) HANYA boleh terlihat oleh siswa itu sendiri dan Admin. JANGAN PERNAH dikirim/ditampilkan ke tutor
    — termasuk nanti di katalog tutor, pengajuan/detail sesi, presensi, tugas, notifikasi, atau JSON apa pun. Tutor cukup
    menerima nama siswa (dan data sesi yang memang perlu). Dokumen siswa hanya lewat route yang mengecek Admin/pemilik.
    Dijaga oleh `StudentVerificationTest::test_student_private_data_never_reaches_tutors` — tambahkan asersi serupa
    setiap kali fitur baru menampilkan data siswa ke tutor. Jangan pernah meminta alamat lengkap rumah.
