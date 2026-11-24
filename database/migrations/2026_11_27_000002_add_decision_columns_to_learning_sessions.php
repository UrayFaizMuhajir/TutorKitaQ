<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROADMAP 3.3 (TT-15): keputusan tutor atas pengajuan sesi. Aditif & nullable.
 * Status baru (kolom teks, tanpa enum): `rejected` (ditolak tutor) dan `expired` (tak dijawab sampai waktu mulai).
 * Index unik parsial slot+siswa hanya mencakup pending/confirmed, jadi siswa boleh memesan ulang setelah ditolak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_sessions', function (Blueprint $table): void {
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learning_sessions', function (Blueprint $table): void {
            $table->dropColumn(['decided_at', 'rejection_reason']);
        });
    }
};
