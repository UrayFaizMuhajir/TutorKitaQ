<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROADMAP 5.1/5.2 (TT-9, TT-10): profil & dokumen pendukung siswa + log verifikasi. Aditif (tabel baru).
 * Tanpa backfill: akun siswa lama wajib melengkapi profil dulu (keputusan pemilik 2026-11-25).
 *
 * PRIVASI DATA ANAK: data di sini (wali, kontak wali, kecamatan/kota, dokumen) hanya untuk siswa itu
 * sendiri dan Admin — tidak pernah ditampilkan ke tutor. Sengaja hanya "Kecamatan / Kota", bukan alamat rumah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('user_id', 80)->unique();
            $table->string('school', 180)->nullable();
            $table->foreignId('grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $table->string('class_name', 20)->nullable();
            $table->string('guardian_name', 120)->nullable();
            $table->string('guardian_contact', 60)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('document_kind', 30)->nullable();
            $table->string('document_path')->nullable();
            $table->string('status', 20)->default('incomplete')->index();
            $table->text('rejection_reason')->nullable();
            $table->string('reviewed_by', 80)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('student_verification_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->string('actor_id', 80)->nullable()->index();
            $table->string('action', 24);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_verification_reviews');
        Schema::dropIfExists('student_profiles');
    }
};
