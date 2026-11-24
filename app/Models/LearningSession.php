<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sesi belajar. starts_at disimpan UTC. Sesi baru selalu punya tutor_id + slot_id; sesi lama (tutor mock) tidak.
 * PRIVASI (Aturan no. 11): tutor hanya boleh melihat nama siswa + detail sesi, bukan data profil siswa.
 */
class LearningSession extends Model
{
    public const ACTIVE_STATUSES = ['pending', 'confirmed'];

    /** Status akhir yang tidak berjalan: dibatalkan, ditolak tutor (TT-15), atau kedaluwarsa (tak dijawab). */
    public const CLOSED_STATUSES = ['cancelled', 'rejected', 'expired'];

    public const STATUS_LABELS = [
        'pending' => 'Menunggu Konfirmasi',
        'confirmed' => 'Terkonfirmasi',
        'in_progress' => 'Sedang Berlangsung',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        'rejected' => 'Ditolak Tutor',
        'expired' => 'Kedaluwarsa',
    ];

    protected $fillable = [
        'student_id', 'tutor_id', 'slot_id', 'tutor_name', 'tutor_university', 'tutor_major', 'subject', 'topic',
        'grade_level', 'starts_at', 'duration_minutes', 'meeting_platform', 'meeting_url', 'status', 'cancelled_by', 'cancel_reason',
        'decided_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
        ];
    }

    /** Status untuk tampilan: confirmed menjadi in_progress/completed menurut waktu (completed otomatis dihapus di TT-16). */
    public function displayStatus(?\Carbon\CarbonInterface $now = null): string
    {
        $now ??= now();
        if ($this->status === 'confirmed' && $now->between($this->starts_at, $this->endsAt())) return 'in_progress';
        if ($this->status === 'confirmed' && $now->greaterThan($this->endsAt())) return 'completed';

        return $this->status;
    }

    /** Tautan kelas hanya dianggap ada bila URL http(s) yang valid. */
    public function meetingLink(): ?string
    {
        $url = $this->meeting_url;

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) ? $url : null;
    }

    /** "Masuk Kelas": confirmed + tautan ada + sejak `join_window_minutes` sebelum mulai sampai sesi berakhir. */
    public function canJoin(?\Carbon\CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->status === 'confirmed' && $this->meetingLink() !== null
            && $now->between($this->starts_at->subMinutes((int) config('tutorkita.join_window_minutes')), $this->endsAt());
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(TutorAvailabilitySlot::class, 'slot_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function endsAt(): \Carbon\CarbonImmutable
    {
        return $this->starts_at->addMinutes($this->duration_minutes);
    }
}
