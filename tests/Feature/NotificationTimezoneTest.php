<?php

namespace Tests\Feature;

use App\Models\UserNotification;
use App\Support\Notifier;
use App\Support\UserTime;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Jam di notifikasi disimpan UTC dan dirender di zona waktu PEMBACA; notifikasi lama (teks) apa adanya. */
class NotificationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/notification-tz-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        UserTime::set(null);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_same_notification_shows_16_00_wita_in_makassar_and_15_00_wib_in_jakarta(): void
    {
        $userId = $this->signIn();
        $moment = CarbonImmutable::parse('2026-12-02 08:00:00', 'UTC');
        Notifier::send($userId, 'tz:at', 'Pengingat sesi', ['Sesi Matematika dimulai ', Notifier::at($moment), '.']);

        $makassar = $this->notificationsHtml('Asia/Makassar');
        $this->assertStringContainsString('Sesi Matematika dimulai 2 Desember 2026, 16.00 WITA.', $makassar);
        $this->assertStringNotContainsString('WIB', $makassar);

        $jakarta = $this->notificationsHtml('Asia/Jakarta');
        $this->assertStringContainsString('Sesi Matematika dimulai 2 Desember 2026, 15.00 WIB.', $jakarta);
        $this->assertStringNotContainsString('WITA', $jakarta);

        // Satu baris saja: yang berbeda hanya tampilannya.
        $this->assertSame(1, UserNotification::query()->where('user_id', $userId)->count());
    }

    public function test_slot_ranges_follow_the_reader_timezone_and_utc_is_stored(): void
    {
        $userId = $this->signIn();
        Notifier::send($userId, 'tz:slot', 'Pengajuan sesi baru', ['Budi mengajukan sesi pada ', Notifier::slot(
            CarbonImmutable::parse('2026-12-02 08:00:00', 'UTC'), CarbonImmutable::parse('2026-12-02 09:00:00', 'UTC'),
        ), '.']);

        $this->assertStringContainsString('16.00–17.00 WITA', $this->notificationsHtml('Asia/Makassar'));
        $this->assertStringContainsString('15.00–16.00 WIB', $this->notificationsHtml('Asia/Jakarta'));
        $this->assertStringContainsString('17.00–18.00 WIT', $this->notificationsHtml('Asia/Jayapura'));

        $row = UserNotification::query()->where('notification_key', 'tz:slot')->firstOrFail();
        $this->assertSame(['slot' => ['2026-12-02T08:00:00Z', '2026-12-02T09:00:00Z']], $row->data['body'][1]);
        // Kolom body tetap berisi teks cadangan dalam zona default.
        $this->assertStringContainsString('15.00–16.00 WIB', $row->body);
    }

    public function test_old_text_notifications_are_shown_as_is(): void
    {
        $userId = $this->signIn();
        UserNotification::query()->create(['user_id' => $userId, 'notification_key' => 'legacy:1', 'category' => 'sessions',
            'title' => 'Sesi lama', 'body' => 'Sesi pada Rabu, 25 Nov · 15.00–16.00 WIB.']);

        $this->assertStringContainsString('Sesi pada Rabu, 25 Nov · 15.00–16.00 WIB.', $this->notificationsHtml('Asia/Makassar'));
    }

    public function test_user_text_is_never_treated_as_a_time_placeholder(): void
    {
        $userId = $this->signIn();
        Notifier::send($userId, 'tz:text', 'Alasan', ['Alasan: {slot} :at {at}']);

        $this->assertStringContainsString('Alasan: {slot} :at {at}', $this->notificationsHtml('Asia/Makassar'));
    }

    public function test_received_time_and_day_group_use_the_reader_timezone(): void
    {
        // 23.30 UTC = 07.30 WITA keesokan harinya.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-02 23:45:00', 'UTC'));
        \Illuminate\Support\Carbon::setTestNow(CarbonImmutable::parse('2026-12-02 23:45:00', 'UTC'));
        $userId = $this->signIn();
        $notification = UserNotification::query()->create(['user_id' => $userId, 'notification_key' => 'tz:created', 'category' => 'system', 'title' => 'Info', 'body' => 'Isi']);
        $notification->forceFill(['created_at' => '2026-12-02 23:30:00'])->save();

        $makassar = $this->notificationsHtml('Asia/Makassar');
        $this->assertStringContainsString('07.30', $makassar);
        $this->assertStringContainsString('Hari Ini', $makassar);
        $jakarta = $this->notificationsHtml('Asia/Jakarta');
        $this->assertStringContainsString('06.30', $jakarta);
        \Illuminate\Support\Carbon::setTestNow();
    }

    private function signIn(): string
    {
        $this->post('/register', ['name' => 'Pembaca', 'contact' => 'pembaca@example.test', 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on']);
        $this->post('/login', ['contact' => 'pembaca@example.test', 'password' => 'TutorKita123!']);
        $this->assertAuthenticated();

        return (string) auth()->id();
    }

    private function notificationsHtml(string $timezone): string
    {
        $html = $this->withUnencryptedCookie('tk_tz', $timezone)->get('/dashboard')->assertOk()->getContent();

        return Str::between($html, 'data-spa-page="notifications"', '</section>');
    }
}
