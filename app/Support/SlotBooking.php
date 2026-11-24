<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Kursi slot diambil/dilepas dengan SATU pernyataan UPDATE atomik — pemeriksaan kursi dan penambahan terjadi bersamaan,
 * jadi dua pemesanan serentak tidak bisa melebihi kapasitas (aman di SQLite dan MySQL). Panggil di dalam transaksi.
 * Catatan: ekspresi SET memakai nilai LAMA kolom (standar SQL), jadi CASE melihat booked_count sebelum ditambah.
 */
final class SlotBooking
{
    /** @return bool true bila kursi berhasil diambil; false bila slot penuh/dibatalkan/tidak ada. */
    public static function takeSeat(int $slotId): bool
    {
        return DB::update(
            "UPDATE tutor_availability_slots SET booked_count = booked_count + 1, status = CASE WHEN booked_count + 1 >= capacity THEN 'full' ELSE 'open' END, updated_at = ? WHERE id = ? AND status = 'open' AND booked_count < capacity",
            [now()->format('Y-m-d H:i:s'), $slotId],
        ) === 1;
    }

    /** Melepas satu kursi; slot yang tidak dibatalkan kembali open. */
    public static function releaseSeat(int $slotId): void
    {
        DB::update(
            "UPDATE tutor_availability_slots SET booked_count = CASE WHEN booked_count > 0 THEN booked_count - 1 ELSE 0 END, status = CASE WHEN status = 'cancelled' THEN 'cancelled' ELSE 'open' END, updated_at = ? WHERE id = ?",
            [now()->format('Y-m-d H:i:s'), $slotId],
        );
    }
}
