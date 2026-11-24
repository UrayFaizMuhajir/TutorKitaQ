<?php

namespace Tests\Concerns;

use App\Models\Subject;
use App\Models\TutorApplication;
use App\Models\TutorAvailabilitySlot;
use App\Models\TutorProfile;
use Carbon\CarbonImmutable;

/**
 * Sejak pengajuan sesi berbasis slot (ROADMAP 3.2), siswa memesan slot tutor terverifikasi+aktif.
 * Helper ini membuat tutor siap pesan (tanpa akun users.json) beserta satu slot open.
 */
trait CreatesBookableSlot
{
    protected function bookableSlot(string $tutorId = '900', string $subject = 'Matematika', int $capacity = 20, ?CarbonImmutable $start = null): TutorAvailabilitySlot
    {
        if (Subject::query()->doesntExist()) $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
        TutorApplication::query()->updateOrCreate(['user_id' => $tutorId], [
            'applicant_name' => 'Tutor Slot', 'education_level' => 'S1', 'institution' => 'Universitas Slot', 'program' => 'Pendidikan',
            'graduation_year' => 2024, 'bio' => 'Tutor uji yang siap dipesan siswa.', 'status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(),
        ]);
        $profile = TutorProfile::query()->updateOrCreate(['user_id' => $tutorId], ['institution' => 'Universitas Slot', 'program' => 'Pendidikan', 'is_active' => true]);
        $profile->subjects()->syncWithoutDetaching([Subject::query()->where('name', $subject)->value('id')]);
        $start ??= CarbonImmutable::now('UTC')->addDays(2)->setTime(8, 0);

        return TutorAvailabilitySlot::query()->create(['tutor_id' => $tutorId, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'capacity' => $capacity, 'booked_count' => 0, 'status' => 'open']);
    }

    /** @return array{slot_id: int, subject: string, grade_level: string, topic: string} */
    protected function slotPayload(TutorAvailabilitySlot $slot, array $overrides = []): array
    {
        $subject = $overrides['subject'] ?? 'Matematika';

        return $overrides + [
            'slot_id' => $slot->id,
            'subject' => $subject,
            'grade_level' => Subject::query()->where('name', $subject)->firstOrFail()->gradeLevels()->orderBy('sort_order')->value('name'),
            'topic' => 'Persamaan kuadrat',
        ];
    }
}
