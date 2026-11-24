<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Zona waktu pengguna. DB selalu UTC; input jam diubah ke UTC, tampilan diubah ke zona pengguna.
 * Zona dibaca middleware ResolveUserTimezone (field `timezone` atau cookie `tk_tz`), cadangan Asia/Jakarta.
 */
final class UserTime
{
    private static ?string $timezone = null;

    public static function isValid(?string $timezone): bool
    {
        return is_string($timezone) && $timezone !== '' && in_array($timezone, \DateTimeZone::listIdentifiers(), true);
    }

    public static function set(?string $timezone): void
    {
        self::$timezone = self::isValid($timezone) ? $timezone : null;
    }

    public static function tz(): string
    {
        return self::$timezone ?? config('tutorkita.default_timezone', 'Asia/Jakarta');
    }

    /** Tanggal (Y-m-d) + jam (H:i) di zona pengguna → waktu UTC. */
    public static function toUtc(string $date, string $time, ?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time, $timezone ?? self::tz())->utc();
    }

    public static function local(CarbonInterface $moment, ?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone($timezone ?? self::tz());
    }

    /** Singkatan zona: WIB/WITA/WIT dari PHP; singkatan angka (mis. "+08") ditulis "UTC+8". */
    public static function abbreviation(?CarbonInterface $moment = null, ?string $timezone = null): string
    {
        $local = self::local($moment ?? CarbonImmutable::now(), $timezone);
        $abbr = $local->format('T');
        if (preg_match('/^[A-Za-z]{2,5}$/', $abbr)) return $abbr;
        $offset = $local->offsetMinutes;
        $hours = intdiv(abs($offset), 60);
        $minutes = abs($offset) % 60;

        return 'UTC'.($offset < 0 ? '−' : '+').$hours.($minutes ? ':'.str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) : '');
    }

    public static function time(CarbonInterface $moment, ?string $timezone = null): string
    {
        return self::local($moment, $timezone)->format('H.i');
    }

    /** "15.00–16.00 WIB" */
    public static function timeRange(CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): string
    {
        return self::time($start, $timezone).'–'.self::time($end, $timezone).' '.self::abbreviation($start, $timezone);
    }

    /** "Rabu, 25 November 2026" */
    public static function dateLong(CarbonInterface $moment, ?string $timezone = null): string
    {
        return self::local($moment, $timezone)->locale('id')->isoFormat('dddd, D MMMM YYYY');
    }

    /** "Rab, 25 Nov" */
    public static function dateShort(CarbonInterface $moment, ?string $timezone = null): string
    {
        return self::local($moment, $timezone)->locale('id')->isoFormat('ddd, D MMM');
    }

    /** "Rabu, 25 Nov · 15.00–16.00 WIB" */
    public static function slotLabel(CarbonInterface $start, CarbonInterface $end, ?string $timezone = null): string
    {
        return self::local($start, $timezone)->locale('id')->isoFormat('dddd, D MMM').' · '.self::timeRange($start, $end, $timezone);
    }

    /** "25 November 2026, 15.00 WIB" */
    public static function dateTime(CarbonInterface $moment, ?string $timezone = null): string
    {
        return self::local($moment, $timezone)->locale('id')->isoFormat('D MMMM YYYY, HH.mm').' '.self::abbreviation($moment, $timezone);
    }
}
