<?php

namespace Tests\Feature;

use App\Models\LearningSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresi bug 2026-11-17: kartu sesi dan dropdown tutor sama-sama memakai atribut data-session-tutor,
 * sehingga dashboard.js mengambil kartu sesi sebagai <select> dan seluruh inisialisasi berikutnya
 * (Lihat Detail, Batalkan Sesi, menu profil, logout) ikut mati.
 */
class DashboardSessionRenderTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedStudent;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userStorePath = storage_path('framework/testing/dashboard-render-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) {
            @unlink($this->userStorePath);
        }

        parent::tearDown();
    }

    public function test_session_form_hooks_do_not_collide_with_session_card_attributes(): void
    {
        $this->registerAndSignIn('Budi', 'budi@test.com');
        // Sejak verifikasi siswa (ROADMAP 5.1), form pengajuan hanya dirender untuk siswa terverifikasi.
        $this->markStudentVerified((string) auth()->id());
        $this->createSessionFor((string) auth()->id());

        $html = $this->get('/dashboard')->assertOk()->assertSee('data-session-card', false)->getContent();

        $this->assertSame(1, substr_count($html, 'data-session-tutor-select'));
        // Sejak pengajuan berbasis slot (ROADMAP 3.2): select tutor bernama tutor_id dan ada select slot.
        $this->assertMatchesRegularExpression('/<select[^>]*name="tutor_id"[^>]*data-session-tutor-select/', $html);
        $this->assertMatchesRegularExpression('/<select[^>]*name="slot_id"[^>]*data-session-slot-select/', $html);
        $this->assertSame(0, preg_match_all('/data-session-tutor(?=[\s>])/', $html), 'Atribut data-session-tutor tanpa nilai hanya boleh ada di kartu sesi.');
        $this->assertSame(1, substr_count($html, 'data-session-tutor="Alya Putri"'));
    }

    public function test_tutor_with_an_existing_session_still_gets_a_working_dashboard_shell(): void
    {
        $this->registerAndSignIn('Gudti', 'gudti@test.com');
        $userId = (string) auth()->id();
        DB::table('user_roles')->where('user_id', $userId)->update(['role' => 'tutor']);
        Auth::logout();
        Auth::login(Auth::guard()->getProvider()->retrieveById($userId));
        $this->createSessionFor($userId, ['topic' => '[p[[l,']);

        // Sejak ROADMAP 0.1 halaman Sesi tidak ada di menu tutor (menyusul di 3.3), jadi sesi lama
        // akun ini tidak dirender; kerangka dashboard (menu profil, logout) tetap harus berfungsi.
        $html = $this->get('/dashboard')->assertOk()
            ->assertSee('<small class="profile-role-badge">Tutor</small>', false)
            ->assertDontSee('data-spa-page="sessions"', false)
            ->assertDontSee('data-session-card', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'data-profile-toggle'), 'Menu profil harus bisa dibuka dari topbar dan dari sidebar.');
        $this->assertMatchesRegularExpression('/<button class="sidebar-user-profile"[^>]*type="button"[^>]*data-profile-toggle/', $html);
        $this->assertMatchesRegularExpression('/<form method="post" action="[^"]*\/logout"/', $html);
    }

    /** @param array<string, mixed> $overrides */
    private function createSessionFor(string $studentId, array $overrides = []): LearningSession
    {
        return LearningSession::query()->create($overrides + [
            'student_id' => $studentId,
            'tutor_name' => 'Alya Putri',
            'tutor_university' => 'Universitas Indonesia',
            'tutor_major' => 'Pendidikan Matematika',
            'subject' => 'IPA',
            'topic' => 'Ekosistem',
            'grade_level' => 'SMP',
            'starts_at' => now()->addDays(7)->setTime(14, 12),
            'duration_minutes' => 60,
            'meeting_platform' => 'Google Meet',
            'status' => 'pending',
        ]);
    }

    private function registerAndSignIn(string $name, string $contact): void
    {
        $this->post('/register', [
            'name' => $name,
            'contact' => $contact,
            'password' => 'TutorKita123!',
            'password_confirmation' => 'TutorKita123!',
            'terms' => 'on',
        ]);
        $this->post('/login', ['contact' => $contact, 'password' => 'TutorKita123!']);
    }
}
