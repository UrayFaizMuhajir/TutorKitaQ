<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\TutorAvailabilitySlot;
use App\Models\TutorProfile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** ROADMAP 3.1 (TT-11): katalog tutor asli + profil + slot kosong 14 hari. */
class TutorCatalogTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Concerns\CreatesVerifiedTutor;
    use \Tests\Concerns\CreatesVerifiedStudent;

    private string $userStorePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userStorePath = storage_path('framework/testing/catalog-users-'.bin2hex(random_bytes(8)).'.json');
        config(['auth.providers.users.path' => $this->userStorePath]);
        Auth::forgetGuards();
        Storage::fake('local');
        $this->seed(\Database\Seeders\SubjectCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->userStorePath) && is_file($this->userStorePath)) @unlink($this->userStorePath);
        parent::tearDown();
    }

    public function test_catalog_lists_only_verified_active_tutors_with_bookable_slots_and_no_fake_ratings(): void
    {
        $gusti = $this->tutor('Gusti Prabu', 'gusti@example.test', 'Matematika');
        $inactive = $this->tutor('Nonaktif Satu', 'nonaktif@example.test', 'IPA');
        DB::table('tutor_profiles')->where('user_id', $inactive)->update(['is_active' => false]);
        $this->register('Calon Tutor', 'calon@example.test', 'tutor'); // belum terverifikasi
        $now = CarbonImmutable::now('UTC');
        $shown = $this->slot($gusti, $now->addDays(2)->setTime(8, 0));
        $this->slot($gusti, $now->addDays(20)->setTime(8, 0)); // di luar 14 hari
        $this->slot($gusti, $now->addHours(2)); // < 3 jam lagi
        $this->slot($gusti, $now->addDays(3)->setTime(8, 0), ['status' => 'full', 'booked_count' => 20]);
        $this->slot($gusti, $now->addDays(4)->setTime(8, 0), ['status' => 'cancelled']);
        $this->slot($inactive, $now->addDays(2)->setTime(10, 0));

        $this->register('Budi', 'budi@example.test');
        $html = $this->withUnencryptedCookie('tk_tz', 'Asia/Jakarta')->get('/dashboard')->assertOk()->getContent();
        $catalog = Str::between($html, 'data-spa-page="tutors"', '<dialog class="catalog-profile-dialog"');

        $this->assertSame(1, substr_count($catalog, 'data-catalog-card '));
        $this->assertStringContainsString('Gusti Prabu', $catalog);
        $this->assertStringNotContainsString('Nonaktif Satu', $catalog);
        $this->assertStringNotContainsString('Calon Tutor', $catalog);
        $this->assertStringContainsString('Belum ada ulasan', $catalog);
        $this->assertStringNotContainsString('★', $catalog);
        // Jenjang tutor = jenjang mapel yang diajarkan (Matematika di seeder).
        $levels = Subject::query()->where('name', 'Matematika')->firstOrFail()->gradeLevels()->orderBy('sort_order')->pluck('name')->all();
        $this->assertStringContainsString('data-levels="'.implode('|', $levels).'"', $catalog);
        // Hanya satu slot yang bisa dipesan tampil, dalam zona pengguna.
        $this->assertSame(1, substr_count($catalog, 'data-catalog-slot='));
        $this->assertStringContainsString('data-catalog-slot="'.$shown->id.'"', $catalog);
        $this->assertStringContainsString('15.00–16.00 WIB', $catalog);
        $this->assertStringContainsString('Terisi 0/20', $catalog);
        $this->assertStringContainsString('1 slot kosong', $catalog);
        // Siswa belum terverifikasi: tombol pilih slot nonaktif + penjelasan.
        $this->assertMatchesRegularExpression('/data-catalog-pick-slot="'.$shown->id.'"[^>]*disabled/', $catalog);
        $this->assertStringContainsString('Pengajuan sesi dibuka setelah profil siswamu terverifikasi admin.', $catalog);
        // Beranda siswa: tutor asli.
        $this->assertStringContainsString('data-home-tutor="'.$gusti.'"', $html);

        $this->markStudentVerified((string) $this->record('budi@example.test')['id']);
        $catalog = Str::between($this->get('/dashboard')->getContent(), 'data-spa-page="tutors"', '<dialog class="catalog-profile-dialog"');
        $this->assertDoesNotMatchRegularExpression('/data-catalog-pick-slot="'.$shown->id.'"[^>]*disabled/', $catalog);
    }

    public function test_students_can_see_photos_of_verified_active_tutors_only(): void
    {
        $gusti = $this->tutor('Gusti Prabu', 'gusti@example.test', 'Matematika');
        $inactive = $this->tutor('Nonaktif', 'nonaktif@example.test', 'IPA');
        foreach ([$gusti, $inactive] as $id) {
            Storage::disk('local')->put("profile-photos/{$id}/foto.png", file_get_contents(public_path('assets/tutorkita/tutorkita-ikon-app.png')));
            TutorProfile::query()->where('user_id', $id)->update(['avatar_path' => "profile-photos/{$id}/foto.png"]);
        }
        DB::table('tutor_profiles')->where('user_id', $inactive)->update(['is_active' => false]);
        $this->register('Citra', 'citra@example.test');
        $citra = (string) $this->record('citra@example.test')['id'];
        $this->post('/logout');
        $this->register('Budi', 'budi@example.test');

        $this->get(route('dashboard.profile.photo', $gusti))->assertOk();
        $this->get(route('dashboard.profile.photo', $inactive))->assertForbidden();
        $this->get(route('dashboard.profile.photo', $citra))->assertForbidden();
        $this->assertStringContainsString(route('dashboard.profile.photo', $gusti), $this->get('/dashboard')->getContent());
    }

    public function test_empty_catalog_explains_that_no_tutor_is_available_yet(): void
    {
        $this->register('Budi', 'budi@example.test');
        $catalog = Str::between($this->get('/dashboard')->getContent(), 'data-spa-page="tutors"', '</section>');
        $this->assertStringContainsString('Belum ada tutor relawan yang tersedia saat ini.', $catalog);
        $this->assertStringContainsString('Belum ada tutor yang tersedia saat ini.', $this->get('/dashboard')->getContent());
    }

    private function tutor(string $name, string $email, string $subject): string
    {
        if (auth()->check()) $this->post('/logout');
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on', 'account_type' => 'tutor']);
        $id = (string) $this->record($email)['id'];
        $this->markTutorVerified($id, $name);
        TutorProfile::query()->where('user_id', $id)->firstOrFail()->subjects()->sync([Subject::query()->where('name', $subject)->value('id')]);

        return $id;
    }

    private function register(string $name, string $email, ?string $type = null): void
    {
        if (auth()->check()) $this->post('/logout');
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on'] + ($type ? ['account_type' => $type] : []));
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!']);
    }

    private function slot(string $tutorId, CarbonImmutable $start, array $extra = []): TutorAvailabilitySlot
    {
        return TutorAvailabilitySlot::query()->create($extra + ['tutor_id' => $tutorId, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'capacity' => 20, 'booked_count' => 0, 'status' => 'open']);
    }

    /** @return array<string, mixed> */
    private function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
