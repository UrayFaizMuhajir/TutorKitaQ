<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Log audit nonaktif/aktif tutor oleh admin (ROADMAP 1.3). action: deactivate|activate. */
class TutorStatusChange extends Model
{
    protected $fillable = ['user_id', 'actor_id', 'action', 'reason'];

    /** Perubahan status terakhir untuk satu tutor (alasan yang ditampilkan). */
    public static function latestFor(string $userId): ?self
    {
        return self::query()->where('user_id', $userId)->latest('id')->first();
    }
}
