<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Konversi data lama (disetujui pemilik 2026-11-25): sesi lama (tutor mock, tanpa slot) menyimpan starts_at sebagai
 * jam dinding WIB dari input datetime-local. Sejak zona waktu per pengguna, DB menyimpan UTC → kurangi 7 jam.
 * Kriteria stabil: sesi baru selalu punya slot_id/tutor_id. Backup: database/backup-*-sebelum-tahap1/.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('learning_sessions')->whereNull('slot_id')->whereNull('tutor_id')->orderBy('id')->get(['id', 'starts_at'])
            ->each(fn ($row) => DB::table('learning_sessions')->where('id', $row->id)->update([
                'starts_at' => \Carbon\CarbonImmutable::parse($row->starts_at, 'UTC')->subHours(7)->format('Y-m-d H:i:s'),
            ]));
    }

    public function down(): void
    {
        DB::table('learning_sessions')->whereNull('slot_id')->whereNull('tutor_id')->orderBy('id')->get(['id', 'starts_at'])
            ->each(fn ($row) => DB::table('learning_sessions')->where('id', $row->id)->update([
                'starts_at' => \Carbon\CarbonImmutable::parse($row->starts_at, 'UTC')->addHours(7)->format('Y-m-d H:i:s'),
            ]));
    }
};
