<?php

namespace App\Http\Controllers;

use App\Models\LearningSession;
use App\Support\SessionDecision;
use App\Support\TutorVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * "Sesi Mengajar" tutor (ROADMAP 3.3 · TT-15): terima/tolak pengajuan per siswa dan isi tautan kelas.
 * Route: terima = `tutor.verified` (terverifikasi + aktif); tolak & tautan = `role:tutor` + terverifikasi, agar tutor
 * yang dinonaktifkan tetap bisa memberi kabar ke siswa. Sesi milik tutor lain → 404.
 */
class TutorSessionController extends Controller
{
    private const PAGE = '/dashboard#teaching';

    public function accept(Request $request, int $learningSession): RedirectResponse
    {
        $data = $request->validateWithBag('teaching', ['meeting_url' => self::meetingUrlRules(false)], self::meetingUrlMessages());
        $session = $this->owned($request, $learningSession);
        if ($failed = $this->attempt(fn () => SessionDecision::accept($session, (string) $request->user()->name, $data['meeting_url'] ?? null))) return $failed;

        return redirect(self::PAGE)->with('status', 'Pengajuan diterima. Siswa mendapat notifikasi.');
    }

    public function reject(Request $request, int $learningSession): RedirectResponse
    {
        $this->assertVerified($request);
        $data = $request->validateWithBag('teaching', ['reason' => ['required', 'string', 'min:5', 'max:500']], [
            'reason.required' => 'Alasan penolakan wajib diisi.', 'reason.min' => 'Alasan minimal 5 karakter.', 'reason.max' => 'Alasan maksimal 500 karakter.',
        ]);
        $session = $this->owned($request, $learningSession);
        if ($failed = $this->attempt(fn () => SessionDecision::reject($session, (string) $request->user()->name, trim($data['reason'])))) return $failed;

        return redirect(self::PAGE)->with('status', 'Pengajuan ditolak. Kursi slot kembali kosong dan siswa mendapat notifikasi.');
    }

    public function meetingLink(Request $request, int $learningSession): RedirectResponse
    {
        $this->assertVerified($request);
        $data = $request->validateWithBag('teaching', ['meeting_url' => self::meetingUrlRules(true)], self::meetingUrlMessages());
        $session = $this->owned($request, $learningSession);
        if ($failed = $this->attempt(fn () => SessionDecision::setMeetingLink($session, (string) $request->user()->name, $data['meeting_url']))) return $failed;

        return redirect(self::PAGE)->with('status', 'Tautan kelas disimpan. Siswa mendapat notifikasi.');
    }

    /** @return list<string> */
    public static function meetingUrlRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:500', 'url:https'];
    }

    /** @return array<string, string> */
    public static function meetingUrlMessages(): array
    {
        return [
            'meeting_url.required' => 'Tautan kelas wajib diisi.',
            'meeting_url.url' => 'Tautan kelas harus berupa alamat https:// yang valid (mis. Google Meet atau Zoom).',
            'meeting_url.max' => 'Tautan kelas terlalu panjang.',
        ];
    }

    /** Aturan bisnis yang gagal (mis. sudah dijawab / waktu lewat) kembali ke halaman sebagai pesan error, bukan halaman 422. */
    private function attempt(callable $action): ?RedirectResponse
    {
        try {
            $action();
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 422) throw $exception;

            return redirect(self::PAGE)->withErrors(['session' => $exception->getMessage()], 'teaching');
        }

        return null;
    }

    private function owned(Request $request, int $id): LearningSession
    {
        return LearningSession::query()->whereKey($id)->where('tutor_id', (string) $request->user()->getAuthIdentifier())->firstOrFail();
    }

    private function assertVerified(Request $request): void
    {
        abort_unless(TutorVerification::isVerified((string) $request->user()->getAuthIdentifier()), 403, 'Akun tutormu belum terverifikasi.');
    }
}
