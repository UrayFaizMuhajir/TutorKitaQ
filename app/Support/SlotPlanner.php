<?php

namespace App\Support;

use App\Models\TutorAvailabilitySlot;
use Carbon\CarbonImmutable;

/**
 * Menghitung slot yang akan dibuat (sekali atau mingguan) — dipakai PRATINJAU dan SIMPAN agar hasilnya identik.
 * Input tanggal/jam dalam zona pengguna, hasil dalam UTC. Bentrok & "sudah lewat" dihitung di UTC.
 */
final class SlotPlanner
{
    /**
     * @return list<array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, ok: bool, reason: ?string, label: string, clash: ?TutorAvailabilitySlot}>
     */
    public static function plan(string $tutorId, string $date, string $startTime, string $endTime, ?string $repeatUntil, ?int $ignoreSlotId = null): array
    {
        $tz = UserTime::tz();
        $first = CarbonImmutable::createFromFormat('Y-m-d', $date, $tz)->startOfDay();
        $last = $repeatUntil ? CarbonImmutable::createFromFormat('Y-m-d', $repeatUntil, $tz)->startOfDay() : $first;
        $now = CarbonImmutable::now('UTC');
        $occurrences = [];
        $planned = [];
        for ($day = $first; $day->lessThanOrEqualTo($last); $day = $day->addWeek()) {
            $start = UserTime::toUtc($day->format('Y-m-d'), $startTime, $tz);
            $end = UserTime::toUtc($day->format('Y-m-d'), $endTime, $tz);
            $reason = null;
            $clash = null;
            if ($start->lessThanOrEqualTo($now)) {
                $reason = 'Sudah lewat';
            } elseif ($clash = self::clash($tutorId, $start, $end, $ignoreSlotId)) {
                $reason = 'Bentrok dengan slot '.UserTime::slotLabel($clash->starts_at, $clash->ends_at, $tz);
            } else {
                foreach ($planned as [$plannedStart, $plannedEnd]) {
                    if ($start->lessThan($plannedEnd) && $end->greaterThan($plannedStart)) $reason = 'Bentrok dengan slot lain di rangkaian ini';
                }
            }
            if ($reason === null) $planned[] = [$start, $end];
            $occurrences[] = ['starts_at' => $start, 'ends_at' => $end, 'ok' => $reason === null, 'reason' => $reason, 'label' => UserTime::slotLabel($start, $end, $tz), 'clash' => $clash];
        }

        return $occurrences;
    }

    /** Slot open/full milik tutor yang beririsan dengan [start, end) — dihitung di UTC. */
    public static function clash(string $tutorId, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreSlotId = null): ?TutorAvailabilitySlot
    {
        return TutorAvailabilitySlot::query()->occupying()->where('tutor_id', $tutorId)
            ->when($ignoreSlotId, fn ($query) => $query->whereKeyNot($ignoreSlotId))
            ->where('starts_at', '<', $end->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $start->format('Y-m-d H:i:s'))
            ->orderBy('starts_at')->first();
    }
}
