<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROADMAP 1.3: log audit nonaktif/aktif tutor oleh admin. Aditif (tabel baru).
 * Status aktif tetap di tutor_profiles.is_active; alasan yang tampil diambil dari log terakhir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutor_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 80)->index();
            $table->string('actor_id', 80)->nullable()->index();
            $table->string('action', 20);
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutor_status_changes');
    }
};
