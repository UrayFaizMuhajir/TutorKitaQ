<?php

namespace Tests\Feature;

use App\Models\LearningMaterial;
use App\Models\StudentAssignment;
use App\Models\Subject;
use App\Models\TutorApplication;
use App\Models\TutorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Daftar sebagai tutor + verifikasi tutor oleh admin (ROADMAP 1.2; TT-4, TT-6). */
class TutorVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/verification-users-'.bin2hex(random_bytes(8)).'.json');
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

    // ---- Pendaftaran -------------------------------------------------------------------------

    public function test_register_page_offers_student_and_tutor_with_student_as_default_and_never_admin(): void
    {
        $html = $this->get('/register')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="account_type" value="student" checked/', $html);
        $this->assertStringContainsString('name="account_type" value="tutor"', $html);
        $this->assertStringContainsString('Saya ingin belajar', $html);
        $this->assertStringContainsString('Saya ingin mengajar', $html);
        $this->assertStringNotContainsString('value="admin"', $html);
    }

    public function test_registration_without_choice_creates_a_student(): void
    {
        $this->post('/register', $this->registration('Sita', 'sita@example.test'))->assertRedirect('/login');

        $this->assertSame('student', $this->storedRecord('sita@example.test')['role']);
        $this->assertDatabaseHas('user_roles', ['user_id' => '1', 'role' => 'student']);
        $this->assertDatabaseCount('tutor_profiles', 0);
    }

    public function test_registering_as_tutor_creates_an_unverified_tutor_that_is_never_active(): void
    {
        $this->post('/register', $this->registration('Gusti', 'gusti@example.test', 'tutor'))
            ->assertRedirect('/login')->assertSessionHas('status');

        $this->assertSame('tutor', $this->storedRecord('gusti@example.test')['role']);
        $this->assertDatabaseHas('user_roles', ['user_id' => '1', 'role' => 'tutor']);
        $this->assertDatabaseHas('user_role_changes', ['user_id' => '1', 'old_role' => null, 'new_role' => 'tutor', 'source' => 'registration']);
        $this->assertDatabaseHas('tutor_profiles', ['user_id' => '1', 'is_active' => 0]);

        $this->login('gusti@example.test');
        $html = $this->get('/dashboard')->assertOk()->getContent();
        $sidebar = Str::between($html, 'id="main-sidebar"', '</aside>');
        $this->assertStringContainsString('data-page-link="verification"', $sidebar);
        $this->assertStringContainsString('data-page-link="notifications"', $sidebar);
        $this->assertStringNotContainsString('data-page-link="materials"', $sidebar);
        $this->assertStringNotContainsString('data-spa-page="materials"', $html);
        $this->assertStringNotContainsString('data-material-form-dialog', $html);
        $this->assertStringContainsString('data-spa-page="verification"', $html);
        $this->assertStringContainsString('Belum lengkap', $html);
        $this->assertStringContainsString('Unggah kartu mahasiswa', $html);
        $this->assertStringNotContainsString('Tutor terverifikasi', $html);
    }

    public function test_admin_or_unknown_account_types_are_rejected_and_create_no_account(): void
    {
        foreach (['admin', 'superadmin', 'Tutor', ''] as $index => $type) {
            $response = $this->from('/register')->post('/register', $this->registration('Nakal', "nakal{$index}@example.test", $type));
            if ($type === '') {
                // Kosong = default Siswa (bukan error), tetapi tetap tidak pernah admin.
                $response->assertRedirect('/login');
                $this->assertSame('student', $this->storedRecord("nakal{$index}@example.test")['role']);
                continue;
            }
            $response->assertRedirect('/register')->assertSessionHasErrors('account_type');
            $this->assertNull($this->storedRecord("nakal{$index}@example.test"));
        }
        $this->assertDatabaseMissing('user_roles', ['role' => 'admin']);

        // Field `role`/`is_admin` palsu diabaikan.
        $this->post('/register', $this->registration('Licik', 'licik@example.test', 'tutor') + ['role' => 'admin', 'is_admin' => true])->assertRedirect('/login');
        $this->assertSame('tutor', $this->storedRecord('licik@example.test')['role']);
        $this->assertDatabaseMissing('user_roles', ['role' => 'admin']);
    }

    // ---- Batasan tutor belum terverifikasi (server, bukan hanya tombol) -----------------------

    public function test_unverified_tutor_gets_403_on_every_material_and_assignment_write(): void
    {
        $tutorId = $this->registerTutor('Gusti', 'gusti@example.test');
        $material = LearningMaterial::query()->create([
            'slug' => 'milik-gusti', 'title' => 'Milik Gusti', 'subject' => 'Matematika', 'grade_level' => 'SMP', 'tutor_name' => 'Gusti',
            'material_type' => 'summary', 'description' => 'Ringkasan.', 'illustration' => 'math', 'is_published' => true, 'created_by' => $tutorId,
        ]);
        $assignment = StudentAssignment::query()->create([
            'student_id' => '42', 'tutor_id' => $tutorId, 'tutor_name' => 'Gusti', 'title' => 'Latihan',
            'subject' => 'Matematika', 'instructions' => 'Kerjakan.', 'due_at' => now()->addDay(),
        ]);
        $submission = $assignment->submission()->create(['student_id' => '42', 'answer_text' => 'Jawaban', 'submitted_at' => now()]);
        $this->login('gusti@example.test');

        $message = 'Akun tutormu belum terverifikasi. Lengkapi profil dan kirim kartu mahasiswa untuk diverifikasi admin.';
        $this->postJson(route('dashboard.materials.store'), $this->materialPayload())->assertForbidden()->assertJsonPath('message', $message);
        $this->putJson(route('dashboard.materials.update', $material), $this->materialPayload())->assertForbidden();
        $this->deleteJson(route('dashboard.materials.destroy', $material))->assertForbidden();
        $this->postJson(route('dashboard.assignments.create'), ['student_id' => '42', 'title' => 'Baru', 'subject' => 'IPA', 'instructions' => 'X', 'due_at' => now()->addDay()->toDateTimeString()])->assertForbidden();
        $this->postJson(route('dashboard.assignments.grade', [$assignment, $submission]), ['score' => 90])->assertForbidden();
        $this->assertDatabaseCount('learning_materials', 1);
        $this->assertDatabaseHas('learning_materials', ['id' => $material->id, 'title' => 'Milik Gusti', 'deleted_at' => null]);
        $this->assertDatabaseHas('assignment_submissions', ['id' => $submission->id, 'score' => null]);
    }

    // ---- Profil, kartu, kirim ------------------------------------------------------------------

    public function test_profile_requires_every_verification_field_and_never_activates_the_tutor(): void
    {
        $this->registerTutor('Gusti', 'gusti@example.test');
        $this->login('gusti@example.test');

        $this->from('/dashboard')->post(route('dashboard.tutor-verification.profile'), [])
            ->assertSessionHasErrors(['education_level', 'institution', 'program', 'graduation_year', 'bio', 'subject_ids', 'avatar']);
        $this->post(route('dashboard.tutor-verification.profile'), ['bio' => 'Pendek'] + $this->profileData())->assertSessionHasErrors('bio');
        $this->post(route('dashboard.tutor-verification.profile'), ['education_level' => 'Profesor'] + $this->profileData())->assertSessionHasErrors('education_level');

        $this->post(route('dashboard.tutor-verification.profile'), $this->profileData())->assertRedirect('/dashboard#verification');
        $profile = TutorProfile::query()->where('user_id', '1')->firstOrFail();
        $this->assertSame('Universitas Contoh', $profile->institution);
        $this->assertSame([$this->math()->id], $profile->subjects()->pluck('subjects.id')->all());
        $this->assertNotNull($profile->avatar_path);
        $this->assertFalse($profile->is_active);
    }

    public function test_student_card_accepts_only_real_jpg_png_or_pdf_up_to_2mb_and_stores_it_privately(): void
    {
        $this->registerTutor('Gusti', 'gusti@example.test');
        $this->login('gusti@example.test');

        // File "sungguhan" (bukan UploadedFile::fake): MIME-nya dibaca dari ISI file seperti upload asli,
        // sehingga program .exe yang diberi nama kartu.pdf benar-benar teruji ditolak.
        $rejected = [
            $this->realUpload('kartu.gif', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x00\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"),
            $this->realUpload('kartu.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 64)),
            $this->realUpload('kartu.pdf', "%PDF-1.4\n".str_repeat('A', 2 * 1024 * 1024 + 10)),
        ];
        foreach ($rejected as $file) {
            $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $file])->assertSessionHasErrors('student_card');
        }
        $this->assertNull(TutorProfile::query()->where('user_id', '1')->value('student_card_path'));

        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakeImage('ktm.png')])->assertRedirect('/dashboard#verification');
        $firstPath = TutorProfile::query()->where('user_id', '1')->value('student_card_path');
        $this->assertStringStartsWith('tutor-documents/1/', $firstPath);
        Storage::disk('local')->assertExists($firstPath);

        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakePdf()])->assertRedirect('/dashboard#verification');
        $secondPath = TutorProfile::query()->where('user_id', '1')->value('student_card_path');
        $this->assertStringEndsWith('.pdf', $secondPath);
        Storage::disk('local')->assertExists($secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        $this->assertFileDoesNotExist(public_path($secondPath));
    }

    public function test_submit_requires_complete_data_then_locks_profile_and_card_while_pending(): void
    {
        $this->registerTutor('Gusti', 'gusti@example.test');
        $this->login('gusti@example.test');

        $this->post(route('dashboard.tutor-verification.submit'))->assertSessionHasErrors('verification');
        $this->assertDatabaseCount('tutor_applications', 0);

        $this->post(route('dashboard.tutor-verification.profile'), $this->profileData());
        $this->post(route('dashboard.tutor-verification.submit'))->assertSessionHasErrors('verification');
        $this->assertStringContainsString('Kartu mahasiswa', session('errors')->first('verification'));

        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakePdf()]);
        $this->post(route('dashboard.tutor-verification.submit'))->assertRedirect('/dashboard#verification')->assertSessionHas('status');

        $application = TutorApplication::query()->where('user_id', '1')->firstOrFail();
        $this->assertSame('pending', $application->status);
        $this->assertSame('Gusti', $application->applicant_name);
        $this->assertSame('Universitas Contoh', $application->institution);
        $this->assertSame(TutorProfile::query()->where('user_id', '1')->value('student_card_path'), $application->student_card_path);
        $this->assertSame([$this->math()->id], $application->subjects()->pluck('subjects.id')->all());
        $this->assertDatabaseHas('tutor_application_reviews', ['tutor_application_id' => $application->id, 'action' => 'submitted', 'to_status' => 'pending']);

        $this->post(route('dashboard.tutor-verification.profile'), $this->profileData())->assertStatus(409);
        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakePdf()])->assertStatus(409);
        $this->post(route('dashboard.tutor-verification.submit'))->assertStatus(409);
        $this->get('/dashboard')->assertOk()->assertSee('Menunggu verifikasi')->assertSee('fieldset class="verification-fieldset" disabled', false);
        $this->assertFalse(TutorProfile::query()->where('user_id', '1')->value('is_active'));
    }

    // ---- Akses file kartu ----------------------------------------------------------------------

    public function test_student_card_is_only_readable_by_its_owner_and_admins(): void
    {
        $this->registerTutor('Gusti', 'gusti@example.test');
        $this->login('gusti@example.test');
        $this->completeVerification();
        $application = TutorApplication::query()->where('user_id', '1')->firstOrFail();

        $own = $this->get(route('dashboard.tutor-verification.student-card.show'))->assertOk();
        $own->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', $own->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $own->headers->get('Cache-Control'));
        $this->get(route('dashboard.tutor-applications.student-card', $application))->assertOk();
        $this->post('/logout');

        $this->post('/register', $this->registration('Siswa Lain', 'siswa@example.test'));
        $this->login('siswa@example.test');
        $this->get(route('dashboard.tutor-applications.student-card', $application))->assertForbidden();
        $this->get(route('dashboard.tutor-verification.student-card.show'))->assertForbidden();
        $this->post('/logout');

        $this->registerTutor('Tutor Lain', 'tutorlain@example.test');
        $this->login('tutorlain@example.test');
        $this->get(route('dashboard.tutor-applications.student-card', $application))->assertForbidden();
        // Route kartu milik sendiri tidak pernah menyajikan kartu orang lain.
        $this->get(route('dashboard.tutor-verification.student-card.show'))->assertNotFound();
        $this->post('/logout');

        $this->signInAdmin();
        $this->get(route('dashboard.tutor-applications.student-card', $application))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/logout');

        // Tamu (belum login) diarahkan ke halaman masuk, tidak pernah mendapat file.
        $this->get(route('dashboard.tutor-applications.student-card', $application))->assertRedirect('/login');
        $this->get(route('dashboard.tutor-verification.student-card.show'))->assertRedirect('/login');
    }

    // ---- Keputusan admin -----------------------------------------------------------------------

    public function test_admin_page_lists_applications_with_status_filters_and_approval_verifies_the_tutor(): void
    {
        $tutorId = $this->registerTutor('Gusti', 'gusti@example.test');
        $this->login('gusti@example.test');
        $this->completeVerification();
        $this->post('/logout');
        $this->signInAdmin();

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $page = Str::between($html, 'data-spa-page="tutor-verifications"', '</section>');
        $this->assertStringContainsString('data-admin-filter="pending"', $page);
        $this->assertMatchesRegularExpression('/data-admin-filter="pending">Menunggu <span>1<\/span>/', $page);
        $this->assertMatchesRegularExpression('/data-admin-filter="approved">Disetujui <span>0<\/span>/', $page);
        $this->assertStringContainsString('Daftar sebagai tutor', $page);
        $this->assertStringContainsString('Universitas Contoh', $page);
        $this->assertStringContainsString('Buka PDF', $page);
        $application = TutorApplication::query()->where('user_id', $tutorId)->firstOrFail();
        $this->assertStringContainsString(route('dashboard.tutor-applications.student-card', $application), $page);

        $this->post(route('dashboard.admin.tutor-applications.decide', $application), ['decision' => 'approve'])->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('tutor_applications', ['id' => $application->id, 'status' => 'approved', 'reviewed_by' => '2']);
        $this->assertDatabaseHas('tutor_application_reviews', ['tutor_application_id' => $application->id, 'action' => 'approve', 'actor_id' => '2']);
        $this->assertDatabaseHas('user_roles', ['user_id' => $tutorId, 'role' => 'tutor']);
        $this->assertDatabaseMissing('user_role_changes', ['user_id' => $tutorId, 'source' => 'tutor_application_approval']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $tutorId, 'title' => 'Akun tutor terverifikasi']);
        $profile = TutorProfile::query()->where('user_id', $tutorId)->firstOrFail();
        $this->assertTrue($profile->is_active);
        $this->assertSame($application->student_card_path, $profile->student_card_path);
        $this->assertSame([$tutorId], TutorProfile::query()->verified()->pluck('user_id')->all());
        $this->post('/logout');

        $this->login('gusti@example.test');
        $html = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('data-page-link="materials"', $html);
        $this->assertStringNotContainsString('data-spa-page="verification"', $html);
        $this->assertStringContainsString('Tutor terverifikasi', $html);
        $this->postJson(route('dashboard.materials.store'), $this->materialPayload())->assertCreated();
        // Kartu terkunci setelah terverifikasi.
        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakePdf()])->assertStatus(409);
    }

    public function test_rejection_needs_a_reason_keeps_tutor_inactive_and_allows_fixing_and_resubmitting(): void
    {
        $tutorId = $this->registerTutor('Gusti', 'gusti@example.test');
        $this->login('gusti@example.test');
        $this->completeVerification();
        $this->post('/logout');
        $this->signInAdmin();
        $application = TutorApplication::query()->where('user_id', $tutorId)->firstOrFail();

        $this->post(route('dashboard.admin.tutor-applications.decide', $application), ['decision' => 'reject'])->assertSessionHasErrors('reason');
        $this->post(route('dashboard.admin.tutor-applications.decide', $application), ['decision' => 'reject', 'reason' => 'Foto kartu mahasiswa buram.'])->assertRedirect();
        $this->assertDatabaseHas('tutor_applications', ['id' => $application->id, 'status' => 'rejected', 'rejection_reason' => 'Foto kartu mahasiswa buram.']);
        $this->assertDatabaseHas('tutor_application_reviews', ['tutor_application_id' => $application->id, 'action' => 'reject', 'reason' => 'Foto kartu mahasiswa buram.']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $tutorId, 'title' => 'Pengajuan tutor belum disetujui', 'action_url' => '/dashboard#verification']);
        $this->assertFalse(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));
        $this->get('/dashboard')->assertSee('data-admin-filter="rejected">Ditolak <span>1</span>', false);
        $this->post('/logout');

        $this->login('gusti@example.test');
        $this->get('/dashboard')->assertOk()->assertSee('Ditolak')->assertSee('Foto kartu mahasiswa buram.');
        $this->postJson(route('dashboard.materials.store'), $this->materialPayload())->assertForbidden();
        // Edit Profil biasa tidak boleh mengaktifkan tutor yang belum terverifikasi.
        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti Prabu', 'institution' => 'Hack', 'is_active' => 1])->assertRedirect();
        $this->assertFalse(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));
        $this->assertSame('Universitas Contoh', TutorProfile::query()->where('user_id', $tutorId)->value('institution'));

        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakeImage('ktm-jelas.png')])->assertRedirect('/dashboard#verification');
        $this->post(route('dashboard.tutor-verification.submit'))->assertRedirect('/dashboard#verification');
        $this->assertDatabaseHas('tutor_applications', ['id' => $application->id, 'status' => 'pending', 'rejection_reason' => null]);
        $this->assertDatabaseHas('tutor_application_reviews', ['tutor_application_id' => $application->id, 'action' => 'resubmitted', 'from_status' => 'rejected']);
        $this->assertStringEndsWith('.png', TutorApplication::query()->whereKey($application->id)->value('student_card_path'));
        $this->assertFalse(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));
    }

    // ---- Alur lama siswa memakai jalur yang sama ----------------------------------------------

    public function test_student_application_requires_a_student_card_and_approval_makes_a_verified_tutor(): void
    {
        $this->post('/register', $this->registration('Sita', 'sita@example.test'));
        $this->login('sita@example.test');
        $application = [
            'applicant_name' => 'Sita', 'education_level' => 'S1', 'institution' => 'Universitas Contoh', 'program' => 'Pendidikan IPA',
            'graduation_year' => 2024, 'subject_ids' => [$this->math()->id], 'bio' => 'Saya senang membantu siswa memahami pelajaran.',
            'avatar' => $this->fakeImage('foto.png'),
        ];
        $this->post(route('dashboard.tutor-applications.store'), $application)->assertSessionHasErrors('student_card');
        $this->assertDatabaseCount('tutor_applications', 0);

        $this->post(route('dashboard.tutor-applications.store'), $application + ['student_card' => $this->fakePdf()])->assertRedirect('/dashboard#profile');
        $stored = TutorApplication::query()->where('user_id', '1')->firstOrFail();
        $this->assertSame('pending', $stored->status);
        $this->assertNotNull($stored->student_card_path);
        $this->get(route('dashboard.tutor-applications.student-card', $stored))->assertOk();
        $this->post('/logout');

        $this->signInAdmin();
        $this->assertStringContainsString('Siswa mengajukan diri', $this->get('/dashboard')->getContent());
        $this->post(route('dashboard.admin.tutor-applications.decide', $stored), ['decision' => 'approve'])->assertRedirect();
        $this->assertDatabaseHas('user_roles', ['user_id' => '1', 'role' => 'tutor']);
        $this->assertDatabaseHas('user_role_changes', ['user_id' => '1', 'source' => 'tutor_application_approval']);
        $this->post('/logout');

        $this->login('sita@example.test');
        $this->postJson(route('dashboard.materials.store'), $this->materialPayload())->assertCreated();
    }

    // ---- Data lama & hak akses route ----------------------------------------------------------

    public function test_existing_tutors_with_an_approved_application_are_verified_without_any_backfill(): void
    {
        $this->post('/register', $this->registration('Tutor Lama', 'lama@example.test'));
        DB::table('user_roles')->where('user_id', '1')->update(['role' => 'tutor']);
        // Seperti tutor@tutorkita.test & gudti: pengajuan approved sejak sebelum fitur ini, tanpa kartu.
        TutorApplication::query()->create([
            'user_id' => '1', 'applicant_name' => 'Tutor Lama', 'education_level' => 'S1', 'institution' => 'Kampus Lama',
            'program' => 'Matematika', 'graduation_year' => 2023, 'bio' => 'Tutor lama yang sudah disetujui admin.',
            'status' => 'approved', 'submitted_at' => now()->subWeek(), 'approved_at' => now()->subWeek(),
        ]);
        TutorProfile::query()->create(['user_id' => '1', 'institution' => 'Kampus Lama', 'is_active' => true]);
        $this->login('lama@example.test');

        $this->get('/dashboard')->assertOk()->assertSee('data-page-link="materials"', false)->assertSee('Tutor terverifikasi');
        $this->postJson(route('dashboard.materials.store'), $this->materialPayload())->assertCreated();
        $this->post('/logout');

        $this->signInAdmin();
        $page = Str::between($this->get('/dashboard')->getContent(), 'data-spa-page="tutor-verifications"', '</section>');
        $this->assertStringContainsString('Belum ada (data lama).', $page);
    }

    public function test_students_and_admins_cannot_use_tutor_verification_routes(): void
    {
        $this->post('/register', $this->registration('Sita', 'sita@example.test'));
        $this->login('sita@example.test');
        $this->post(route('dashboard.tutor-verification.profile'), $this->profileData())->assertForbidden();
        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakePdf()])->assertForbidden();
        $this->post(route('dashboard.tutor-verification.submit'))->assertForbidden();
        $this->post('/logout');

        $this->signInAdmin();
        $this->post(route('dashboard.tutor-verification.submit'))->assertForbidden();
        $this->assertDatabaseCount('tutor_applications', 0);
    }

    // ---- Helper --------------------------------------------------------------------------------

    private function registration(string $name, string $email, ?string $accountType = null): array
    {
        $data = ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on'];

        return $accountType === null ? $data : $data + ['account_type' => $accountType];
    }

    private function registerTutor(string $name, string $email): string
    {
        $this->post('/register', $this->registration($name, $email, 'tutor'))->assertRedirect('/login');

        return (string) $this->storedRecord($email)['id'];
    }

    private function login(string $email): void
    {
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');
    }

    private function signInAdmin(): void
    {
        $this->post('/register', $this->registration('Admin Uji', 'admin@example.test'));
        $id = (string) $this->storedRecord('admin@example.test')['id'];
        DB::table('user_roles')->where('user_id', $id)->update(['role' => 'admin']);
        $this->login('admin@example.test');
    }

    private function completeVerification(): void
    {
        $this->post(route('dashboard.tutor-verification.profile'), $this->profileData())->assertRedirect('/dashboard#verification');
        $this->post(route('dashboard.tutor-verification.student-card.store'), ['student_card' => $this->fakePdf()])->assertRedirect('/dashboard#verification');
        $this->post(route('dashboard.tutor-verification.submit'))->assertRedirect('/dashboard#verification');
    }

    private function profileData(): array
    {
        return [
            'education_level' => 'S1', 'institution' => 'Universitas Contoh', 'program' => 'Pendidikan Matematika',
            'graduation_year' => 2024, 'bio' => 'Saya senang membantu siswa memahami matematika dengan contoh sehari-hari.',
            'subject_ids' => [$this->math()->id], 'avatar' => $this->fakeImage('foto.png'),
        ];
    }

    private function materialPayload(): array
    {
        return ['title' => 'Materi Baru', 'subject' => 'Matematika', 'grade_level' => 'SMP', 'material_type' => 'video',
            'video_url' => 'https://video.example.test/x', 'description' => 'Video singkat.', 'is_published' => '1'];
    }

    private function math(): Subject
    {
        return Subject::query()->where('name', 'Matematika')->firstOrFail();
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(public_path('assets/tutorkita/tutorkita-ikon-app.png')));
    }

    /** UploadedFile asli (mode tes) dari file sementara; MIME ditebak dari isi file, bukan nama. */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ktm');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('ktm.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    /** @return array<string, mixed>|null */
    private function storedRecord(string $email): ?array
    {
        if (! is_file($this->userStorePath)) return null;
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
