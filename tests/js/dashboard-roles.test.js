import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Kerangka dashboard per role, mengikuti Blade setelah ROADMAP 0.1:
// - Tutor: hanya Beranda, Materi, Notifikasi yang dirender (tanpa katalog/sesi/tugas/riwayat).
// - Admin: hanya Beranda dan Notifikasi; aksi Verifikasi Tutor & Pengguna ada di sidebar.
const menus = {
    student: [['dashboard', 'Beranda'], ['tutors', 'Katalog Tutor'], ['sessions', 'Sesi Belajar'], ['materials', 'Materi Pembelajaran'], ['assignments', 'Tugas & Penilaian'], ['notifications', 'Notifikasi'], ['history', 'Riwayat Sesi']],
    tutor: [['dashboard', 'Beranda'], ['materials', 'Materi Pembelajaran'], ['notifications', 'Notifikasi']],
    admin: [['dashboard', 'Beranda'], ['notifications', 'Notifikasi']],
};

const sidebar = (role) => `
    <aside class="sidebar" id="main-sidebar">
        <nav class="sidebar-nav" data-sidebar-nav data-role="${role}" data-spa-nav>
            ${menus[role].map(([page, title]) => `<button class="nav-link" type="button" data-page-link="${page}" data-page-title="${title}">${title}</button>`).join('')}
            ${role === 'admin' ? `
            <button class="nav-link nav-action" type="button" data-open-admin-verification>Verifikasi Tutor <span class="nav-count">1</span></button>
            <button class="nav-link nav-action" type="button" data-open-admin-users>Pengguna</button>` : ''}
        </nav>
        <button class="sidebar-user-profile" type="button" aria-expanded="false" aria-controls="profile-menu" data-profile-toggle>Akun ⌄</button>
    </aside>`;

const topbar = (placeholder) => `
    <header class="topbar">
        <button type="button" data-sidebar-toggle aria-expanded="false">Menu</button>
        <strong data-page-heading>Dashboard</strong>
        <label class="search-box"><span class="sr-only" data-search-label>${placeholder}</span><input type="search" placeholder="${placeholder}" data-dashboard-search data-default-placeholder="${placeholder}"></label>
        <div class="dropdown-wrap">
            <button type="button" aria-expanded="false" data-notification-toggle>Notifikasi</button>
            <div data-notification-menu hidden>
                <a class="notification-item" href="#sessions" data-notification-open data-notification-id="7">Sesi diperbarui</a>
                <button type="button" data-open-notifications>Lihat semua</button>
            </div>
        </div>
        <div class="dropdown-wrap profile-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profile-menu" data-profile-toggle>Akun</button>
            <div class="dropdown-menu profile-menu" id="profile-menu" data-profile-menu hidden>
                <button type="button" data-open-profile>Edit Profil</button>
                <form method="post" action="/logout"><button type="submit">Keluar</button></form>
            </div>
        </div>
    </header>`;

const notificationsPage = `
    <section class="spa-page" data-spa-page="notifications" hidden>
        <button type="button" data-notification-filter="all">Semua</button>
        <article class="notification-center-card" data-notification-card data-category="system" data-notification-id="9"><a href="#notifications" data-notification-open data-notification-id="9">Info</a></article>
        <p data-notification-filter-empty hidden></p>
    </section>`;

const profileDialog = '<dialog data-profile-dialog><button type="button" data-role-dialog-close>×</button></dialog>';

const tutorHtml = () => `
    ${sidebar('tutor')}
    ${topbar('Cari materi atau notifikasi...')}
    <main id="main-content">
        <div class="spa-page" data-spa-page="dashboard">
            <button class="button" type="button" data-page-link="materials">Kelola materi</button>
        </div>
        <section class="spa-page" data-spa-page="materials" hidden>
            <button type="button" data-material-create-open>Tambah Materi</button>
            <input type="search" data-material-search><select data-material-grade><option value="all">Semua</option></select><select data-material-sort><option value="newest">Terbaru</option></select>
            <div data-material-grid>
                <article data-material-card data-id="1" data-subject="Matematika" data-grade="SMP" data-title="Pecahan" data-search="pecahan" data-views="0" data-date="0" data-published="1">Pecahan</article>
            </div>
            <span data-material-count></span><span data-material-result-label></span>
            <div data-material-filter-empty hidden></div><nav data-material-pagination></nav>
        </section>
        ${notificationsPage}
    </main>
    ${profileDialog}
    <div data-toast-region></div>`;

