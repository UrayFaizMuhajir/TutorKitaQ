<?php

namespace Tests\Feature;

use App\Models\GradeLevel;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** TT-9 / ROADMAP 5.1: siswa melengkapi profil + dokumen pendukung; wajib terverifikasi sebelum mengajukan sesi. */
class StudentVerificationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;
    use \Tests\Concerns\CreatesBookableSlot;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/student-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
        Storage::fake('local');
        $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        parent::tearDown();
    }

    public function test_new_and_existing_students_start_incomplete_and_cannot_request_sessions_but_can_use_other_features(): void
    {
        $this->registerAndLogin('Budi', 'budi@example.test');

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('data-page-link="student-verification"', Str::between($html, 'id="main-sidebar"', '</aside>'));
        $this->assertStringContainsString('data-spa-page="student-verification"', $html);
        $this->assertStringContainsString('data-student-verification-banner', $html);
        $this->assertStringContainsString('Belum lengkap', $html);
        // Tombol pengajuan nonaktif + penjelasan; dialog pengajuan tidak dirender.
        $this->assertStringContainsString('data-session-request-locked', $html);
        $this->assertStringContainsString('data-session-verification-note', $html);
        $this->assertStringNotContainsString('data-session-create-form', $html);
        $this->assertStringNotContainsString('data-session-dialog-open', $html);
        $this->assertStringContainsString('Pengajuan sesi dibuka setelah profil siswamu diverifikasi admin.', $html);
        $this->assertStringNotContainsString('hanya dapat dilakukan oleh akun Siswa', $html);
        $this->assertDatabaseCount('student_profiles', 0);

        $this->postJson(route('dashboard.sessions.store'), $this->sessionPayload())->assertForbidden()
            ->assertJsonPath('message', 'Profil siswamu belum terverifikasi. Lengkapi profil dan dokumen pendukung di halaman Profil & Verifikasi, lalu tunggu persetujuan admin.');
        $this->assertDatabaseCount('learning_sessions', 0);

        // Fitur lain tetap bisa dipakai sebelum terverifikasi.
        $this->get(route('subjects.catalog'))->assertOk();
        $math = \App\Models\Subject::query()->where('name', 'Matematika')->firstOrFail();
        $this->post(route('subjects.select', $math), ['grade_level_id' => GradeLevel::query()->where('name', 'SMP')->value('id')])->assertRedirect();
        $this->assertDatabaseHas('student_subjects', ['student_id' => '1', 'subject_id' => $math->id]);
    }

    public function test_profile_fields_are_validated_and_region_is_only_kecamatan_kota(): void
    {
        $this->registerAndLogin('Budi', 'budi@example.test');

        $this->post(route('dashboard.student-verification.profile'), [])
            ->assertSessionHasErrors(['school', 'grade_level_id', 'class_name', 'guardian_name', 'guardian_contact', 'region']);
        $this->post(route('dashboard.student-verification.profile'), ['grade_level_id' => 999] + $this->profileData())->assertSessionHasErrors('grade_level_id');
        $this->post(route('dashboard.student-verification.profile'), ['guardian_contact' => 'bukan kontak'] + $this->profileData())->assertSessionHasErrors('guardian_contact');
        $this->post(route('dashboard.student-verification.profile'), ['region' => str_repeat('a', 101)] + $this->profileData())->assertSessionHasErrors('region');
        $this->post(route('dashboard.student-verification.profile'), ['class_name' => str_repeat('a', 21)] + $this->profileData())->assertSessionHasErrors('class_name');
        $this->assertDatabaseCount('student_profiles', 0);

        $this->post(route('dashboard.student-verification.profile'), ['region' => str_repeat('b', 100)] + $this->profileData())->assertRedirect('/dashboard#student-verification');
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();
        $this->assertSame('incomplete', $profile->status);
        $this->assertSame('SMP Negeri 1', $profile->school);
        $this->assertSame(str_repeat('b', 100), $profile->region);
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('student_profiles', 'address'));
    }

    public function test_document_accepts_only_real_jpg_png_or_pdf_up_to_2mb_and_is_stored_privately(): void
    {
        $this->registerAndLogin('Budi', 'budi@example.test');

        foreach ([
            $this->realUpload('kartu.gif', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x00\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"),
            $this->realUpload('kartu.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 64)),
            $this->realUpload('kartu.pdf', "%PDF-1.4\n".str_repeat('A', 2 * 1024 * 1024 + 10)),
        ] as $file) {
            $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $file])->assertSessionHasErrors('student_document');
        }
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'ktp', 'student_document' => $this->fakePdf()])->assertSessionHasErrors('document_kind');
        $this->assertDatabaseCount('student_profiles', 0);

        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakeImage()])->assertRedirect('/dashboard#student-verification');
        $first = StudentProfile::query()->where('user_id', '1')->value('document_path');
        $this->assertStringStartsWith('student-documents/1/', $first);
        Storage::disk('local')->assertExists($first);

        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'surat_keterangan', 'student_document' => $this->fakePdf()])->assertRedirect();
        $second = StudentProfile::query()->where('user_id', '1')->value('document_path');
        Storage::disk('local')->assertExists($second);
        Storage::disk('local')->assertMissing($first);
        $this->assertSame('surat_keterangan', StudentProfile::query()->where('user_id', '1')->value('document_kind'));
        $this->assertFileDoesNotExist(public_path($second));
    }

    public function test_submit_requires_complete_data_locks_while_pending_and_verified_students_can_request_sessions(): void
    {
        $this->registerAndLogin('Budi', 'budi@example.test');

        $this->post(route('dashboard.student-verification.submit'))->assertSessionHasErrors('verification');
        $this->post(route('dashboard.student-verification.profile'), $this->profileData());
        $this->post(route('dashboard.student-verification.submit'))->assertSessionHasErrors('verification');
        $this->assertStringContainsString('Dokumen pendukung', session('errors')->first('verification'));

        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()]);
        $this->post(route('dashboard.student-verification.submit'))->assertRedirect('/dashboard#student-verification')->assertSessionHas('status');
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();
        $this->assertSame('pending', $profile->status);
        $this->assertDatabaseHas('student_verification_reviews', ['student_profile_id' => $profile->id, 'action' => 'submitted', 'to_status' => 'pending']);

        $this->post(route('dashboard.student-verification.profile'), $this->profileData())->assertStatus(409);
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()])->assertStatus(409);
        $this->post(route('dashboard.student-verification.submit'))->assertStatus(409);
        $this->postJson(route('dashboard.sessions.store'), $this->sessionPayload())->assertForbidden();
        $this->get('/dashboard')->assertSee('Menunggu verifikasi')->assertSee('fieldset class="verification-fieldset" disabled', false);

        // Setelah terverifikasi (keputusan admin diuji di AdminStudentVerificationTest): sesi boleh diajukan,
        // profil boleh diubah tanpa verifikasi ulang, tetapi dokumen terkunci.
        $profile->update(['status' => 'verified', 'verified_at' => now()]);
        $this->postJson(route('dashboard.sessions.store'), $this->sessionPayload())->assertCreated();
        $this->post(route('dashboard.student-verification.profile'), ['class_name' => '9A'] + $this->profileData())->assertRedirect();
        $this->assertSame('verified', $profile->fresh()->status);
        $this->assertSame('9A', $profile->fresh()->class_name);
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()])->assertStatus(409);
        $html = $this->get('/dashboard')->getContent();
        $this->assertStringContainsString('data-session-create-form', $html);
        $this->assertStringNotContainsString('data-session-request-locked', $html);
        $this->assertStringNotContainsString('data-student-verification-banner', $html);
    }

    public function test_student_document_is_only_readable_by_its_owner_and_admins(): void
    {
        $this->registerAndLogin('Budi', 'budi@example.test');
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()]);
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();

        $own = $this->get(route('dashboard.student-verification.document.show'))->assertOk();
        $own->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $own->headers->get('Content-Security-Policy'));
        $this->get(route('dashboard.student-profiles.document', $profile))->assertOk();
        $this->post('/logout');

        $this->registerAndLogin('Siswa Lain', 'lain@example.test');
        $this->get(route('dashboard.student-profiles.document', $profile))->assertForbidden();
        $this->get(route('dashboard.student-verification.document.show'))->assertNotFound();
        $this->post('/logout');

        $this->registerAndLogin('Tutor', 'tutor@example.test', 'tutor');
        $this->markTutorVerified((string) $this->record('tutor@example.test')['id']);
        $this->get(route('dashboard.student-profiles.document', $profile))->assertForbidden();
        $this->get(route('dashboard.student-verification.document.show'))->assertForbidden();
        $this->post('/logout');

        $this->registerAndLogin('Admin', 'admin@example.test');
        DB::table('user_roles')->where('user_id', (string) $this->record('admin@example.test')['id'])->update(['role' => 'admin']);
        Auth::logout();
        Auth::login(Auth::guard()->getProvider()->retrieveById((string) $this->record('admin@example.test')['id']));
        $this->get(route('dashboard.student-profiles.document', $profile))->assertOk();
        $this->post('/logout');

        $this->get(route('dashboard.student-profiles.document', $profile))->assertRedirect('/login');
    }

    /**
     * PRIVASI DATA ANAK: wali, kontak wali, kecamatan/kota, dan dokumen siswa tidak pernah tampil ke tutor
     * (terverifikasi maupun belum), di halaman mana pun yang dirender untuk tutor.
     */
    public function test_student_private_data_never_reaches_tutors(): void
    {
        $this->registerAndLogin('Budi', 'budi@example.test');
        $this->post(route('dashboard.student-verification.profile'), ['guardian_name' => 'Ibu Rahmawati Privat', 'guardian_contact' => 'wali.privat@example.test', 'region' => 'Kec. Rahasia, Kota Privat'] + $this->profileData());
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()]);
        $this->post(route('dashboard.student-verification.submit'));
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();
        $privateValues = ['Ibu Rahmawati Privat', 'wali.privat@example.test', 'Kec. Rahasia, Kota Privat', $profile->document_path, 'student-profiles/'.$profile->id];
        // Pemiliknya sendiri melihat datanya.
        $ownHtml = $this->get('/dashboard')->getContent();
        $this->assertStringContainsString('Ibu Rahmawati Privat', $ownHtml);
        $this->post('/logout');

        foreach (['verified' => true, 'unverified' => false] as $label => $verified) {
            $this->registerAndLogin("Tutor {$label}", "tutor-{$label}@example.test", 'tutor');
            if ($verified) $this->markTutorVerified((string) $this->record("tutor-{$label}@example.test")['id']);
            $html = $this->get('/dashboard')->assertOk()->getContent();
            foreach ($privateValues as $value) {
                $this->assertStringNotContainsString($value, $html, "Data siswa \"{$value}\" tidak boleh tampil ke tutor ({$label}).");
            }
            $this->get(route('dashboard.student-profiles.document', $profile))->assertForbidden();
            $this->post('/logout');
        }
    }

    public function test_tutors_and_admins_cannot_use_student_verification_routes(): void
    {
        $this->registerAndLogin('Tutor', 'tutor@example.test', 'tutor');
        $this->post(route('dashboard.student-verification.profile'), $this->profileData())->assertForbidden();
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()])->assertForbidden();
        $this->post(route('dashboard.student-verification.submit'))->assertForbidden();
        $this->assertDatabaseCount('student_profiles', 0);
    }

    private function registerAndLogin(string $name, string $email, ?string $accountType = null): void
    {
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on']
            + ($accountType ? ['account_type' => $accountType] : []))->assertRedirect('/login');
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');
    }

    private function profileData(): array
    {
        return [
            'school' => 'SMP Negeri 1', 'grade_level_id' => GradeLevel::query()->where('name', 'SMP')->value('id'), 'class_name' => '8B',
            'guardian_name' => 'Ibu Sari', 'guardian_contact' => '081234567890', 'region' => 'Kec. Coblong, Bandung',
        ];
    }

    private function sessionPayload(): array
    {
        // Sejak pengajuan berbasis slot (ROADMAP 3.2): payload memakai slot tutor asli, bukan tutor mock.
        $this->payloadSlot ??= $this->bookableSlot();

        return $this->slotPayload($this->payloadSlot);
    }

    private ?\App\Models\TutorAvailabilitySlot $payloadSlot = null;
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dokumen.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    private function fakeImage(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('kartu.png', file_get_contents(public_path('assets/tutorkita/tutorkita-ikon-app.png')));
    }

    /** @return array<string, mixed> */
    private function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
