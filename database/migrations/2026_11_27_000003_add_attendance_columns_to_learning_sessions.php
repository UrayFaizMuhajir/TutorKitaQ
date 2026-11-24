<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROADMAP 3.4 (TT-16): presensi per siswa, catatan sesi + catatan per siswa, dan waktu tutor menandai selesai.
 * Aditif & nullable. Status `completed` kini HANYA diisi tutor (tidak lagi dihitung otomatis dari waktu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_sessions', function (Blueprint $table): void {
            $table->string('attendance', 16)->nullable(); // present | absent | excused
            $table->text('tutor_note')->nullable();      // catatan tutor untuk siswa ini (opsional)
            $table->text('session_note')->nullable();    // catatan sesi (sama untuk semua siswa satu slot)
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('learning_sessions', function (Blueprint $table): void {
            $table->dropColumn(['attendance', 'tutor_note', 'session_note', 'completed_at']);
        });
    }
};