const adminHtml = () => `
    ${sidebar('admin')}
    ${topbar('Cari notifikasi...')}
    <main id="main-content">
        <div class="spa-page" data-spa-page="dashboard">
            <button class="button" type="button" data-open-admin-verification>Periksa pengajuan</button>
        </div>
        ${notificationsPage}
    </main>
    ${profileDialog}
    <dialog data-admin-verification-dialog><button type="button" data-role-dialog-close>×</button></dialog>
    <dialog data-admin-users-dialog><button type="button" data-role-dialog-close>×</button></dialog>
    <div data-toast-region></div>`;

// Siswa: halaman Materi dengan panel "Mata Pelajaranku" (kartu memakai data-levels, bukan data-level).
const studentHtml = () => `
    ${sidebar('student')}
    ${topbar('Cari jadwal atau tutor...')}
    <main id="main-content">
        <div class="spa-page" data-spa-page="dashboard"></div>
        <section class="spa-page" data-spa-page="sessions" data-session-config data-can-manage-sessions="1" hidden>
            <button type="button" data-session-filter="all">Semua Sesi</button>
            <button type="button" data-session-filter="upcoming">Sesi Mendatang</button>
            <h2 data-session-heading>Semua Sesi</h2><p data-session-description></p><strong data-session-count></strong>
            <div data-session-list><div data-session-empty></div><div data-session-filter-empty hidden></div></div>
        </section>
        <section class="spa-page" data-spa-page="materials" hidden>
            <section data-integrated-subjects data-search-url="/subjects/catalog">
                <input type="search" data-subject-search>
                <article data-subject-card data-subject-id="1" data-selected="0" data-search="matematika" data-category="eksakta" data-levels="sd|smp">Matematika</article>
                <div data-subject-empty hidden>Tidak ada mata pelajaran yang cocok</div>
            </section>
            <div data-materials-panel></div>
        </section>
        ${notificationsPage}
    </main>
    ${profileDialog}
    <div data-toast-region></div>`;

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const loadDashboard = async (html, { hash = '' } = {}) => {
    window.history.replaceState(null, '', `/dashboard${hash}`);
    document.body.className = 'dashboard-body';
    document.body.innerHTML = html;
    vi.resetModules();
    await import('../../resources/js/dashboard.js');
};

const visiblePage = () => document.querySelector('[data-spa-page]:not([hidden])')?.dataset.spaPage;
const profileMenu = () => document.querySelector('[data-profile-menu]');
let desktop = false;

