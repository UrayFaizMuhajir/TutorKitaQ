<?php

namespace Tests\Feature;

use App\Models\LearningSession;
use App\Models\Subject;
use App\Models\TutorAvailabilitySlot;
use App\Models\TutorProfile;
use App\Support\SlotBooking;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** ROADMAP 3.2 (TT-12, TT-25): pesan slot, kapasitas, batal, ganti jadwal, bentrok, zona waktu, konkuren. */
class SessionBookingTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;
    use \Tests\Concerns\CreatesVerifiedStudent;
    use \Tests\Concerns\CreatesBookableSlot;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/booking-users-'.bin2hex(random_bytes(8)).'.json');
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

    public function test_tutor_in_makassar_creates_16_00_and_a_student_in_jakarta_books_and_sees_15_00(): void
    {
        $tutorId = $this->tutorAccount('Gusti Prabu', 'gusti@example.test');
        $date = CarbonImmutable::now('Asia/Makassar')->addDays(3)->format('Y-m-d');
        $this->withUnencryptedCookie('tk_tz', 'Asia/Makassar')->post(route('dashboard.tutor.slots.store'), ['date' => $date, 'start_time' => '16:00', 'end_time' => '17:00', 'capacity' => 20])->assertRedirect();
        $slot = TutorAvailabilitySlot::query()->firstOrFail();
        $this->assertSame('08:00', $slot->starts_at->format('H:i'));

        $studentId = $this->student('Budi', 'budi@example.test');
        $catalog = Str::between($this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->get('/dashboard')->getContent(), 'data-spa-page="tutors"', '<dialog class="catalog-profile-dialog"');
        $this->assertStringContainsString('15.00–16.00 WIB', $catalog);
        $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertCreated();
        $this->assertStringContainsString('15.00–16.00 WIB', Str::between($this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->get('/dashboard')->getContent(), 'data-spa-page="sessions"', 'data-session-filter-empty'));
        $this->assertDatabaseHas('learning_sessions', ['student_id' => $studentId, 'tutor_id' => $tutorId, 'slot_id' => $slot->id, 'status' => 'pending']);

        $this->post('/logout');
        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!']);
        $schedule = Str::between($this->withUnencryptedCookie('tk_tz', 'Asia/Makassar')->get('/dashboard')->getContent(), 'data-spa-page="schedule"', '</section>');
        $this->assertStringContainsString('16.00–17.00 WITA', $schedule);
        $this->assertStringContainsString('Terisi 1/20', $schedule);
        $this->assertStringContainsString('Budi', $schedule);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $tutorId, 'title' => 'Pengajuan sesi baru']);
        // Notifikasi dibuat saat siswa (WIB) memesan, tetapi dirender di zona tutor yang membacanya.
        $notifications = Str::between($this->withUnencryptedCookie('tk_tz', 'Asia/Makassar')->get('/dashboard')->getContent(), 'data-spa-page="notifications"', '</section>');
        $this->assertStringContainsString('16.00–17.00 WITA', $notifications);
        $this->assertStringNotContainsString('WIB', $notifications);
    }

    public function test_capacity_is_enforced_and_a_student_cannot_book_the_same_slot_twice(): void
    {
        $slot = $this->bookableSlot('900', 'Matematika', 2);
        foreach (['a', 'b'] as $key) {
            $this->student("Siswa {$key}", "{$key}@example.test");
            $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertCreated();
        }
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertStatus(409)->assertJsonPath('message', 'Slot ini sudah penuh. Pilih slot lain.');
        $this->assertSame('full', $slot->fresh()->status);

        $this->student('Siswa c', 'c@example.test');
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertStatus(409);
        $this->assertSame(2, LearningSession::query()->active()->where('slot_id', $slot->id)->count());
        $this->assertSame(2, $slot->fresh()->booked_count);

        $open = $this->bookableSlot('901', 'Matematika', 5, CarbonImmutable::now('UTC')->addDays(3)->setTime(8, 0));
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($open))->assertCreated();
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($open))->assertStatus(409)->assertJsonPath('message', 'Kamu sudah terdaftar di slot ini.');
        $this->assertSame(1, $open->fresh()->booked_count);
    }

    public function test_seat_update_is_atomic_and_the_partial_unique_index_blocks_double_active_bookings(): void
    {
        $slot = $this->bookableSlot('900', 'Matematika', 1);
        $this->assertTrue(SlotBooking::takeSeat($slot->id));
        // Walau status dipaksa 'open', pernyataan atomik tetap menolak karena booked_count >= capacity.
        TutorAvailabilitySlot::query()->whereKey($slot->id)->update(['status' => 'open']);
        $this->assertFalse(SlotBooking::takeSeat($slot->id));
        $this->assertSame(1, $slot->fresh()->booked_count);

        $row = ['student_id' => '77', 'tutor_id' => '900', 'slot_id' => $slot->id, 'tutor_name' => 'T', 'subject' => 'Matematika', 'topic' => 'X',
            'grade_level' => 'SMP', 'starts_at' => $slot->starts_at, 'duration_minutes' => 60, 'meeting_platform' => 'Google Meet', 'status' => 'pending'];
        LearningSession::query()->create($row);
        LearningSession::query()->create(['status' => 'cancelled'] + $row); // sesi batal tidak menghalangi
        $this->expectException(UniqueConstraintViolationException::class);
        LearningSession::query()->create($row);
    }

    public function test_booking_closes_three_hours_before_start(): void
    {
        $slot = $this->bookableSlot('900', 'Matematika', 5, CarbonImmutable::now('UTC')->addMinutes(170));
        $this->student('Budi', 'budi@example.test');
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertUnprocessable()
            ->assertJsonPath('message', 'Slot ini terlalu dekat dengan waktu mulai. Pemesanan paling lambat 3 jam sebelum sesi.');
        $this->assertSame(180, config('tutorkita.booking_lead_minutes'));
    }

    public function test_cancelling_frees_a_seat_for_another_student_and_notifies_the_tutor(): void
    {
        $slot = $this->bookableSlot('900', 'Matematika', 1);
        $this->student('Budi', 'budi@example.test');
        $id = $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertCreated()->json('session.id');
        $this->postJson(route('dashboard.sessions.cancel', $id))->assertOk();
        $this->assertDatabaseHas('learning_sessions', ['id' => $id, 'status' => 'cancelled', 'cancelled_by' => 'student']);
        $this->assertSame(['open', 0], [$slot->fresh()->status, $slot->fresh()->booked_count]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => '900', 'title' => 'Siswa membatalkan sesi']);

        $this->student('Citra', 'citra@example.test');
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertCreated();
        $this->assertSame('full', $slot->fresh()->status);
    }

    public function test_reschedule_moves_the_seat_to_another_slot_of_the_same_tutor_in_one_step(): void
    {
        $from = $this->bookableSlot('900', 'Matematika', 3);
        $to = $this->bookableSlot('900', 'Matematika', 1, CarbonImmutable::now('UTC')->addDays(5)->setTime(8, 0));
        $otherTutor = $this->bookableSlot('901', 'Matematika', 5, CarbonImmutable::now('UTC')->addDays(6)->setTime(8, 0));
        $this->student('Budi', 'budi@example.test');
        $id = $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($from))->assertCreated()->json('session.id');

        $this->postJson(route('dashboard.sessions.reschedule', $id), ['slot_id' => $otherTutor->id])->assertUnprocessable()->assertJsonPath('message', 'Ganti jadwal hanya ke slot tutor yang sama.');
        $this->postJson(route('dashboard.sessions.reschedule', $id), ['slot_id' => $to->id])->assertOk();
        $session = LearningSession::query()->findOrFail($id);
        $this->assertSame([$to->id, 'pending'], [$session->slot_id, $session->status]);
        $this->assertSame($to->starts_at->toIso8601String(), $session->starts_at->toIso8601String());
        $this->assertSame([0, 'open'], [$from->fresh()->booked_count, $from->fresh()->status]);
        $this->assertSame([1, 'full'], [$to->fresh()->booked_count, $to->fresh()->status]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => '900', 'title' => 'Siswa mengganti jadwal sesi']);

        // Slot tujuan penuh → 409, kursi lama tetap aman.
        $this->student('Citra', 'citra@example.test');
        $citra = $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($from))->assertCreated()->json('session.id');
        $this->postJson(route('dashboard.sessions.reschedule', $citra), ['slot_id' => $to->id])->assertStatus(409);
        $this->assertSame([$from->id, 1], [LearningSession::query()->findOrFail($citra)->slot_id, $from->fresh()->booked_count]);

        // Sesi lama (tutor mock, tanpa slot) tidak bisa diganti jadwalnya.
        $legacy = LearningSession::query()->create(['student_id' => (string) auth()->id(), 'tutor_name' => 'Alya Putri', 'subject' => 'IPA', 'topic' => 'Lama',
            'grade_level' => 'SMP', 'starts_at' => now()->addDays(3), 'duration_minutes' => 60, 'meeting_platform' => 'Google Meet', 'status' => 'pending']);
        $this->postJson(route('dashboard.sessions.reschedule', $legacy->id), ['slot_id' => $to->id])->assertUnprocessable();
    }

    public function test_overlapping_bookings_are_allowed_but_marked_red_notified_and_unmarked_once_resolved(): void
    {
        $math = $this->bookableSlot('900', 'Matematika', 5);
        $ipa = $this->bookableSlot('901', 'IPA', 5, $math->starts_at->addMinutes(30));
        $studentId = $this->student('Budi', 'budi@example.test');
        $first = $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($math))->assertCreated()->json('session.id');
        $second = $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($ipa, ['subject' => 'IPA']))->assertCreated();
        $this->assertStringContainsString('bentrok dengan Matematika', $second->json('message'));
        $this->assertNotEmpty($second->json('session.conflicts'));
        $this->assertDatabaseHas('user_notifications', ['user_id' => $studentId, 'title' => 'Jadwal bentrok']);

        $html = $this->get('/dashboard')->getContent();
        $sessions = Str::between($html, 'data-spa-page="sessions"', 'data-session-filter-empty');
        $this->assertSame(2, substr_count($sessions, 'data-session-conflict="1"'));
        $this->assertStringContainsString('data-conflict-badge', Str::between($html, 'data-spa-page="dashboard"', 'data-spa-page="tutors"'));
        $this->assertStringContainsString('data-active-intervals=', $html);

        $this->postJson(route('dashboard.sessions.cancel', $first))->assertOk();
        $html = $this->get('/dashboard')->getContent();
        $this->assertStringNotContainsString('data-session-conflict="1"', $html);
        $this->assertStringNotContainsString('data-conflict-badge', $html);
    }

    public function test_tutor_notifications_never_contain_student_private_profile_data(): void
    {
        $slot = $this->bookableSlot('900', 'Matematika', 5);
        $this->student('Budi', 'budi@example.test');
        $this->postJson(route('dashboard.sessions.store'), $this->slotPayload($slot))->assertCreated();
        $body = \App\Models\UserNotification::query()->where('user_id', '900')->value('body');
        $this->assertStringContainsString('Budi', $body);
        foreach (['Wali Uji', '081234567890', 'Kec. Uji', 'SMP Uji', 'student-documents'] as $private) $this->assertStringNotContainsString($private, $body);
    }

    /**
     * Tes konkuren SUNGGUHAN: beberapa proses PHP paralel memesan slot berkapasitas 2 pada database SQLite sementara
     * (bukan database asli). Hasil akhir harus tepat 2 sesi dan booked_count = 2.
     */
    public function test_parallel_processes_cannot_overbook_a_slot(): void
    {
        $dir = storage_path('framework/testing');
        $db = $dir.'/tutorkita-concurrency-'.bin2hex(random_bytes(6)).'.sqlite';
        $users = $dir.'/tutorkita-concurrency-users-'.bin2hex(random_bytes(6)).'.json';
        touch($db);
        file_put_contents($users, '[]');
        $realDb = database_path('database.sqlite');
        $realHash = is_file($realDb) ? md5_file($realDb) : null;
        $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $db, 'DB_URL' => '', 'AUTH_USERS_FILE' => $users, 'APP_ENV' => 'testing'];
        $script = base_path('tests/Support/concurrent-booking.php');
        $run = fn (array $args) => tap(new Process(array_merge([PHP_BINARY, $script], $args), base_path(), $env, null, 120))->run();

        try {
            $setup = $run(['setup', '2']);
            $this->assertTrue($setup->isSuccessful(), $setup->getErrorOutput());
            [$slotId, $level] = explode('|', trim($setup->getOutput()));

            $processes = [];
            foreach (range(1, 6) as $student) {
                $process = new Process([PHP_BINARY, $script, 'book', $slotId, (string) (100 + $student), $level], base_path(), $env, null, 120);
                $process->start();
                $processes[] = $process;
            }
            $results = array_map(function (Process $process): string { $process->wait(); return trim($process->getOutput()); }, $processes);

            $this->assertSame(2, count(array_filter($results, fn (string $r): bool => $r === 'OK')), 'Hasil: '.implode(', ', $results));
            $this->assertSame(4, count(array_filter($results, fn (string $r): bool => $r === 'FULL')), 'Hasil: '.implode(', ', $results));
            $report = $run(['report', $slotId]);
            $this->assertSame('2|2|full', trim($report->getOutput()));
            $this->assertSame($realHash, is_file($realDb) ? md5_file($realDb) : null, 'Database asli tidak boleh berubah');
        } finally {
            @unlink($db);
            @unlink($users);
        }
    }

    private function tutorAccount(string $name, string $email): string
    {
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on', 'account_type' => 'tutor']);
        $id = (string) $this->record($email)['id'];
        $this->markTutorVerified($id, $name);
        TutorProfile::query()->where('user_id', $id)->firstOrFail()->subjects()->sync([Subject::query()->where('name', 'Matematika')->value('id')]);
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!']);

        return $id;
    }

    private function student(string $name, string $email): string
    {
        if (auth()->check()) $this->post('/logout');
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on']);
        $id = (string) $this->record($email)['id'];
        $this->markStudentVerified($id);
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!']);

        return $id;
    }

    /** @return array<string, mixed> */
    private function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
