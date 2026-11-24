<?php

namespace App\Http\Middleware;

use App\Models\TutorStatusChange;
use App\Support\TutorVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `tutor.verified`: hanya tutor yang pengajuannya disetujui admin DAN tidak sedang dinonaktifkan.
 * Dipakai untuk aksi tutor yang mengubah data (materi, tugas). Menyembunyikan tombol saja tidak cukup.
 */
class EnsureVerifiedTutor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->role ?? null) === 'tutor', 403, 'Fitur ini khusus akun tutor.');
        $userId = (string) $user->getAuthIdentifier();
        abort_unless(
            TutorVerification::isVerified($userId),
            403,
            'Akun tutormu belum terverifikasi. Lengkapi profil dan kirim kartu mahasiswa untuk diverifikasi admin.',
        );
        if (! TutorVerification::isActive($userId)) {
            $reason = TutorStatusChange::latestFor($userId)?->reason;
            abort(403, 'Akun tutormu sedang dinonaktifkan admin.'.($reason ? ' Alasan: '.$reason : ''));
        }

        return $next($request);
    }
}
