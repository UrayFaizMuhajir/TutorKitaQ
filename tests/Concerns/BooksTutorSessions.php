<?php

namespace Tests\Concerns;

use App\Models\LearningSession;
use App\Models\Subject;
use App\Models\TutorAvailabilitySlot;
use App\Models\TutorProfile;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Alur sesi tutor (TT-15/16) di tes: akun nyata di users.json sementara ($this->userStorePath), tutor terverifikasi + slot,
 * siswa terverifikasi memesan, login bergantian. Butuh CreatesVerifiedTutor + CreatesVerifiedStudent.
 */
trait BooksTutorSessions
{
    /** @return array{0: string, 1: TutorAvailabilitySlot} */
    protected function tutorWithSlot(int $capacity = 20): array
    {
        $tutorId = $this->tutorAccount('Gusti Prabu', 'tutor@example.test');
        $start = CarbonImmutable::now('UTC')->addDays(2)->setTime(8, 0);
        $slot = TutorAvailabilitySlot::query()->create(['tutor_id' => $tutorId, 'starts_at' => $start, 'ends_at' => $start->addHour(), 'capacity' => $capacity, 'booked_count' => 0, 'status' => 'open']);

        return [$tutorId, $slot];
    }

    protected function bookAs(string $name, string $email, TutorAvailabilitySlot $slot): LearningSession
    {
        $id = $this->account($name, $email, 'student');
        $this->markStudentVerified($id);
        $this->login($email);
        $this->postJson(route('dashboard.sessions.store'), $this->payload($slot))->assertCreated();

        return LearningSession::query()->where('student_id', $id)->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    protected function payload(TutorAvailabilitySlot $slot): array
    {
        return ['slot_id' => $slot->id, 'subject' => 'Matematika', 'topic' => 'Persamaan kuadrat',
            'grade_level' => Subject::query()->where('name', 'Matematika')->firstOrFail()->gradeLevels()->orderBy('sort_order')->value('name')];
    }

    protected function tutorAccount(string $name, string $email): string
    {
        $id = $this->account($name, $email, 'tutor');
        $this->markTutorVerified($id, $name);
        TutorProfile::query()->where('user_id', $id)->firstOrFail()->subjects()->sync([Subject::query()->where('name', 'Matematika')->value('id')]);

        return $id;
    }

    protected function account(string $name, string $email, string $type): string
    {
        if (auth()->check()) $this->post('/logout');
        $this->post('/register', ['name' => $name, 'contact' => $email, 'password' => 'TutorKita123!', 'password_confirmation' => 'TutorKita123!', 'terms' => 'on', 'account_type' => $type]);
        if (auth()->check()) $this->post('/logout');

        return (string) $this->record($email)['id'];
    }

    protected function login(string $email): void
    {
        if (auth()->check()) $this->post('/logout');
        Auth::forgetGuards();
        $this->post('/login', ['contact' => $email, 'password' => 'TutorKita123!']);
        $this->assertAuthenticated();
    }

    protected function teachingPage(): string
    {
        return Str::between($this->get('/dashboard')->assertOk()->getContent(), 'data-spa-page="teaching"', '</section>');
    }

    protected function studentCard(LearningSession $session): string
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();
        $marker = 'data-session-detail-url="'.route('dashboard.sessions.show', ['learningSession' => $session->id]).'"';

        return Str::before(Str::after($html, $marker), '</article>');
    }

    protected function notificationBody(string $userId, string $title): string
    {
        return (string) UserNotification::query()->where('user_id', $userId)->where('title', $title)->latest('id')->value('body');
    }

    /** @return array<string, mixed> */
    protected function record(string $email): array
    {
        $records = json_decode(file_get_contents($this->userStorePath), true, flags: JSON_THROW_ON_ERROR);

        return collect($records)->first(fn (array $record): bool => ($record['email'] ?? null) === $email);
    }
}
