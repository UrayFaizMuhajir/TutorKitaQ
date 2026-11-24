<?php

namespace Tests\Feature;

use App\Models\LearningSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningSessionTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedStudent;
    use \Tests\Concerns\CreatesBookableSlot;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userStorePath = storage_path('framework/testing/session-users-'.bin2hex(random_bytes(8)).'.json');
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

    public function test_student_can_request_a_session_and_it_is_saved_without_a_fake_meeting_link(): void
    {
        // Sejak pengajuan berbasis slot (ROADMAP 3.2): siswa terverifikasi memesan slot tutor asli (bukan tutor mock).
        $slot = $this->bookableSlot();
        $this->registerAndSignIn('Budi', 'budi@test.com');
        $this->markStudentVerified((string) auth()->id());

        $response = $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertCreated()
            ->assertJsonPath('session.status', 'pending')
            ->assertJsonPath('session.meeting_url', null);

        $session = LearningSession::query()->firstOrFail();
        $this->assertSame((string) auth()->id(), $session->student_id);
        $this->assertSame('900', $session->tutor_id);
        $this->assertSame($slot->id, $session->slot_id);
        $this->assertSame('Matematika', $session->subject);
        $this->assertSame($slot->starts_at->toIso8601String(), $session->starts_at->toIso8601String());
        $this->assertNull($session->meeting_url);

        $this->get('/dashboard#sessions')->assertOk()
            ->assertSeeText('Sesi Belajar')
            ->assertSeeText('Persamaan kuadrat')
            ->assertSee('data-session-card', false);

        $this->getJson(route('dashboard.sessions.show', $response->json('session.id')))
            ->assertOk()
            ->assertJsonPath('session.slot_id', $slot->id)
            ->assertJsonPath('session.can_reschedule', true);
    }
    public function test_session_data_is_private_and_owner_can_cancel_a_future_request(): void
    {
        $this->registerAndSignIn('Budi', 'budi@test.com');
        $session = LearningSession::query()->create([
            'student_id' => (string) auth()->id(),
            'tutor_name' => 'Alya Putri',
            'tutor_university' => 'Universitas Indonesia',
            'tutor_major' => 'Pendidikan Matematika',
            'subject' => 'Matematika',
            'topic' => 'Aljabar',
            'grade_level' => 'SMP',
            'starts_at' => now()->addDays(2),
            'duration_minutes' => 60,
            'meeting_platform' => 'Google Meet',
            'status' => 'pending',
        ]);
        $this->post('/logout');

        $this->registerAndSignIn('Andi', 'andi@test.com');
        $this->getJson(route('dashboard.sessions.show', $session->id))->assertNotFound();
        $this->postJson(route('dashboard.sessions.cancel', $session->id))->assertNotFound();
        $this->get('/dashboard#sessions')->assertOk()->assertDontSeeText('Aljabar');
        $this->post('/logout');

        $this->registerAndSignIn('Budi', 'budi@test.com');
        $this->postJson(route('dashboard.sessions.cancel', $session->id))
            ->assertOk()
            ->assertJsonPath('message', 'Pengajuan sesi berhasil dibatalkan.');
        $this->assertDatabaseHas('learning_sessions', ['id' => $session->id, 'status' => 'cancelled']);
    }

    public function test_unavailable_tutors_and_unmatched_subjects_cannot_be_requested(): void
    {
        // Sejak pengajuan berbasis slot (ROADMAP 3.2): tutor nonaktif/slot tidak valid/mapel atau jenjang tidak cocok ditolak.
        $slot = $this->bookableSlot();
        $this->registerAndSignIn('Budi', 'budi@test.com');
        $this->markStudentVerified((string) auth()->id());

        DB::table('tutor_profiles')->where('user_id', '900')->update(['is_active' => false]);
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))
            ->assertUnprocessable()->assertJsonPath('message', 'Tutor ini sedang tidak tersedia.');
        DB::table('tutor_profiles')->where('user_id', '900')->update(['is_active' => true]);

        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot, ['subject' => 'Bahasa Inggris', 'grade_level' => 'SMP']))
            ->assertUnprocessable()->assertJsonPath('message', 'Mata pelajaran tidak diajarkan tutor ini.');
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot, ['grade_level' => 'Perguruan Tinggi']))
            ->assertUnprocessable()->assertJsonPath('message', 'Jenjang tidak tersedia untuk mata pelajaran ini.');
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot, ['slot_id' => 99999]))
            ->assertUnprocessable()->assertJsonPath('message', 'Slot tidak tersedia.');

        $this->assertDatabaseCount('learning_sessions', 0);
        $this->assertSame(0, $slot->fresh()->booked_count);
    }
    public function test_student_sees_session_request_controls(): void
    {
        $this->registerAndSignIn('Budi', 'budi@test.com');
        // Sejak verifikasi siswa (ROADMAP 5.1), kontrol pengajuan hanya untuk siswa terverifikasi.
        $this->markStudentVerified((string) auth()->id());

        $this->get('/dashboard')->assertOk()
            ->assertSee('data-can-manage-sessions="1"', false)
            ->assertSee('data-session-dialog-open', false)
            ->assertSee('data-session-create-form', false);
    }

    public function test_tutor_and_admin_cannot_request_or_cancel_sessions(): void
    {
        $request = [
            'tutor_name' => 'Alya Putri',
            'subject' => 'Matematika',
            'topic' => 'Persamaan kuadrat',
            'grade_level' => 'SMP',
            'starts_at' => now()->addDays(2)->setTime(10, 0)->format('Y-m-d\\TH:i'),
        ];

        foreach (['tutor' => ['Gudti', 'gudti@test.com'], 'admin' => ['Admin', 'admin@test.com']] as $role => [$name, $contact]) {
            $this->registerAndSignIn($name, $contact);
            $userId = (string) auth()->id();
            DB::table('user_roles')->where('user_id', $userId)->update(['role' => $role]);
            Auth::logout();
            Auth::login(Auth::guard()->getProvider()->retrieveById($userId));
            // Sesi milik akun itu sendiri: penolakan harus karena role, bukan karena kepemilikan.
            $session = LearningSession::query()->create([
                'student_id' => $userId,
                'tutor_name' => 'Alya Putri',
                'tutor_university' => 'Universitas Indonesia',
                'tutor_major' => 'Pendidikan Matematika',
                'subject' => 'IPA',
                'topic' => "Sesi lama {$role}",
                'grade_level' => 'SMP',
                'starts_at' => now()->addDays(7),
                'duration_minutes' => 60,
                'meeting_platform' => 'Google Meet',
                'status' => 'pending',
            ]);

            $this->postJson(route('dashboard.sessions.store'), $request)
                ->assertForbidden()
                ->assertJsonPath('message', 'Kamu tidak memiliki akses untuk tindakan ini.');
            $this->postJson(route('dashboard.sessions.cancel', $session->id))->assertForbidden();
            $this->assertDatabaseHas('learning_sessions', ['id' => $session->id, 'status' => 'pending']);

            $this->getJson(route('dashboard.sessions.show', $session->id))->assertOk();
            // Sejak ROADMAP 0.1 halaman Sesi hanya dirender untuk Siswa.
            $this->get('/dashboard')->assertOk()
                ->assertDontSee('data-spa-page="sessions"', false)
                ->assertDontSee('data-session-card', false)
                ->assertDontSee('data-session-dialog-open', false)
                ->assertDontSee('data-session-create-form', false);

            $this->post('/logout');
        }

        $this->assertDatabaseCount('learning_sessions', 2);
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
