<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dokumen privat (kartu mahasiswa tutor, dokumen pendukung siswa): satu pola keamanan.
 * - Validasi dari ISI file (mimetypes), JPG/PNG/PDF, maksimal 2 MB.
 * - Disimpan di disk privat `local` (storage/app/private), tidak pernah di folder publik.
 * - Disajikan hanya lewat route yang sudah mengecek Admin/pemilik, dengan header nosniff + CSP sandbox + no-store.
 */
final class PrivateDocument
{
    public const MAX_KILOBYTES = 2048;

    public static function rules(): array
    {
        return ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'mimetypes:image/jpeg,image/png,application/pdf', 'max:'.self::MAX_KILOBYTES];
    }

    public static function messages(string $field, string $label): array
    {
        return [
            $field.'.required' => 'Unggah '.$label.' (JPG, PNG, atau PDF).',
            $field.'.file' => 'Unggah '.$label.' (JPG, PNG, atau PDF).',
            $field.'.mimes' => ucfirst($label).' harus berupa JPG, PNG, atau PDF.',
            $field.'.mimetypes' => ucfirst($label).' harus berupa JPG, PNG, atau PDF.',
            $field.'.max' => 'Ukuran '.$label.' maksimal 2 MB.',
            $field.'.uploaded' => ucfirst($label).' gagal diunggah. Pastikan ukurannya maksimal 2 MB.',
        ];
    }

    public static function store(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, 'local');
    }

    public static function isPdf(?string $path): bool
    {
        return is_string($path) && str_ends_with(strtolower($path), '.pdf');
    }

    public static function response(?string $path, string $downloadBaseName): StreamedResponse
    {
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404, 'Dokumen tidak ditemukan.');

        return Storage::disk('local')->response($path, $downloadBaseName.'.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Cegah skrip di dalam PDF/berkas berjalan dengan origin aplikasi.
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ], 'inline');
    }
}
