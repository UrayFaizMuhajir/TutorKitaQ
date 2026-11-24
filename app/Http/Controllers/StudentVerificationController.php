<?php

namespace App\Http\Controllers;

use App\Models\StudentProfile;
use App\Support\LocalUserStore;
use App\Support\PrivateDocument;
use App\Support\StudentVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Halaman "Profil & Verifikasi" siswa (route `role:student`, ROADMAP 5.1) dan penyajian dokumen siswa
 * yang hanya boleh dibuka Admin atau pemiliknya. Data ini tidak pernah ditampilkan ke tutor.
 */
class StudentVerificationController extends Controller
{
    private const PAGE = '/dashboard#student-verification';

    public function saveProfile(Request $request): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $profile = StudentProfile::query()->firstOrNew(['user_id' => $userId]);
        abort_if($profile->status === 'pending', 409, 'Data sedang diperiksa admin dan belum bisa diubah.');

        $data = $request->validate([
            'school' => ['required', 'string', 'max:180'],
            'grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'class_name' => ['required', 'string', 'max:20'],
            'guardian_name' => ['required', 'string', 'max:120'],
            'guardian_contact' => [
                'required', 'string', 'max:60',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! LocalUserStore::isValidContact($value)) $fail('Masukkan email atau nomor telepon wali yang valid.');
                },
            ],
            'region' => ['required', 'string', 'max:100'],
        ], [
            'school.required' => 'Nama sekolah perlu diisi.',
            'grade_level_id.required' => 'Pilih jenjang.',
            'grade_level_id.exists' => 'Jenjang tidak tersedia.',
            'class_name.required' => 'Kelas perlu diisi.',
            'class_name.max' => 'Kelas maksimal 20 karakter.',
            'guardian_name.required' => 'Nama wali perlu diisi.',
            'guardian_contact.required' => 'Kontak wali perlu diisi.',
            'region.required' => 'Kecamatan / Kota perlu diisi.',
            'region.max' => 'Kecamatan / Kota maksimal 100 karakter.',
        ]);

        $profile->fill([
            'school' => trim($data['school']), 'grade_level_id' => $data['grade_level_id'], 'class_name' => trim($data['class_name']),
            'guardian_name' => trim($data['guardian_name']), 'guardian_contact' => trim($data['guardian_contact']), 'region' => trim($data['region']),
        ]);
        if (! $profile->exists) $profile->status = 'incomplete';
        $profile->save();

        return redirect(self::PAGE)->with('status', 'Profil siswa tersimpan.');
    }

    public function uploadDocument(Request $request): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $profile = StudentProfile::query()->firstOrNew(['user_id' => $userId]);
        abort_if($profile->status === 'pending', 409, 'Data sedang diperiksa admin dan belum bisa diubah.');
        abort_if($profile->status === 'verified', 409, 'Profilmu sudah terverifikasi. Dokumen pendukung tidak bisa diganti.');

        $data = $request->validate([
            'document_kind' => ['required', Rule::in(array_keys(StudentProfile::DOCUMENT_KINDS))],
            'student_document' => PrivateDocument::rules(),
        ], ['document_kind.required' => 'Pilih jenis dokumen.', 'document_kind.in' => 'Jenis dokumen tidak dikenal.'] + PrivateDocument::messages('student_document', 'dokumen pendukung'));

        $path = PrivateDocument::store($request->file('student_document'), 'student-documents/'.$userId);
        $oldPath = $profile->document_path;
        if (! $profile->exists) $profile->status = 'incomplete';
        $profile->fill(['document_kind' => $data['document_kind'], 'document_path' => $path])->save();
        if ($oldPath && $oldPath !== $path) Storage::disk('local')->delete($oldPath);

        return redirect(self::PAGE)->with('status', 'Dokumen pendukung tersimpan.');
    }

    public function submit(Request $request): RedirectResponse
    {
        $userId = (string) $request->user()->getAuthIdentifier();
        $state = StudentVerification::stateFor($userId);
        abort_if(in_array($state['status'], ['pending', 'verified'], true), 409, $state['status'] === 'pending'
            ? 'Data sedang diperiksa admin.' : 'Profilmu sudah terverifikasi.');
        $problems = $state['missing'];
        if (! $state['document_uploaded']) $problems[] = 'Dokumen pendukung';
        if ($problems !== []) {
            throw ValidationException::withMessages(['verification' => 'Lengkapi dulu: '.implode(', ', $problems).'.']);
        }

        DB::transaction(function () use ($userId): void {
            $profile = StudentProfile::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($profile->status, ['incomplete', 'rejected'], true), 409, 'Status profil sudah berubah. Muat ulang halaman.');
            $fromStatus = $profile->status;
            $profile->update([
                'status' => 'pending', 'rejection_reason' => null, 'reviewed_by' => null,
                'submitted_at' => now(), 'reviewed_at' => null, 'verified_at' => null,
            ]);
            $profile->reviews()->create([
                'actor_id' => $userId, 'action' => $fromStatus === 'rejected' ? 'resubmitted' : 'submitted',
                'from_status' => $fromStatus, 'to_status' => 'pending',
            ]);
        });

        return redirect(self::PAGE)->with('status', 'Data verifikasi terkirim. Admin akan memeriksa profil dan dokumenmu.');
    }

    /** Dokumen milik siswa sendiri. */
    public function ownDocument(Request $request): StreamedResponse
    {
        $path = StudentProfile::query()->where('user_id', (string) $request->user()->getAuthIdentifier())->value('document_path');

        return PrivateDocument::response($path, 'dokumen-siswa');
    }

    /** Dokumen siswa untuk Admin atau pemiliknya. Tutor dan siswa lain selalu ditolak. */
    public function profileDocument(Request $request, StudentProfile $studentProfile): StreamedResponse
    {
        $user = $request->user();
        abort_unless(
            ($user->role ?? null) === 'admin' || (string) $user->getAuthIdentifier() === (string) $studentProfile->user_id,
            403,
            'Kamu tidak memiliki akses ke dokumen ini.',
        );

        return PrivateDocument::response($studentProfile->document_path, 'dokumen-siswa');
    }
}
