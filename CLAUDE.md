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
- `storage/app/private/*` lainnya — unggahan (foto profil, PDF materi, jawaban tugas).
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
- Langkah berikutnya: ROADMAP 0.1 (tunjukkan rencana dulu, tunggu persetujuan).

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
- 0.1 Tampilan per role (sidebar, beranda, menu; satu sumber definisi menu per role) — **Belum** (hanya menu profil yang beda).
- 0.2 Pengecekan role via middleware `role:` di route sesi/materi/tugas/mapel + tes diizinkan/ditolak — **Sebagian** (pengajuan tutor, keputusan admin, dan pengajuan/pembatalan sesi `role:student` + tes; materi/tugas/mapel belum).
- 0.3 Keamanan login: pesan error seragam + rate limit (mis. 5/menit per kontak+IP) — **Belum**.

### Tahap 1: Admin
- 1.1 Katalog mapel & jenjang (CRUD, jenjang per mapel, cari; mapel terpakai hanya diarsip) — **Belum** (mapel hanya dari seeder).
- 1.2 Pengajuan tutor lengkap (unggah kartu mahasiswa privat; daftar + filter + cari; detail & pratinjau aman; setujui/tolak) — **Sebagian** (setujui/tolak ada di `TutorApplicationController::decide`; perluas, jangan tulis ulang).
- 1.3 Kelola tutor (daftar, nonaktif/aktif + alasan + audit; tutor nonaktif tak bisa kelola materi/tugas; notifikasi) — **Belum** (`updateProfile` memaksa `is_active = true`).
- 1.4 Kelola pengguna (daftar, cari, filter role, detail; nonaktifkan akun = tak bisa login; perbaiki N+1) — **Sebagian** (dialog daftar read-only, N+1).

### Tahap 2: Tutor
- 2.1 Profil tutor (mapel dari katalog 1.1, foto bisa dilihat siswa, status jelas) — **Sebagian**.
- 2.2 Slot ketersediaan (tabel baru, CRUD, validasi bentrok, migrasi/usangkan `availability`; terpisah dari `learning_sessions`) — **Belum**.
- 2.3 Materi (tutor lihat miliknya termasuk draf; mapel/jenjang dari katalog; data lama tetap terbaca; buang jenjang di luar `grade_levels`) — **Sebagian** (CRUD materi selesai; draf tak terlihat; mapel/jenjang teks).

### Tahap 3: Alur sesi (urutan wajib)
- 3.1 Katalog tutor asli + slot kosong (ganti `DashboardMockData`; filter dari DB) — **Sebagian** (UI ada, data mock).
- 3.2 Ajukan/batalkan sesi (`tutor_id`, `slot_id` aditif; anti pesan ganda dalam transaksi; hanya Siswa) — **Sebagian** (store/cancel ke tutor mock; "hanya Siswa" sudah).
- 3.3 Tutor terima/tolak pengajuan + alasan + notifikasi siswa — **Belum**.
- 3.4 Presensi, catatan, tandai selesai (status `completed` HANYA dari tutor) — **Belum**.

### Tahap 4: Tugas & materi untuk siswa
- 4.1 UI tutor untuk tugas (hanya siswa yang pernah sesi dengannya; daftar pengumpulan; unduh; nilai + umpan balik) — **Sebagian** (backend `create`/`grade` saja).
- 4.2 Siswa mengerjakan tugas & melihat nilai (sesuaikan dengan 4.1) — **Sebagian besar ada**.
- 4.3 Siswa melihat/mengunduh materi + gate role — **Ada, tanpa gate role**.

### Tahap 5: Siswa
- 5.1 Profil siswa lengkap + dokumen pendukung privat — **Belum**. Sebelum mulai, TANYAKAN apakah verifikasi wajib sebelum memesan sesi (kalau ya, 5.1–5.2 sebelum Tahap 3).
- 5.2 Admin memverifikasi siswa — **Belum**.
- 5.3 Rating & ulasan (hanya sesi selesai dari 3.4, satu per sesi) — **Belum**.
- 5.4 Tutor lihat ulasan; Admin sembunyikan/tampilkan + alasan; rating katalog dari ulasan tak tersembunyi — **Belum**.

### Tahap 6: Penutup
- 6.1 Dashboard ringkasan & grafik per role dengan data asli — **Sebagian** (siswa saja).
- 6.2 Bersih-bersih kode mati, DatabaseSeeder "Test User", README.md, FOR INFO.md — **Belum**.

## Kartu Jira (TT Sprint 1; status Jira per 2026-11-24, status kode per audit 2026-11-24)
Kode tetap acuan. ✅ selesai, 🟡 sebagian, ❌ belum. TT-14, TT-23, TT-24 tidak ada di board — abaikan.

