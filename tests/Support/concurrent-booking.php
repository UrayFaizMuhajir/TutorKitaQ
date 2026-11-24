<?php

/*
| Skrip pembantu tes konkuren (SessionBookingConcurrencyTest). Dijalankan sebagai PROSES TERPISAH dengan env
| DB_DATABASE = file SQLite sementara dan AUTH_USERS_FILE = file users.json sementara — tidak pernah menyentuh
| database/database.sqlite atau storage/app/private/users.json yang asli.
|
| Mode:
|   setup <capacity>                  → migrasi + tutor terverifikasi + 1 slot; cetak "slotId|gradeLevel"
|   book <slotId> <studentId> <level> → coba pesan; cetak OK / FULL / ERR:...
|   report <slotId>                   → cetak "booked_count|jumlah_sesi_aktif|status"
*/

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = (string) config('database.connections.sqlite.database');
if (realpath($database) === realpath($base.'/database/database.sqlite') || ! str_contains($database, 'tutorkita-concurrency-')) {
    fwrite(STDERR, "Menolak berjalan: bukan database sementara ({$database}).\n");
    exit(2);
}

$mode = $argv[1] ?? '';
try {
    if ($mode === 'setup') {
        Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        Illuminate\Support\Facades\Artisan::call('db:seed', ['--class' => Database\Seeders\SubjectCatalogSeeder::class, '--force' => true]);
        App\Models\TutorApplication::query()->create([
            'user_id' => '900', 'applicant_name' => 'Tutor Konkuren', 'education_level' => 'S1', 'institution' => 'Kampus', 'program' => 'Prodi',
            'graduation_year' => 2024, 'bio' => 'Tutor untuk uji pemesanan serentak.', 'status' => 'approved', 'submitted_at' => now(), 'approved_at' => now(),
        ]);
        $profile = App\Models\TutorProfile::query()->create(['user_id' => '900', 'institution' => 'Kampus', 'program' => 'Prodi', 'is_active' => true]);
        $subject = App\Models\Subject::query()->where('name', 'Matematika')->firstOrFail();
        $profile->subjects()->sync([$subject->id]);
        $start = Carbon\CarbonImmutable::now('UTC')->addDays(2)->setTime(8, 0);
        $slot = App\Models\TutorAvailabilitySlot::query()->create(['tutor_id' => '900', 'starts_at' => $start, 'ends_at' => $start->addHour(), 'capacity' => (int) ($argv[2] ?? 2), 'booked_count' => 0, 'status' => 'open']);
        echo $slot->id.'|'.$subject->gradeLevels()->orderBy('sort_order')->value('name');
    } elseif ($mode === 'book') {
        App\Support\SessionBooking::book((string) $argv[3], 'Siswa '.$argv[3], ['slot_id' => (int) $argv[2], 'subject' => 'Matematika', 'grade_level' => (string) $argv[4], 'topic' => 'Uji konkuren']);
        echo 'OK';
    } elseif ($mode === 'report') {
        $slot = App\Models\TutorAvailabilitySlot::query()->findOrFail((int) $argv[2]);
        echo $slot->booked_count.'|'.App\Models\LearningSession::query()->active()->where('slot_id', $slot->id)->count().'|'.$slot->status;
    }
} catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    echo $exception->getStatusCode() === 409 ? 'FULL' : 'ERR:'.$exception->getMessage();
} catch (Throwable $exception) {
    echo 'ERR:'.get_class($exception).': '.$exception->getMessage();
}
