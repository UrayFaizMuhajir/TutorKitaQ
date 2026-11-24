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

/** TT-10 / ROADMAP 5.2: admin melihat, mencari, dan memverifikasi siswa. */
class AdminStudentVerificationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;
    use \Tests\Concerns\CreatesBookableSlot;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/admin-student-users-'.bin2hex(random_bytes(8)).'.json');
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

    public function test_admin_page_lists_every_student_with_status_counts_search_data_and_safe_document_links(): void
    {
        $this->submittedStudent('Budi', 'budi@example.test', 'SMP Negeri 1');
        $this->post('/logout');
        $this->registerAndLogin('Citra', 'citra@example.test'); // belum mengisi profil
        $this->post('/logout');
        $this->registerAndLogin('Tutor', 'tutor@example.test', 'tutor'); // bukan siswa: tidak boleh muncul
        $this->post('/logout');
        $this->signInAdmin();

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $sidebar = Str::between($html, 'id="main-sidebar"', '</aside>');
        $this->assertMatchesRegularExpression('/data-page-link="student-verifications"[^>]*>.*?Verifikasi Siswa.*?nav-count[^>]*>1</s', $sidebar);
        $page = Str::between($html, 'data-spa-page="student-verifications"', '</section>');
        $this->assertMatchesRegularExpression('/data-admin-filter="pending">Menunggu <span>1<\/span>/', $page);
        $this->assertMatchesRegularExpression('/data-admin-filter="incomplete">Belum lengkap <span>1<\/span>/', $page);
        $this->assertMatchesRegularExpression('/data-admin-filter="all">Semua <span>2<\/span>/', $page);
        $this->assertStringContainsString('data-search="budi budi@example.test smp negeri 1', $page);
        $this->assertStringContainsString('Siswa ini belum mengisi profil siswa.', $page);
        $this->assertStringNotContainsString('tutor@example.test', $page);
        $this->assertStringContainsString('Ibu Sari', $page);
        $this->assertStringContainsString('Kec. Coblong, Bandung', $page);
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();
        $this->assertStringContainsString(route('dashboard.student-profiles.document', $profile), $page);
        $this->assertStringContainsString(route('dashboard.admin.student-profiles.decide', $profile), $page);
    }

    public function test_approval_verifies_the_student_logs_it_notifies_and_unlocks_session_requests(): void
    {
        $this->submittedStudent('Budi', 'budi@example.test');
        $this->post('/logout');
        $this->signInAdmin();
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();

        $this->post(route('dashboard.admin.student-profiles.decide', $profile), ['decision' => 'approve'])
            ->assertRedirect('/dashboard#student-verifications')->assertSessionHas('status');
        $profile->refresh();
        $this->assertSame('verified', $profile->status);
        $this->assertNotNull($profile->verified_at);
        $this->assertSame('2', $profile->reviewed_by);
        $this->assertDatabaseHas('student_verification_reviews', ['student_profile_id' => $profile->id, 'action' => 'approve', 'actor_id' => '2', 'to_status' => 'verified']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => '1', 'title' => 'Profil siswa terverifikasi']);
        $this->post(route('dashboard.admin.student-profiles.decide', $profile), ['decision' => 'approve'])->assertStatus(409);
        $this->post('/logout');

        $this->post('/login', ['contact' => 'budi@example.test', 'password' => 'TutorKita123!']);
        // Sejak pengajuan berbasis slot (ROADMAP 3.2): memesan slot tutor asli.
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($this->bookableSlot()))->assertCreated();
    }

    public function test_rejection_needs_a_reason_shows_it_to_the_student_and_allows_resubmission(): void
    {
        $this->submittedStudent('Budi', 'budi@example.test');
        $this->post('/logout');
        $this->signInAdmin();
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();

        $this->post(route('dashboard.admin.student-profiles.decide', $profile), ['decision' => 'reject'])->assertSessionHasErrors('reason');
        $this->post(route('dashboard.admin.student-profiles.decide', $profile), ['decision' => 'reject', 'reason' => 'Foto kartu pelajar tidak terbaca.'])->assertRedirect();
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id, 'status' => 'rejected', 'rejection_reason' => 'Foto kartu pelajar tidak terbaca.']);
        $this->assertDatabaseHas('student_verification_reviews', ['student_profile_id' => $profile->id, 'action' => 'reject', 'reason' => 'Foto kartu pelajar tidak terbaca.']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => '1', 'title' => 'Verifikasi profil siswa ditolak', 'action_url' => '/dashboard#student-verification']);
        $this->post('/logout');

        $this->post('/login', ['contact' => 'budi@example.test', 'password' => 'TutorKita123!']);
        $this->get('/dashboard')->assertSee('Ditolak')->assertSee('Foto kartu pelajar tidak terbaca.');
        $this->postJson(route('dashboard.sessions.store'), ['tutor_name' => 'Alya Putri'])->assertForbidden();
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()])->assertRedirect();
        $this->post(route('dashboard.student-verification.submit'))->assertRedirect('/dashboard#student-verification');
        $this->assertDatabaseHas('student_profiles', ['id' => $profile->id, 'status' => 'pending', 'rejection_reason' => null]);
        $this->assertDatabaseHas('student_verification_reviews', ['student_profile_id' => $profile->id, 'action' => 'resubmitted', 'from_status' => 'rejected']);
    }

    public function test_only_admins_can_decide_and_only_on_pending_profiles(): void
    {
        $this->submittedStudent('Budi', 'budi@example.test');
        $profile = StudentProfile::query()->where('user_id', '1')->firstOrFail();
        $this->post(route('dashboard.admin.student-profiles.decide', $profile), ['decision' => 'approve'])->assertForbidden();
        $this->post('/logout');

        $this->registerAndLogin('Tutor', 'tutor@example.test', 'tutor');
        $this->markTutorVerified((string) $this->record('tutor@example.test')['id']);
        $this->post(route('dashboard.admin.student-profiles.decide', $profile), ['decision' => 'approve'])->assertForbidden();
        // Tutor tidak pernah mendapat halaman Verifikasi Siswa.
        $this->assertStringNotContainsString('data-spa-page="student-verifications"', $this->get('/dashboard')->getContent());
        $this->post('/logout');
        $this->assertSame('pending', $profile->fresh()->status);

        $this->registerAndLogin('Dina', 'dina@example.test');
        $this->post(route('dashboard.student-verification.profile'), $this->profileData('SMP Dua'));
        $incomplete = StudentProfile::query()->where('user_id', (string) $this->record('dina@example.test')['id'])->firstOrFail();
        $this->post('/logout');
        $this->signInAdmin();
        $this->post(route('dashboard.admin.student-profiles.decide', $incomplete), ['decision' => 'approve'])->assertStatus(409);
        $this->assertSame('incomplete', $incomplete->fresh()->status);
        $this->assertDatabaseCount('student_verification_reviews', 1);
    }

    private function submittedStudent(string $name, string $email, string $school = 'SMP Negeri 1'): void
    {
        $this->registerAndLogin($name, $email);
        $this->post(route('dashboard.student-verification.profile'), $this->profileData($school))->assertRedirect();
        $this->post(route('dashboard.student-verification.document.store'), ['document_kind' => 'kartu_pelajar', 'student_document' => $this->fakePdf()])->assertRedirect();
        $this->post(route('dashboard.student-verification.submit'))->assertRedirect('/dashboard#student-verification');
    }

    private function signInAdmin(): void
    {
        $this->registerAndLogin('Admin Uji', 'admin@example.test');
        $id = (string) $this->record('admin@example.test')['id'];
        DB::table('user_roles')->where('user_id', $id)->update(['role' => 'admin']);
        Auth::logout();
        Auth::login(Auth::guard()->getProvider()->retrieveById($id));
    }

    private function registerAndLogin(string $name, string $email, ?string $accountType = null): void
    {
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on']
            + ($accountType ? ['account_type' => $accountType] : []))->assertRedirect('/login');
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');
    }

    private function profileData(string $school): array
    {
        return [
            'school' => $school, 'grade_level_id' => GradeLevel::query()->where('name', 'SMP')->value('id'), 'class_name' => '8B',
            'guardian_name' => 'Ibu Sari', 'guardian_contact' => '081234567890', 'region' => 'Kec. Coblong, Bandung',
        ];
    }

    private function fakePdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('dokumen.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    }

    /** @return array<string, mixed> */
    private function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
