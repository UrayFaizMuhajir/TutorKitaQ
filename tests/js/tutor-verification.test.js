import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Daftar sebagai tutor + verifikasi tutor (ROADMAP 1.2). Struktur DOM mengikuti Blade.
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const registerHtml = (checked = 'student') => `
    <div data-auth-feedback hidden></div>
    <form class="auth-form" data-auth-form="register" novalidate>
        <input name="name" data-auth-field><input name="contact" data-auth-field>
        <input name="password" type="password" data-auth-field><input name="password_confirmation" type="password" data-auth-field>
        <fieldset data-account-type>
            <label><input type="radio" name="account_type" value="student" ${checked === 'student' ? 'checked' : ''}> Saya ingin belajar</label>
            <label><input type="radio" name="account_type" value="tutor" ${checked === 'tutor' ? 'checked' : ''}> Saya ingin mengajar</label>
            <p data-tutor-hint ${checked === 'tutor' ? '' : 'hidden'}>Akun tutor perlu diverifikasi admin.</p>
        </fieldset>
        <input type="checkbox" name="terms" data-auth-field>
        <button type="submit">Daftar Sekarang</button>
    </form>`;

const shell = (items, main) => `
    <aside class="sidebar" id="main-sidebar">
        <nav class="sidebar-nav" data-sidebar-nav data-spa-nav>
            ${items.map(([page, title]) => `<button class="nav-link" type="button" data-page-link="${page}" data-page-title="${title}">${title}</button>`).join('')}
        </nav>
        <button class="sidebar-user-profile" type="button" aria-expanded="false" data-profile-toggle>Akun</button>
    </aside>
    <header class="topbar">
        <strong data-page-heading>Dashboard</strong>
        <input type="search" data-dashboard-search data-default-placeholder="Cari notifikasi...">
        <div class="dropdown-wrap"><button type="button" data-notification-toggle>N</button><div data-notification-menu hidden></div></div>
        <div class="dropdown-wrap profile-wrap"><button class="profile-trigger" type="button" data-profile-toggle>Akun</button>
            <div class="dropdown-menu profile-menu" id="profile-menu" data-profile-menu hidden><button type="button" data-page-link="verification">Profil &amp; Verifikasi</button></div></div>
    </header>
    <main id="main-content">${main}</main>
    <div data-toast-region></div>`;

const unverifiedTutorHtml = () => shell(
    [['dashboard', 'Beranda'], ['verification', 'Profil & Verifikasi'], ['notifications', 'Notifikasi']],
    `<div class="spa-page" data-spa-page="dashboard"><button class="button" type="button" data-page-link="verification">Buka Profil &amp; Verifikasi</button></div>
     <section class="spa-page" data-spa-page="verification" data-tutor-verification hidden>
        <form class="role-form" data-verification-card-form>
            <input type="file" name="student_card" data-student-card-input>
            <p data-student-card-error hidden></p>
            <button type="submit">Simpan Kartu</button>
        </form>
     </section>
     <section class="spa-page" data-spa-page="notifications" hidden></section>`,
);

const adminHtml = () => shell(
    [['dashboard', 'Beranda'], ['tutor-verifications', 'Verifikasi Tutor'], ['notifications', 'Notifikasi']],
    `<div class="spa-page" data-spa-page="dashboard"><button class="button" type="button" data-page-link="tutor-verifications">Periksa pengajuan</button></div>
     <section class="spa-page" data-spa-page="tutor-verifications" data-admin-verifications hidden>
        <button type="button" class="" data-verification-filter="all">Semua</button>
        <button type="button" class="is-active" data-verification-filter="pending">Menunggu</button>
        <button type="button" class="" data-verification-filter="approved">Disetujui</button>
        <button type="button" class="" data-verification-filter="rejected">Ditolak</button>
        <article data-verification-item data-status="pending">A</article>
        <article data-verification-item data-status="pending">B</article>
        <article data-verification-item data-status="rejected" hidden>C</article>
        <p data-verification-empty hidden>Tidak ada pengajuan pada status ini.</p>
     </section>
     <section class="spa-page" data-spa-page="notifications" hidden></section>`,
);

const load = async (html, script, hash = '') => {
    window.history.replaceState(null, '', `/dashboard${hash}`);
    document.body.className = script === 'dashboard' ? 'dashboard-body' : '';
    document.body.innerHTML = html;
    vi.resetModules();
    await import(`../../resources/js/${script}.js`);
};

const visiblePage = () => document.querySelector('[data-spa-page]:not([hidden])')?.dataset.spaPage;
const visibleItems = () => [...document.querySelectorAll('[data-verification-item]')].filter((item) => !item.hidden).map((item) => item.textContent);

