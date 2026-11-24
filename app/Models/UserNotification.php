<?php

namespace App\Models;

use App\Support\Notifier;
use Illuminate\Database\Eloquent\Model;

class UserNotification extends Model
{
    protected $fillable = ['user_id', 'notification_key', 'category', 'title', 'body', 'data', 'action_url', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'data' => 'array'];
    }

    /**
     * Isi notifikasi untuk pembaca: waktu kejadian (UTC di `data`) diformat di zona pembaca (default: zona request).
     * Notifikasi lama tanpa `data` memakai `body` apa adanya.
     */
    public function displayBody(?string $timezone = null): string
    {
        $segments = $this->data['body'] ?? null;

        return is_array($segments) ? Notifier::render($segments, $timezone) : (string) $this->body;
    }
}
