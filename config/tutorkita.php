<?php

/*
| Pengaturan khusus TutorKita (slot, pemesanan sesi, zona waktu).
| Semua waktu di database disimpan dalam UTC; tampilan mengikuti zona waktu pengguna.
*/
return [
    // Zona waktu cadangan bila browser tidak mengirim zona (cookie tk_tz / field timezone).
    'default_timezone' => 'Asia/Jakarta',

    // Siswa paling lambat memesan / memindahkan jadwal sekian menit sebelum slot dimulai.
    'booking_lead_minutes' => 180,

    // Slot ketersediaan tutor.
    'slot_min_minutes' => 30,
    'slot_max_minutes' => 180,
    'slot_max_capacity' => 20,
    'slot_max_repeat_weeks' => 12,

    // Katalog menampilkan slot kosong sekian hari ke depan.
    'catalog_days' => 14,

    // Tombol "Masuk Kelas" aktif sekian menit sebelum sesi dimulai.
    'join_window_minutes' => 15,

    // Tutor diingatkan sekali bila sesi confirmed belum ditandai selesai sekian jam setelah jam selesainya.
    'completion_reminder_hours' => 24,
];
