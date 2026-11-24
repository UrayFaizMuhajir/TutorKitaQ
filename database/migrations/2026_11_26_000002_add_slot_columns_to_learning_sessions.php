<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROADMAP 3.2 (TT-12): sesi terhubung ke tutor asli dan slot. Aditif; sesi lama (tutor mock) tetap terbaca.
 *
 * KHUSUS SQLITE: index unik PARSIAL — satu siswa hanya boleh punya satu sesi AKTIF per slot, tetapi boleh mendaftar lagi
 * setelah membatalkan. MySQL tidak mendukung index parsial; lihat CLAUDE.md "Kalau pindah ke MySQL" (kolom generated).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('learning_sessions', function (Blueprint $table): void {
            $table->string('tutor_id', 80)->nullable()->index();
            $table->foreignId('slot_id')->nullable()->constrained('tutor_availability_slots')->nullOnDelete();
            $table->string('cancelled_by', 20)->nullable();
            $table->text('cancel_reason')->nullable();
        });
        DB::statement("CREATE UNIQUE INDEX learning_sessions_active_slot_student_unique ON learning_sessions (slot_id, student_id) WHERE slot_id IS NOT NULL AND status IN ('pending', 'confirmed')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS learning_sessions_active_slot_student_unique');
        Schema::table('learning_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('slot_id');
            $table->dropIndex(['tutor_id']);
            $table->dropColumn(['tutor_id', 'cancelled_by', 'cancel_reason']);
        });
    }
};
