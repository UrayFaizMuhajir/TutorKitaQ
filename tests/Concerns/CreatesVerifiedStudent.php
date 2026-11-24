<?php

namespace Tests\Concerns;

use App\Models\GradeLevel;
use App\Models\StudentProfile;

/**
 * Sejak verifikasi siswa (ROADMAP 5.1), siswa wajib terverifikasi sebelum mengajukan sesi.
 * Helper ini menandai akun siswa uji sebagai terverifikasi, meniru hasil persetujuan admin.
 */
trait CreatesVerifiedStudent
{
    protected function markStudentVerified(string $userId): void
    {
        $gradeLevelId = GradeLevel::query()->value('id') ?? GradeLevel::query()->create(['name' => 'SMP', 'slug' => 'smp', 'sort_order' => 2])->id;
        StudentProfile::query()->updateOrCreate(['user_id' => $userId], [
            'school' => 'SMP Uji', 'grade_level_id' => $gradeLevelId, 'class_name' => '8A', 'guardian_name' => 'Wali Uji',
            'guardian_contact' => '081234567890', 'region' => 'Kec. Uji, Kota Uji', 'document_kind' => 'kartu_pelajar',
            'document_path' => 'student-documents/'.$userId.'/uji.pdf', 'status' => 'verified',
            'submitted_at' => now(), 'reviewed_at' => now(), 'verified_at' => now(),
        ]);
    }
}