describe('dashboard.js per role (ROADMAP 0.1)', () => {
    let consoleError;

    beforeEach(() => {
        HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
        HTMLDialogElement.prototype.close = function close() { this.open = false; };
        // jsdom belum punya innerText (browser punya); pencarian notifikasi/sesi memakainya.
        if (!('innerText' in HTMLElement.prototype)) {
            Object.defineProperty(HTMLElement.prototype, 'innerText', { configurable: true, get() { return this.textContent; } });
        }
        desktop = false;
        window.matchMedia = (query) => ({ matches: query.includes('min-width: 961px') ? desktop : false, addEventListener() {}, removeEventListener() {} });
        window.scrollTo = () => {};
        Element.prototype.scrollTo = () => {};
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ ids: [1], unread_count: 0, message: 'OK' }) }));
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleError.mockRestore();
        vi.restoreAllMocks();
    });

    describe('DOM Tutor', () => {
        it('dimuat tanpa error walau katalog, sesi, tugas, dan riwayat tidak dirender', async () => {
            await loadDashboard(tutorHtml());

            expect(consoleError).not.toHaveBeenCalled();
            expect(visiblePage()).toBe('dashboard');
            expect(document.querySelector('[data-page-heading]').textContent).toBe('Beranda');
        });

        it('hash ke halaman yang tidak ada di menu tutor (#sessions, #history) dialihkan ke Beranda', async () => {
            for (const hash of ['#sessions', '#history', '#tutors', '#assignments', '#profile']) {
                await loadDashboard(tutorHtml(), { hash });
                expect(visiblePage()).toBe('dashboard');
            }
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('menu sidebar dan tombol beranda membuka Materi; judul diambil dari menu', async () => {
            await loadDashboard(tutorHtml());

            document.querySelector('[data-spa-page="dashboard"] [data-page-link="materials"]').click();
            expect(visiblePage()).toBe('materials');
            expect(document.querySelector('[data-page-heading]').textContent).toBe('Materi Pembelajaran');
            expect(document.querySelector('.nav-link[data-page-link="materials"]').classList.contains('is-current')).toBe(true);
            // Tombol di beranda bukan item menu, jadi tidak ikut ditandai aktif.
            expect(document.querySelector('[data-spa-page="dashboard"] [data-page-link]').hasAttribute('aria-current')).toBe(false);

            document.querySelector('.nav-link[data-page-link="dashboard"]').click();
            expect(visiblePage()).toBe('dashboard');
            expect(document.querySelector('[data-dashboard-search]').placeholder).toBe('Cari materi atau notifikasi...');
        });

        it('mengetik di kotak cari topbar tidak memicu error di halaman mana pun', async () => {
            await loadDashboard(tutorHtml());
            const search = document.querySelector('[data-dashboard-search]');

            for (const page of ['dashboard', 'materials', 'notifications']) {
                document.querySelector(`.nav-link[data-page-link="${page}"]`).click();
                search.value = 'pecahan';
                search.dispatchEvent(new Event('input', { bubbles: true }));
            }
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('notifikasi yang menautkan ke #sessions membuka halaman Notifikasi, bukan halaman kosong', async () => {
            await loadDashboard(tutorHtml());

            document.querySelector('.notification-item[data-notification-open]').click();
            await flush();
            expect(visiblePage()).toBe('notifications');
        });
    });

    describe('DOM Admin', () => {
        it('dimuat tanpa error walau hanya Beranda dan Notifikasi yang dirender', async () => {
            await loadDashboard(adminHtml(), { hash: '#materials' });

            expect(consoleError).not.toHaveBeenCalled();
            expect(visiblePage()).toBe('dashboard');
        });

        it('Verifikasi Tutor dan Pengguna di sidebar membuka dialognya; tombol beranda juga', async () => {
            await loadDashboard(adminHtml());
            const verification = document.querySelector('[data-admin-verification-dialog]');
            const users = document.querySelector('[data-admin-users-dialog]');

            document.querySelector('.sidebar [data-open-admin-verification]').click();
            expect(verification.open).toBe(true);
            verification.close();

            document.querySelector('[data-spa-page="dashboard"] [data-open-admin-verification]').click();
            expect(verification.open).toBe(true);
            verification.close();

            document.querySelector('.sidebar [data-open-admin-users]').click();
            expect(users.open).toBe(true);
            expect(consoleError).not.toHaveBeenCalled();
        });

        it('notifikasi yang menautkan ke halaman non-admin membuka Notifikasi', async () => {
            await loadDashboard(adminHtml());

            document.querySelector('.notification-item[data-notification-open]').click();
            await flush();
            expect(visiblePage()).toBe('notifications');
        });
    });

    describe('Tombol ⌄ di sidebar', () => {
        it('di layar lebar menu menempel di atas tombol ⌄, lalu kembali ke kanan atas saat dibuka dari topbar', async () => {
            desktop = true;
            await loadDashboard(tutorHtml());
            const sidebarButton = document.querySelector('.sidebar-user-profile');
            sidebarButton.getBoundingClientRect = () => ({ left: 24, top: 700, right: 260, bottom: 760, width: 236, height: 60 });

            sidebarButton.click();
            expect(profileMenu().hidden).toBe(false);
            expect(profileMenu().classList.contains('is-sidebar-anchored')).toBe(true);
            expect(document.body.classList.contains('profile-menu-anchored')).toBe(true);
            expect(profileMenu().style.getPropertyValue('--profile-menu-left')).toBe('24px');
            expect(profileMenu().style.getPropertyValue('--profile-menu-bottom')).toBe(`${window.innerHeight - 700 + 10}px`);
            expect(sidebarButton.getAttribute('aria-expanded')).toBe('true');
            expect(profileMenu().querySelector('form[action="/logout"]')).not.toBeNull();

            document.querySelector('.profile-trigger').click();
            expect(profileMenu().hidden).toBe(false);
            expect(profileMenu().classList.contains('is-sidebar-anchored')).toBe(false);
            expect(profileMenu().style.getPropertyValue('--profile-menu-left')).toBe('');
            expect(sidebarButton.getAttribute('aria-expanded')).toBe('false');
            expect(document.querySelector('.profile-trigger').getAttribute('aria-expanded')).toBe('true');

            document.querySelector('.profile-trigger').click();
            expect(profileMenu().hidden).toBe(true);
        });

        it('resize di layar lebar memindah menu mengikuti tombol ⌄ (tidak menutupnya)', async () => {
            desktop = true;
            await loadDashboard(tutorHtml());
            const sidebarButton = document.querySelector('.sidebar-user-profile');
            let top = 700;
            sidebarButton.getBoundingClientRect = () => ({ left: 24, top, right: 260, bottom: top + 60, width: 236, height: 60 });

            sidebarButton.click();
            top = 600;
            window.dispatchEvent(new Event('resize'));
            expect(profileMenu().hidden).toBe(false);
            expect(profileMenu().classList.contains('is-sidebar-anchored')).toBe(true);
            expect(profileMenu().style.getPropertyValue('--profile-menu-bottom')).toBe(`${window.innerHeight - 600 + 10}px`);
        });

        it('klik di luar, Esc, atau layar menyempit menutup menu dan melepas posisi sidebar', async () => {
            desktop = true;
            await loadDashboard(adminHtml());
            const sidebarButton = document.querySelector('.sidebar-user-profile');

            sidebarButton.click();
            document.body.click();
            expect(profileMenu().hidden).toBe(true);
            expect(profileMenu().classList.contains('is-sidebar-anchored')).toBe(false);
            expect(document.body.classList.contains('profile-menu-anchored')).toBe(false);

            sidebarButton.click();
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
            expect(profileMenu().hidden).toBe(true);
            expect(profileMenu().classList.contains('is-sidebar-anchored')).toBe(false);

            sidebarButton.click();
            desktop = false;
            window.dispatchEvent(new Event('resize'));
            expect(profileMenu().hidden).toBe(true);
            expect(document.body.classList.contains('profile-menu-anchored')).toBe(false);
        });

        it('di layar sempit (sidebar = laci) menu tetap muncul di kanan atas dan laci ditutup', async () => {
            desktop = false;
            await loadDashboard(tutorHtml());
            document.querySelector('#main-sidebar').classList.add('is-open');

            document.querySelector('.sidebar-user-profile').click();
            expect(profileMenu().hidden).toBe(false);
            expect(profileMenu().classList.contains('is-sidebar-anchored')).toBe(false);
            expect(document.querySelector('#main-sidebar').classList.contains('is-open')).toBe(false);
        });
    });

    describe('DOM Siswa', () => {
        it('tab "Semua" berjudul "Semua Sesi"; tab Mendatang tetap "Sesi Mendatang"', async () => {
            await loadDashboard(studentHtml(), { hash: '#sessions' });
            const heading = document.querySelector('[data-session-heading]');

            expect(heading.textContent).toBe('Semua Sesi');
            document.querySelector('[data-session-filter="upcoming"]').click();
            expect(heading.textContent).toBe('Sesi Mendatang');
            document.querySelector('[data-session-filter="all"]').click();
            expect(heading.textContent).toBe('Semua Sesi');
            expect(document.querySelector('[data-session-description]').textContent).toContain('mendatang, selesai, dan dibatalkan');
        });

        it('mengetik di kotak cari topbar tidak error dan tidak memunculkan pesan "mapel tidak cocok" yang keliru', async () => {
            await loadDashboard(studentHtml());
            await flush();
            const search = document.querySelector('[data-dashboard-search]');

            search.value = 'jadwal';
            search.dispatchEvent(new Event('input', { bubbles: true }));
            expect(consoleError).not.toHaveBeenCalled();
            expect(document.querySelector('[data-subject-empty]').hidden).toBe(true);
        });

        it('placeholder kotak cari di Beranda tidak tertimpa oleh modul Mata Pelajaranku', async () => {
            await loadDashboard(studentHtml());
            await flush();

            expect(document.querySelector('[data-dashboard-search]').placeholder).toBe('Cari jadwal atau tutor...');
            document.querySelector('.nav-link[data-page-link="materials"]').click();
            expect(document.querySelector('[data-dashboard-search]').placeholder).toBe('Cari materi, mata pelajaran, atau topik...');
        });
    });
});
