<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Models\UserNotification;
use App\Support\LocalUserStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** TT-10 / ROADMAP 5.2: admin menyetujui atau menolak verifikasi siswa (route `role:admin`). */
class AdminStudentController extends Controller
{
    public function decide(Request $request, StudentProfile $studentProfile, LocalUserStore $users): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:5', 'max:2000'],
        ], ['reason.required_if' => 'Alasan penolakan wajib diisi.', 'reason.min' => 'Alasan penolakan minimal 5 karakter.']);
        $account = $users->findById((string) $studentProfile->user_id);
        abort_unless($account, 404, 'Akun siswa tidak ditemukan.');
        $approved = $data['decision'] === 'approve';
        $reason = $approved ? null : trim((string) $data['reason']);
        $adminId = (string) $request->user()->getAuthIdentifier();

        DB::transaction(function () use ($studentProfile, $approved, $reason, $adminId): void {
            $profile = StudentProfile::query()->whereKey($studentProfile->id)->lockForUpdate()->firstOrFail();
            abort_unless($profile->status === 'pending', 409, 'Pengajuan verifikasi ini sudah diproses.');
            $profile->update([
                'status' => $approved ? 'verified' : 'rejected',
                'rejection_reason' => $reason,
                'reviewed_by' => $adminId, 'reviewed_at' => now(), 'verified_at' => $approved ? now() : null,
            ]);
            $review = $profile->reviews()->create([
                'actor_id' => $adminId, 'action' => $approved ? 'approve' : 'reject',
                'from_status' => 'pending', 'to_status' => $profile->status, 'reason' => $reason,
            ]);
            UserNotification::query()->create([
                'user_id' => (string) $profile->user_id,
                'notification_key' => 'student-verification-'.$review->id,
                'category' => 'system',
                'title' => $approved ? 'Profil siswa terverifikasi' : 'Verifikasi profil siswa ditolak',
                'body' => $approved
                    ? 'Selamat, profilmu sudah diverifikasi admin. Kamu sekarang bisa mengajukan sesi belajar.'
                    : 'Admin menolak verifikasi profilmu. Alasan: '.$reason.' Perbaiki data lalu kirim ulang.',
                'action_url' => $approved ? '/dashboard#sessions' : '/dashboard#student-verification',
            ]);
        });

        $name = (string) ($account['name'] ?? 'Siswa');

        return redirect('/dashboard#student-verifications')->with('status', $approved ? $name.' terverifikasi.' : 'Verifikasi '.$name.' ditolak.');
    }
}
