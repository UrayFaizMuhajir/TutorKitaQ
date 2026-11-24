<?php

namespace App\Http\Middleware;

use App\Support\StudentVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `student.verified`: siswa wajib terverifikasi sebelum mengajukan sesi (ROADMAP 5.1).
 * Materi, Mata Pelajaranku, dan tugas tetap bisa dipakai sebelum terverifikasi.
 */
class EnsureVerifiedStudent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->role ?? null) === 'student', 403, 'Kamu tidak memiliki akses untuk tindakan ini.');
        abort_unless(
            StudentVerification::isVerified((string) $user->getAuthIdentifier()),
            403,
            'Profil siswamu belum terverifikasi. Lengkapi profil dan dokumen pendukung di halaman Profil & Verifikasi, lalu tunggu persetujuan admin.',
        );

        return $next($request);
    }
}
