<?php

namespace Tests\Feature;

use App\Models\LearningMaterial;
use App\Models\StudentAssignment;
use App\Models\TutorProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ROADMAP 1.3 / TT-6 sisa: admin menonaktifkan dan mengaktifkan kembali tutor. */
class TutorStatusTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/status-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
        $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        parent::tearDown();
    }

    public function test_admin_deactivates_with_a_reason_logs_it_and_notifies_the_tutor(): void
    {
        $tutorId = $this->createVerifiedTutor('Gusti', 'gusti@example.test');
        $this->signIn('Admin Uji', 'admin@example.test', 'admin');

        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate'])->assertSessionHasErrors('reason');
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'abc'])->assertSessionHasErrors('reason');
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'hapus', 'reason' => 'Alasan cukup panjang'])->assertSessionHasErrors('action');
        $this->assertTrue(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));

        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'Laporan materi tidak sesuai.'])
            ->assertRedirect('/dashboard?admin_tab=tutors#tutor-verifications')->assertSessionHas('status');
        $this->assertFalse(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));
        $this->assertDatabaseHas('tutor_status_changes', ['user_id' => $tutorId, 'actor_id' => '2', 'action' => 'deactivate', 'reason' => 'Laporan materi tidak sesuai.']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $tutorId, 'title' => 'Akun tutor dinonaktifkan']);
        // Status ganda ditolak.
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'Coba lagi nonaktif.'])->assertStatus(409);
        $this->assertSame([], TutorProfile::query()->verified()->active()->pluck('user_id')->all());

        $adminHtml = $this->get('/dashboard?admin_tab=tutors')->getContent();
        // Setelah aksi status, admin kembali ke tab Tutor Terverifikasi (dirender terbuka oleh server).
        $this->assertStringContainsString('data-admin-tab-panel="tutors" >', $adminHtml);
        $this->assertStringContainsString('data-admin-tab-panel="applications"  hidden', $adminHtml);
        $page = Str::between($adminHtml, 'data-admin-tab-panel="tutors"', '</section>');
        $this->assertMatchesRegularExpression('/data-admin-filter="inactive">Nonaktif <span>1<\/span>/', $page);
        $this->assertStringContainsString('Dinonaktifkan', $page);
        $this->assertStringContainsString('Laporan materi tidak sesuai.', $page);
        $this->assertStringContainsString('Aktifkan kembali', $page);

        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'activate', 'reason' => 'Sudah diklarifikasi.'])->assertRedirect();
        $this->assertTrue(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));
        $this->assertDatabaseHas('tutor_status_changes', ['user_id' => $tutorId, 'action' => 'activate', 'reason' => 'Sudah diklarifikasi.']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $tutorId, 'title' => 'Akun tutor diaktifkan kembali']);
        $this->assertSame([$tutorId], TutorProfile::query()->verified()->active()->pluck('user_id')->all());
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'activate', 'reason' => 'Aktifkan lagi.'])->assertStatus(409);
    }

    public function test_inactive_tutor_can_log_in_and_read_but_every_write_is_blocked_with_the_reason(): void
    {
        $tutorId = $this->createVerifiedTutor('Gusti', 'gusti@example.test');
        $material = LearningMaterial::query()->create([
            'slug' => 'milik-gusti', 'title' => 'Milik Gusti', 'subject' => 'Matematika', 'grade_level' => 'SMP', 'tutor_name' => 'Gusti',
            'material_type' => 'summary', 'description' => 'Ringkasan.', 'illustration' => 'math', 'is_published' => true, 'created_by' => $tutorId,
        ]);
        $assignment = StudentAssignment::query()->create([
            'student_id' => '42', 'tutor_id' => $tutorId, 'tutor_name' => 'Gusti', 'title' => 'Latihan', 'subject' => 'Matematika',
            'instructions' => 'Kerjakan.', 'due_at' => now()->addDay(),
        ]);
        $submission = $assignment->submission()->create(['student_id' => '42', 'answer_text' => 'Jawaban', 'submitted_at' => now()]);
        $this->signIn('Admin Uji', 'admin@example.test', 'admin');
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'Laporan materi tidak sesuai.']);
        $this->post('/logout');

        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');
        $html = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('data-tutor-inactive', $html);
        $this->assertStringContainsString('Akun dinonaktifkan', $html);
        $this->assertStringContainsString('Laporan materi tidak sesuai.', $html);
        $this->assertStringContainsString('Akun tutor dinonaktifkan admin', $html);
        // Menu Materi tetap ada untuk membaca, tetapi tombol & dialog kelola disembunyikan.
        $this->assertStringContainsString('data-page-link="materials"', $html);
        $this->assertStringContainsString('data-spa-page="materials"', $html);
        $this->assertStringNotContainsString('data-material-create-open', $html);
        $this->assertStringNotContainsString('data-material-form-dialog', $html);
        $this->assertStringNotContainsString('data-material-management-actions', $html);
        $this->getJson(route('dashboard.materials.show', $material))->assertOk();

        $message = 'Akun tutormu sedang dinonaktifkan admin. Alasan: Laporan materi tidak sesuai.';
        $this->postJson(route('dashboard.materials.store'), $this->materialPayload())->assertForbidden()->assertJsonPath('message', $message);
        $this->putJson(route('dashboard.materials.update', $material), $this->materialPayload())->assertForbidden();
        $this->deleteJson(route('dashboard.materials.destroy', $material))->assertForbidden();
        $this->postJson(route('dashboard.assignments.create'), ['student_id' => '42', 'title' => 'Baru', 'subject' => 'IPA', 'instructions' => 'X', 'due_at' => now()->addDay()->toDateTimeString()])->assertForbidden();
        $this->postJson(route('dashboard.assignments.grade', [$assignment, $submission]), ['score' => 90])->assertForbidden();
        // Edit Profil tidak bisa mengaktifkan kembali.
        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti', 'is_active' => 1])->assertRedirect();
        $this->assertFalse(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));
        $this->assertDatabaseHas('learning_materials', ['id' => $material->id, 'title' => 'Milik Gusti', 'deleted_at' => null]);
        $this->post('/logout');

        $this->signIn('Admin Dua', 'admin2@example.test', 'admin');
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'activate', 'reason' => 'Sudah diklarifikasi.']);
        $this->post('/logout');
        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!']);
        $this->postJson(route('dashboard.materials.store'), $this->materialPayload())->assertCreated();
        $this->assertStringContainsString('data-material-create-open', $this->get('/dashboard')->getContent());
    }

    public function test_only_admins_can_change_status_and_only_for_verified_tutors(): void
    {
        $tutorId = $this->createVerifiedTutor('Gusti', 'gusti@example.test');
        $this->post('/register', $this->registration('Calon', 'calon@example.test') + ['account_type' => 'tutor']);
        $unverifiedId = (string) $this->record('calon@example.test')['id'];
        $this->post('/register', $this->registration('Sita', 'sita@example.test'));
        $studentId = (string) $this->record('sita@example.test')['id'];

        $this->post('/login', ['contact' => 'sita@example.test', 'password' => 'TutorKita123!']);
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'Siswa mencoba.'])->assertForbidden();
        $this->post('/logout');
        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!']);
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'Tutor mencoba.'])->assertForbidden();
        $this->post('/logout');

        $this->signIn('Admin Uji', 'admin@example.test', 'admin');
        $this->post(route('dashboard.admin.tutors.status', $unverifiedId), ['action' => 'deactivate', 'reason' => 'Belum terverifikasi.'])->assertStatus(409);
        $this->post(route('dashboard.admin.tutors.status', $studentId), ['action' => 'deactivate', 'reason' => 'Ini akun siswa.'])->assertNotFound();
        $this->post(route('dashboard.admin.tutors.status', '999'), ['action' => 'deactivate', 'reason' => 'Tidak ada akun.'])->assertNotFound();
        $this->assertDatabaseCount('tutor_status_changes', 0);
        $this->assertTrue(TutorProfile::query()->where('user_id', $tutorId)->value('is_active'));
    }

    private function createVerifiedTutor(string $name, string $email): string
    {
        $this->post('/register', $this->registration($name, $email) + ['account_type' => 'tutor'])->assertRedirect('/login');
        $id = (string) $this->record($email)['id'];
        $this->markTutorVerified($id, $name);

        return $id;
    }

    private function signIn(string $name, string $email, string $role): void
    {
        $this->post('/register', $this->registration($name, $email));
        $id = (string) $this->record($email)['id'];
        DB::table('user_roles')->where('user_id', $id)->update(['role' => $role]);
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');
    }

    private function registration(string $name, string $email): array
    {
        return ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on'];
    }

    private function materialPayload(): array
    {
        return ['title' => 'Materi Baru', 'subject' => 'Matematika', 'grade_level' => 'SMP', 'material_type' => 'video',
            'video_url' => 'https://video.example.test/x', 'description' => 'Video singkat.', 'is_published' => '1'];
    }

    /** @return array<string, mixed> */
    private function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
