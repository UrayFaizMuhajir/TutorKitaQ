<?php

namespace App\Support;

use App\Models\StudentProfile;

/**
 * Verifikasi siswa (ROADMAP 5.1/5.2). Status langsung di student_profiles.status:
 * incomplete → pending → verified / rejected (bisa diajukan ulang).
 * Siswa WAJIB terverifikasi sebelum mengajukan sesi (middleware `student.verified`).
 * Data profil siswa hanya untuk siswa itu sendiri dan Admin (privasi data anak).
 */
final class StudentVerification
{
    public static function isVerified(string $userId): bool
    {
        return StudentProfile::query()->where('user_id', $userId)->where('status', 'verified')->exists();
    }

    /** @return list<string> */
    public static function missingFields(?StudentProfile $profile): array
    {
        $labels = [
            'school' => 'Sekolah',
            'grade_level_id' => 'Jenjang',
            'class_name' => 'Kelas',
            'guardian_name' => 'Nama wali',
            'guardian_contact' => 'Kontak wali',
            'region' => 'Kecamatan / Kota',
        ];
        $missing = [];
        foreach ($labels as $field => $label) {
            if (blank($profile?->{$field})) $missing[] = $label;
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    public static function stateFor(string $userId): array
    {
        $profile = StudentProfile::query()->with('gradeLevel')->where('user_id', $userId)->first();
        $status = $profile?->status ?? 'incomplete';
        $missing = self::missingFields($profile);
        $hasDocument = (bool) $profile?->document_path;

        return [
            'status' => $status,
            'label' => VerificationStatus::label($status),
            'profile' => $profile,
            'rejection_reason' => $status === 'rejected' ? $profile?->rejection_reason : null,
            'missing' => $missing,
            'profile_complete' => $missing === [],
            'document_uploaded' => $hasDocument,
            'document_is_pdf' => PrivateDocument::isPdf($profile?->document_path),
            'submitted' => in_array($status, ['pending', 'verified'], true),
            // Saat menunggu: semua terkunci. Setelah terverifikasi: profil boleh diubah, dokumen terkunci.
            'profile_locked' => $status === 'pending',
            'document_locked' => in_array($status, ['pending', 'verified'], true),
            'can_submit' => in_array($status, ['incomplete', 'rejected'], true) && $missing === [] && $hasDocument,
        ];
    }
}
