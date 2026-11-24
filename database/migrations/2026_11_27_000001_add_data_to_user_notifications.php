<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isi notifikasi terstruktur: potongan teks + waktu kejadian (UTC) agar jam dirender di zona waktu pembaca.
 * Aditif & nullable: notifikasi lama (data NULL) tetap tampil memakai kolom `body` apa adanya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->json('data')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->dropColumn('data');
        });
    }
};
