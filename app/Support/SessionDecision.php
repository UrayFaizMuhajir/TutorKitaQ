<?php

namespace App\Support;

use App\Models\LearningSession;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Keputusan tutor atas pengajuan sesi (ROADMAP 3.3 · TT-15): terima, tolak (kursi bebas), tautan kelas, kedaluwarsa.
 * Setiap perubahan status memakai UPDATE bersyarat (status lama + belum mulai) sehingga dua aksi serentak tidak bentrok.
 * PRIVASI (Aturan no. 11): notifikasi ke siswa hanya berisi data sesi dan nama tutor.
 */
final class SessionDecision
{
    /** Terima satu pengajuan. Tautan kelas opsional (bisa diisi menyusul sebelum sesi mulai). */
    public static function accept(LearningSession $session, string $tutorName, ?string $meetingUrl): void
    {
        $updated = LearningSession::query()->whereKey($session->id)->where('status', 'pending')
            ->where('starts_at', '>', self::nowUtc())
            ->update(array_filter([
                'status' => 'confirmed', 'decided_at' => now(), 'updated_at' => now(),
                'meeting_url' => $meetingUrl, 'meeting_platform' => $meetingUrl ? self::platform($meetingUrl) : null,
            ], fn ($value): bool => $value !== null));
        if ($updated !== 1) throw new HttpException(422, 'Pengajuan ini sudah tidak bisa diterima (sudah dijawab, dibatalkan, atau waktunya lewat).');

        Notifier::send((string) $session->student_id, 'session-'.$session->id.'-accepted-'.now()->format('YmdHisu'), 'Sesi diterima tutor',
            [$tutorName.' menerima sesi '.$session->subject.' pada ', Notifier::slot($session->starts_at, $session->endsAt()), '. '
                .($meetingUrl ? 'Tautan kelas sudah tersedia; tombol Masuk Kelas aktif '.self::joinWindow().' menit sebelum mulai.' : 'Tautan kelas akan dibagikan tutor sebelum sesi dimulai.')],
            '/dashboard#sessions');
    }

    /** Tolak satu pengajuan: alasan wajib, kursi slot dilepas. */
    public static function reject(LearningSession $session, string $tutorName, string $reason): void
    {
        DB::transaction(function () use ($session, $reason): void {
            $updated = LearningSession::query()->whereKey($session->id)->where('status', 'pending')
                ->where('starts_at', '>', self::nowUtc())
                ->update(['status' => 'rejected', 'rejection_reason' => $reason, 'decided_at' => now(), 'updated_at' => now()]);
            if ($updated !== 1) throw new HttpException(422, 'Pengajuan ini sudah tidak bisa ditolak (sudah dijawab, dibatalkan, atau waktunya lewat).');
            if ($session->slot_id) SlotBooking::releaseSeat((int) $session->slot_id);
        }, 5);

        Notifier::send((string) $session->student_id, 'session-'.$session->id.'-rejected', 'Pengajuan sesi ditolak',
            [$tutorName.' menolak pengajuan sesi '.$session->subject.' pada ', Notifier::slot($session->starts_at, $session->endsAt()), '. Alasan: '.$reason.' Kamu bisa memilih slot lain di Katalog Tutor.'],
            '/dashboard#sessions');
    }

    /** Simpan/ganti tautan kelas untuk sesi yang sudah diterima dan belum mulai. */
    public static function setMeetingLink(LearningSession $session, string $tutorName, string $meetingUrl): void
    {
        $updated = LearningSession::query()->whereKey($session->id)->where('status', 'confirmed')
            ->where('starts_at', '>', self::nowUtc())
            ->update(['meeting_url' => $meetingUrl, 'meeting_platform' => self::platform($meetingUrl), 'updated_at' => now()]);
        if ($updated !== 1) throw new HttpException(422, 'Tautan kelas hanya bisa diisi untuk sesi yang sudah diterima dan belum dimulai.');

        Notifier::send((string) $session->student_id, 'session-'.$session->id.'-link-'.substr(sha1($meetingUrl), 0, 12), 'Tautan kelas tersedia',
            [$tutorName.' membagikan tautan kelas untuk sesi '.$session->subject.' pada ', Notifier::slot($session->starts_at, $session->endsAt()), '. Tombol Masuk Kelas aktif '.self::joinWindow().' menit sebelum mulai.'],
            '/dashboard#sessions');
    }

    /**
     * Pengajuan berbasis slot yang tidak dijawab sampai waktu mulai → `expired` + kursi bebas + notifikasi siswa.
     * Dipanggil saat halaman dimuat (tanpa scheduler). Sesi lama tanpa slot tidak disentuh.
     *
     * @return int jumlah sesi yang kedaluwarsa pada pemanggilan ini
     */
    public static function expireStale(): int
    {
        $count = 0;
        LearningSession::query()->where('status', 'pending')->whereNotNull('slot_id')
            ->where('starts_at', '<=', self::nowUtc())->orderBy('id')->limit(200)->get()
            ->each(function (LearningSession $session) use (&$count): void {
                $expired = DB::transaction(function () use ($session): bool {
                    $updated = LearningSession::query()->whereKey($session->id)->where('status', 'pending')
                        ->update(['status' => 'expired', 'updated_at' => now()]);
                    if ($updated === 1) SlotBooking::releaseSeat((int) $session->slot_id);

                    return $updated === 1;
                }, 5);
                if (! $expired) return; // sudah diproses permintaan lain
                $count++;
                Notifier::send((string) $session->student_id, 'session-'.$session->id.'-expired', 'Pengajuan sesi kedaluwarsa',
                    ['Pengajuan sesi '.$session->subject.' dengan '.$session->tutor_name.' pada ', Notifier::slot($session->starts_at, $session->endsAt()), ' tidak dijawab tutor sampai waktu mulai. Silakan pilih slot lain.'],
                    '/dashboard#sessions');
            });

        return $count;
    }

    /** Nama platform dari host tautan (hanya untuk label). */
    public static function platform(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            $host === 'meet.google.com' => 'Google Meet',
            $host === 'zoom.us' || str_ends_with($host, '.zoom.us') => 'Zoom',
            $host === 'teams.microsoft.com' || $host === 'teams.live.com' => 'Microsoft Teams',
            default => 'Tautan kelas',
        };
    }

    private static function nowUtc(): string
    {
        return now()->utc()->format('Y-m-d H:i:s');
    }

    private static function joinWindow(): int
    {
        return (int) config('tutorkita.join_window_minutes');
    }
}
