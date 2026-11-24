<?php

namespace Tests\Concerns;

use App\Models\TutorApplication;
use App\Models\TutorProfile;

/**
 * Sejak verifikasi tutor (ROADMAP 1.2), role "tutor" saja tidak cukup untuk mengelola materi/tugas:
 * pengajuannya harus approved. Helper ini menandai akun tutor uji sebagai terverifikasi,
 * meniru hasil persetujuan admin.
 */
trait CreatesVerifiedTutor
{
    protected function markTutorVerified(string $userId, string $name = 'Tutor Uji'): void
    {
        TutorApplication::query()->updateOrCreate(['user_id' => $userId], [
            'applicant_name' => $name, 'education_level' => 'S1', 'institution' => 'Universitas Uji',
            'program' => 'Pendidikan', 'graduation_year' => 2024, 'bio' => 'Tutor uji yang sudah diverifikasi admin.',
            'status' => 'approved', 'submitted_at' => now(), 'reviewed_at' => now(), 'approved_at' => now(),
        ]);
        TutorProfile::query()->updateOrCreate(['user_id' => $userId], ['institution' => 'Universitas Uji', 'is_active' => true]);
    }
}
