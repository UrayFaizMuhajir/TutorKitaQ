<?php

namespace App\Support;

use App\Models\LearningSession;
use App\Models\Subject;
use App\Models\TutorAvailabilitySlot;
use App\Models\TutorProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Pemesanan sesi berbasis slot (ROADMAP 3.2 · TT-12, TT-25). Dipakai controller DAN tes konkuren.
 * Kursi diambil atomik (SlotBooking::takeSeat); index unik parsial mencegah siswa terdaftar dua kali di slot yang sama.
 * Bentrok dengan sesi lain milik siswa TIDAK ditolak — hanya ditandai (lihat conflictsFor).
 * PRIVASI (Aturan no. 11): notifikasi ke tutor hanya nama siswa + detail sesi.
 */
final class SessionBooking
{
    /** @param array{slot_id: int, subject: string, grade_level: string, topic: string} $input */
    public static function book(string $studentId, string $studentName, array $input): LearningSession
    {
        $slot = TutorAvailabilitySlot::query()->find($input['slot_id']);
        self::assertBookable($slot);
        $profile = TutorProfile::query()->with('subjects.gradeLevels')->where('user_id', $slot->tutor_id)->firstOrFail();
        $subject = $profile->subjects->firstWhere('name', $input['subject']);
        if (! $subject) throw new HttpException(422, 'Mata pelajaran tidak diajarkan tutor ini.');
        if (! $subject->gradeLevels->pluck('name')->contains($input['grade_level'])) throw new HttpException(422, 'Jenjang tidak tersedia untuk mata pelajaran ini.');
        $tutorName = (string) (app(LocalUserStore::class)->findById((string) $slot->tutor_id)['name'] ?? 'Tutor');

        try {
            $session = DB::transaction(function () use ($slot, $studentId, $input, $profile, $tutorName, $subject): LearningSession {
                // UPDATE atomik HARUS pernyataan pertama: di PHP < 8.4 Laravel memulai transaksi SQLite DEFERRED;
                // SELECT lebih dulu akan memegang kunci baca dan memicu deadlock "database is locked" saat serentak.
                if (! SlotBooking::takeSeat($slot->id)) throw new HttpException(409, 'Slot ini sudah penuh. Pilih slot lain.');
                if (LearningSession::query()->active()->where('slot_id', $slot->id)->where('student_id', $studentId)->exists()) {
                    throw new HttpException(409, 'Kamu sudah terdaftar di slot ini.'); // rollback → kursi kembali
                }

                return LearningSession::query()->create([
                    'student_id' => $studentId, 'tutor_id' => (string) $slot->tutor_id, 'slot_id' => $slot->id,
                    'tutor_name' => $tutorName, 'tutor_university' => $profile->institution, 'tutor_major' => $profile->program,
                    'subject' => $subject->name, 'topic' => trim($input['topic']), 'grade_level' => $input['grade_level'],
                    'starts_at' => $slot->starts_at, 'duration_minutes' => $slot->durationMinutes(),
                    'meeting_platform' => 'Google Meet', 'meeting_url' => null, 'status' => 'pending',
                ]);
            }, 5); // dicoba ulang bila SQLite sibuk ("database is locked" = error konkurensi bagi Laravel)
        } catch (UniqueConstraintViolationException) {
            throw new HttpException(409, 'Kamu sudah terdaftar di slot ini.');
        }

        Notifier::send((string) $slot->tutor_id, 'session-'.$session->id.'-requested', 'Pengajuan sesi baru',
            $studentName.' mengajukan sesi '.$session->subject.' – '.$session->topic.' pada '.UserTime::slotLabel($session->starts_at, $session->endsAt(), config('tutorkita.default_timezone')).'.', '/dashboard#schedule');
        self::notifyConflicts($studentId, $session);

        return $session;
    }

    public static function cancel(LearningSession $session, string $studentName): void
    {
        DB::transaction(function () use ($session): void {
            $updated = LearningSession::query()->whereKey($session->id)->whereIn('status', LearningSession::ACTIVE_STATUSES)
                ->where('starts_at', '>', now()->utc()->format('Y-m-d H:i:s'))
                ->update(['status' => 'cancelled', 'cancelled_by' => 'student', 'updated_at' => now()]);
            if ($updated !== 1) throw new HttpException(422, 'Sesi ini sudah tidak dapat dibatalkan.');
            if ($session->slot_id) SlotBooking::releaseSeat((int) $session->slot_id);
        }, 5);
        if ($session->tutor_id) {
            Notifier::send((string) $session->tutor_id, 'session-'.$session->id.'-cancelled-by-student', 'Siswa membatalkan sesi',
                $studentName.' membatalkan sesi '.$session->subject.' pada '.UserTime::slotLabel($session->starts_at, $session->endsAt(), config('tutorkita.default_timezone')).'. Satu kursi kembali kosong.', '/dashboard#schedule');
        }
    }

