<?php

namespace Tests\Feature;

use App\Models\LearningSession;
use App\Models\TutorProfile;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ROADMAP 3.4 (TT-16): presensi per siswa, catatan, "Tandai selesai" — status completed HANYA dari tutor. */
class TutorSessionCompletionTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;
    use \Tests\Concerns\CreatesVerifiedStudent;
    use \Tests\Concerns\BooksTutorSessions;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/completion-users-'.bin2hex(random_bytes(8)).'.json');
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

    public function test_sessions_are_never_completed_automatically_and_wait_for_the_tutor(): void
    {
        [, $slot] = $this->tutorWithSlot();
        $session = $this->confirmedSession('Budi', 'budi@example.test', $slot);

        $this->travelTo($slot->ends_at->addHours(3));
        $this->login('budi@example.test');
        $this->assertStringContainsString('Menunggu Konfirmasi Tutor', $this->studentCard($session));
        $this->getJson(route('dashboard.sessions.show', $session))->assertJsonPath('session.status', 'awaiting_completion')
            ->assertJsonPath('session.status_label', 'Menunggu Konfirmasi Tutor');
        $this->assertSame('confirmed', $session->fresh()->status);
        $this->assertNull($session->fresh()->completed_at);
    }

    public function test_attendance_opens_only_after_start_and_completion_requires_every_student(): void
    {
        [, $slot] = $this->tutorWithSlot(capacity: 3);
        $budi = $this->confirmedSession('Budi', 'budi@example.test', $slot);
        $sari = $this->confirmedSession('Sari', 'sari@example.test', $slot);
        $pending = $this->bookAs('Dewi', 'dewi@example.test', $slot); // belum diterima → tidak ikut presensi
        $this->login('tutor@example.test');

        // Sebelum mulai: ditolak, dan tab Presensi kosong.
        $this->post(route('dashboard.tutor.slots.attendance', $slot), ['attendance' => [$budi->id => 'present']])->assertSessionHasErrors('session', null, 'teaching');
        $this->assertStringNotContainsString('data-attendance-form', $this->attendancePanel());
        $this->assertNull($budi->fresh()->attendance);

        $this->travelTo($slot->starts_at->addMinutes(10));
        $panel = $this->attendancePanel();
        $this->assertStringContainsString('data-attendance-form', $panel);
        $this->assertStringContainsString('Budi', $panel);
        $this->assertStringContainsString('Sari', $panel);
        $this->assertStringNotContainsString('Dewi', $panel);

        $this->post(route('dashboard.tutor.slots.attendance', $slot), ['attendance' => [$budi->id => 'hadir']])->assertSessionHasErrors('attendance.'.$budi->id, null, 'teaching');

        // Simpan sebagian tanpa selesai.
        $this->post(route('dashboard.tutor.slots.attendance', $slot), [
            'attendance' => [$budi->id => 'present'], 'notes' => [$budi->id => 'Aktif bertanya.'], 'session_note' => 'Bab 3: persamaan kuadrat.',
        ])->assertRedirect('/dashboard#teaching');
        $this->assertSame(['present', 'Aktif bertanya.', 'Bab 3: persamaan kuadrat.', 'confirmed'], [$budi->fresh()->attendance, $budi->fresh()->tutor_note, $budi->fresh()->session_note, $budi->fresh()->status]);

        // Selesai butuh presensi Sari juga.
        $this->post(route('dashboard.tutor.slots.attendance', $slot), ['complete' => 1, 'session_note' => 'Bab 3: persamaan kuadrat.'])->assertSessionHasErrors('session', null, 'teaching');
        $this->assertSame('confirmed', $sari->fresh()->status);

        $this->post(route('dashboard.tutor.slots.attendance', $slot), [
            'attendance' => [$sari->id => 'excused'], 'session_note' => 'Bab 3: persamaan kuadrat. PR halaman 40.', 'complete' => 1,
        ])->assertRedirect('/dashboard#teaching');
        foreach ([[$budi, 'present'], [$sari, 'excused']] as [$session, $mark]) {
            $fresh = $session->fresh();
            $this->assertSame(['completed', $mark, 'Bab 3: persamaan kuadrat. PR halaman 40.'], [$fresh->status, $fresh->attendance, $fresh->session_note]);
            $this->assertNotNull($fresh->completed_at);
        }
        $this->assertSame('Aktif bertanya.', $budi->fresh()->tutor_note); // tidak terhapus oleh simpan berikutnya
        $this->assertSame('expired', $pending->fresh()->status); // tak dijawab sampai mulai (TT-15), tidak ikut selesai
        $this->assertStringContainsString('Kehadiranmu: Izin.', (string) UserNotification::query()->where('user_id', (string) $sari->student_id)->where('title', 'Sesi selesai')->value('body'));

        // Setelah selesai data terkunci; Riwayat tutor menampilkan presensi & catatan.
        $this->post(route('dashboard.tutor.slots.attendance', $slot), ['attendance' => [$budi->id => 'absent']])->assertSessionHasErrors('session', null, 'teaching');
        $this->assertSame('present', $budi->fresh()->attendance);
        $history = Str::after($this->teachingPage(), 'data-admin-tab-panel="history"');
        $this->assertStringContainsString('Kehadiran: <strong>Hadir</strong>', $history);
        $this->assertStringContainsString('Catatan sesi: Bab 3: persamaan kuadrat. PR halaman 40.', $history);

        // Siswa melihat kehadiran & catatan miliknya di Riwayat (JSON detail).
        $this->login('budi@example.test');
        $this->getJson(route('dashboard.sessions.show', $budi))
            ->assertJsonPath('session.status', 'completed')->assertJsonPath('session.attendance_label', 'Hadir')
            ->assertJsonPath('session.session_note', 'Bab 3: persamaan kuadrat. PR halaman 40.')->assertJsonPath('session.tutor_note', 'Aktif bertanya.');
        $this->assertStringContainsString('Selesai', $this->studentCard($budi));
    }

    public function test_only_the_owning_verified_tutor_can_record_attendance_and_inactive_tutors_still_can(): void
    {
        [$tutorId, $slot] = $this->tutorWithSlot();
        $session = $this->confirmedSession('Budi', 'budi@example.test', $slot);
        $this->travelTo($slot->starts_at->addMinutes(5));
        $payload = ['attendance' => [$session->id => 'present'], 'complete' => 1];

        $this->login('budi@example.test');
        $this->post(route('dashboard.tutor.slots.attendance', $slot), $payload)->assertForbidden();
        $this->tutorAccount('Tutor Lain', 'lain@example.test');
        $this->login('lain@example.test');
        $this->post(route('dashboard.tutor.slots.attendance', $slot), $payload)->assertNotFound();
        $this->account('Tutor Baru', 'baru@example.test', 'tutor');
        $this->login('baru@example.test');
        $this->post(route('dashboard.tutor.slots.attendance', $slot), $payload)->assertForbidden();
        $this->assertSame('confirmed', $session->fresh()->status);

        // Sesi yang sudah terjadi tetap boleh ditutup oleh tutor yang kemudian dinonaktifkan.
        TutorProfile::query()->where('user_id', $tutorId)->update(['is_active' => false]);
        $this->login('tutor@example.test');
        $this->post(route('dashboard.tutor.slots.attendance', $slot), $payload)->assertRedirect('/dashboard#teaching');
        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_tutor_gets_one_reminder_and_a_highlight_24_hours_after_an_unfinished_session(): void
    {
        [$tutorId, $slot] = $this->tutorWithSlot(capacity: 2);
        $session = $this->confirmedSession('Budi', 'budi@example.test', $slot);
        $pending = $this->bookAs('Sari', 'sari@example.test', $slot); // tidak pernah diterima → bukan sasaran pengingat
        $reminders = fn (): int => UserNotification::query()->where('user_id', $tutorId)->where('title', 'Pengingat: tandai sesi selesai')->count();

        // 23 jam setelah selesai: belum ada pengingat maupun tanda.
        $this->travelTo($slot->ends_at->addHours(23));
        $this->login('tutor@example.test');
        $this->assertStringNotContainsString('data-overdue', $this->attendancePanel());
        $this->assertSame(0, $reminders());

        // Lewat 24 jam: satu notifikasi + tanda mencolok; memuat ulang (oleh siapa pun) tidak menggandakan.
        $this->travelTo($slot->ends_at->addHours(24)->addMinute());
        $panel = $this->attendancePanel();
        $this->assertStringContainsString('data-overdue-alert', $panel);
        $this->assertMatchesRegularExpression('/<li class="teaching-row is-overdue" data-teaching-session="'.$session->id.'"[^>]*data-overdue/', $panel);
        $this->assertSame(1, $reminders());
        $body = (string) UserNotification::query()->where('user_id', $tutorId)->where('title', 'Pengingat: tandai sesi selesai')->value('body');
        $this->assertStringContainsString('Jangan lupa isi presensi dan tandai selesai sesi Matematika', $body);
        $this->assertStringNotContainsString('Wali Uji', $body);
        $this->get('/dashboard')->assertOk();
        $this->login('budi@example.test');
        $this->get('/dashboard')->assertOk();
        $this->assertSame(1, $reminders());
        $this->assertSame(0, UserNotification::query()->where('user_id', (string) $pending->student_id)->where('title', 'Pengingat: tandai sesi selesai')->count());

        // Setelah ditandai selesai: tanda hilang, tidak ada pengingat baru.
        $this->login('tutor@example.test');
        $this->post(route('dashboard.tutor.slots.attendance', $slot), ['attendance' => [$session->id => 'present'], 'complete' => 1])->assertRedirect('/dashboard#teaching');
        $this->travelTo($slot->ends_at->addDays(3));
        $this->assertStringNotContainsString('data-overdue', $this->teachingPage());
        $this->assertSame(1, $reminders());
    }

    public function test_attendance_page_never_shows_student_private_profile_data(): void
    {
        [, $slot] = $this->tutorWithSlot();
        $this->confirmedSession('Budi', 'budi@example.test', $slot);
        $this->travelTo($slot->starts_at->addMinutes(5));
        $this->login('tutor@example.test');
        $html = $this->get('/dashboard')->getContent();
        $this->assertStringContainsString('data-attendance-form', $html);
        foreach (['Wali Uji', '081234567890', 'Kec. Uji', 'SMP Uji', 'student-documents'] as $private) {
            $this->assertStringNotContainsString($private, $html, 'Data privat siswa bocor ke tutor: '.$private);
        }
    }

    private function confirmedSession(string $name, string $email, \App\Models\TutorAvailabilitySlot $slot): LearningSession
    {
        $session = $this->bookAs($name, $email, $slot);
        $this->login('tutor@example.test');
        $this->post(route('dashboard.tutor.sessions.accept', $session), ['meeting_url' => 'https://meet.google.com/abc-defg-hij'])->assertRedirect('/dashboard#teaching');

        return $session->fresh();
    }

    private function attendancePanel(): string
    {
        return Str::between($this->teachingPage(), 'data-admin-tab-panel="attendance"', 'data-admin-tab-panel="history"');
    }
}
