<?php

namespace App\Http\Controllers;

use App\Models\TutorApplication;
use App\Models\TutorProfile;
use App\Support\LocalUserStore;
use App\Support\TutorVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman "Profil & Verifikasi" untuk tutor yang mendaftar sendiri (route `role:tutor`),
 * plus penyajian file kartu mahasiswa yang hanya boleh dibuka Admin atau pemiliknya.
 */
class TutorVerificationController extends Controller
{
    private const PAGE = '/dashboard#verification';

    public function saveProfile(Request $request, LocalUserStore $users): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $this->abortIfLocked($userId);
        $profile = TutorProfile::query()->firstOrNew(['user_id' => $userId]);
        $hasAvatar = (bool) ($profile->avatar_path || $request->user()->avatar_path);

        $data = $request->validate([
            'education_level' => ['required', 'string', Rule::in(TutorVerification::EDUCATION_LEVELS)],
            'institution' => ['required', 'string', 'max:180'],
            'program' => ['required', 'string', 'max:180'],
            'graduation_year' => ['required', 'integer', 'between:1950,'.(now()->year + 8)],
            'bio' => ['required', 'string', 'min:20', 'max:2000'],
            'experience' => ['nullable', 'string', 'max:4000'],
            'additional_info' => ['nullable', 'string', 'max:2000'],
            'subject_ids' => ['required', 'array', 'min:1', 'max:30'],
            'subject_ids.*' => ['required', 'integer', 'distinct', 'exists:subjects,id'],
            'avatar' => [$hasAvatar ? 'nullable' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ], [
            'education_level.required' => 'Pilih pendidikan terakhir.',
            'institution.required' => 'Kampus / institusi perlu diisi.',
            'program.required' => 'Jurusan / program studi perlu diisi.',
            'graduation_year.required' => 'Tahun lulus perlu diisi.',
            'bio.required' => 'Biografi perlu diisi.',
            'bio.min' => 'Biografi minimal 20 karakter.',
            'subject_ids.required' => 'Pilih minimal satu mata pelajaran yang kamu kuasai.',
            'avatar.required' => 'Tambahkan foto profil.',
            'avatar.image' => 'Unggah foto JPG, PNG, atau WebP yang valid.',
            'avatar.max' => 'Ukuran foto maksimal 2 MB.',
        ]);

        $newAvatar = $request->file('avatar')?->store('profile-photos/'.$userId, 'local');
        try {
            DB::transaction(function () use ($profile, $data, $newAvatar): void {
                $profile->fill([
                    'education_level' => $data['education_level'], 'institution' => trim($data['institution']),
                    'program' => trim($data['program']), 'graduation_year' => $data['graduation_year'],
                    'bio' => trim($data['bio']), 'experience' => $data['experience'] ?? null,
                    'additional_info' => $data['additional_info'] ?? null,
                ]);
                if ($newAvatar) $profile->avatar_path = $newAvatar;
                // is_active sengaja tidak disentuh: hanya persetujuan admin yang boleh mengaktifkan tutor.
                if (! $profile->exists) $profile->is_active = false;
                $profile->save();
                $profile->subjects()->sync($data['subject_ids']);
            });
            if ($newAvatar && ! $users->updateProfile($userId, ['avatar_path' => $newAvatar])) {
                throw new \RuntimeException('Akun tidak ditemukan.');
            }
        } catch (\Throwable $exception) {
            if ($newAvatar) Storage::disk('local')->delete($newAvatar);
            throw $exception;
        }

        return redirect(self::PAGE)->with('status', 'Profil tutor tersimpan.');
    }

    public function uploadCard(Request $request): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $this->abortIfLocked($userId);
        $request->validate(['student_card' => TutorVerification::cardRules()], TutorVerification::cardMessages());

        $path = TutorVerification::storeCard($request->file('student_card'), $userId);
        $profile = TutorProfile::query()->firstOrNew(['user_id' => $userId]);
        $oldPath = $profile->student_card_path;
        if (! $profile->exists) $profile->is_active = false;
        $profile->student_card_path = $path;
        $profile->save();
        if ($oldPath !== $path) TutorVerification::deleteCardIfUnused($oldPath);

        return redirect(self::PAGE)->with('status', 'Kartu mahasiswa tersimpan.');
    }

    public function submit(Request $request): RedirectResponse
    {
        $user = $request->user();
        $userId = (string) $user->getAuthIdentifier();
        $this->abortIfLocked($userId);
        $state = TutorVerification::stateFor($userId, $user->avatar_path);
        $problems = $state['missing'];
        if (! $state['card_uploaded']) $problems[] = 'Kartu mahasiswa';
        if ($problems !== []) {
            throw ValidationException::withMessages(['verification' => 'Lengkapi dulu: '.implode(', ', $problems).'.']);
        }

        /** @var TutorProfile $profile */
        $profile = $state['profile'];
        $previousCard = $state['application']?->student_card_path;
        TutorVerification::saveApplication($userId, [
            'applicant_name' => (string) $user->name, 'education_level' => $profile->education_level,
            'institution' => $profile->institution, 'program' => $profile->program,
            'graduation_year' => $profile->graduation_year, 'bio' => $profile->bio,
            'experience' => $profile->experience, 'additional_info' => $profile->additional_info,
            'avatar_path' => $profile->avatar_path ?: $user->avatar_path,
            'student_card_path' => $profile->student_card_path,
        ], $profile->subjects->modelKeys());
        if ($previousCard !== $profile->student_card_path) TutorVerification::deleteCardIfUnused($previousCard);

        return redirect(self::PAGE)->with('status', 'Data verifikasi terkirim. Admin akan memeriksa profil dan kartu mahasiswamu.');
    }

    /** Kartu draf milik tutor sendiri. */
    public function ownCard(Request $request): StreamedResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $path = TutorProfile::query()->where('user_id', $userId)->value('student_card_path')
            ?? TutorApplication::query()->where('user_id', $userId)->value('student_card_path');

        return $this->streamCard($path);
    }

    /** Kartu pada pengajuan: hanya Admin atau pemilik pengajuan. */
    public function applicationCard(Request $request, TutorApplication $tutorApplication): StreamedResponse
    {
        $user = $request->user();
        abort_unless(
            ($user->role ?? null) === 'admin' || (string) $user->getAuthIdentifier() === (string) $tutorApplication->user_id,
            403,
            'Kamu tidak memiliki akses ke dokumen ini.',
        );

        return $this->streamCard($tutorApplication->student_card_path);
    }

    private function abortIfLocked(string $userId): void
    {
        $status = TutorApplication::query()->where('user_id', $userId)->value('status');
        abort_if($status === 'pending', 409, 'Data sedang diperiksa admin dan belum bisa diubah.');
        abort_if($status === 'approved', 409, 'Akunmu sudah terverifikasi. Kartu mahasiswa tidak bisa diganti.');
    }

    private function streamCard(?string $path): StreamedResponse
    {
        abort_unless(is_string($path) && $path !== '' && Storage::disk('local')->exists($path), 404, 'Kartu mahasiswa tidak ditemukan.');

        return Storage::disk('local')->response($path, 'kartu-mahasiswa.'.pathinfo($path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            // Cegah skrip di dalam PDF/berkas berjalan dengan origin aplikasi.
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self'; style-src 'unsafe-inline'",
        ], 'inline');
    }
}