    /** Ganti jadwal (TT-25): pindah ke slot lain tutor yang sama dalam satu transaksi. */
    public static function reschedule(LearningSession $session, int $newSlotId, string $studentName): LearningSession
    {
        if (! $session->slot_id || ! $session->tutor_id) throw new HttpException(422, 'Sesi lama tanpa slot tidak bisa diganti jadwalnya. Batalkan lalu ajukan sesi baru.');
        if (! in_array($session->status, LearningSession::ACTIVE_STATUSES, true) || $session->starts_at->isPast()) throw new HttpException(422, 'Sesi ini sudah tidak dapat diganti jadwalnya.');
        if ($newSlotId === (int) $session->slot_id) throw new HttpException(422, 'Pilih slot yang berbeda.');
        $slot = TutorAvailabilitySlot::query()->find($newSlotId);
        self::assertBookable($slot);
        if ((string) $slot->tutor_id !== (string) $session->tutor_id) throw new HttpException(422, 'Ganti jadwal hanya ke slot tutor yang sama.');
        $oldLabel = UserTime::slotLabel($session->starts_at, $session->endsAt(), config('tutorkita.default_timezone'));

        try {
            DB::transaction(function () use ($session, $slot): void {
                if (! SlotBooking::takeSeat($slot->id)) throw new HttpException(409, 'Slot tujuan sudah penuh. Pilih slot lain.');
                if (LearningSession::query()->active()->where('slot_id', $slot->id)->where('student_id', $session->student_id)->exists()) {
                    throw new HttpException(409, 'Kamu sudah terdaftar di slot itu.');
                }
                // Waktu baru perlu dijawab tutor lagi (terima/tolak menyusul di ROADMAP 3.3).
                $moved = LearningSession::query()->whereKey($session->id)->where('slot_id', $session->slot_id)
                    ->whereIn('status', LearningSession::ACTIVE_STATUSES)->where('starts_at', '>', now()->utc()->format('Y-m-d H:i:s'))
                    ->update(['slot_id' => $slot->id, 'starts_at' => $slot->starts_at->format('Y-m-d H:i:s'), 'duration_minutes' => $slot->durationMinutes(), 'status' => 'pending', 'updated_at' => now()]);
                if ($moved !== 1) throw new HttpException(422, 'Sesi ini sudah tidak dapat diganti jadwalnya.');
                SlotBooking::releaseSeat((int) $session->slot_id);
            }, 5);
        } catch (UniqueConstraintViolationException) {
            throw new HttpException(409, 'Kamu sudah terdaftar di slot itu.');
        }
        $session->refresh();
        Notifier::send((string) $session->tutor_id, 'session-'.$session->id.'-rescheduled-'.$slot->id, 'Siswa mengganti jadwal sesi',
            $studentName.' memindahkan sesi '.$session->subject.' dari '.$oldLabel.' ke '.UserTime::slotLabel($session->starts_at, $session->endsAt(), config('tutorkita.default_timezone')).'.', '/dashboard#schedule');
        self::notifyConflicts((string) $session->student_id, $session);

        return $session;
    }

    /**
     * Bentrok antar sesi aktif (belum selesai) milik satu siswa, dihitung di UTC.
     *
     * @param  Collection<int, LearningSession>  $sessions
     * @return array<int, list<LearningSession>> id sesi → sesi lain yang bertabrakan
     */
    public static function conflictsFor(Collection $sessions): array
    {
        $now = now()->utc();
        $active = $sessions->filter(fn (LearningSession $session): bool => in_array($session->status, LearningSession::ACTIVE_STATUSES, true)
            && $session->endsAt()->greaterThan($now))->values();
        $map = [];
        foreach ($active as $i => $a) {
            foreach ($active as $j => $b) {
                if ($i === $j) continue;
                if ($a->starts_at->lessThan($b->endsAt()) && $a->endsAt()->greaterThan($b->starts_at)) $map[$a->id][] = $b;
            }
        }

        return $map;
    }

    private static function notifyConflicts(string $studentId, LearningSession $session): void
    {
        $conflicts = self::conflictsFor(LearningSession::query()->where('student_id', $studentId)->get())[$session->id] ?? [];
        if ($conflicts === []) return;
        $names = collect($conflicts)->map(fn (LearningSession $other): string => $other->subject.', '.UserTime::slotLabel($other->starts_at, $other->endsAt(), config('tutorkita.default_timezone')))->join('; ');
        Notifier::send($studentId, 'session-'.$session->id.'-conflict-'.$session->starts_at->getTimestamp(), 'Jadwal bentrok',
            'Sesi '.$session->subject.' bentrok dengan: '.$names.'. Pilih "Ganti jadwal" atau batalkan salah satu.', '/dashboard#sessions');
    }

    private static function assertBookable(?TutorAvailabilitySlot $slot): void
    {
        if (! $slot || $slot->status === 'cancelled') throw new HttpException(422, 'Slot tidak tersedia.');
        if (! TutorProfile::query()->verified()->active()->where('user_id', (string) $slot->tutor_id)->exists()) throw new HttpException(422, 'Tutor ini sedang tidak tersedia.');
        if ($slot->starts_at->lessThan(now()->utc()->addMinutes((int) config('tutorkita.booking_lead_minutes')))) {
            throw new HttpException(422, 'Slot ini terlalu dekat dengan waktu mulai. Pemesanan paling lambat '.intdiv((int) config('tutorkita.booking_lead_minutes'), 60).' jam sebelum sesi.');
        }
        if ($slot->status === 'full' || $slot->booked_count >= $slot->capacity) throw new HttpException(409, 'Slot ini sudah penuh. Pilih slot lain.');
    }
}
