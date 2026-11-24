<?php

namespace App\Support;

use App\Models\TutorAvailabilitySlot;
use App\Models\TutorProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Katalog tutor asli (ROADMAP 3.1 · TT-11): hanya tutor terverifikasi DAN aktif. Jenjang tutor = jenjang dari mapel
 * yang diajarkannya. Slot kosong = slot open dengan kursi tersisa, mulai ≥ batas pemesanan, dalam N hari ke depan.
 * Rating belum ada (TT-21) → tidak pernah menampilkan angka palsu.
 */
final class TutorCatalog
{
    /** @return Collection<int, array<string, mixed>> */
    public static function make(LocalUserStore $users): Collection
    {
        $accounts = collect($users->all())->keyBy(fn (array $record): string => (string) ($record['id'] ?? ''));
        $profiles = TutorProfile::query()->verified()->active()->with('subjects.gradeLevels')->orderBy('id')->get();
        $from = now()->utc()->addMinutes((int) config('tutorkita.booking_lead_minutes'));
        $until = now()->utc()->addDays((int) config('tutorkita.catalog_days'));
        $slots = TutorAvailabilitySlot::query()->where('status', 'open')->whereColumn('booked_count', '<', 'capacity')
            ->whereIn('tutor_id', $profiles->pluck('user_id')->map(fn ($id): string => (string) $id))
            ->where('starts_at', '>=', $from->format('Y-m-d H:i:s'))->where('starts_at', '<=', $until->format('Y-m-d H:i:s'))
            ->orderBy('starts_at')->get()->groupBy('tutor_id');

        return $profiles->values()->map(function (TutorProfile $profile, int $index) use ($accounts, $slots): array {
            $record = $accounts[(string) $profile->user_id] ?? [];
            $name = (string) ($record['name'] ?? 'Tutor');
            $subjects = $profile->subjects->pluck('name')->values()->all();
            $levels = $profile->subjects->flatMap(fn ($subject) => $subject->gradeLevels)->sortBy('sort_order')->pluck('name')->unique()->values()->all();
            $tutorSlots = $slots->get((string) $profile->user_id, collect())->map(fn (TutorAvailabilitySlot $slot): array => [
                'id' => $slot->id,
                'label' => UserTime::slotLabel($slot->starts_at, $slot->ends_at),
                'starts_ts' => $slot->starts_at->getTimestamp(),
                'ends_ts' => $slot->ends_at->getTimestamp(),
                'booked' => $slot->booked_count,
                'capacity' => $slot->capacity,
            ])->values()->all();
            $parts = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            return [
                'id' => (string) $profile->user_id,
                'name' => $name,
                'initials' => mb_strtoupper(implode('', array_map(fn (string $part): string => mb_substr($part, 0, 1), array_slice($parts, 0, 2)))),
                'occupation' => $record['occupation'] ?? null,
                'institution' => $profile->institution,
                'program' => $profile->program,
                'bio' => $profile->bio,
                'subjects' => $subjects,
                'levels' => $levels,
                'photo_url' => ($profile->avatar_path || ($record['avatar_path'] ?? null)) ? route('dashboard.profile.photo', $profile->user_id) : null,
                'slots' => $tutorSlots,
                'next_slot_ts' => $tutorSlots[0]['starts_ts'] ?? null,
                'created' => $index,
                'searchable' => Str::lower($name.' '.$profile->institution.' '.$profile->program.' '.implode(' ', $subjects)),
            ];
        });
    }
}
