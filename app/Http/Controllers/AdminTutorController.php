<?php

namespace App\Http\Controllers;

use App\Models\TutorProfile;
use App\Models\TutorStatusChange;
use App\Models\UserNotification;
use App\Support\LocalUserStore;
use App\Support\TutorVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** ROADMAP 1.3: admin menonaktifkan / mengaktifkan kembali tutor terverifikasi (route `role:admin`). */
class AdminTutorController extends Controller
{
    public function updateStatus(Request $request, string $userId, LocalUserStore $users): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['deactivate', 'activate'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'action.in' => 'Aksi tidak dikenal.',
            'reason.required' => 'Alasan wajib diisi.',
            'reason.min' => 'Alasan minimal 5 karakter.',
            'reason.max' => 'Alasan maksimal 2000 karakter.',
        ]);
        $account = $users->findById($userId);
        abort_unless($account, 404, 'Akun tutor tidak ditemukan.');
        abort_unless(DB::table('user_roles')->where('user_id', $userId)->value('role') === 'tutor', 404, 'Akun ini bukan tutor.');
        abort_unless(TutorVerification::isVerified($userId), 409, 'Hanya tutor terverifikasi yang dapat dinonaktifkan atau diaktifkan.');
        $deactivate = $data['action'] === 'deactivate';
        $reason = trim($data['reason']);
        $adminId = (string) $request->user()->getAuthIdentifier();
        // Sesi aktif mendatang milik tutor: admin wajib mengonfirmasi dampaknya; siswa terdampak diberi notifikasi.
        $impacted = $deactivate ? self::upcomingActiveSessions($userId) : collect();
        if ($impacted->isNotEmpty() && ! $request->boolean('confirm_impact')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['confirm_impact' => 'Tutor ini masih punya '.$impacted->count().' sesi mendatang dengan '
                .$impacted->pluck('student_id')->unique()->count().' siswa. Centang "Saya paham" untuk melanjutkan.']);
        }

        DB::transaction(function () use ($userId, $deactivate, $reason, $adminId, $impacted, $account): void {
            $profile = TutorProfile::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            abort_if($deactivate && ! $profile->is_active, 409, 'Tutor ini sudah nonaktif.');
            abort_if(! $deactivate && $profile->is_active, 409, 'Tutor ini sudah aktif.');
            $profile->update(['is_active' => ! $deactivate]);
            $change = TutorStatusChange::query()->create([
                'user_id' => $userId, 'actor_id' => $adminId, 'action' => $deactivate ? 'deactivate' : 'activate', 'reason' => $reason,
            ]);
            UserNotification::query()->create([
                'user_id' => $userId,
                'notification_key' => 'tutor-status-'.$change->id,
                'category' => 'system',
                'title' => $deactivate ? 'Akun tutor dinonaktifkan' : 'Akun tutor diaktifkan kembali',
                'body' => ($deactivate
                    ? 'Admin menonaktifkan akun tutormu. Kamu belum bisa mengelola materi dan tugas. Alasan: '
                    : 'Admin mengaktifkan kembali akun tutormu. Kamu bisa mengelola materi dan tugas lagi. Alasan: ').$reason,
                'action_url' => '/dashboard',
            ]);
            // Siswa yang punya sesi mendatang dengan tutor ini diberi kabar (sesi tidak otomatis dibatalkan).
            $impacted->each(fn (\App\Models\LearningSession $session) => \App\Support\Notifier::send(
                (string) $session->student_id, 'tutor-deactivated-'.$change->id.'-session-'.$session->id, 'Tutor sesi kamu dinonaktifkan',
                [($account['name'] ?? 'Tutor').' sedang dinonaktifkan admin. Sesi '.$session->subject.' pada ',
                    \App\Support\Notifier::slot($session->starts_at, $session->endsAt()), ' mungkin tidak berjalan; tunggu kabar atau ganti jadwal.'],
                '/dashboard#sessions',
            ));
        });

        $name = (string) ($account['name'] ?? 'Tutor');
        if ($impacted->isNotEmpty()) $name .= ' ('.$impacted->pluck('student_id')->unique()->count().' siswa terdampak diberi notifikasi)';

        return redirect('/dashboard?admin_tab=tutors#tutor-verifications')->with('status', $deactivate ? $name.' dinonaktifkan.' : $name.' diaktifkan kembali.');
    }
    /** Sesi aktif (pending/confirmed) tutor yang belum dimulai. */
    public static function upcomingActiveSessions(string $tutorId): \Illuminate\Support\Collection
    {
        return \App\Models\LearningSession::query()->active()->where('tutor_id', $tutorId)
            ->where('starts_at', '>', now()->utc()->format('Y-m-d H:i:s'))->orderBy('starts_at')->get();
    }
}
