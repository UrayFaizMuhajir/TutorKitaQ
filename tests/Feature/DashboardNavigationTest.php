<?php

namespace Tests\Feature;

use App\Models\AssignmentSubmission;
use App\Models\LearningMaterial;
use App\Models\StudentAssignment;
use App\Models\TutorApplication;
use App\Support\DashboardNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ROADMAP 0.1: tampilan per role (menu, beranda, halaman SPA yang dirender). */
class DashboardNavigationTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/navigation-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
        $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        parent::tearDown();
    }

    public function test_menu_definition_is_a_single_source_per_role_and_unknown_roles_fall_back_to_student(): void
    {
        // Sejak verifikasi siswa (ROADMAP 5.1), menu Siswa punya item ke-8: Profil & Verifikasi.
        $this->assertSame(['dashboard', 'tutors', 'sessions', 'materials', 'assignments', 'notifications', 'history', 'student-verification'], DashboardNavigation::forRole('student')['pages']);
        $this->assertSame(['dashboard', 'materials', 'notifications'], DashboardNavigation::forRole('tutor')['pages']);
        // Sejak verifikasi tutor: Verifikasi Tutor jadi halaman; Pengguna tetap dialog.
        $this->assertSame(['dashboard', 'tutor-verifications', 'notifications'], DashboardNavigation::forRole('admin')['pages']);
        $this->assertSame(['admin-users'], array_values(array_filter(array_column(DashboardNavigation::forRole('admin')['items'], 'action'))));
        // Tutor yang belum terverifikasi: Beranda, Profil & Verifikasi, Notifikasi.
        $this->assertSame(['dashboard', 'verification', 'notifications'], DashboardNavigation::forRole('tutor', false)['pages']);
        $this->assertSame('tutor-unverified', DashboardNavigation::forRole('tutor', false)['home']);
        $this->assertSame(DashboardNavigation::forRole('student')['pages'], DashboardNavigation::forRole('student', false)['pages']);

        $this->assertSame('Siswa', DashboardNavigation::roleLabel('student'));
        $this->assertSame('Tutor', DashboardNavigation::roleLabel('tutor'));
        $this->assertSame('Admin', DashboardNavigation::roleLabel('admin'));
        $this->assertSame('Siswa', DashboardNavigation::roleLabel('aneh'));

        foreach ([null, '', 'superadmin', 'Admin'] as $unknown) {
            $navigation = DashboardNavigation::forRole($unknown);
            $this->assertSame('student', $navigation['role']);
            $this->assertSame(DashboardNavigation::forRole('student')['pages'], $navigation['pages']);
        }
    }

    public function test_student_sees_the_learning_menu_and_every_student_page(): void
    {
        $this->signInAs('Sita', 'sita@example.test', 'student');

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $sidebar = $this->sidebar($html);

        foreach (['dashboard', 'tutors', 'sessions', 'materials', 'assignments', 'notifications', 'history', 'student-verification'] as $page) {
            $this->assertStringContainsString('data-page-link="'.$page.'"', $sidebar);
            $this->assertStringContainsString('data-spa-page="'.$page.'"', $html);
        }
        $this->assertStringContainsString('aria-label="Menu belajar"', $sidebar);
        $this->assertStringNotContainsString('data-open-admin', $html);
        $this->assertStringContainsString('<span>Ruang belajarmu</span>', $html);
        $this->assertStringContainsString('Jadwal belajar', $html);
        $this->assertStringContainsString('data-integrated-subjects', $html);
        $this->assertStringContainsString('data-material-area="subjects"', $html);
        $this->assertStringContainsString('Ajukan Menjadi Tutor', $this->profileMenu($html));
    }

    public function test_all_sessions_tab_heading_is_semua_sesi_not_sesi_mendatang(): void
    {
        $this->signInAs('Sita', 'sita@example.test', 'student');

        $this->get('/dashboard')->assertOk()
            ->assertSee('<h2 data-session-heading>Semua Sesi</h2>', false)
            ->assertSee('Semua sesi belajarmu: mendatang, selesai, dan dibatalkan.', false)
            ->assertDontSee('<h2 data-session-heading>Sesi Mendatang</h2>', false);
    }

    public function test_tutor_sees_the_teaching_menu_with_real_counts_and_no_student_only_pages(): void
    {
        $tutorId = $this->signInAs('Gudti', 'gudti@example.test', 'tutor');
        $this->material($tutorId, 'Pecahan Dasar', true);
        $this->material($tutorId, 'Bangun Datar', true);
        $this->material($tutorId, 'Draf Aljabar', false);
        $this->material('99', 'Materi Tutor Lain', true);
        $this->material($tutorId, 'Materi Diarsipkan', true)->delete();
        $assignment = StudentAssignment::query()->create([
            'student_id' => '42', 'tutor_id' => $tutorId, 'tutor_name' => 'Gudti', 'title' => 'Latihan 1',
            'subject' => 'Matematika', 'instructions' => 'Kerjakan.', 'due_at' => now()->addDay(),
        ]);
        AssignmentSubmission::query()->create(['student_assignment_id' => $assignment->id, 'student_id' => '42', 'answer_text' => 'Jawaban', 'submitted_at' => now()]);
        $otherAssignment = StudentAssignment::query()->create([
            'student_id' => '42', 'tutor_id' => '99', 'tutor_name' => 'Lain', 'title' => 'Tugas lain',
            'subject' => 'IPA', 'instructions' => 'Kerjakan.', 'due_at' => now()->addDay(),
        ]);
        AssignmentSubmission::query()->create(['student_assignment_id' => $otherAssignment->id, 'student_id' => '42', 'answer_text' => 'Jawaban', 'submitted_at' => now()]);

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $sidebar = $this->sidebar($html);

        foreach (['dashboard', 'materials', 'notifications'] as $page) {
            $this->assertStringContainsString('data-page-link="'.$page.'"', $sidebar);
            $this->assertStringContainsString('data-spa-page="'.$page.'"', $html);
        }
        foreach (['tutors', 'sessions', 'assignments', 'history'] as $page) {
            $this->assertStringNotContainsString('data-page-link="'.$page.'"', $sidebar);
            $this->assertStringNotContainsString('data-spa-page="'.$page.'"', $html);
        }
        $this->assertStringContainsString('aria-label="Menu mengajar"', $sidebar);
        $this->assertStringContainsString('<span>Ruang mengajarmu</span>', $html);
        $this->assertStringNotContainsString('data-open-admin', $html);
        // "Mata Pelajaranku" khusus siswa, juga bila tutor membuka ?materials=subjects.
        $this->assertStringNotContainsString('data-integrated-subjects', $html);
        $this->assertStringNotContainsString('data-subject-confirm-dialog', $html);
        $this->assertStringContainsString('data-material-create-open', $html);
        $this->assertStringNotContainsString('data-materials-panel hidden', $this->get('/dashboard?materials=subjects')->getContent());

        $this->assertStat($html, 'Materi Terbit', '2');
        $this->assertStat($html, 'Draf Materi', '1');
        $this->assertStat($html, 'Tugas Diberikan', '1');
        $this->assertStat($html, 'Menunggu Dinilai', '1');
        $home = Str::between($html, 'data-spa-page="dashboard"', 'data-spa-page="materials"');
        $this->assertStringContainsString('Draf Aljabar', $home);
        $this->assertStringContainsString('Draf</span>', $home);
        $this->assertStringNotContainsString('Materi Tutor Lain', $home);
        $this->assertStringNotContainsString('Materi Diarsipkan', $html);
        $this->assertStringContainsString('Tutor terverifikasi', $this->profileMenu($html));
    }

    public function test_admin_actions_live_in_the_sidebar_only_and_home_shows_real_counts(): void
    {
        $studentId = $this->signInAs('Sita', 'sita@example.test', 'student');
        $this->post('/logout');
        $this->signInAs('Gudti', 'gudti@example.test', 'tutor');
        $this->post('/logout');
        TutorApplication::query()->create([
            'user_id' => $studentId, 'applicant_name' => 'Sita Calon Tutor', 'education_level' => 'S1',
            'institution' => 'Universitas Contoh', 'program' => 'Pendidikan Matematika', 'graduation_year' => 2024,
            'bio' => 'Saya senang membantu siswa memahami pelajaran.', 'status' => 'pending', 'submitted_at' => now(),
        ]);
        $this->signInAs('Admin Uji', 'admin@example.test', 'admin');

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $sidebar = $this->sidebar($html);
        $profileMenu = $this->profileMenu($html);

        $this->assertStringContainsString('aria-label="Menu admin"', $sidebar);
        // Sejak verifikasi tutor, Verifikasi Tutor adalah halaman (bukan dialog); Pengguna tetap dialog.
        $this->assertStringContainsString('data-page-link="tutor-verifications"', $sidebar);
        $this->assertStringContainsString('data-open-admin-users', $sidebar);
        $this->assertMatchesRegularExpression('/data-page-link="tutor-verifications"[^>]*>.*?Verifikasi Tutor.*?nav-count[^>]*>1</s', $sidebar);
        $this->assertStringNotContainsString('data-open-admin', $profileMenu);
        $this->assertStringContainsString('Edit Profil', $profileMenu);
        $this->assertStringContainsString('<span>Panel admin</span>', $html);

        foreach (['dashboard', 'tutor-verifications', 'notifications'] as $page) {
            $this->assertStringContainsString('data-spa-page="'.$page.'"', $html);
        }
        foreach (['tutors', 'sessions', 'materials', 'assignments', 'history', 'verification'] as $page) {
            $this->assertStringNotContainsString('data-page-link="'.$page.'"', $sidebar);
            $this->assertStringNotContainsString('data-spa-page="'.$page.'"', $html);
        }
        // Dialog Pengguna tetap dirender agar tombol sidebar bisa membukanya; dialog verifikasi lama sudah diganti halaman.
        $this->assertStringNotContainsString('data-admin-verification-dialog', $html);
        $this->assertStringContainsString('data-admin-users-dialog', $html);

        $this->assertStat($html, 'Pengajuan Tutor', '1');
        $this->assertStat($html, 'Siswa', '1');
        $this->assertStat($html, 'Tutor', '1');
        $this->assertStat($html, 'Admin', '1');
        $this->assertStringContainsString('Universitas Contoh · diajukan', $html);
    }

    private function signInAs(string $name, string $email, string $role): string
    {
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on'])->assertRedirect('/login');
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');
        $userId = (string) auth()->id();
        if ($role !== 'student') {
            DB::table('user_roles')->where('user_id', $userId)->update(['role' => $role]);
            Auth::logout();
            Auth::login(Auth::guard()->getProvider()->retrieveById($userId));
        }
        // Tes 0.1 memakai tutor terverifikasi (menu Materi); tutor belum terverifikasi dites di TutorVerificationTest.
        if ($role === 'tutor') $this->markTutorVerified($userId, $name);

        return $userId;
    }

    private function material(string $ownerId, string $title, bool $published): LearningMaterial
    {
        return LearningMaterial::query()->create([
            'slug' => Str::slug($title).'-'.Str::random(5), 'title' => $title, 'subject' => 'Matematika', 'grade_level' => 'SMP',
            'tutor_name' => 'Tutor', 'material_type' => 'summary', 'description' => 'Ringkasan.', 'illustration' => 'math',
            'is_published' => $published, 'published_at' => $published ? now() : null, 'created_by' => $ownerId,
        ]);
    }

    private function assertStat(string $html, string $label, string $value): void
    {
        $this->assertStringContainsString('<div class="stat-value">'.$value.'</div><div class="stat-label">'.$label.'</div>', $html);
    }

    private function sidebar(string $html): string
    {
        return Str::between($html, 'id="main-sidebar"', '</aside>');
    }

    private function profileMenu(string $html): string
    {
        return Str::between($html, 'data-profile-menu', 'profile-menu-logout-form');
    }
}
