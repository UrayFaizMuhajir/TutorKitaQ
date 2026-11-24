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
- Langkah berikutnya (urutan Jira): uji browser gabungan → lalu TT-7/TT-8 (ROADMAP 2.2 slot tutor) atau 0.2 sisa — rencana dulu.

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

## Kartu Jira (TT Sprint 1; status Jira per 2026-11-24, status kode per audit 2026-11-24)
Kode tetap acuan. ✅ selesai, 🟡 sebagian, ❌ belum. TT-14, TT-23, TT-24 tidak ada di board — abaikan.

| Kartu | Judul | Jira | Kode | ROADMAP |
|---|---|---|---|---|
| TT-2 | Fitur login dan logout | Done | ✅ (keamanan di 0.3) | 0.3 |
| TT-3 | Menambah dan mengubah mapel & jenjang (= "Mata Pelajaranku" siswa) | Done | ✅ | — (selesai) |
| TT-5 | Melihat, mencari, dan menghapus mapel (= "Mata Pelajaranku" siswa) | Done | ✅ | — (selesai) |
| TT-4 | [Tutor] Melengkapi profil dan mengunggah kartu mahasiswa | Done | ✅ (2026-11-24, uji browser tertunda) | 1.2 |
| TT-6 | [Admin] Menyetujui, menolak, atau menonaktifkan tutor | Done | ✅ (2026-11-25, uji browser tertunda) | 1.2, 1.3 |
| TT-9 | [Siswa] Melengkapi profil dan mengunggah dokumen pendukung | Done | ✅ (2026-11-25, uji browser tertunda) | 5.1 |
| TT-10 | [Admin] Melihat, mencari, dan memverifikasi siswa | To Do | ✅ (2026-11-25, uji browser tertunda) | 5.2 |
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
