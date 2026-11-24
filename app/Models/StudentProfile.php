<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Profil & dokumen pendukung siswa (ROADMAP 5.1).
 * PRIVASI DATA ANAK: hanya boleh terlihat oleh siswa pemiliknya dan Admin — jangan pernah kirim ke tutor
 * (termasuk katalog, sesi, dan detail sesi di masa depan).
 */
class StudentProfile extends Model
{
    public const DOCUMENT_KINDS = ['kartu_pelajar' => 'Kartu pelajar', 'surat_keterangan' => 'Surat keterangan sekolah'];

    protected $fillable = [
        'user_id', 'school', 'grade_level_id', 'class_name', 'guardian_name', 'guardian_contact', 'region',
        'document_kind', 'document_path', 'status', 'rejection_reason', 'reviewed_by', 'submitted_at', 'reviewed_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(StudentVerificationReview::class);
    }
}
