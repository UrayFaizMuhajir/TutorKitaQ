<?php

namespace App\Support;

use App\Models\AssignmentSubmission;
use App\Models\LearningSession;
use App\Models\StudentAssignment;
use App\Models\UserNotification;
use App\Models\UserNotificationPreference;
use Illuminate\Contracts\Auth\Authenticatable;

class NotificationCenterData
{
    private const CATEGORIES = ['sessions', 'assignments', 'messages', 'system'];

    /** @return array{notifications: \Illuminate\Support\Collection, notification_unread_count: int, notification_category_counts: array<string, int>, notification_preference_enabled: bool} */
    public static function make(Authenticatable $user): array
    {
        $userId = (string) $user->getAuthIdentifier();
        $preference = UserNotificationPreference::query()->firstOrCreate(['user_id' => $userId], ['enabled' => true]);
        if ($preference->enabled) self::syncActivity($userId);

        $items = UserNotification::query()->where('user_id', $userId)->latest('created_at')->get();
        $counts = ['all' => $items->count()];
        foreach (self::CATEGORIES as $category) {
            $counts[$category] = $items->where('category', $category)->count();
        }

        $items = $items->map(function (UserNotification $item): UserNotification {
            // Semua label waktu di zona pembaca (created_at tersimpan UTC).
            $created = UserTime::local($item->created_at);
            $today = UserTime::local(now());
            $item->display_body = $item->displayBody();
            $item->time_label = $created->format('H.i');
            $item->date_label = $created->locale('id')->isoFormat('D MMMM YYYY');
            $item->group_label = $created->isSameDay($today) ? 'Hari Ini' : ($created->isSameDay($today->subDay()) ? 'Kemarin' : $created->locale('id')->isoFormat('D MMMM YYYY'));
            return $item;
        });

        return [
            'notifications' => $items,
            'notification_unread_count' => $items->whereNull('read_at')->count(),
            'notification_category_counts' => $counts,
            'notification_preference_enabled' => $preference->enabled,
        ];
    }

    private static function syncActivity(string $userId): void
    {
        LearningSession::query()->where('student_id', $userId)->get()->each(function (LearningSession $session) use ($userId): void {
            // Sesi berbasis slot: keputusan tutor, pembatalan tutor, dan kedaluwarsa sudah dikirim langsung lewat Notifier
            // (SessionDecision/TutorSlotController) — di sini hanya konfirmasi pengajuan & selesai agar tidak ganda.
            if ($session->slot_id && ! in_array($session->status, ['pending', 'completed'], true)) return;
            $statusLabel = match ($session->status) {
                'confirmed' => 'Sesi Belajar Dikonfirmasi',
                'cancelled' => 'Sesi Belajar Dibatalkan',
                'completed' => 'Sesi Belajar Selesai',
                default => 'Pengajuan Sesi Belajar',
            };
            self::store($userId, 'session:'.$session->id.':'.$session->status, 'sessions', $statusLabel,
                [$session->subject.' dengan '.$session->tutor_name.' · ', Notifier::at($session->starts_at)], '#sessions');
        });

        StudentAssignment::query()->with('submission')->where('student_id', $userId)->get()->each(function (StudentAssignment $assignment) use ($userId): void {
            self::store($userId, 'assignment:'.$assignment->id, 'assignments', 'Tugas Baru',
                [$assignment->tutor_name.' memberikan tugas: “'.$assignment->title.'”. Deadline: ', Notifier::at($assignment->due_at), '.'], '#assignments');
            $submission = $assignment->submission;
            if ($submission instanceof AssignmentSubmission && $submission->graded_at) {
                self::store($userId, 'grade:'.$submission->id.':'.$submission->graded_at->timestamp, 'assignments', 'Nilai Tugas Telah Diberikan',
                    'Tugas “'.$assignment->title.'” telah dinilai. Nilaimu: '.number_format((float) $submission->score, 0).'/100.'.($submission->feedback ? ' Feedback: '.$submission->feedback : ''), '#assignments');
            }
        });
    }

    /** @param  string|list<string|array<string, mixed>>  $body */
    private static function store(string $userId, string $key, string $category, string $title, string|array $body, ?string $url): void
    {
        UserNotification::query()->firstOrCreate(
            ['user_id' => $userId, 'notification_key' => $key],
            ['category' => $category, 'title' => $title, ...Notifier::attributes($body), 'action_url' => $url],
        );
    }
}