| Kartu | Judul | Jira | Kode | ROADMAP |
|---|---|---|---|---|
| TT-2 | Fitur login dan logout | Done | ✅ (keamanan di 0.3) | 0.3 |
| TT-3 | Menambah dan mengubah mapel & jenjang (= "Mata Pelajaranku" siswa) | Done | ✅ | — (selesai) |
| TT-5 | Melihat, mencari, dan menghapus mapel (= "Mata Pelajaranku" siswa) | Done | ✅ | — (selesai) |
| TT-4 | [Tutor] Melengkapi profil dan mengunggah kartu mahasiswa | Done | 🟡 tanpa kartu mahasiswa | 1.2, 2.1 |
| TT-6 | [Admin] Menyetujui, menolak, atau menonaktifkan tutor | Done | 🟡 tanpa nonaktifkan | 1.2, 1.3 |
| TT-9 | [Siswa] Melengkapi profil dan mengunggah dokumen pendukung | Done | ❌ | 5.1 |
| TT-10 | [Admin] Melihat, mencari, dan memverifikasi siswa | To Do | ❌ (hanya daftar read-only) | 1.4, 5.2 |
| TT-7 | [Tutor] Menambah slot jadwal ketersediaan | In Progress | ❌ (hanya teks `availability`) | 2.2 |
| TT-8 | [Tutor] Melihat, mengubah, dan menghapus slot jadwal | In Progress | ❌ | 2.2 |
| TT-11 | [Siswa] Mencari tutor serta melihat profil dan slot kosong | Done | 🟡 UI dengan data mock | 3.1 |
| TT-12 | [Siswa] Mengajukan dan membatalkan pengajuan sesi | To Do | 🟡 ke tutor mock | 3.2 |
| TT-15 | [Tutor] Menerima atau menolak pengajuan sesi | To Do | ❌ | 3.3 |
| TT-16 | [Tutor] Presensi & catatan, menandai sesi selesai | To Do | ❌ | 3.4 |
| TT-17 | [Tutor] Mengunggah, mengubah, dan menghapus materi | In Progress | 🟡 CRUD ada; draf tak terlihat, mapel teks | 2.3 |
| TT-18 | [Siswa] Melihat dan mengunduh materi | To Do | 🟡 berfungsi, tanpa gate role | 4.3 |
| TT-19 | [Tutor] Membuat tugas dan menilai pengumpulan | To Do | 🟡 backend saja | 4.1 |
| TT-20 | [Siswa] Mengumpulkan tugas dan melihat nilai | To Do | 🟡 sebagian besar ada | 4.2 |
| TT-21 | [Siswa] Rating dan ulasan untuk sesi selesai | To Do | ❌ | 5.3 |
| TT-22 | [Tutor/Admin] Melihat ulasan, menyembunyikan ulasan | To Do | ❌ | 5.4 |
| TT-13 | [Semua role] Dashboard ringkasan dan grafik | Done | 🟡 siswa saja | 6.1 (+0.1) |
| TT-25 | [Siswa] Menambah, mengubah, menghapus jadwal pelajaran | Done | ❌ (jadwal hanya dari `learning_sessions`) | belum ada; tanyakan maksudnya |

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
- `updateProfile` selalu mengisi `tutor_profiles.is_active = true` — ROADMAP 1.3.
- Status sesi `completed` dihitung otomatis dari waktu, bukan ditandai tutor — ROADMAP 3.4.
- Mapel/jenjang di `learning_materials` dan `learning_sessions` disimpan sebagai teks, bukan FK; materi punya jenjang
  "Perguruan Tinggi" yang tidak ada di `grade_levels` — ROADMAP 2.3/3.2.
- Sidebar belum menyesuaikan role; route materi/tugas/mapel belum memakai `role:` (sesi sudah) — ROADMAP 0.1/0.2.
- Tombol ⌄ sidebar membuka menu profil yang posisinya di kanan atas (topbar), bukan di dekat tombolnya — ROADMAP 0.1.
- Halaman Riwayat Sesi: kolom kanan menumpuk di atas hero/statistik pada lebar ±1440px (sudah ada sebelum 2026-11-18).
- `npm audit`: advisory ReDoS `picomatch` (high, hanya dev tooling Vite/vitest); perbaikannya butuh `--force` (breaking).
- Blade: jangan campur `@php(...)` inline dengan blok `@php … @endphp` di file yang sama (salah parse →
  "Undefined variable"). `workspace.blade.php` memakai blok.
- Tes yang mengubah role lewat `DB::table('user_roles')` harus memanggil `Auth::logout()` lalu
  `Auth::login(Auth::guard()->getProvider()->retrieveById($id))`; tanpa itu user lama (role lama) tetap di-cache.
- `AssignmentController::create` menerima student_id siapa pun — ROADMAP 4.1.
- Query N+1 di daftar pengguna admin; `FileUserProvider` query role tiap request — ROADMAP 1.4.
- Kode mati: `SubjectsMockData`, `welcome.blade.php`, `components/subject-card.blade.php`, file `[` di root — ROADMAP 6.2.
- `FOR INFO.md` dan `README.md` usang — ROADMAP 6.2.
- Riwayat git masih memuat `database/recovery-20261110-115933-users.json` (email + hash); file sudah dihapus dari
  repo tanpa menulis ulang riwayat.

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
