<?php

namespace Tests\Feature;

use App\Models\TutorApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Kolom "Pekerjaan" opsional di profil (semua role), disimpan di LocalUserStore (users.json). */
class OccupationTest extends TestCase
{
    use RefreshDatabase;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/occupation-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
        $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        parent::tearDown();
    }

    public function test_occupation_is_saved_and_shown_under_the_name_in_topbar_sidebar_and_profile_menu(): void
    {
        $this->registerAndLogin('Gusti Prabu', 'gusti@example.test');

        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti Prabu', 'occupation' => '  Gojek  '])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('Gojek', $this->record('gusti@example.test')['occupation']);
        $this->refreshSignedInUser('gusti@example.test');

        $html = $this->get('/dashboard')->assertOk()->getContent();
        // Topbar: nama lalu pekerjaan.
        $this->assertStringContainsString('<span class="profile-copy"><strong>Gusti Prabu</strong><small data-user-subtitle>Gojek</small></span>', $html);
        // Sidebar kiri bawah: nama lalu pekerjaan.
        $this->assertStringContainsString('<span><strong>Gusti Prabu</strong><small data-user-subtitle>Gojek</small></span>', Str::between($html, 'id="main-sidebar"', '</aside>'));
        // Menu profil: pekerjaan di bawah nama, peran tetap terlihat sebagai badge.
        $menu = Str::between($html, 'data-profile-menu', 'profile-menu-logout-form');
        $this->assertMatchesRegularExpression('/<strong>Gusti Prabu<\/strong><small class="profile-menu-occupation" data-user-occupation>Gojek<\/small>/', $menu);
        $this->assertStringContainsString('<small class="profile-role-badge">Student</small>', $menu);
        // Field Edit Profil terisi nilai tersimpan.
        $this->assertStringContainsString('name="occupation" value="Gojek" maxlength="60"', $html);
    }

    public function test_without_occupation_the_role_is_shown_and_untouched_accounts_do_not_change(): void
    {
        $this->registerAndLogin('Sita', 'sita@example.test');
        $before = file_get_contents($this->userStorePath);

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="profile-copy"><strong>Sita</strong><small data-user-subtitle>Student</small></span>', $html);
        $this->assertStringNotContainsString('data-user-occupation', $html);
        // Membuka dashboard tidak menulis apa pun ke akun.
        $this->assertSame($before, file_get_contents($this->userStorePath));
        $this->assertArrayNotHasKey('occupation', $this->record('sita@example.test'));

        // Menyimpan profil tanpa mengisi pekerjaan tidak menambah kunci occupation.
        $this->post(route('dashboard.profile.update'), ['name' => 'Sita', 'occupation' => ''])->assertRedirect();
        $this->post(route('dashboard.profile.update'), ['name' => 'Sita'])->assertRedirect();
        $this->assertArrayNotHasKey('occupation', $this->record('sita@example.test'));

        // Tutor & Admin tanpa pekerjaan → peran masing-masing.
        $this->post('/logout');
        $this->registerAndLogin('Tutor Baru', 'tutor@example.test', ['account_type' => 'tutor']);
        $this->assertStringContainsString('<small data-user-subtitle>Tutor</small>', $this->get('/dashboard')->getContent());
        $this->post('/logout');
        $this->registerAndLogin('Admin Uji', 'admin@example.test');
        DB::table('user_roles')->where('user_id', (string) $this->record('admin@example.test')['id'])->update(['role' => 'admin']);
        Auth::logout();
        Auth::login(Auth::guard()->getProvider()->retrieveById((string) $this->record('admin@example.test')['id']));
        $this->assertStringContainsString('<small data-user-subtitle>Admin</small>', $this->get('/dashboard')->getContent());
        // Akun lain yang tidak pernah mengisi pekerjaan tetap tanpa kunci occupation.
        $this->assertArrayNotHasKey('occupation', $this->record('sita@example.test'));
    }

    public function test_clearing_occupation_falls_back_to_the_role_and_length_is_validated(): void
    {
        $this->registerAndLogin('Gusti', 'gusti@example.test');

        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti', 'occupation' => str_repeat('a', 61)])->assertSessionHasErrors('occupation');
        $this->assertSame('Pekerjaan maksimal 60 karakter.', session('errors')->first('occupation'));
        $this->assertArrayNotHasKey('occupation', $this->record('gusti@example.test'));

        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti', 'occupation' => str_repeat('b', 60)])->assertSessionHasNoErrors();
        $this->assertSame(str_repeat('b', 60), $this->record('gusti@example.test')['occupation']);

        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti', 'occupation' => ''])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('occupation', $this->record('gusti@example.test'));
        $this->refreshSignedInUser('gusti@example.test');
        $this->assertStringContainsString('<small data-user-subtitle>Student</small>', $this->get('/dashboard')->getContent());
    }

    public function test_occupation_output_is_escaped(): void
    {
        $this->registerAndLogin('Gusti', 'gusti@example.test');
        $payload = '<script>alert(1)</script>';

        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti', 'occupation' => $payload])->assertSessionHasNoErrors();
        $this->refreshSignedInUser('gusti@example.test');
        $html = $this->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_admin_sees_occupations_in_users_list_and_tutor_verification_page(): void
    {
        $this->registerAndLogin('Gusti Prabu', 'gusti@example.test', ['account_type' => 'tutor']);
        $this->post(route('dashboard.profile.update'), ['name' => 'Gusti Prabu', 'occupation' => 'Driver Gojek']);
        $gustiId = (string) $this->record('gusti@example.test')['id'];
        TutorApplication::query()->create([
            'user_id' => $gustiId, 'applicant_name' => 'Gusti Prabu', 'education_level' => 'S1', 'institution' => 'Universitas Contoh',
            'program' => 'Matematika', 'graduation_year' => 2024, 'bio' => 'Bio calon tutor yang cukup panjang.', 'status' => 'pending', 'submitted_at' => now(),
        ]);
        $this->post('/logout');

        $this->registerAndLogin('Admin Uji', 'admin@example.test');
        $adminId = (string) $this->record('admin@example.test')['id'];
        DB::table('user_roles')->where('user_id', $adminId)->update(['role' => 'admin']);
        Auth::logout();
        Auth::login(Auth::guard()->getProvider()->retrieveById($adminId));

        $html = $this->get('/dashboard')->assertOk()->getContent();
        $users = Str::between($html, 'data-admin-users-dialog', '</dialog>');
        $this->assertStringContainsString('<strong>Gusti Prabu</strong><small class="admin-user-occupation">Driver Gojek</small>', $users);
        $verification = Str::between($html, 'data-spa-page="tutor-verifications"', '</section>');
        $this->assertStringContainsString('<p class="verification-occupation">Driver Gojek</p>', $verification);
    }

    private function registerAndLogin(string $name, string $email, array $extra = []): void
    {
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on'] + $extra)->assertRedirect('/login');
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');
    }

    /** Guard tes menyimpan objek user antar-request; di aplikasi nyata user dibaca ulang tiap request. */
    private function refreshSignedInUser(string $email): void
    {
        Auth::logout();
        Auth::login(Auth::guard()->getProvider()->retrieveById((string) $this->record($email)['id']));
    }

    /** @return array<string, mixed> */
    private function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
