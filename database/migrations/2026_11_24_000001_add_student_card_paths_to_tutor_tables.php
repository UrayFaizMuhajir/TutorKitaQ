<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verifikasi tutor (ROADMAP 1.2): lokasi file kartu mahasiswa (disk privat `local`).
 *
 * Aditif saja: dua kolom nullable, tanpa mengubah baris yang ada. Status verifikasi tetap diambil dari
 * tutor_applications.status (approved = terverifikasi), jadi tutor lama yang pengajuannya sudah approved
 * otomatis terverifikasi tanpa backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_applications', function (Blueprint $table): void {
            // Salinan kartu yang diperiksa admin saat pengajuan dikirim.
            $table->string('student_card_path')->nullable()->after('avatar_path');
        });
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            // Kartu draf milik tutor yang mendaftar sendiri; terkunci setelah terverifikasi.
            $table->string('student_card_path')->nullable()->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->dropColumn('student_card_path');
        });
        Schema::table('tutor_applications', function (Blueprint $table): void {
            $table->dropColumn('student_card_path');
        });
    }
};
