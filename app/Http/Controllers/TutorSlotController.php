<?php

namespace App\Http\Controllers;

use App\Models\LearningSession;
use App\Models\TutorAvailabilitySlot;
use App\Support\LocalUserStore;
use App\Support\Notifier;
use App\Support\SlotPlanner;
use App\Support\TutorVerification;
use App\Support\UserTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Jadwal Saya" tutor (ROADMAP 2.2 · TT-7, TT-8). Tambah/ubah/hapus/pratinjau: middleware `tutor.verified`
 * (terverifikasi + aktif). Batalkan slot berpemesan: `role:tutor` + terverifikasi (tutor nonaktif tetap boleh).
 * Slot milik tutor lain selalu 404.
 */
class TutorSlotController extends Controller
{
    private const PAGE = '/dashboard#schedule';

    public function preview(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request);
        $plan = SlotPlanner::plan($this->tutorId($request), $data['date'], $data['start_time'], $data['end_time'], $data['repeat_until']);

        return response()->json([
            'timezone' => UserTime::tz(),
            'occurrences' => array_map(fn (array $item): array => ['label' => $item['label'], 'ok' => $item['ok'], 'reason' => $item['reason']], $plan),
            'create_count' => count(array_filter($plan, fn (array $item): bool => $item['ok'])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePlan($request);
        $tutorId = $this->tutorId($request);
        $created = [];
        $skipped = [];
        DB::transaction(function () use ($data, $tutorId, &$created, &$skipped): void {
            $created = []; $skipped = []; // aman bila transaksi dicoba ulang
            // Dihitung ulang di dalam transaksi: yang tersimpan hanya tanggal tanpa bentrok (sama dengan pratinjau).
            foreach (SlotPlanner::plan($tutorId, $data['date'], $data['start_time'], $data['end_time'], $data['repeat_until']) as $item) {
                if (! $item['ok']) { $skipped[] = $item; continue; }
                TutorAvailabilitySlot::query()->create([
                    'tutor_id' => $tutorId, 'starts_at' => $item['starts_at'], 'ends_at' => $item['ends_at'],
                    'capacity' => $data['capacity'], 'booked_count' => 0, 'status' => 'open',
                ]);
                $created[] = $item['label'];
            }
        }, 5); // dicoba ulang bila SQLite sibuk
        $skippedText = implode('; ', array_map(fn (array $item): string => $item['label'].' ('.$item['reason'].')', $skipped));
        if ($created === []) {
            throw ValidationException::withMessages(['slot' => 'Tidak ada slot yang dibuat. '.$skippedText]);
        }
        $message = count($created).' slot dibuat.'.($skipped ? ' Dilewati: '.$skippedText.'.' : '');
        if ($skipped) {
            // Notifikasi menyimpan waktu UTC agar tetap benar bila tutor kemudian membuka dari zona lain.
            $body = [count($created).' slot dibuat. Dilewati: '];
            foreach ($skipped as $i => $item) {
                array_push($body, $i ? '; ' : '', Notifier::slot($item['starts_at'], $item['ends_at']), ' (');
                $clash = $item['clash'];
                if ($clash) array_push($body, 'Bentrok dengan slot ', Notifier::slot($clash->starts_at, $clash->ends_at));
                else $body[] = (string) $item['reason'];
                $body[] = ')';
            }
            $body[] = '.';
            Notifier::send($tutorId, 'slot-skip-'.now()->format('YmdHisu'), 'Sebagian slot dilewati', $body, self::PAGE, 'system');
        }

        return redirect(self::PAGE)->with('status', $message);
    }

    public function update(Request $request, int $slot): RedirectResponse
    {
        $tutorId = $this->tutorId($request);
        $model = $this->ownedSlot($tutorId, $slot);
        abort_if($model->status === 'cancelled', 409, 'Slot ini sudah dibatalkan.');
        abort_if($model->starts_at->isPast(), 409, 'Slot yang sudah lewat tidak bisa diubah.');
        $capacityRule = ['required', 'integer', 'min:'.max(1, $model->booked_count), 'max:'.config('tutorkita.slot_max_capacity')];

        if ($model->booked_count > 0) {
            // Slot berpemesan: tanggal/jam terkunci, kapasitas tidak boleh di bawah jumlah pemesan.
            $data = $request->validate(['capacity' => $capacityRule], ['capacity.min' => 'Kapasitas tidak boleh kurang dari jumlah pemesan ('.$model->booked_count.').']);
            abort_if($request->filled('date') && $request->input('date') !== UserTime::local($model->starts_at)->format('Y-m-d'), 409, 'Slot yang sudah dipesan tidak bisa diubah tanggal atau jamnya.');
            abort_if($request->filled('start_time') && $request->input('start_time') !== UserTime::local($model->starts_at)->format('H:i'), 409, 'Slot yang sudah dipesan tidak bisa diubah tanggal atau jamnya.');
            abort_if($request->filled('end_time') && $request->input('end_time') !== UserTime::local($model->ends_at)->format('H:i'), 409, 'Slot yang sudah dipesan tidak bisa diubah tanggal atau jamnya.');
            DB::transaction(function () use ($model, $data): void {
                $fresh = TutorAvailabilitySlot::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
                abort_if($data['capacity'] < $fresh->booked_count, 409, 'Kapasitas tidak boleh kurang dari jumlah pemesan.');
                $fresh->update(['capacity' => $data['capacity'], 'status' => $fresh->booked_count >= $data['capacity'] ? 'full' : 'open']);
            }, 5);

            return redirect(self::PAGE)->with('status', 'Kapasitas slot diperbarui.');
        }

        $data = $this->validatePlan($request, false);
        $plan = SlotPlanner::plan($tutorId, $data['date'], $data['start_time'], $data['end_time'], null, $model->id)[0];
        if (! $plan['ok']) throw ValidationException::withMessages(['slot' => $plan['reason'].'.']);
        DB::transaction(function () use ($model, $plan, $data): void {
            $fresh = TutorAvailabilitySlot::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            abort_if($fresh->booked_count > 0, 409, 'Slot ini baru saja dipesan siswa; tanggal dan jam tidak bisa diubah.');
            $fresh->update(['starts_at' => $plan['starts_at'], 'ends_at' => $plan['ends_at'], 'capacity' => $data['capacity'], 'status' => 'open']);
        }, 5);

        return redirect(self::PAGE)->with('status', 'Slot diperbarui.');
    }

    public function destroy(Request $request, int $slot): RedirectResponse
    {
        $model = $this->ownedSlot($this->tutorId($request), $slot);
        DB::transaction(function () use ($model): void {
            $fresh = TutorAvailabilitySlot::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            abort_if($fresh->booked_count > 0 || $fresh->activeSessions()->exists(), 409, 'Slot yang sudah dipesan tidak bisa dihapus. Batalkan dengan alasan.');
            abort_if($fresh->status === 'cancelled', 409, 'Slot ini sudah dibatalkan.');
            $fresh->delete();
        }, 5);

        return redirect(self::PAGE)->with('status', 'Slot dihapus.');
    }

    public function cancel(Request $request, int $slot, LocalUserStore $users): RedirectResponse
    {
        $tutorId = $this->tutorId($request);
        abort_unless(TutorVerification::isVerified($tutorId), 403, 'Akun tutormu belum terverifikasi.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']], ['reason.required' => 'Alasan pembatalan wajib diisi.', 'reason.min' => 'Alasan minimal 5 karakter.']);
        $model = $this->ownedSlot($tutorId, $slot);
        $reason = trim($data['reason']);
        $tutorName = (string) ($users->findById($tutorId)['name'] ?? 'Tutor');
        $affected = 0;
        DB::transaction(function () use ($model, $reason, $tutorName, &$affected): void {
            $affected = 0; // aman bila transaksi dicoba ulang
            $fresh = TutorAvailabilitySlot::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            abort_if($fresh->status === 'cancelled', 409, 'Slot ini sudah dibatalkan.');
            abort_if($fresh->starts_at->isPast(), 409, 'Slot yang sudah dimulai tidak bisa dibatalkan.');
            $time = Notifier::slot($fresh->starts_at, $fresh->ends_at); // dirender di zona tiap siswa, bukan zona tutor
            $fresh->activeSessions()->get()->each(function (LearningSession $session) use ($reason, $tutorName, $time, &$affected): void {
                $session->update(['status' => 'cancelled', 'cancelled_by' => 'tutor', 'cancel_reason' => $reason]);
                $affected++;
                Notifier::send((string) $session->student_id, 'session-'.$session->id.'-cancelled-by-tutor', 'Sesi dibatalkan tutor',
                    [$tutorName.' membatalkan sesi '.$session->subject.' (', $time, '). Alasan: '.$reason], '/dashboard#sessions');
            });
            $fresh->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'booked_count' => 0]);
        }, 5);

        return redirect(self::PAGE)->with('status', 'Slot dibatalkan.'.($affected ? ' '.$affected.' siswa mendapat notifikasi.' : ''));
    }

    /** @return array{date: string, start_time: string, end_time: string, capacity: int, repeat_until: ?string} */
    private function validatePlan(Request $request, bool $allowRepeat = true): array
    {
        $min = (int) config('tutorkita.slot_min_minutes');
        $max = (int) config('tutorkita.slot_max_minutes');
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:'.config('tutorkita.slot_max_capacity')],
            'repeat_weekly' => ['nullable', 'boolean'],
            'repeat_until' => ['nullable', 'required_if:repeat_weekly,1', 'date_format:Y-m-d', 'after_or_equal:date'],
        ], [
            'date.required' => 'Tanggal wajib diisi.', 'start_time.required' => 'Jam mulai wajib diisi.', 'end_time.required' => 'Jam selesai wajib diisi.',
            'capacity.min' => 'Kapasitas minimal 1 siswa.', 'capacity.max' => 'Kapasitas maksimal '.config('tutorkita.slot_max_capacity').' siswa.',
            'repeat_until.required_if' => 'Isi tanggal akhir pengulangan.', 'repeat_until.after_or_equal' => 'Tanggal akhir pengulangan harus setelah tanggal mulai.',
        ]);
        $minutes = (int) ((strtotime('1970-01-01 '.$data['end_time'].':00 UTC') - strtotime('1970-01-01 '.$data['start_time'].':00 UTC')) / 60);
        if ($minutes <= 0) throw ValidationException::withMessages(['end_time' => 'Jam selesai harus setelah jam mulai.']);
        if ($minutes < $min || $minutes > $max) throw ValidationException::withMessages(['end_time' => "Durasi slot harus {$min}–{$max} menit."]);
        $repeatUntil = $allowRepeat && $request->boolean('repeat_weekly') ? ($data['repeat_until'] ?? null) : null;
        if ($repeatUntil) {
            $weeks = CarbonImmutable::parse($data['date'])->diffInDays(CarbonImmutable::parse($repeatUntil)) / 7;
            if ($weeks > (int) config('tutorkita.slot_max_repeat_weeks')) {
                throw ValidationException::withMessages(['repeat_until' => 'Pengulangan maksimal '.config('tutorkita.slot_max_repeat_weeks').' minggu.']);
            }
        }

        return ['date' => $data['date'], 'start_time' => $data['start_time'], 'end_time' => $data['end_time'],
            'capacity' => (int) ($data['capacity'] ?? config('tutorkita.slot_max_capacity')), 'repeat_until' => $repeatUntil];
    }

    private function tutorId(Request $request): string
    {
        return (string) $request->user()->getAuthIdentifier();
    }

    private function ownedSlot(string $tutorId, int $slot): TutorAvailabilitySlot
    {
        return TutorAvailabilitySlot::query()->where('tutor_id', $tutorId)->findOrFail($slot);
    }
}
