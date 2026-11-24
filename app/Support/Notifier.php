<?php

namespace App\Support;

use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Membuat notifikasi in-app. PRIVASI (Aturan no. 11): isi untuk tutor tidak boleh memuat data profil siswa.
 *
 * Isi boleh berupa teks biasa ATAU daftar potongan: string, Notifier::slot($mulai, $selesai), atau Notifier::at($waktu).
 * Waktu disimpan UTC di kolom `data` dan baru diformat di zona waktu PEMBACA saat dirender (UserNotification::displayBody).
 * Kolom `body` tetap diisi teks dalam zona default sebagai cadangan. Potongan teks tidak pernah ditafsirkan sebagai
 * placeholder, jadi isian pengguna (topik, alasan) aman.
 */
final class Notifier
{
    /** @param  string|list<string|array<string, mixed>>  $body */
    public static function send(string $userId, string $key, string $title, string|array $body, string $actionUrl = '/dashboard', string $category = 'sessions'): void
    {
        UserNotification::query()->firstOrCreate(
            ['user_id' => $userId, 'notification_key' => $key],
            ['category' => $category, 'title' => $title, ...self::attributes($body), 'action_url' => $actionUrl],
        );
    }

    /**
     * Kolom `body` + `data` untuk isi teks atau berpotongan.
     *
     * @param  string|list<string|array<string, mixed>>  $body
     * @return array{body: string, data: ?array{body: list<string|array<string, mixed>>}}
     */
    public static function attributes(string|array $body): array
    {
        if (is_string($body)) return ['body' => $body, 'data' => null];

        return ['body' => self::render($body, config('tutorkita.default_timezone')), 'data' => ['body' => array_values($body)]];
    }

    /** Potongan rentang slot → "Rabu, 25 Nov · 15.00–16.00 WIB" di zona pembaca. */
    public static function slot(CarbonInterface $start, CarbonInterface $end): array
    {
        return ['slot' => [self::utc($start), self::utc($end)]];
    }

    /** Potongan satu waktu → "25 November 2026, 15.00 WIB" di zona pembaca. */
    public static function at(CarbonInterface $moment): array
    {
        return ['at' => self::utc($moment)];
    }

    /** @param  list<string|array<string, mixed>>  $segments */
    public static function render(array $segments, ?string $timezone = null): string
    {
        $text = '';
        foreach ($segments as $segment) {
            if (is_string($segment)) {
                $text .= $segment;
            } elseif (isset($segment['slot'][0], $segment['slot'][1])) {
                $start = self::parse($segment['slot'][0]);
                $end = self::parse($segment['slot'][1]);
                if ($start && $end) $text .= UserTime::slotLabel($start, $end, $timezone);
            } elseif (isset($segment['at'])) {
                $moment = self::parse($segment['at']);
                if ($moment) $text .= UserTime::dateTime($moment, $timezone);
            }
        }

        return $text;
    }

    private static function utc(CarbonInterface $moment): string
    {
        return CarbonImmutable::instance($moment)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) return null;
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
