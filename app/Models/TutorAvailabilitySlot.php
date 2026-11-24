<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Slot ketersediaan tutor (ROADMAP 2.2). Waktu UTC. capacity 1–20, booked_count = jumlah sesi aktif.
 * status: open (masih ada kursi) | full | cancelled. Kursi diambil/dilepas secara atomik di App\Support\SlotBooking.
 */
class TutorAvailabilitySlot extends Model
{
    protected $fillable = ['tutor_id', 'starts_at', 'ends_at', 'capacity', 'booked_count', 'status', 'cancel_reason'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(LearningSession::class, 'slot_id');
    }

    public function activeSessions(): HasMany
    {
        return $this->sessions()->whereIn('status', LearningSession::ACTIVE_STATUSES);
    }

    /** Slot yang masih memakai jam (open/full), dipakai untuk cek bentrok slot tutor. */
    public function scopeOccupying(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'full']);
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }
}
