<?php

namespace App\Http\Controllers;

use App\Models\LearningSession;
use App\Support\SessionBooking;
use App\Support\UserTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sesi belajar siswa (ROADMAP 3.2 · TT-12, TT-25). Ajukan & ganti jadwal: `role:student` + `student.verified`;
 * batalkan: `role:student`. Detail: hanya pemilik. Waktu disimpan UTC, ditampilkan dalam zona pengguna.
 */
class LearningSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slot_id' => ['required', 'integer'],
            'subject' => ['required', 'string', 'max:100'],
            'grade_level' => ['required', 'string', 'max:30'],
            'topic' => ['required', 'string', 'max:180'],
        ], [
            'slot_id.required' => 'Pilih slot jadwal tutor.',
            'subject.required' => 'Pilih mata pelajaran.',
            'grade_level.required' => 'Pilih jenjang.',
            'topic.required' => 'Topik pembelajaran perlu diisi.',
        ]);
        $session = SessionBooking::book((string) $request->user()->getAuthIdentifier(), (string) $request->user()->name, $data);
        $conflicts = $this->conflictLabels($request, $session);

        return response()->json([
            'message' => 'Pengajuan sesi berhasil disimpan dan menunggu konfirmasi tutor.'.($conflicts ? ' Perhatian: jadwal ini bentrok dengan '.implode('; ', $conflicts).'.' : ''),
            'session' => $this->serialize($session, $conflicts),
        ], 201);
    }

    public function show(Request $request, int $learningSession): JsonResponse
    {
        $session = $this->owned($request, $learningSession);

        return response()->json(['session' => $this->serialize($session, $this->conflictLabels($request, $session))]);
    }

    public function cancel(Request $request, int $learningSession): JsonResponse
    {
        SessionBooking::cancel($this->owned($request, $learningSession), (string) $request->user()->name);

        return response()->json(['message' => 'Pengajuan sesi berhasil dibatalkan.']);
    }

    public function reschedule(Request $request, int $learningSession): JsonResponse
    {
        $data = $request->validate(['slot_id' => ['required', 'integer']], ['slot_id.required' => 'Pilih slot baru.']);
        $session = SessionBooking::reschedule($this->owned($request, $learningSession), (int) $data['slot_id'], (string) $request->user()->name);
        $conflicts = $this->conflictLabels($request, $session);

        return response()->json([
            'message' => 'Jadwal sesi dipindahkan dan menunggu konfirmasi tutor.'.($conflicts ? ' Perhatian: masih bentrok dengan '.implode('; ', $conflicts).'.' : ''),
            'session' => $this->serialize($session, $conflicts),
        ]);
    }

    private function owned(Request $request, int $id): LearningSession
    {
        return LearningSession::query()->whereKey($id)->where('student_id', (string) $request->user()->getAuthIdentifier())->firstOrFail();
    }

    /** @return list<string> */
    private function conflictLabels(Request $request, LearningSession $session): array
    {
        $all = LearningSession::query()->where('student_id', (string) $request->user()->getAuthIdentifier())->get();

        return array_map(fn (LearningSession $other): string => $other->subject.', '.UserTime::slotLabel($other->starts_at, $other->endsAt()),
            SessionBooking::conflictsFor($all)[$session->id] ?? []);
    }

    /** @param list<string> $conflicts */
    private function serialize(LearningSession $session, array $conflicts = []): array
    {
        $now = now();
        $endsAt = $session->endsAt();
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

        return [
            'id' => $session->id,
            'tutor_id' => $session->tutor_id,
            'slot_id' => $session->slot_id,
            'tutor_name' => $session->tutor_name,
            'tutor_university' => $session->tutor_university,
            'tutor_major' => $session->tutor_major,
            'subject' => $session->subject,
            'topic' => $session->topic,
            'grade_level' => $session->grade_level,
            'starts_at' => $session->starts_at->toIso8601String(),
            'date_label' => UserTime::dateLong($session->starts_at),
            'time_label' => UserTime::timeRange($session->starts_at, $endsAt),
            'duration_minutes' => $session->duration_minutes,
            'meeting_platform' => $session->meeting_platform,
            'meeting_url' => $hasRealMeetingUrl ? $meetingUrl : null,
            'status' => $status,
            'cancelled_by' => $session->cancelled_by,
            'cancel_reason' => $session->cancel_reason,
            'conflicts' => $conflicts,
            'can_reschedule' => (bool) $session->slot_id && in_array($session->status, LearningSession::ACTIVE_STATUSES, true) && $session->starts_at->isFuture(),
        ];
    }
}
