<?php

namespace App\Support;

use App\Models\TutorApplication;
use App\Models\TutorProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Verifikasi tutor (ROADMAP 1.2). Satu jalur untuk dua asal pengajuan:
 * - tutor yang mendaftar sendiri (draf di tutor_profiles → dikirim sebagai pengajuan), dan
 * - siswa yang mengajukan diri menjadi tutor (form pengajuan lama).
 *
 * Sumber kebenaran status: tutor_applications.status
 *   (tidak ada)/draf → incomplete, pending → pending, approved → verified, rejected → rejected.
 */
final class TutorVerification
{
    public const STATUS_LABELS = VerificationStatus::LABELS;

    public const EDUCATION_LEVELS = ['SMA/SMK', 'Diploma', 'S1', 'S2', 'S3'];

    public const CARD_MAX_KILOBYTES = PrivateDocument::MAX_KILOBYTES;

    public static function isVerified(string $userId): bool
    {
        return TutorApplication::query()->where('user_id', $userId)->where('status', 'approved')->exists();
    }

    /** Tutor aktif = tidak sedang dinonaktifkan admin (tutor_profiles.is_active). */
    public static function isActive(string $userId): bool
    {
        return (bool) TutorProfile::query()->where('user_id', $userId)->value('is_active');
    }

    /** Boleh mengelola materi/tugas: terverifikasi DAN aktif. */
    public static function canManage(string $userId): bool
    {
        return self::isVerified($userId) && self::isActive($userId);
    }

    public static function statusFromApplication(?string $applicationStatus): string
    {
        return match ($applicationStatus) {
            'approved' => 'verified',
            'pending' => 'pending',
            'rejected' => 'rejected',
            default => 'incomplete',
        };
    }

    /** Aturan validasi kartu mahasiswa: pola dokumen privat bersama (isi file, JPG/PNG/PDF, maks. 2 MB). */
    public static function cardRules(): array
    {
        return PrivateDocument::rules();
    }

    public static function cardMessages(string $field = 'student_card'): array
    {
        return PrivateDocument::messages($field, 'kartu mahasiswa');
    }

    /** Disimpan di disk privat `local` (storage/app/private), tidak pernah di folder publik. */
    public static function storeCard(UploadedFile $file, string $userId): string
    {
        return PrivateDocument::store($file, 'tutor-documents/'.$userId);
    }

    /** Hapus file kartu lama hanya bila tidak lagi dirujuk profil atau pengajuan mana pun. */
    public static function deleteCardIfUnused(?string $path): void
    {
        if (! $path) return;
        $referenced = TutorProfile::query()->where('student_card_path', $path)->exists()
            || TutorApplication::query()->where('student_card_path', $path)->exists();
        if (! $referenced) Storage::disk('local')->delete($path);
    }

    public static function isPdf(?string $path): bool
    {
        return PrivateDocument::isPdf($path);
    }

    /** @return list<string> label isian profil yang masih kosong */
    public static function missingProfileFields(?TutorProfile $profile, ?string $accountAvatar): array
    {
        $missing = [];
        $checks = [
            'education_level' => 'Pendidikan terakhir',
            'institution' => 'Kampus / institusi',
            'program' => 'Jurusan / program studi',
            'graduation_year' => 'Tahun lulus',
        ];
        foreach ($checks as $field => $label) {
            if (blank($profile?->{$field})) $missing[] = $label;
        }
        if (mb_strlen(trim((string) $profile?->bio)) < 20) $missing[] = 'Biografi (minimal 20 karakter)';
        if (! $profile || $profile->subjects->isEmpty()) $missing[] = 'Mata pelajaran';
        if (! ($profile?->avatar_path || $accountAvatar)) $missing[] = 'Foto profil';

        return $missing;
    }

    /**
     * Ringkasan status untuk tutor (beranda & halaman Profil & Verifikasi).
     *
     * @return array<string, mixed>
     */
    public static function stateFor(string $userId, ?string $accountAvatar): array
    {
        $profile = TutorProfile::query()->with('subjects')->where('user_id', $userId)->first();
        $application = TutorApplication::query()->where('user_id', $userId)->first();
        $status = self::statusFromApplication($application?->status);
        $missing = self::missingProfileFields($profile, $accountAvatar);
        $locked = in_array($status, ['pending', 'verified'], true);
        $cardUploaded = (bool) ($profile?->student_card_path || ($locked && $application?->student_card_path));

        return [
            'status' => $status,
            'label' => self::STATUS_LABELS[$status],
            'profile' => $profile,
            'application' => $application,
            'rejection_reason' => $status === 'rejected' ? $application?->rejection_reason : null,
            'missing' => $missing,
            'profile_complete' => $missing === [],
            'card_uploaded' => $cardUploaded,
            'card_path' => $profile?->student_card_path,
            'card_is_pdf' => self::isPdf($profile?->student_card_path),
            'submitted' => $locked,
            'locked' => $locked,
            'can_submit' => ! $locked && $missing === [] && $cardUploaded,
        ];
    }

    /**
     * Simpan pengajuan berstatus pending (dipakai kedua alur) beserta catatan review.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $subjectIds
     */
    public static function saveApplication(string $userId, array $attributes, array $subjectIds): TutorApplication
    {
        return DB::transaction(function () use ($userId, $attributes, $subjectIds): TutorApplication {
            $application = TutorApplication::query()->lockForUpdate()->firstOrNew(['user_id' => $userId]);
            if ($application->exists && $application->status === 'pending') {
                throw ValidationException::withMessages(['application' => 'Pengajuanmu masih menunggu pemeriksaan admin.']);
            }
            if ($application->exists && $application->status === 'approved') {
                throw ValidationException::withMessages(['application' => 'Akunmu sudah terverifikasi sebagai tutor.']);
            }
            $fromStatus = $application->status;
            $application->fill($attributes + [
                'status' => 'pending', 'rejection_reason' => null, 'reviewed_by' => null,
                'submitted_at' => now(), 'reviewed_at' => null, 'approved_at' => null,
            ]);
            $application->save();
            $application->subjects()->sync($subjectIds);
            $application->reviews()->create([
                'actor_id' => $userId, 'action' => $fromStatus === 'rejected' ? 'resubmitted' : 'submitted',
                'from_status' => $fromStatus, 'to_status' => 'pending',
            ]);

            return $application;
        });
    }
}
