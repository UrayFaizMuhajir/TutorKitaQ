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

    protected $fillable = [
        'student_id', 'tutor_id', 'slot_id', 'tutor_name', 'tutor_university', 'tutor_major', 'subject', 'topic',
        'grade_level', 'starts_at', 'duration_minutes', 'meeting_platform', 'meeting_url', 'status', 'cancelled_by', 'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'duration_minutes' => 'integer',
        ];
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
