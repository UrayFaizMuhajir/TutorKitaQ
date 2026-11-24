<?php

namespace App\Support;

use App\Models\LearningSession;
use App\Models\TutorAvailabilitySlot;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Presensi, catatan, dan "Tandai selesai" (ROADMAP 3.4 · TT-16). Hanya setelah waktu mulai, hanya sesi confirmed di slot
 * milik tutor. `completed` HANYA berasal dari sini. PRIVASI (Aturan no. 11): tutor hanya melihat nama siswa.
 */
final class SessionCompletion
{
    /**
     * @param  array<int|string, ?string>  $attendance  id sesi → present|absent|excused
     * @param  array<int|string, ?string>  $notes       id sesi → catatan per siswa (opsional)
     */
    public static function record(TutorAvailabilitySlot $slot, string $tutorName, array $attendance, array $notes, ?string $sessionNote, bool $complete): void
    {
        if ($slot->starts_at->isFuture()) throw new HttpException(422, 'Presensi baru bisa diisi setelah sesi dimulai.');
        $sessionNote = self::clean($sessionNote);

        $completed = DB::transaction(function () use ($slot, $attendance, $notes, $sessionNote, $complete): array {
            $sessions = LearningSession::query()->where('slot_id', $slot->id)->where('tutor_id', (string) $slot->tutor_id)
                ->where('status', 'confirmed')->orderBy('id')->get();
            if ($sessions->isEmpty()) throw new HttpException(422, 'Tidak ada sesi terkonfirmasi yang bisa dipresensi di slot ini.');

            $changes = [];
            foreach ($sessions as $session) {
                $mark = $attendance[$session->id] ?? $session->attendance;
                if ($complete && ! isset(LearningSession::ATTENDANCE_LABELS[$mark ?? ''])) {
                    throw new HttpException(422, 'Isi presensi semua siswa sebelum menandai sesi selesai.');
                }
                $changes[$session->id] = [
                    'attendance' => $mark ?: null,
                    'tutor_note' => array_key_exists($session->id, $notes) ? self::clean($notes[$session->id]) : $session->tutor_note,
                    'session_note' => $sessionNote,
                    'updated_at' => now(),
                ] + ($complete ? ['status' => 'completed', 'completed_at' => now()] : []);
            }
            foreach ($changes as $id => $values) {
                // Bersyarat pada status agar pembatalan/penyelesaian serentak tidak tertimpa.
                $updated = LearningSession::query()->whereKey($id)->where('status', 'confirmed')->update($values);
                if ($updated !== 1) throw new HttpException(422, 'Data sesi berubah saat disimpan. Muat ulang halaman lalu coba lagi.');
            }

            return $complete ? $sessions->all() : [];
        }, 5);

        foreach ($completed as $session) {
            $session->refresh();
            Notifier::send((string) $session->student_id, 'session-'.$session->id.'-completed', 'Sesi selesai',
                [$tutorName.' menandai sesi '.$session->subject.' pada ', Notifier::slot($session->starts_at, $session->endsAt()),
                    ' selesai. Kehadiranmu: '.$session->attendanceLabel().'. Catatan tutor bisa dilihat di Riwayat Sesi.'],
                '/dashboard#history');
        }
    }

    /** Sesi confirmed yang berakhir lebih dari `completion_reminder_hours` lalu dan belum ditandai selesai. */
    public static function isOverdue(LearningSession $session, ?\Carbon\CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $session->status === 'confirmed'
            && $session->endsAt()->addHours((int) config('tutorkita.completion_reminder_hours'))->lessThan($now);
    }

    /**
     * Pengingat tutor (dihitung saat halaman dimuat, tanpa scheduler): satu notifikasi per sesi terlambat —
     * kunci notifikasi unik per sesi menjamin tidak terkirim dua kali. Isi tanpa data profil siswa (Aturan no. 11).
     *
     * @return int jumlah sesi terlambat yang diperiksa
     */
    public static function remindOverdue(): int
    {
        $now = now();
        // Pra-saring kasar di SQL (mulai ≤ batas), lalu cek tepat dengan jam selesai di PHP.
        $limit = $now->copy()->utc()->subHours((int) config('tutorkita.completion_reminder_hours'))->format('Y-m-d H:i:s');
        $overdue = LearningSession::query()->where('status', 'confirmed')->whereNotNull('tutor_id')->whereNotNull('slot_id')
            ->where('starts_at', '<', $limit)->orderBy('id')->limit(200)->get()
            ->filter(fn (LearningSession $session): bool => self::isOverdue($session, $now));
        $overdue->each(fn (LearningSession $session) => Notifier::send((string) $session->tutor_id, 'session-'.$session->id.'-completion-reminder',
            'Pengingat: tandai sesi selesai',
            ['Jangan lupa isi presensi dan tandai selesai sesi '.$session->subject.' – '.$session->topic.' pada ', Notifier::slot($session->starts_at, $session->endsAt()), '. Siswa masih melihat status "Menunggu Konfirmasi Tutor".'],
            '/dashboard#teaching'));

        return $overdue->count();
    }

    private static function clean(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
