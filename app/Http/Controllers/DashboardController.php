<?php

namespace App\Http\Controllers;

use App\Support\DashboardMockData;
use App\Support\DashboardNavigation;
use App\Support\StudentVerification;
use App\Support\TutorVerification;
use App\Models\AssignmentSubmission;
use App\Models\LearningMaterial;
use App\Models\LearningSession;
use App\Models\StudentAssignment;
use App\Models\StudentProfile;
use App\Models\TutorStatusChange;
use App\Support\LearningMaterialsDashboardData;
use App\Support\AssignmentDashboardData;
use App\Support\NotificationCenterData;
use App\Support\SessionHistoryData;
use App\Models\GradeLevel;
use App\Models\Subject;
use App\Models\TutorApplication;
use App\Models\TutorProfile;
use App\Support\LocalUserStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(LocalUserStore $users): View
    {
        $authenticatedUser = Auth::user();
        // TT-15: pengajuan yang tak dijawab sampai waktu mulai menjadi "Kedaluwarsa" (tanpa scheduler).
        \App\Support\SessionDecision::expireStale();
        $fullName = (string) $authenticatedUser->name;
        $nameParts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = mb_strtoupper(implode('', array_map(
            fn (string $part): string => mb_substr($part, 0, 1),
            array_slice($nameParts, 0, 2),
        )));

        $dashboardData = DashboardMockData::make();
        // Katalog tutor ASLI (ROADMAP 3.1) menggantikan data mock; beranda siswa menampilkan maks. 3 tutor asli.
        $dashboardData['catalog_tutors'] = \App\Support\TutorCatalog::make($users);
        $dashboardData['tutors'] = $dashboardData['catalog_tutors']->sortBy(fn (array $tutor) => $tutor['next_slot_ts'] ?? PHP_INT_MAX)->take(3)->values();
        $dashboardData['user'] = [
            'name' => $fullName,
            'full_name' => $fullName,
            'email' => (string) $authenticatedUser->email,
            'initials' => $initials,
            'level' => 'Anggota TutorKita',
            'role' => $authenticatedUser->role ?? 'student',
            'role_label' => DashboardNavigation::roleLabel($authenticatedUser->role ?? 'student'),
            'avatar_url' => $authenticatedUser->avatar_path ? route('dashboard.profile.photo', $authenticatedUser->getAuthIdentifier()) : null,
            'occupation' => $authenticatedUser->occupation ?: null,
        ];
        // Teks di bawah nama (topbar, sidebar, menu profil): pekerjaan bila diisi, kalau tidak peran.
        $dashboardData['user']['subtitle'] = $dashboardData['user']['occupation'] ?? $dashboardData['user']['role_label'];

        $userId = (string) $authenticatedUser->getAuthIdentifier();
        $isTutor = $dashboardData['user']['role'] === 'tutor';
        $verifiedTutor = $isTutor && TutorVerification::isVerified($userId);
        $navigation = DashboardNavigation::forRole($dashboardData['user']['role'], $verifiedTutor);
        $dashboardData['tutor_verified'] = $verifiedTutor;
        // ROADMAP 5.1: status verifikasi siswa (data ini hanya untuk siswa itu sendiri & admin).
        $dashboardData['student_verification'] = $dashboardData['user']['role'] === 'student' ? StudentVerification::stateFor($userId) : null;
        // ROADMAP 1.3: tutor terverifikasi yang dinonaktifkan admin tetap bisa membaca, tetapi tidak mengelola.
        $tutorActive = $verifiedTutor && TutorVerification::isActive($userId);
        $dashboardData['tutor_can_manage'] = $tutorActive;
        $dashboardData['tutor_deactivation'] = $verifiedTutor && ! $tutorActive ? TutorStatusChange::latestFor($userId) : null;
        $dashboardData['tutor_verification'] = $isTutor && ! $verifiedTutor
            ? TutorVerification::stateFor($userId, $authenticatedUser->avatar_path)
            : null;

        $dashboardData['tutor_profile'] = TutorProfile::query()->with('subjects')->where('user_id', (string) $authenticatedUser->getAuthIdentifier())->first();
        $profileAvatar = $authenticatedUser->avatar_path ?? $dashboardData['tutor_profile']?->avatar_path;
        $dashboardData['user']['avatar_url'] = $profileAvatar ? route('dashboard.profile.photo', $authenticatedUser->getAuthIdentifier()) : null;
        $dashboardData['tutor_application'] = TutorApplication::query()->with('subjects')->where('user_id', (string) $authenticatedUser->getAuthIdentifier())->first();
        $dashboardData['pending_tutor_applications'] = collect();
        $dashboardData['admin_tutor_applications'] = collect();
        $dashboardData['admin_tutor_application_counts'] = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        $dashboardData['admin_verified_tutors'] = collect();
        $dashboardData['admin_students'] = collect();
        $dashboardData['admin_student_counts'] = ['all' => 0, 'pending' => 0, 'rejected' => 0, 'incomplete' => 0, 'verified' => 0];
        $dashboardData['admin_verified_tutor_counts'] = ['all' => 0, 'active' => 0, 'inactive' => 0];
        $dashboardData['admin_user_rows'] = collect();
        $dashboardData['pending_tutor_application_count'] = 0;
        if (($authenticatedUser->role ?? 'student') === 'admin') {
            $accounts = collect($users->all())->keyBy(fn (array $record): string => (string) ($record['id'] ?? ''));
            // Asal pengajuan: tutor yang mendaftar sendiri tercatat dengan source "registration".
            $registeredAsTutor = DB::table('user_role_changes')->where('source', 'registration')->where('new_role', 'tutor')->pluck('user_id')->map(fn ($id): string => (string) $id)->flip();
            $applications = TutorApplication::query()
                ->with(['subjects', 'reviews' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")
                ->orderBy('submitted_at')
                ->get()
                ->map(function (TutorApplication $application) use ($accounts, $registeredAsTutor): TutorApplication {
                    $record = $accounts[(string) $application->user_id] ?? [];
                    $application->candidate_contact = $record['email'] ?? $record['phone'] ?? $record['contact'] ?? '';
                    $application->candidate_name = $record['name'] ?? $application->applicant_name;
                    $application->candidate_occupation = $record['occupation'] ?? null;
                    $application->candidate_photo_url = route('dashboard.profile.photo', $application->user_id);
                    $application->origin_label = isset($registeredAsTutor[(string) $application->user_id]) ? 'Daftar sebagai tutor' : 'Siswa mengajukan diri';
                    $application->card_url = $application->student_card_path ? route('dashboard.tutor-applications.student-card', $application) : null;
                    $application->card_is_pdf = TutorVerification::isPdf($application->student_card_path);

                    return $application;
                });
            // ROADMAP 1.3: daftar tutor terverifikasi untuk tab "Tutor Terverifikasi" (cari + filter aktif/nonaktif).
            $statusChanges = TutorStatusChange::query()->orderBy('id')->get()->groupBy('user_id');
            $upcomingByTutor = LearningSession::query()->active()->whereNotNull('tutor_id')
                ->where('starts_at', '>', now()->utc()->format('Y-m-d H:i:s'))->get(['tutor_id', 'student_id'])->groupBy('tutor_id');
            $dashboardData['admin_verified_tutors'] = TutorProfile::query()->verified()->with('subjects')->orderBy('id')->get()
                ->map(function (TutorProfile $profile) use ($accounts, $statusChanges, $upcomingByTutor): TutorProfile {
                    $upcoming = $upcomingByTutor->get((string) $profile->user_id, collect());
                    $profile->impact_sessions = $upcoming->count();
                    $profile->impact_students = $upcoming->pluck('student_id')->unique()->count();
                    $record = $accounts[(string) $profile->user_id] ?? [];
                    $profile->account_name = $record['name'] ?? 'Tutor';
                    $profile->account_contact = $record['email'] ?? $record['phone'] ?? $record['contact'] ?? '';
                    $profile->account_occupation = $record['occupation'] ?? null;
                    $profile->photo_url = route('dashboard.profile.photo', $profile->user_id);
                    $profile->latest_status_change = $statusChanges->get((string) $profile->user_id)?->last();

                    return $profile;
                });
            $dashboardData['admin_verified_tutor_counts'] = [
                'all' => $dashboardData['admin_verified_tutors']->count(),
                'active' => $dashboardData['admin_verified_tutors']->where('is_active', true)->count(),
                'inactive' => $dashboardData['admin_verified_tutors']->where('is_active', false)->count(),
            ];
            $pendingApplications = $applications->where('status', 'pending')->values();
            $dashboardData['admin_tutor_applications'] = $applications;
            $dashboardData['admin_tutor_application_counts'] = ['all' => $applications->count()] + collect(['pending', 'approved', 'rejected'])
                ->mapWithKeys(fn (string $status): array => [$status => $applications->where('status', $status)->count()])->all();
            $dashboardData['pending_tutor_application_count'] = $pendingApplications->count();
            $dashboardData['pending_tutor_applications'] = $pendingApplications;
            // Role semua akun diambil dengan satu query (sebelumnya satu query per akun).
            $rolesByUser = DB::table('user_roles')->pluck('role', 'user_id');
            // TT-10 / ROADMAP 5.2: semua akun siswa (termasuk yang belum mengisi profil = Belum lengkap).
            $studentProfiles = StudentProfile::query()
                ->with(['gradeLevel', 'reviews' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
                ->get()->keyBy(fn (StudentProfile $profile): string => (string) $profile->user_id);
            $statusOrder = ['pending' => 0, 'rejected' => 1, 'incomplete' => 2, 'verified' => 3];
            $dashboardData['admin_students'] = $accounts->filter(fn (array $record): bool => ($rolesByUser[(string) ($record['id'] ?? '')] ?? ($record['role'] ?? 'student')) === 'student')
                ->map(function (array $record) use ($studentProfiles): array {
                    $profile = $studentProfiles->get((string) ($record['id'] ?? ''));

                    return [
                        'id' => (string) ($record['id'] ?? ''),
                        'name' => (string) ($record['name'] ?? 'Siswa'),
                        'contact' => (string) ($record['email'] ?? $record['phone'] ?? $record['contact'] ?? ''),
                        'occupation' => $record['occupation'] ?? null,
                        'profile' => $profile,
                        'status' => $profile?->status ?? 'incomplete',
                        'document_url' => $profile?->document_path ? route('dashboard.student-profiles.document', $profile) : null,
                        'document_is_pdf' => \App\Support\PrivateDocument::isPdf($profile?->document_path),
                    ];
                })
                ->sortBy(fn (array $row): string => ($statusOrder[$row['status']] ?? 9).'-'.($row['profile']?->submitted_at?->timestamp ?? 0))
                ->values();
            $dashboardData['admin_student_counts'] = ['all' => $dashboardData['admin_students']->count()] + collect(array_keys($statusOrder))
                ->mapWithKeys(fn (string $status): array => [$status => $dashboardData['admin_students']->where('status', $status)->count()])->all();
            $dashboardData['admin_user_rows'] = $accounts->values()->map(function (array $record) use ($rolesByUser): array {
                $role = $rolesByUser[(string) ($record['id'] ?? '')] ?? ($record['role'] ?? 'student');

                return [
                    'id' => (string) ($record['id'] ?? ''), 'name' => (string) ($record['name'] ?? 'Pengguna'),
                    'contact' => (string) ($record['email'] ?? $record['phone'] ?? $record['contact'] ?? ''),
                    'occupation' => $record['occupation'] ?? null,
                    'role' => $role,
                ];
            });
        }

        $now = now();
        // Zona pengguna: tanggal/jam & batas "hari ini"/"minggu ini" dihitung dalam zona ini; DB tetap UTC.
        $localNow = \App\Support\UserTime::local($now);
        $studentSessions = LearningSession::query()
            ->where('student_id', (string) $authenticatedUser->getAuthIdentifier())
            ->orderBy('starts_at')
            ->get();
        // Bentrok antar sesi aktif siswa (ROADMAP 3.2): ditandai merah, dihitung ulang tiap muat halaman.
        $conflictMap = \App\Support\SessionBooking::conflictsFor($studentSessions);
        $dashboardData['learning_sessions'] = $studentSessions
            ->map(function (LearningSession $session) use ($now, $conflictMap): LearningSession {
                $endsAt = $session->endsAt();
                $status = $session->displayStatus($now);

                $session->display_status = $status;
                $session->status_label = LearningSession::STATUS_LABELS[$status] ?? LearningSession::STATUS_LABELS['pending'];
                $session->local_starts_at = \App\Support\UserTime::local($session->starts_at);
                $session->date_label = \App\Support\UserTime::dateLong($session->starts_at);
                $session->short_date_label = $session->local_starts_at->locale('id')->isoFormat('ddd, D MMM YYYY');
                $session->time_label = \App\Support\UserTime::timeRange($session->starts_at, $endsAt);
                $session->initials = mb_strtoupper(implode('', array_map(
                    static fn (string $part): string => mb_substr($part, 0, 1),
                    array_slice(preg_split('/\s+/', trim($session->tutor_name), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2),
                )));
                // TT-15: "Masuk Kelas" hanya bila confirmed + tautan https ada + ≤ join_window_minutes sebelum mulai.
                $session->can_join = $session->canJoin($now);
                $session->join_hint = match (true) {
                    $session->can_join => null,
                    $session->status === 'pending' => 'Menunggu tutor menerima pengajuan.',
                    $session->status === 'confirmed' && ! $session->meetingLink() => 'Tautan kelas akan dibagikan tutor.',
                    $session->status === 'confirmed' && $now->lessThan($endsAt) => 'Tombol aktif '.config('tutorkita.join_window_minutes').' menit sebelum sesi dimulai.',
                    default => 'Sesi ini tidak bisa dimasuki.',
                };
                $session->meeting_url = $session->meetingLink();
                $conflicts = $conflictMap[$session->id] ?? [];
                $session->has_conflict = $conflicts !== [];
                $session->conflict_label = collect($conflicts)->map(fn (LearningSession $other): string => $other->subject.', '.\App\Support\UserTime::slotLabel($other->starts_at, $other->endsAt()))->join('; ');
                $session->can_reschedule = (bool) $session->slot_id && in_array($session->status, LearningSession::ACTIVE_STATUSES, true) && $session->starts_at->isFuture();

                return $session;
            });

        $dashboardData['today_sessions'] = $dashboardData['learning_sessions']
            ->filter(fn (LearningSession $session): bool => $session->local_starts_at->isSameDay($localNow) && ! in_array($session->display_status, LearningSession::CLOSED_STATUSES, true));
        $weekStart = $localNow->startOfWeek();
        $weekEnd = $localNow->endOfWeek();
        $dashboardData['week_sessions'] = $dashboardData['learning_sessions']
            ->filter(fn (LearningSession $session): bool => $session->local_starts_at->betweenIncluded($weekStart, $weekEnd) && ! in_array($session->display_status, LearningSession::CLOSED_STATUSES, true))
            ->take(4);
        // Form pengajuan sesi berbasis slot: hanya tutor katalog yang punya slot kosong.
        $dashboardData['booking_tutors'] = $dashboardData['catalog_tutors']->filter(fn (array $tutor): bool => $tutor['slots'] !== [])->values();
        $dashboardData['student_active_intervals'] = $studentSessions
            ->filter(fn (LearningSession $session): bool => in_array($session->status, LearningSession::ACTIVE_STATUSES, true) && $session->endsAt()->greaterThan($now))
            ->map(fn (LearningSession $session): array => [
                'id' => $session->id, 'start' => $session->starts_at->getTimestamp(), 'end' => $session->endsAt()->getTimestamp(),
                'label' => $session->subject.', '.\App\Support\UserTime::slotLabel($session->starts_at, $session->endsAt()),
            ])->values();
        $dashboardData += SessionHistoryData::make($dashboardData['learning_sessions']);
        $dashboardData += LearningMaterialsDashboardData::make($authenticatedUser);
        $dashboardData['subject_catalog'] = Subject::query()
            ->with('gradeLevels')
            ->withCount(['materials as materials_count' => fn ($query) => $query->where('is_published', true)])
            ->orderBy('name')
            ->get();
        $dashboardData['subject_grade_levels'] = GradeLevel::query()->orderBy('sort_order')->get();
        $studentSubjects = DB::table('student_subjects')
            ->where('student_id', (string) $authenticatedUser->getAuthIdentifier())
            ->get(['subject_id', 'grade_level_id']);
        $dashboardData['student_subject_ids'] = $studentSubjects->pluck('subject_id')->map(fn ($id): int => (int) $id)->all();
        $dashboardData['student_subject_levels'] = $studentSubjects->pluck('grade_level_id', 'subject_id')->all();
        $dashboardData['student_subject_count'] = $studentSubjects->count();

        $dashboardData['schedules'] = $dashboardData['learning_sessions']
            ->filter(fn (LearningSession $session): bool => $session->local_starts_at->greaterThanOrEqualTo($localNow->startOfDay())
                && ! in_array($session->display_status, [...LearningSession::CLOSED_STATUSES, 'completed'], true))
            ->map(function (LearningSession $session) use ($localNow): array {
                $isToday = $session->local_starts_at->isSameDay($localNow);
                $dateLabel = $isToday
                    ? 'Hari ini'
                    : ($session->local_starts_at->isSameDay($localNow->addDay()) ? 'Besok' : $session->local_starts_at->locale('id')->isoFormat('dddd, D MMM'));
                $colors = ['Matematika' => 'blue', 'Bahasa Indonesia' => 'purple', 'Bahasa Inggris' => 'amber'];

                return [
                    'subject' => $session->subject,
                    'topic' => $session->topic ?: $session->subject,
                    'tutor' => $session->tutor_name,
                    'initials' => $session->initials,
                    'time' => $session->time_label,
                    'date' => $session->local_starts_at->toDateString(),
                    'conflict' => $session->has_conflict ? $session->conflict_label : null,
                    'date_label' => $dateLabel,
                    'status' => $session->status_label,
                    'status_key' => $isToday ? 'today' : 'upcoming',
                    'color' => $colors[$session->subject] ?? 'blue',
                ];
            })
            ->values();
        $dashboardData['today_schedule_count'] = $dashboardData['schedules']->where('status_key', 'today')->count();
        $dashboardData['upcoming_schedule_count'] = $dashboardData['schedules']->where('status_key', 'upcoming')->count();

        $completedThisWeek = $dashboardData['learning_sessions']->filter(
            fn (LearningSession $session): bool => $session->display_status === 'completed'
                && $session->starts_at->betweenIncluded($weekStart, $weekEnd),
        );
        $weekDates = collect(range(0, 6))->map(fn (int $offset) => $weekStart->copy()->addDays($offset));
        $minutesByDay = $weekDates->map(fn ($day) => $completedThisWeek
            ->filter(fn (LearningSession $session): bool => $session->starts_at->isSameDay($day))
            ->sum('duration_minutes'));
        $maxMinutes = max(1, (int) $minutesByDay->max());
        $dashboardData['progress'] = array_replace($dashboardData['progress'], [
            'weekly' => $weekDates->map(fn ($day, int $index): array => [
                'day' => $day->locale('id')->isoFormat('dd'),
                'value' => $minutesByDay[$index] > 0 ? (int) round($minutesByDay[$index] / $maxMinutes * 100) : 0,
            ])->all(),
            'weekly_hours' => number_format($minutesByDay->sum() / 60, 1, ',', '.'),
            'weekly_session_count' => $completedThisWeek->count(),
            'subjects' => $dashboardData['materials']->groupBy('subject')->map(function ($materials, string $subject): array {
                $completed = $materials->where('is_completed', true)->count();

                return [
                    'name' => $subject,
                    'value' => $materials->isNotEmpty() ? (int) round($completed / $materials->count() * 100) : 0,
                    'color' => ['Bahasa Indonesia' => 'purple', 'Bahasa Inggris' => 'amber'][$subject] ?? 'blue',
                ];
            })->filter(fn (array $subject): bool => $subject['value'] > 0)->values()->all(),
        ]);
        $dashboardData['stats'] = [
            ['label' => 'Mata Pelajaran Aktif', 'value' => (string) $studentSubjects->count(), 'detail' => $studentSubjects->isEmpty() ? 'Belum ada mata pelajaran dipilih' : 'Pilihan belajarmu', 'icon' => 'book', 'tone' => 'blue', 'target' => 'mata-pelajaran'],
            ['label' => 'Jadwal Hari Ini', 'value' => (string) $dashboardData['today_schedule_count'], 'detail' => $dashboardData['upcoming_schedule_count'].' sesi mendatang', 'icon' => 'calendar', 'tone' => 'sky', 'target' => 'jadwal'],
            ['label' => 'Sesi Belajar', 'value' => (string) $dashboardData['session_history_total'], 'detail' => $dashboardData['session_history_completed'].' sesi selesai', 'icon' => 'play', 'tone' => 'amber', 'target' => 'progress'],
            ['label' => 'Progress Belajar', 'value' => $dashboardData['material_progress_percent'].'%', 'detail' => $dashboardData['completed_material_count'].' dari '.$dashboardData['material_count'].' materi selesai', 'icon' => 'trend', 'tone' => 'green', 'target' => 'progress'],
        ];
        $dashboardData += AssignmentDashboardData::make($authenticatedUser);
        $dashboardData = array_replace($dashboardData, NotificationCenterData::make($authenticatedUser));

        $dashboardData['tutor_slots'] = $navigation['home'] === 'tutor' ? $this->tutorSlots($userId, $users) : collect();
        $dashboardData['tutor_teaching'] = $navigation['home'] === 'tutor' ? $this->tutorTeaching($userId, $users) : null;
        $dashboardData['tutor_home'] = $navigation['home'] === 'tutor'
            ? $this->tutorHome((string) $authenticatedUser->getAuthIdentifier())
            : null;
        $dashboardData['admin_home'] = $navigation['role'] === 'admin'
            ? $this->adminHome($dashboardData['pending_tutor_applications'], $dashboardData['admin_user_rows'])
            : null;

        $navigation['items'] = array_map(fn (array $item): array => $item + [
            'count' => match ($item['page'] ?? null) {
                'tutor-verifications' => $dashboardData['pending_tutor_application_count'],
                'student-verifications' => $dashboardData['admin_student_counts']['pending'],
                'teaching' => $dashboardData['tutor_teaching']['pending_count'] ?? null,
                default => null,
            },
        ], $navigation['items']);
        $dashboardData['navigation'] = $navigation;

        return view('dashboard.index', $dashboardData + [
            'active_nav' => 'dashboard',
            'page_title' => 'Dashboard',
            'search_placeholder' => $navigation['search_placeholder'],
        ]);
    }

    /**
     * Slot milik tutor untuk "Jadwal Saya". PRIVASI (Aturan no. 11): per pemesan hanya nama, mapel, topik.
     */
    private function tutorSlots(string $tutorId, LocalUserStore $users)
    {
        $names = collect($users->all())->mapWithKeys(fn (array $record): array => [(string) ($record['id'] ?? '') => (string) ($record['name'] ?? 'Siswa')]);
        $now = now()->utc();

        return \App\Models\TutorAvailabilitySlot::query()->where('tutor_id', $tutorId)
            ->with(['activeSessions' => fn ($query) => $query->orderBy('id')])
            ->orderBy('starts_at')->get()
            ->map(function (\App\Models\TutorAvailabilitySlot $slot) use ($names, $now): \App\Models\TutorAvailabilitySlot {
                $slot->is_past = $slot->starts_at->lessThanOrEqualTo($now);
                $slot->label = \App\Support\UserTime::slotLabel($slot->starts_at, $slot->ends_at);
                $local = \App\Support\UserTime::local($slot->starts_at);
                $slot->local_date = $local->format('Y-m-d');
                $slot->local_start = $local->format('H:i');
                $slot->local_end = \App\Support\UserTime::local($slot->ends_at)->format('H:i');
                $slot->display_status = $slot->status === 'cancelled' ? 'cancelled' : ($slot->is_past ? 'past' : $slot->status);
                $slot->bookings = $slot->activeSessions->map(fn (LearningSession $session): array => [
                    'name' => $names[(string) $session->student_id] ?? 'Siswa',
                    'subject' => $session->subject,
                    'topic' => $session->topic,
                ])->all();

                return $slot;
            });
    }

    /**
     * "Sesi Mengajar" (TT-15): sesi milik tutor dikelompokkan per slot dalam tiga tab — Pengajuan (pending mendatang),
     * Mendatang (confirmed belum selesai), Riwayat (sisanya). PRIVASI (Aturan no. 11): per siswa hanya nama + data sesi.
     *
     * @return array{tabs: array<string, list<array<string, mixed>>>, counts: array<string, int>, pending_count: int}
     */
    private function tutorTeaching(string $tutorId, LocalUserStore $users): array
    {
        $names = collect($users->all())->mapWithKeys(fn (array $record): array => [(string) ($record['id'] ?? '') => (string) ($record['name'] ?? 'Siswa')]);
        $now = now();
        $rows = LearningSession::query()->where('tutor_id', $tutorId)->with('slot')->orderBy('starts_at')->orderBy('id')->get()
            ->map(function (LearningSession $session) use ($names, $now): array {
                $status = $session->displayStatus($now);
                $future = $session->starts_at->greaterThan($now);
                $tab = match (true) {
                    $session->status === 'pending' && $future => 'pending',
                    $session->status === 'confirmed' && $session->endsAt()->greaterThan($now) => 'upcoming',
                    default => 'history',
                };

                return [
                    'tab' => $tab,
                    'group' => $session->slot_id ? 'slot-'.$session->slot_id : 'session-'.$session->id,
                    'slot' => $session->slot,
                    'id' => $session->id,
                    'starts_at' => $session->starts_at,
                    'label' => \App\Support\UserTime::slotLabel($session->starts_at, $session->endsAt()),
                    'student_name' => $names[(string) $session->student_id] ?? 'Siswa',
                    'subject' => $session->subject,
                    'topic' => $session->topic,
                    'grade_level' => $session->grade_level,
                    'status' => $status,
                    'status_label' => LearningSession::STATUS_LABELS[$status] ?? $status,
                    'reason' => $session->status === 'rejected' ? $session->rejection_reason : ($session->status === 'cancelled' ? $session->cancel_reason : null),
                    'meeting_url' => $session->meetingLink(),
                    'meeting_platform' => $session->meeting_platform,
                    'can_decide' => $tab === 'pending',
                    'can_set_link' => $session->status === 'confirmed' && $future,
                ];
            });

        $tabs = [];
        foreach (['pending', 'upcoming', 'history'] as $tab) {
            $tabRows = $rows->where('tab', $tab);
            if ($tab === 'history') $tabRows = $tabRows->sortByDesc(fn (array $row) => $row['starts_at']->getTimestamp());
            $tabs[$tab] = $tabRows->groupBy('group')->map(fn ($group): array => [
                'label' => $group->first()['label'],
                'slot' => $group->first()['slot'],
                'sessions' => $group->values()->all(),
                'search' => \Illuminate\Support\Str::lower($group->first()['label'].' '.$group->pluck('student_name')->join(' ').' '.$group->pluck('subject')->join(' ')),
            ])->values()->all();
        }
        $counts = collect($tabs)->map(fn (array $groups): int => (int) collect($groups)->sum(fn (array $group): int => count($group['sessions'])))->all();

        return ['tabs' => $tabs, 'counts' => $counts, 'pending_count' => $counts['pending']];
    }

    /** Ringkasan beranda tutor: hanya data milik tutor yang sedang login. */
    private function tutorHome(string $tutorId): array
    {
        $ownMaterials = fn () => LearningMaterial::query()->where('created_by', $tutorId);
        $publishedCount = $ownMaterials()->where('is_published', true)->count();
        $draftCount = $ownMaterials()->where('is_published', false)->count();
        $assignmentCount = StudentAssignment::query()->where('tutor_id', $tutorId)->count();
        $ungradedCount = AssignmentSubmission::query()
            ->whereNotNull('submitted_at')
            ->whereNull('score')
            ->whereHas('assignment', fn ($query) => $query->where('tutor_id', $tutorId))
            ->count();

        return [
            'stats' => [
                ['label' => 'Materi Terbit', 'value' => (string) $publishedCount, 'detail' => 'Terlihat oleh siswa', 'icon' => 'book-open', 'tone' => 'blue', 'target' => 'materials'],
                ['label' => 'Draf Materi', 'value' => (string) $draftCount, 'detail' => 'Belum diterbitkan', 'icon' => 'edit', 'tone' => 'sky', 'target' => 'materials'],
                ['label' => 'Tugas Diberikan', 'value' => (string) $assignmentCount, 'detail' => 'Tugas yang kamu buat', 'icon' => 'document', 'tone' => 'amber', 'target' => null],
                ['label' => 'Menunggu Dinilai', 'value' => (string) $ungradedCount, 'detail' => 'Pengumpulan belum dinilai', 'icon' => 'check', 'tone' => 'green', 'target' => null],
            ],
            'recent_materials' => $ownMaterials()
                ->latest('updated_at')
                ->take(5)
                ->get(['id', 'title', 'subject', 'grade_level', 'is_published', 'updated_at']),
        ];
    }

    /** Ringkasan beranda admin dari data yang sudah dirakit untuk dialog admin. */
    private function adminHome($pendingApplications, $userRows): array
    {
        $roleCounts = collect($userRows)->countBy('role');

        return [
            'stats' => [
                ['label' => 'Pengajuan Tutor', 'value' => (string) $pendingApplications->count(), 'detail' => 'Menunggu pemeriksaan', 'icon' => 'graduation-cap', 'tone' => 'blue', 'target' => null],
                ['label' => 'Siswa', 'value' => (string) ($roleCounts['student'] ?? 0), 'detail' => 'Akun berstatus Siswa', 'icon' => 'user', 'tone' => 'sky', 'target' => null],
                ['label' => 'Tutor', 'value' => (string) ($roleCounts['tutor'] ?? 0), 'detail' => 'Akun berstatus Tutor', 'icon' => 'users', 'tone' => 'amber', 'target' => null],
                ['label' => 'Admin', 'value' => (string) ($roleCounts['admin'] ?? 0), 'detail' => 'Akun berstatus Admin', 'icon' => 'check', 'tone' => 'green', 'target' => null],
            ],
            'recent_applications' => $pendingApplications->take(5)->values(),
        ];
    }
}
