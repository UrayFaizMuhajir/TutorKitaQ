<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROADMAP 2.2 (TT-7, TT-8): slot ketersediaan tutor, terpisah dari learning_sessions. Aditif (tabel baru).
 * Waktu disimpan UTC. Satu slot untuk banyak siswa: capacity 1–20, booked_count dinaikkan secara atomik.
 * status: open (masih ada kursi) | full | cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_availability_slots', function (Blueprint $table): void {
            $table->id();
            $table->string('tutor_id', 80)->index();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedTinyInteger('capacity')->default(20);
            $table->unsignedTinyInteger('booked_count')->default(0);
            $table->string('status', 20)->default('open')->index();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['tutor_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_availability_slots');
    }
};
