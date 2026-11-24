<?php

namespace App\Support;

/**
 * Satu-satunya sumber definisi menu dashboard per role.
 *
 * Sidebar, judul topbar, halaman SPA yang dirender, dan judul halaman di JS
 * semuanya diturunkan dari sini. Menyembunyikan menu BUKAN pengaman data:
 * hak akses tetap dicek di route (middleware `role:`) — lihat ROADMAP 0.2.
 */
final class DashboardNavigation
{
    public const ROLES = ['student', 'tutor', 'admin'];

    /**
     * @return array{role: string, label: string, aria_label: string, kicker: string, search_placeholder: string, items: list<array<string, mixed>>, pages: list<string>}
     */
    public static function forRole(?string $role): array
    {
        $role = in_array($role, self::ROLES, true) ? $role : 'student';

        $definition = match ($role) {
            'tutor' => [
                'label' => 'MENU MENGAJAR',
                'aria_label' => 'Menu mengajar',
                'kicker' => 'Ruang mengajarmu',
                'search_placeholder' => 'Cari materi atau notifikasi...',
                'items' => [
                    self::page('dashboard', 'home', 'Beranda'),
                    self::page('materials', 'book', 'Materi Pembelajaran'),
                    self::page('notifications', 'bell', 'Notifikasi'),
                ],
            ],
            'admin' => [
                'label' => 'MENU ADMIN',
                'aria_label' => 'Menu admin',
                'kicker' => 'Panel admin',
                'search_placeholder' => 'Cari notifikasi...',
                'items' => [
                    self::page('dashboard', 'home', 'Beranda'),
                    self::action('admin-verification', 'check', 'Verifikasi Tutor'),
                    self::action('admin-users', 'users', 'Pengguna'),
                    self::page('notifications', 'bell', 'Notifikasi'),
                ],
            ],
            default => [
                'label' => 'MENU BELAJAR',
                'aria_label' => 'Menu belajar',
                'kicker' => 'Ruang belajarmu',
                'search_placeholder' => 'Cari jadwal atau tutor...',
                'items' => [
                    self::page('dashboard', 'home', 'Beranda'),
                    self::page('tutors', 'grid', 'Katalog Tutor'),
                    self::page('sessions', 'calendar', 'Sesi Belajar'),
                    self::page('materials', 'book', 'Materi Pembelajaran'),
                    self::page('assignments', 'check', 'Tugas & Penilaian'),
                    self::page('notifications', 'bell', 'Notifikasi'),
                    self::page('history', 'clock', 'Riwayat Sesi'),
                ],
            ],
        };

        $definition['role'] = $role;
        $definition['pages'] = array_values(array_map(
            static fn (array $item): string => $item['page'],
            array_filter($definition['items'], static fn (array $item): bool => isset($item['page'])),
        ));

        return $definition;
    }

    /** @return array{page: string, icon: string, label: string} */
    private static function page(string $page, string $icon, string $label): array
    {
        return ['page' => $page, 'icon' => $icon, 'label' => $label];
    }

    /** @return array{action: string, icon: string, label: string} */
    private static function action(string $action, string $icon, string $label): array
    {
        return ['action' => $action, 'icon' => $icon, 'label' => $label];
    }
}
