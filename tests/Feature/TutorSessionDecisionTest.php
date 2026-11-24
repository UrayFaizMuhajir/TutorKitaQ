<?php

namespace Tests\Feature;

use App\Models\LearningSession;
use App\Models\Subject;
use App\Models\TutorAvailabilitySlot;
use App\Models\TutorProfile;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ROADMAP 3.3 (TT-15): tutor menerima/menolak pengajuan per siswa, tautan kelas, Masuk Kelas, kedaluwarsa. */
class TutorSessionDecisionTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;
    use \Tests\Concerns\CreatesVerifiedStudent;
    use \Tests\Concerns\BooksTutorSessions;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/decision-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
        $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        \App\Support\UserTime::set(null);
        parent::tearDown();
    }

    public function test_tutor_accepts_one_student_with_a_meeting_link_and_join_opens_15_minutes_before_start(): void
    {
        [$tutorId, $slot] = $this->tutorWithSlot(capacity: 2);
        $budi = $this->bookAs('Budi', 'budi@example.test', $slot);
        $sari = $this->bookAs('Sari', 'sari@example.test', $slot);

        $this->login('tutor@example.test');
        $page = $this->teachingPage();
        $this->assertStringContainsString('Budi', $page);
        $this->assertStringContainsString('Sari', $page);
        $this->assertStringContainsString('aria-label="2 menunggu"', $this->get('/dashboard')->getContent());

        // Terima Budi saja (per siswa); Sari tetap menunggu.
        $this->post(route('dashboard.tutor.sessions.accept', $budi), ['meeting_url' => 'https://meet.google.com/abc-defg-hij'])->assertRedirect('/dashboard#teaching');
        $this->assertDatabaseHas('learning_sessions', ['id' => $budi->id, 'status' => 'confirmed', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'meeting_platform' => 'Google Meet']);
        $this->assertNotNull($budi->fresh()->decided_at);
        $this->assertSame('pending', $sari->fresh()->status);
        $this->assertSame(2, $slot->fresh()->booked_count);
        $this->assertDatabaseHas('user_notifications', ['user_id' => (string) $budi->student_id, 'title' => 'Sesi diterima tutor']);

        // Siswa: belum bisa masuk kelas (2 hari lagi) → tombol nonaktif + penjelasan.
        $this->login('budi@example.test');
        $card = $this->studentCard($budi);
        $this->assertStringNotContainsString('href="https://meet.google.com/abc-defg-hij"', $card);
        $this->assertStringContainsString('Tombol aktif 15 menit sebelum sesi dimulai.', $card);
        $this->getJson(route('dashboard.sessions.show', $budi))->assertJsonPath('session.status_label', 'Terkonfirmasi')->assertJsonPath('session.meeting_url', null);

        $this->travelTo($slot->starts_at->subMinutes(16));
        $this->assertStringNotContainsString('href="https://meet.google.com/abc-defg-hij"', $this->studentCard($budi));
        $this->travelTo($slot->starts_at->subMinutes(15));
        $this->assertStringContainsString('href="https://meet.google.com/abc-defg-hij"', $this->studentCard($budi));
        $this->getJson(route('dashboard.sessions.show', $budi))->assertJsonPath('session.can_join', true);

        // Pengajuan Sari yang belum dijawab tidak bisa dimasuki dan kedaluwarsa saat sesi mulai.
        $this->login('sari@example.test');
        $this->assertStringContainsString('Menunggu tutor menerima pengajuan.', $this->studentCard($sari));
    }

    public function test_accept_without_link_then_add_it_later_and_only_https_links_are_accepted(): void
    {
        [, $slot] = $this->tutorWithSlot();
        $session = $this->bookAs('Budi', 'budi@example.test', $slot);
        $this->login('tutor@example.test');

        foreach (['http://meet.google.com/abc', 'javascript:alert(1)', 'bukan tautan', 'ftp://zoom.us/j/1'] as $bad) {
            $this->post(route('dashboard.tutor.sessions.accept', $session), ['meeting_url' => $bad])->assertSessionHasErrors('meeting_url', null, 'teaching');
        }
        $this->assertSame('pending', $session->fresh()->status);

        // Tautan belum boleh diisi sebelum menerima.
        $this->post(route('dashboard.tutor.sessions.meeting-link', $session), ['meeting_url' => 'https://zoom.us/j/123'])->assertSessionHasErrors('session', null, 'teaching');

        $this->post(route('dashboard.tutor.sessions.accept', $session))->assertRedirect('/dashboard#teaching');
        $this->assertDatabaseHas('learning_sessions', ['id' => $session->id, 'status' => 'confirmed', 'meeting_url' => null]);
        $this->assertStringContainsString('Tautan kelas akan dibagikan tutor sebelum sesi dimulai.', $this->notificationBody((string) $session->student_id, 'Sesi diterima tutor'));

        $this->post(route('dashboard.tutor.sessions.meeting-link', $session), ['meeting_url' => 'http://zoom.us/j/123'])->assertSessionHasErrors('meeting_url', null, 'teaching');
        $this->post(route('dashboard.tutor.sessions.meeting-link', $session), ['meeting_url' => 'https://us02web.zoom.us/j/123'])->assertRedirect('/dashboard#teaching');
        $this->assertDatabaseHas('learning_sessions', ['id' => $session->id, 'meeting_url' => 'https://us02web.zoom.us/j/123', 'meeting_platform' => 'Zoom']);
        $this->assertDatabaseHas('user_notifications', ['user_id' => (string) $session->student_id, 'title' => 'Tautan kelas tersedia']);
        $this->assertStringContainsString('https://us02web.zoom.us/j/123', $this->teachingPage());

        // Setelah sesi dimulai tautan tidak bisa diubah lagi.
        $this->travelTo($slot->starts_at->addMinute());
        $this->post(route('dashboard.tutor.sessions.meeting-link', $session), ['meeting_url' => 'https://zoom.us/j/999'])->assertSessionHasErrors('session', null, 'teaching');
        $this->assertSame('https://us02web.zoom.us/j/123', $session->fresh()->meeting_url);
    }

    public function test_reject_requires_a_reason_frees_the_seat_notifies_and_lets_the_student_book_again(): void
    {
        [, $slot] = $this->tutorWithSlot(capacity: 1);
        $session = $this->bookAs('Budi', 'budi@example.test', $slot);
        $this->assertSame('full', $slot->fresh()->status);
        $this->login('tutor@example.test');

        $this->post(route('dashboard.tutor.sessions.reject', $session), ['reason' => ''])->assertSessionHasErrors('reason', null, 'teaching');
        $this->post(route('dashboard.tutor.sessions.reject', $session), ['reason' => 'abc'])->assertSessionHasErrors('reason', null, 'teaching');
        $this->assertSame('pending', $session->fresh()->status);

        $this->post(route('dashboard.tutor.sessions.reject', $session), ['reason' => 'Topik ini di luar keahlian saya.'])->assertRedirect('/dashboard#teaching');
        $fresh = $session->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('Topik ini di luar keahlian saya.', $fresh->rejection_reason);
        $this->assertSame([0, 'open'], [$slot->fresh()->booked_count, $slot->fresh()->status]);
        $this->assertStringContainsString('Alasan: Topik ini di luar keahlian saya.', $this->notificationBody((string) $session->student_id, 'Pengajuan sesi ditolak'));

        // Tidak bisa dijawab dua kali.
        $this->post(route('dashboard.tutor.sessions.accept', $session))->assertSessionHasErrors('session', null, 'teaching');
        $this->assertSame('rejected', $session->fresh()->status);
        $this->assertStringContainsString('Ditolak Tutor', Str::between($this->teachingPage(), 'data-admin-tab-panel="history"', '</div>'.PHP_EOL.'        </div>'));

        // Siswa melihat status + alasan, dan boleh memesan slot yang sama lagi (index unik hanya pending/confirmed).
        $this->login('budi@example.test');
        $this->getJson(route('dashboard.sessions.show', $session))
            ->assertJsonPath('session.status', 'rejected')->assertJsonPath('session.status_label', 'Ditolak Tutor')
            ->assertJsonPath('session.rejection_reason', 'Topik ini di luar keahlian saya.');
        $this->assertStringContainsString('Ditolak Tutor', $this->studentCard($session));
        $this->postJson(route('dashboard.sessions.store'), $this->payload($slot))->assertCreated();
        $this->assertSame(1, $slot->fresh()->booked_count);
    }

    public function test_unanswered_requests_expire_at_start_time_and_free_the_seat(): void
    {
        [, $slot] = $this->tutorWithSlot(capacity: 1);
        $session = $this->bookAs('Budi', 'budi@example.test', $slot);

        $this->travelTo($slot->starts_at->subMinute());
        $this->get('/dashboard')->assertOk();
        $this->assertSame('pending', $session->fresh()->status);

        $this->travelTo($slot->starts_at->addMinute());
        $this->login('tutor@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $session))->assertSessionHasErrors('session', null, 'teaching');
        $page = $this->teachingPage(); // memuat halaman = menjalankan kedaluwarsa
        $this->assertSame('expired', $session->fresh()->status);
        $this->assertSame([0, 'open'], [$slot->fresh()->booked_count, $slot->fresh()->status]);
        $this->assertStringContainsString('Kedaluwarsa', $page);
        $this->assertSame(1, UserNotification::query()->where('user_id', (string) $session->student_id)->where('title', 'Pengajuan sesi kedaluwarsa')->count());

        // Memuat ulang tidak membuat notifikasi ganda; sesi confirmed tidak ikut kedaluwarsa.
        $this->get('/dashboard')->assertOk();
        $this->assertSame(1, UserNotification::query()->where('user_id', (string) $session->student_id)->where('title', 'Pengajuan sesi kedaluwarsa')->count());
        $this->login('budi@example.test');
        $this->getJson(route('dashboard.sessions.show', $session))->assertJsonPath('session.status_label', 'Kedaluwarsa');
    }

    public function test_access_is_limited_to_the_owning_verified_tutor_and_inactive_tutors_can_only_reject_or_set_links(): void
    {
        [$tutorId, $slot] = $this->tutorWithSlot(capacity: 3);
        $first = $this->bookAs('Budi', 'budi@example.test', $slot);
        $second = $this->bookAs('Sari', 'sari@example.test', $slot);

        // Siswa (termasuk pemilik sesi) dan admin ditolak.
        $this->login('budi@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $first))->assertForbidden();
        $this->post(route('dashboard.tutor.sessions.reject', $first), ['reason' => 'Tidak jadi saja.'])->assertForbidden();
        $this->post(route('dashboard.tutor.sessions.meeting-link', $first), ['meeting_url' => 'https://meet.google.com/x'])->assertForbidden();
        $this->account('Admin Uji', 'admin@example.test', 'student');
        DB::table('user_roles')->where('user_id', $this->record('admin@example.test')['id'])->update(['role' => 'admin']);
        $this->login('admin@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $first))->assertForbidden();
        $this->post(route('dashboard.tutor.sessions.reject', $first), ['reason' => 'Tidak jadi saja.'])->assertForbidden();

        // Tutor lain → 404; tutor belum terverifikasi → 403.
        $this->tutorAccount('Tutor Lain', 'lain@example.test');
        $this->login('lain@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $first))->assertNotFound();
        $this->post(route('dashboard.tutor.sessions.reject', $first), ['reason' => 'Bukan sesi saya.'])->assertNotFound();
        $this->account('Tutor Baru', 'baru@example.test', 'tutor');
        $this->login('baru@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $first))->assertForbidden();
        $this->post(route('dashboard.tutor.sessions.reject', $first), ['reason' => 'Belum terverifikasi.'])->assertForbidden();
        $this->assertSame(['pending', 'pending'], [$first->fresh()->status, $second->fresh()->status]);

        // Tutor pemilik dinonaktifkan: tidak bisa menerima, tetap bisa menolak.
        TutorProfile::query()->where('user_id', $tutorId)->update(['is_active' => false]);
        $this->login('tutor@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $first))->assertForbidden();
        $page = $this->teachingPage();
        $this->assertStringContainsString('belum bisa menerima pengajuan baru', $page);
        $this->assertStringNotContainsString(route('dashboard.tutor.sessions.accept', $first), $page);
        $this->post(route('dashboard.tutor.sessions.reject', $first), ['reason' => 'Akun sedang dinonaktifkan.'])->assertRedirect('/dashboard#teaching');
        $this->assertSame('rejected', $first->fresh()->status);
    }

    public function test_teaching_page_never_shows_student_private_profile_data(): void
    {
        [, $slot] = $this->tutorWithSlot();
        $session = $this->bookAs('Budi', 'budi@example.test', $slot);
        $this->login('tutor@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $session), ['meeting_url' => 'https://meet.google.com/abc']);
        $html = $this->get('/dashboard')->getContent();
        $teaching = $this->teachingPage();
        $this->assertStringContainsString('Budi', $teaching);
        foreach (['Wali Uji', '081234567890', 'Kec. Uji', 'SMP Uji', 'student-documents'] as $private) {
            $this->assertStringNotContainsString($private, $html, 'Data privat siswa bocor ke tutor: '.$private);
        }
    }
}
