<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Log verifikasi siswa (pola sama dengan tutor_application_reviews). */
class StudentVerificationReview extends Model
{
    protected $fillable = ['student_profile_id', 'actor_id', 'action', 'from_status', 'to_status', 'reason'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }
}