const chooseFile = (input, file) => {
    Object.defineProperty(input, 'files', { configurable: true, value: [file] });
    input.dispatchEvent(new Event('change', { bubbles: true }));
};

describe('Daftar sebagai Siswa/Tutor', () => {
    it('default Siswa tanpa catatan; memilih Tutor memunculkan catatan verifikasi', async () => {
        await load(registerHtml(), 'auth');
        const hint = document.querySelector('[data-tutor-hint]');
        const tutor = document.querySelector('input[value="tutor"]');
        const student = document.querySelector('input[value="student"]');

        expect(student.checked).toBe(true);
        expect(hint.hidden).toBe(true);

        tutor.checked = true;
        tutor.dispatchEvent(new Event('change', { bubbles: true }));
        expect(hint.hidden).toBe(false);

        student.checked = true;
        student.dispatchEvent(new Event('change', { bubbles: true }));
        expect(hint.hidden).toBe(true);
    });

    it('pilihan Tutor yang dikembalikan server (old input) langsung menampilkan catatan', async () => {
        await load(registerHtml('tutor'), 'auth');
        expect(document.querySelector('[data-tutor-hint]').hidden).toBe(false);
    });
});

describe('Dashboard verifikasi tutor', () => {
    let consoleError;

    beforeEach(() => {
        HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
        HTMLDialogElement.prototype.close = function close() { this.open = false; };
        window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        window.scrollTo = () => {};
        Element.prototype.scrollTo = () => {};
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({}) }));
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleError.mockRestore();
        vi.restoreAllMocks();
    });

    it('tutor belum terverifikasi: dimuat tanpa error, #materials dialihkan ke Beranda, menu membuka Profil & Verifikasi', async () => {
        for (const hash of ['#materials', '#assignments', '#tutor-verifications']) {
            await load(unverifiedTutorHtml(), 'dashboard', hash);
            expect(visiblePage()).toBe('dashboard');
        }
        document.querySelector('.nav-link[data-page-link="verification"]').click();
        expect(visiblePage()).toBe('verification');
        expect(document.querySelector('[data-page-heading]').textContent).toBe('Profil & Verifikasi');

        document.querySelector('.nav-link[data-page-link="dashboard"]').click();
        document.querySelector('[data-spa-page="dashboard"] [data-page-link="verification"]').click();
        expect(visiblePage()).toBe('verification');
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('kartu mahasiswa: tipe selain JPG/PNG/PDF dan file >2 MB ditolak di browser', async () => {
        await load(unverifiedTutorHtml(), 'dashboard', '#verification');
        const input = document.querySelector('[data-student-card-input]');
        const error = document.querySelector('[data-student-card-error]');

        chooseFile(input, new File(['GIF89a'], 'kartu.gif', { type: 'image/gif' }));
        expect(error.hidden).toBe(false);
        expect(error.textContent).toContain('JPG, PNG, atau PDF');

        chooseFile(input, new File([new Uint8Array(2 * 1024 * 1024 + 1)], 'kartu.pdf', { type: 'application/pdf' }));
        expect(error.hidden).toBe(false);
        expect(error.textContent).toContain('maksimal 2 MB');

        chooseFile(input, new File(['%PDF-1.4'], 'kartu.pdf', { type: 'application/pdf' }));
        expect(error.hidden).toBe(true);
        chooseFile(input, new File(['png'], 'kartu.png', { type: 'image/png' }));
        expect(error.hidden).toBe(true);
    });

    it('admin: halaman Verifikasi Tutor memfilter pengajuan per status', async () => {
        await load(adminHtml(), 'dashboard', '#tutor-verifications');
        expect(visiblePage()).toBe('tutor-verifications');
        expect(visibleItems()).toEqual(['A', 'B']);

        document.querySelector('[data-verification-filter="all"]').click();
        expect(visibleItems()).toEqual(['A', 'B', 'C']);
        expect(document.querySelector('[data-verification-filter="all"]').getAttribute('aria-selected')).toBe('true');

        document.querySelector('[data-verification-filter="rejected"]').click();
        expect(visibleItems()).toEqual(['C']);
        expect(document.querySelector('[data-verification-empty]').hidden).toBe(true);

        document.querySelector('[data-verification-filter="approved"]').click();
        expect(visibleItems()).toEqual([]);
        expect(document.querySelector('[data-verification-empty]').hidden).toBe(false);
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('admin: tombol beranda membuka halaman Verifikasi Tutor', async () => {
        await load(adminHtml(), 'dashboard');
        await flush();
        document.querySelector('[data-spa-page="dashboard"] [data-page-link="tutor-verifications"]').click();
        expect(visiblePage()).toBe('tutor-verifications');
        expect(document.querySelector('.nav-link[data-page-link="tutor-verifications"]').classList.contains('is-current')).toBe(true);
    });
});
