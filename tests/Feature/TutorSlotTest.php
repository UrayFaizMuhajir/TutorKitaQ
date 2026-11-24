<?php

namespace Tests\Feature;

use App\Models\LearningSession;
use App\Models\StudentProfile;
use App\Models\TutorAvailabilitySlot;
use App\Support\UserTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ROADMAP 2.2 (TT-7, TT-8): slot ketersediaan tutor, zona waktu pengguna, kapasitas, pratinjau mingguan. */
class TutorSlotTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;
    use \Tests\Concerns\CreatesVerifiedStudent;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/slot-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
        $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        UserTime::set(null);
        parent::tearDown();
    }

    public function test_slot_time_is_converted_from_the_tutor_timezone_to_utc_and_shown_in_each_viewer_timezone(): void
    {
        $tutorId = $this->tutor('Gusti', 'gusti@example.test');
        $date = CarbonImmutable::now('Asia/Makassar')->addDays(5)->format('Y-m-d');

        $this->withUnencryptedCookie('tk_tz', 'Asia/Makassar')
            ->post(route('dashboard.tutor.slots.store'), ['date' => $date, 'start_time' => '16:00', 'end_time' => '17:00', 'capacity' => 20])
            ->assertRedirect('/dashboard#schedule');
        $slot = TutorAvailabilitySlot::query()->firstOrFail();
        $this->assertSame($date.' 08:00:00', $slot->starts_at->format('Y-m-d H:i:s'), 'Tersimpan dalam UTC');
        $this->assertSame('09:00', $slot->ends_at->format('H:i'));

        $this->assertStringContainsString('16.00–17.00 WITA', $this->withUnencryptedCookie('tk_tz', 'Asia/Makassar')->get('/dashboard')->getContent());
        $this->assertStringContainsString('15.00–16.00 WIB', $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->get('/dashboard')->getContent());
        $this->assertStringContainsString('17.00–18.00 WIT', $this->withUnencryptedCookie('tk_tz', 'Asia/Jayapura')->get('/dashboard')->getContent());
        // Zona palsu/kosong → Asia/Jakarta.
        $this->assertStringContainsString('15.00–16.00 WIB', $this->withUnencryptedCookie('tk_tz', 'Mars/Olympus')->get('/dashboard')->getContent());
        $this->assertSame('UTC+8', UserTime::abbreviation(CarbonImmutable::now(), 'Asia/Singapore'));
    }

    public function test_slot_validation_rejects_past_times_bad_durations_bad_capacity_and_overlaps_in_utc(): void
    {
        $this->tutor('Gusti', 'gusti@example.test');
        $tomorrow = CarbonImmutable::now('Asia/Jakarta')->addDay()->format('Y-m-d');
        $yesterday = CarbonImmutable::now('Asia/Jakarta')->subDay()->format('Y-m-d');
        $post = fn (array $data) => $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->post(route('dashboard.tutor.slots.store'), $data + ['capacity' => 20]);

        $post(['date' => $yesterday, 'start_time' => '10:00', 'end_time' => '11:00'])->assertSessionHasErrors('slot');
        $post(['date' => $tomorrow, 'start_time' => '10:00', 'end_time' => '10:20'])->assertSessionHasErrors('end_time');
        $post(['date' => $tomorrow, 'start_time' => '10:00', 'end_time' => '13:30'])->assertSessionHasErrors('end_time');
        $post(['date' => $tomorrow, 'start_time' => '11:00', 'end_time' => '10:00'])->assertSessionHasErrors('end_time');
        $post(['date' => $tomorrow, 'start_time' => '10:00', 'end_time' => '11:00', 'capacity' => 21])->assertSessionHasErrors('capacity');
        $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->post(route('dashboard.tutor.slots.store'), ['date' => $tomorrow, 'start_time' => '10:00', 'end_time' => '11:00', 'capacity' => 0])->assertSessionHasErrors('capacity');
        $this->assertDatabaseCount('tutor_availability_slots', 0);

        $post(['date' => $tomorrow, 'start_time' => '10:00', 'end_time' => '11:00'])->assertRedirect();
        // Bentrok dihitung di UTC: 11.30–12.30 WITA = 10.30–11.30 WIB → beririsan.
        $this->withUnencryptedCookie('tk_tz', 'Asia/Makassar')->post(route('dashboard.tutor.slots.store'), ['date' => $tomorrow, 'start_time' => '11:30', 'end_time' => '12:30', 'capacity' => 5])
            ->assertSessionHasErrors('slot');
        $this->assertStringContainsString('Bentrok dengan slot', session('errors')->first('slot'));
        // Bersebelahan (11.00–12.00 WIB) boleh.
        $post(['date' => $tomorrow, 'start_time' => '11:00', 'end_time' => '12:00'])->assertRedirect();
        $this->assertDatabaseCount('tutor_availability_slots', 2);
    }

    public function test_weekly_preview_marks_conflicts_red_and_saving_only_creates_safe_dates_with_a_skip_notice(): void
    {
        $tutorId = $this->tutor('Gusti', 'gusti@example.test');
        $first = CarbonImmutable::now('Asia/Jakarta')->addDays(2)->startOfDay();
        $request = ['date' => $first->format('Y-m-d'), 'start_time' => '15:00', 'end_time' => '16:00', 'capacity' => 10, 'repeat_weekly' => 1, 'repeat_until' => $first->addWeeks(3)->format('Y-m-d')];
        // Slot yang sudah ada pada minggu ke-2 → harus dilewati.
        TutorAvailabilitySlot::query()->create(['tutor_id' => $tutorId, 'starts_at' => UserTime::toUtc($first->addWeek()->format('Y-m-d'), '15:30', 'Asia/Jakarta'), 'ends_at' => UserTime::toUtc($first->addWeek()->format('Y-m-d'), '16:30', 'Asia/Jakarta'), 'capacity' => 5]);

        $preview = $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->postJson(route('dashboard.tutor.slots.preview'), $request)->assertOk()->json();
        $this->assertCount(4, $preview['occurrences']);
        $this->assertSame(3, $preview['create_count']);
        $this->assertFalse($preview['occurrences'][1]['ok']);
        $this->assertStringContainsString('Bentrok dengan slot', $preview['occurrences'][1]['reason']);
        $this->assertDatabaseCount('tutor_availability_slots', 1); // pratinjau tidak menulis apa pun

        $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->post(route('dashboard.tutor.slots.store'), $request)->assertRedirect()->assertSessionHas('status');
        $this->assertStringContainsString('3 slot dibuat. Dilewati:', session('status'));
        $this->assertDatabaseCount('tutor_availability_slots', 4);
        $this->assertSame(3, TutorAvailabilitySlot::query()->where('capacity', 10)->count());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $tutorId, 'title' => 'Sebagian slot dilewati']);

        $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->post(route('dashboard.tutor.slots.store'), ['repeat_until' => $first->addWeeks(13)->format('Y-m-d')] + $request)->assertSessionHasErrors('repeat_until');
    }

    public function test_booked_slots_lock_time_capacity_cannot_drop_below_bookings_and_cancel_notifies_every_student(): void
    {
        $tutorId = $this->tutor('Gusti', 'gusti@example.test');
        $slot = $this->slot($tutorId, 3);
        $budi = $this->bookDirectly($slot, 'Budi', 'budi@example.test');
        $citra = $this->bookDirectly($slot, 'Citra', 'citra@example.test');
        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!']);
        $local = UserTime::local($slot->starts_at, 'Asia/Jakarta');

        $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->put(route('dashboard.tutor.slots.update', $slot->id), ['date' => $local->addDay()->format('Y-m-d'), 'start_time' => '09:00', 'end_time' => '10:00', 'capacity' => 3])->assertStatus(409);
        $this->put(route('dashboard.tutor.slots.update', $slot->id), ['capacity' => 1])->assertSessionHasErrors('capacity');
        $this->put(route('dashboard.tutor.slots.update', $slot->id), ['capacity' => 2])->assertRedirect();
        $this->assertSame('full', $slot->fresh()->status);
        $this->delete(route('dashboard.tutor.slots.destroy', $slot->id))->assertStatus(409);

        // PRIVASI: tutor melihat nama, mapel, topik — bukan data profil siswa.
        $html = $this->get('/dashboard')->getContent();
        $schedule = Str::between($html, 'data-spa-page="schedule"', '</section>');
        $this->assertStringContainsString('Budi', $schedule);
        $this->assertStringContainsString('Terisi 2/2', $schedule);
        foreach (['Wali Uji', 'Kec. Uji, Kota Uji', '081234567890', 'student-documents/'] as $private) $this->assertStringNotContainsString($private, $html);

        $this->post(route('dashboard.tutor.slots.cancel', $slot->id), [])->assertSessionHasErrors('reason');
        $this->post(route('dashboard.tutor.slots.cancel', $slot->id), ['reason' => 'Ada acara kampus mendadak.'])->assertRedirect()->assertSessionHas('status');
        $this->assertSame('cancelled', $slot->fresh()->status);
        foreach ([$budi, $citra] as $session) {
            $this->assertDatabaseHas('learning_sessions', ['id' => $session->id, 'status' => 'cancelled', 'cancelled_by' => 'tutor', 'cancel_reason' => 'Ada acara kampus mendadak.']);
            $this->assertDatabaseHas('user_notifications', ['user_id' => $session->student_id, 'title' => 'Sesi dibatalkan tutor']);
        }
    }

    public function test_open_slots_can_be_edited_and_deleted_but_never_other_tutors_slots_or_by_unverified_or_inactive_tutors(): void
    {
        $tutorId = $this->tutor('Gusti', 'gusti@example.test');
        $slot = $this->slot($tutorId, 20);
        $date = UserTime::local($slot->starts_at, 'Asia/Jakarta')->format('Y-m-d');
        $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->put(route('dashboard.tutor.slots.update', $slot->id), ['date' => $date, 'start_time' => '18:00', 'end_time' => '19:30', 'capacity' => 8])->assertRedirect();
        $this->assertSame('11:00', $slot->fresh()->starts_at->format('H:i'));
        $this->assertSame(8, $slot->fresh()->capacity);
        $this->post('/logout');

        $this->tutor('Hana', 'hana@example.test');
        $this->put(route('dashboard.tutor.slots.update', $slot->id), ['capacity' => 2])->assertNotFound();
        $this->delete(route('dashboard.tutor.slots.destroy', $slot->id))->assertNotFound();
        $this->post(route('dashboard.tutor.slots.cancel', $slot->id), ['reason' => 'Bukan slot saya.'])->assertNotFound();
        $this->post('/logout');

        $this->post('/register', ['name' => 'Calon', 'contact' => 'calon@example.test', 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on', 'account_type' => 'tutor']);
        $this->post('/login', ['contact' => 'calon@example.test', 'password' => 'TutorKita123!']);
        $this->post(route('dashboard.tutor.slots.store'), ['date' => $date, 'start_time' => '08:00', 'end_time' => '09:00'])->assertForbidden();
        $this->assertStringNotContainsString('data-spa-page="schedule"', $this->get('/dashboard')->getContent());
        $this->post('/logout');

        DB::table('tutor_profiles')->where('user_id', $tutorId)->update(['is_active' => false]);
        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!']);
        $this->post(route('dashboard.tutor.slots.store'), ['date' => $date, 'start_time' => '08:00', 'end_time' => '09:00'])->assertForbidden();
        $this->delete(route('dashboard.tutor.slots.destroy', $slot->id))->assertForbidden();
        $html = $this->get('/dashboard')->getContent();
        $this->assertStringContainsString('data-spa-page="schedule"', $html);
        $this->assertStringNotContainsString('data-slot-form', $html);
        $this->post('/logout');

        DB::table('tutor_profiles')->where('user_id', $tutorId)->update(['is_active' => true]);
        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!']);
        $this->delete(route('dashboard.tutor.slots.destroy', $slot->id))->assertRedirect();
        $this->assertDatabaseCount('tutor_availability_slots', 0);
    }

    public function test_inactive_tutor_can_still_cancel_a_booked_slot_so_students_are_informed(): void
    {
        $tutorId = $this->tutor('Gusti', 'gusti@example.test');
        $slot = $this->slot($tutorId, 5);
        $session = $this->bookDirectly($slot, 'Budi', 'budi@example.test');
        DB::table('tutor_profiles')->where('user_id', $tutorId)->update(['is_active' => false]);
        $this->post('/login', ['contact' => 'gusti@example.test', 'password' => 'TutorKita123!']);

        $this->post(route('dashboard.tutor.slots.cancel', $slot->id), ['reason' => 'Akun sedang dinonaktifkan.'])->assertRedirect();
        $this->assertDatabaseHas('learning_sessions', ['id' => $session->id, 'status' => 'cancelled', 'cancelled_by' => 'tutor']);
    }

    public function test_admin_must_confirm_impact_before_deactivating_a_tutor_with_upcoming_sessions_and_students_are_notified(): void
    {
        $tutorId = $this->tutor('Gusti', 'gusti@example.test');
        $slot = $this->slot($tutorId, 5);
        $budi = $this->bookDirectly($slot, 'Budi', 'budi@example.test');
        $citra = $this->bookDirectly($slot, 'Citra', 'citra@example.test');
        $this->post('/register', ['name' => 'Admin', 'contact' => 'admin@example.test', 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on']);
        $adminId = (string) $this->record('admin@example.test')['id'];
        DB::table('user_roles')->where('user_id', $adminId)->update(['role' => 'admin']);
        $this->post('/login', ['contact' => 'admin@example.test', 'password' => 'TutorKita123!']);

        $page = Str::between($this->get('/dashboard?admin_tab=tutors')->getContent(), 'data-admin-tab-panel="tutors"', '</section>');
        $this->assertStringContainsString('2 sesi mendatang dengan 2 siswa', $page);
        $this->assertStringContainsString('name="confirm_impact"', $page);

        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'Laporan materi.'])->assertSessionHasErrors('confirm_impact');
        $this->assertTrue((bool) DB::table('tutor_profiles')->where('user_id', $tutorId)->value('is_active'));
        $this->post(route('dashboard.admin.tutors.status', $tutorId), ['action' => 'deactivate', 'reason' => 'Laporan materi.', 'confirm_impact' => 1])->assertRedirect();
        $this->assertFalse((bool) DB::table('tutor_profiles')->where('user_id', $tutorId)->value('is_active'));
        foreach ([$budi, $citra] as $session) {
            $this->assertDatabaseHas('user_notifications', ['user_id' => $session->student_id, 'title' => 'Tutor sesi kamu dinonaktifkan']);
            $this->assertDatabaseHas('learning_sessions', ['id' => $session->id, 'status' => 'pending']);
        }
    }

    private function tutor(string $name, string $email): string
    {
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on', 'account_type' => 'tutor'])->assertRedirect('/login');
        $id = (string) $this->record($email)['id'];
        $this->markTutorVerified($id, $name);
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!'])->assertRedirect('/dashboard');

        return $id;
    }

    private function slot(string $tutorId, int $capacity): TutorAvailabilitySlot
    {
        $start = CarbonImmutable::now('UTC')->addDays(4)->setTime(9, 0);

        return TutorAvailabilitySlot::query()->create(['tutor_id' => $tutorId, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'capacity' => $capacity, 'booked_count' => 0, 'status' => 'open']);
    }

    /** Pemesanan langsung di DB (alur pemesanan siswa diuji di SessionBookingTest). */
    private function bookDirectly(TutorAvailabilitySlot $slot, string $name, string $email): LearningSession
    {
        if (auth()->check()) $this->post('/logout'); // /register hanya untuk tamu
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on']);
        $studentId = (string) $this->record($email)['id'];
        $this->markStudentVerified($studentId);
        $slot->refresh();
        $slot->update(['booked_count' => $slot->booked_count + 1, 'status' => $slot->booked_count + 1 >= $slot->capacity ? 'full' : 'open']);

        return LearningSession::query()->create([
            'student_id' => $studentId, 'tutor_id' => $slot->tutor_id, 'slot_id' => $slot->id, 'tutor_name' => 'Gusti',
            'subject' => 'Matematika', 'topic' => 'Pecahan', 'grade_level' => 'SMP', 'starts_at' => $slot->starts_at,
            'duration_minutes' => 60, 'meeting_platform' => 'Google Meet', 'status' => 'pending',
        ]);
    }

    /** @return array<string, mixed> */
    private function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
