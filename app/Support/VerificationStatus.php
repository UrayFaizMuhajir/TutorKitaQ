<?php

namespace App\Support;

/** Label status verifikasi yang sama untuk tutor dan siswa. */
final class VerificationStatus
{
    public const LABELS = [
        'incomplete' => 'Belum lengkap',
        'pending' => 'Menunggu verifikasi',
        'verified' => 'Terverifikasi',
        'rejected' => 'Ditolak',
    ];

    public static function label(?string $status): string
    {
        return self::LABELS[$status] ?? self::LABELS['incomplete'];
    }
}
