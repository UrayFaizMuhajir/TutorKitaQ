<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TutorProfile extends Model
{
    protected $fillable = [
        'user_id', 'education_level', 'institution', 'program', 'graduation_year', 'bio',
        'experience', 'additional_info', 'avatar_path', 'student_card_path', 'availability', 'is_active',
    ];

    protected function casts(): array
    {
        return ['availability' => 'array', 'is_active' => 'boolean'];
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'tutor_profile_subject');
    }

    /**
     * Tutor terverifikasi = pengajuan tutornya berstatus approved. Dipakai katalog tutor asli (ROADMAP 3.1)
     * agar tutor yang belum terverifikasi tidak pernah tampil.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereIn('user_id', TutorApplication::query()->where('status', 'approved')->select('user_id'));
    }

    /**
     * Tutor aktif (tidak dinonaktifkan admin, ROADMAP 1.3). Katalog tutor asli memakai
     * `TutorProfile::verified()->active()`.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
