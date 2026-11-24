<?php

namespace App\Support;

use App\Models\UserNotification;

/** Membuat notifikasi in-app. PRIVASI (Aturan no. 11): isi untuk tutor tidak boleh memuat data profil siswa. */
final class Notifier
{
    public static function send(string $userId, string $key, string $title, string $body, string $actionUrl = '/dashboard', string $category = 'sessions'): void
    {
        UserNotification::query()->firstOrCreate(
            ['user_id' => $userId, 'notification_key' => $key],
            ['category' => $category, 'title' => $title, 'body' => $body, 'action_url' => $actionUrl],
        );
    }
}
