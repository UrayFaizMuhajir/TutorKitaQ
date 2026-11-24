<?php

namespace App\Http\Middleware;

use App\Support\TutorVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `tutor.verified`: hanya tutor yang pengajuannya sudah disetujui admin.
 * Dipakai untuk aksi tutor yang mengubah data (materi, tugas). Menyembunyikan tombol saja tidak cukup.
 */
class EnsureVerifiedTutor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->role ?? null) === 'tutor', 403, 'Fitur ini khusus akun tutor.');
        abort_unless(
            TutorVerification::isVerified((string) $user->getAuthIdentifier()),
            403,
            'Akun tutormu belum terverifikasi. Lengkapi profil dan kirim kartu mahasiswa untuk diverifikasi admin.',
        );

        return $next($request);
    }
}
