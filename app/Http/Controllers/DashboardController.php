<?php

namespace App\Http\Controllers;

use App\Support\DashboardMockData;
use App\Support\DashboardNavigation;
use App\Support\TutorVerification;
use App\Models\AssignmentSubmission;
use App\Models\LearningMaterial;
use App\Models\LearningSession;
use App\Models\StudentAssignment;
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
        $fullName = (string) $authenticatedUser->name;
        $nameParts = preg_split('/\s+/', trim($fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = mb_strtoupper(implode('', array_map(
            fn (string $part): string => mb_substr($part, 0, 1),
            array_slice($nameParts, 0, 2),
        )));

        $dashboardData = DashboardMockData::make();
        $dashboardData['user'] = [
            'name' => $fullName,
            'full_name' => $fullName,
            'email' => (string) $authenticatedUser->email,
            'initials' => $initials,
            'level' => 'Anggota TutorKita',
            'role' => $authenticatedUser->role ?? 'student',
            'role_label' => ['student' => 'Student', 'tutor' => 'Tutor', 'admin' => 'Admin'][$authenticatedUser->role ?? 'student'] ?? 'Student',
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
            $pendingApplications = $applications->where('status', 'pending')->values();
            $dashboardData['admin_tutor_applications'] = $applications;
            $dashboardData['admin_tutor_application_counts'] = ['all' => $applications->count()] + collect(['pending', 'approved', 'rejected'])
                ->mapWithKeys(fn (string $status): array => [$status => $applications->where('status', $status)->count()])->all();
            $dashboardData['pending_tutor_application_count'] = $pendingApplications->count();
            $dashboardData['pending_tutor_applications'] = $pendingApplications;
            // Role semua akun diambil dengan satu query (sebelumnya satu query per akun).
            $rolesByUser = DB::table('user_roles')->pluck('role', 'user_id');
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
        $dashboardData['learning_sessions'] = LearningSession::query()
            ->where('student_id', (string) $authenticatedUser->getAuthIdentifier())
            ->orderBy('starts_at')
            ->get()
            ->map(function (LearningSession $session) use ($now): LearningSession {
                $endsAt = $session->starts_at->copy()->addMinutes($session->duration_minutes);
                $status = $session->status;
                if ($status === 'confirmed' && $now->between($session->starts_at, $endsAt)) {
                    $status = 'in_progress';
                } elseif ($status === 'confirmed' && $now->greaterThan($endsAt)) {
                    $status = 'completed';
                }
                $meetingUrl = $session->meeting_url;
                $hasRealMeetingUrl = is_string($meetingUrl)
                    && filter_var($meetingUrl, FILTER_VALIDATE_URL)
                    && in_array(parse_url($meetingUrl, PHP_URL_SCHEME), ['http', 'https'], true);

                $session->display_status = $status;
                $session->status_label = [
                    'pending' => 'Menunggu Konfirmasi',
                    'confirmed' => 'Terkonfirmasi',
                    'in_progress' => 'Sedang Berlangsung',
                    'completed' => 'Selesai',
                    'cancelled' => 'Dibatalkan',
                ][$status] ?? 'Menunggu Konfirmasi';
                $session->date_label = $session->starts_at->locale('id')->isoFormat('dddd, D MMMM YYYY');
                $session->short_date_label = $session->starts_at->locale('id')->isoFormat('ddd, D MMM YYYY');
                $session->time_label = $session->starts_at->format('H:i').' – '.$endsAt->format('H:i');
                $session->initials = mb_strtoupper(implode('', array_map(
                    static fn (string $part): string => mb_substr($part, 0, 1),
                    array_slice(preg_split('/\s+/', trim($session->tutor_name), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2),
                )));
                $session->can_join = in_array($status, ['confirmed', 'in_progress'], true)
                    && $hasRealMeetingUrl
                    && $now->between($session->starts_at->copy()->subMinutes(15), $endsAt);
                $session->meeting_url = $hasRealMeetingUrl ? $meetingUrl : null;

                return $session;
            });

        $dashboardData['today_sessions'] = $dashboardData['learning_sessions']
            ->filter(fn (LearningSession $session): bool => $session->starts_at->isSameDay($now) && $session->display_status !== 'cancelled');
        $weekStart = $now->copy()->startOfWeek();
        $weekEnd = $now->copy()->endOfWeek();
        $dashboardData['week_sessions'] = $dashboardData['learning_sessions']
            ->filter(fn (LearningSession $session): bool => $session->starts_at->betweenIncluded($weekStart, $weekEnd) && $session->display_status !== 'cancelled')
            ->take(4);
        $dashboardData['session_tutors'] = $dashboardData['catalog_tutors'];
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
            ->filter(fn (LearningSession $session): bool => $session->starts_at->greaterThanOrEqualTo($now->copy()->startOfDay())
                && ! in_array($session->display_status, ['cancelled', 'completed'], true))
            ->map(function (LearningSession $session) use ($now): array {
                $isToday = $session->starts_at->isSameDay($now);
                $dateLabel = $isToday
                    ? 'Hari ini'
                    : ($session->starts_at->isTomorrow() ? 'Besok' : $session->starts_at->locale('id')->isoFormat('dddd, D MMM'));
                $colors = ['Matematika' => 'blue', 'Bahasa Indonesia' => 'purple', 'Bahasa Inggris' => 'amber'];

                return [
                    'subject' => $session->subject,
                    'topic' => $session->topic ?: $session->subject,
                    'tutor' => $session->tutor_name,
                    'initials' => $session->initials,
                    'time' => $session->time_label,
                    'date' => $session->starts_at->toDateString(),
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

        $dashboardData['tutor_home'] = $navigation['home'] === 'tutor'
            ? $this->tutorHome((string) $authenticatedUser->getAuthIdentifier())
            : null;
        $dashboardData['admin_home'] = $navigation['role'] === 'admin'
            ? $this->adminHome($dashboardData['pending_tutor_applications'], $dashboardData['admin_user_rows'])
            : null;

        $navigation['items'] = array_map(fn (array $item): array => $item + [
            'count' => ($item['page'] ?? null) === 'tutor-verifications' ? $dashboardData['pending_tutor_application_count'] : null,
        ], $navigation['items']);
        $dashboardData['navigation'] = $navigation;

        return view('dashboard.index', $dashboardData + [
            'active_nav' => 'dashboard',
            'page_title' => 'Dashboard',
            'search_placeholder' => $navigation['search_placeholder'],
        ]);
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
                ['label' => 'Siswa', 'value' => (string) ($roleCounts['student'] ?? 0), 'detail' => 'Akun berstatus Student', 'icon' => 'user', 'tone' => 'sky', 'target' => null],
                ['label' => 'Tutor', 'value' => (string) ($roleCounts['tutor'] ?? 0), 'detail' => 'Akun berstatus Tutor', 'icon' => 'users', 'tone' => 'amber', 'target' => null],
                ['label' => 'Admin', 'value' => (string) ($roleCounts['admin'] ?? 0), 'detail' => 'Akun berstatus Admin', 'icon' => 'check', 'tone' => 'green', 'target' => null],
            ],
            'recent_applications' => $pendingApplications->take(5)->values(),
        ];
    }
}
