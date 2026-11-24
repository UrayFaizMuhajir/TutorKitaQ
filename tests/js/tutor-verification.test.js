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
        <nav data-admin-tabs>
            <button type="button" class="is-active" data-admin-tab="applications">Pengajuan</button>
            <button type="button" data-admin-tab="tutors">Tutor Terverifikasi</button>
        </nav>
        <div data-admin-tab-panel="applications">
            <div data-admin-list data-default-filter="pending">
                <input type="search" data-admin-search>
                <button type="button" class="" data-admin-filter="all">Semua</button>
                <button type="button" class="is-active" data-admin-filter="pending">Menunggu</button>
                <button type="button" class="" data-admin-filter="approved">Disetujui</button>
                <button type="button" class="" data-admin-filter="rejected">Ditolak</button>
                <article data-admin-item data-status="pending" data-search="ayu universitas indonesia">A</article>
                <article data-admin-item data-status="pending" data-search="budi itb">B</article>
                <article data-admin-item data-status="rejected" data-search="citra ugm" hidden>C</article>
                <p data-admin-empty hidden>Tidak ada pengajuan yang cocok.</p>
            </div>
        </div>
        <div data-admin-tab-panel="tutors" hidden>
            <div data-admin-list data-default-filter="all">
                <input type="search" data-admin-search>
                <button type="button" class="is-active" data-admin-filter="all">Semua</button>
                <button type="button" data-admin-filter="active">Aktif</button>
                <button type="button" data-admin-filter="inactive">Nonaktif</button>
                <article data-admin-item data-status="active" data-search="gusti driver gojek">G</article>
                <article data-admin-item data-status="inactive" data-search="hana kampus">H</article>
                <p data-admin-empty hidden>Tidak ada tutor yang cocok.</p>
            </div>
        </div>
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
const visibleItems = (panel = 'applications') => [...document.querySelectorAll(`[data-admin-tab-panel="${panel}"] [data-admin-item]`)].filter((item) => !item.hidden).map((item) => item.textContent);

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

        const applications = document.querySelector('[data-admin-tab-panel="applications"]');
        applications.querySelector('[data-admin-filter="all"]').click();
        expect(visibleItems()).toEqual(['A', 'B', 'C']);
        expect(applications.querySelector('[data-admin-filter="all"]').getAttribute('aria-selected')).toBe('true');

        applications.querySelector('[data-admin-filter="rejected"]').click();
        expect(visibleItems()).toEqual(['C']);
        expect(applications.querySelector('[data-admin-empty]').hidden).toBe(true);

        applications.querySelector('[data-admin-filter="approved"]').click();
        expect(visibleItems()).toEqual([]);
        expect(applications.querySelector('[data-admin-empty]').hidden).toBe(false);
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('admin: kotak cari menyaring per nama/kampus dan digabung dengan filter status', async () => {
        await load(adminHtml(), 'dashboard', '#tutor-verifications');
        const applications = document.querySelector('[data-admin-tab-panel="applications"]');
        const search = applications.querySelector('[data-admin-search]');

        applications.querySelector('[data-admin-filter="all"]').click();
        search.value = 'ITB';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        expect(visibleItems()).toEqual(['B']);

        search.value = 'tidak-ada';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        expect(visibleItems()).toEqual([]);
        expect(applications.querySelector('[data-admin-empty]').hidden).toBe(false);

        search.value = '';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        applications.querySelector('[data-admin-filter="pending"]').click();
        expect(visibleItems()).toEqual(['A', 'B']);
    });

    it('admin: tab Tutor Terverifikasi dengan filter aktif/nonaktif dan cari', async () => {
        await load(adminHtml(), 'dashboard', '#tutor-verifications');
        document.querySelector('[data-admin-tab="tutors"]').click();
        const tutors = document.querySelector('[data-admin-tab-panel="tutors"]');
        expect(tutors.hidden).toBe(false);
        expect(document.querySelector('[data-admin-tab-panel="applications"]').hidden).toBe(true);
        expect(visibleItems('tutors')).toEqual(['G', 'H']);

        tutors.querySelector('[data-admin-filter="inactive"]').click();
        expect(visibleItems('tutors')).toEqual(['H']);
        tutors.querySelector('[data-admin-filter="all"]').click();
        const search = tutors.querySelector('[data-admin-search]');
        search.value = 'gojek';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        expect(visibleItems('tutors')).toEqual(['G']);

        document.querySelector('[data-admin-tab="applications"]').click();
        expect(tutors.hidden).toBe(true);
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('siswa belum terverifikasi: tombol pengajuan sesi nonaktif, tanpa dialog, dan penjelasan membuka Profil & Verifikasi', async () => {
        const studentHtml = shell(
            [['dashboard', 'Beranda'], ['sessions', 'Sesi Belajar'], ['student-verification', 'Profil & Verifikasi']],
            `<div class="spa-page" data-spa-page="dashboard"><button class="button" type="button" data-page-link="student-verification">Profil &amp; Verifikasi</button></div>
             <section class="spa-page" data-spa-page="sessions" data-session-config data-can-manage-sessions="1" hidden>
                <button type="button" data-session-filter="all">Semua Sesi</button>
                <button class="session-new-button" type="button" disabled data-session-request-locked>Ajukan Sesi Baru</button>
                <h2 data-session-heading></h2><p data-session-description></p><strong data-session-count></strong>
                <div data-session-list><div data-session-empty></div><div data-session-filter-empty hidden></div></div>
                <div data-session-verification-note><button type="button" class="button" data-page-link="student-verification">Buka Profil &amp; Verifikasi</button></div>
             </section>
             <section class="spa-page" data-spa-page="student-verification" data-student-verification hidden>
                <form class="role-form"><input type="file" data-student-card-input data-document-label="Dokumen pendukung"><p data-student-card-error hidden></p></form>
             </section>`,
        );
        await load(studentHtml, 'dashboard', '#sessions');
        expect(visiblePage()).toBe('sessions');
        expect(document.querySelector('[data-session-request-locked]').disabled).toBe(true);
        expect(document.querySelector('[data-session-create-dialog]')).toBeNull();

        document.querySelector('[data-session-verification-note] [data-page-link]').click();
        expect(visiblePage()).toBe('student-verification');
        expect(document.querySelector('[data-page-heading]').textContent).toBe('Profil & Verifikasi');

        const input = document.querySelector('[data-student-card-input]');
        chooseFile(input, new File(['GIF89a'], 'kartu.gif', { type: 'image/gif' }));
        expect(document.querySelector('[data-student-card-error]').textContent).toBe('Dokumen pendukung harus berupa JPG, PNG, atau PDF.');
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('admin: halaman Verifikasi Siswa memfilter status (termasuk Belum lengkap) dan mencari per sekolah', async () => {
        const html = shell(
            [['dashboard', 'Beranda'], ['student-verifications', 'Verifikasi Siswa']],
            `<div class="spa-page" data-spa-page="dashboard"></div>
             <section class="spa-page" data-spa-page="student-verifications" data-admin-student-verifications hidden>
                <div data-admin-list data-default-filter="pending">
                    <input type="search" data-admin-search>
                    <button type="button" data-admin-filter="all">Semua</button>
                    <button type="button" class="is-active" data-admin-filter="pending">Menunggu</button>
                    <button type="button" data-admin-filter="incomplete">Belum lengkap</button>
                    <button type="button" data-admin-filter="verified">Terverifikasi</button>
                    <article data-admin-item data-status="pending" data-search="budi smp negeri 1">Budi</article>
                    <article data-admin-item data-status="incomplete" data-search="citra" hidden>Citra</article>
                    <article data-admin-item data-status="verified" data-search="dina sd harapan" hidden>Dina</article>
                    <p data-admin-empty hidden>Tidak ada siswa yang cocok.</p>
                </div>
             </section>`,
        );
        await load(html, 'dashboard', '#student-verifications');
        const items = () => [...document.querySelectorAll('[data-spa-page="student-verifications"] [data-admin-item]')].filter((item) => !item.hidden).map((item) => item.textContent);
        expect(visiblePage()).toBe('student-verifications');
        expect(document.querySelector('[data-page-heading]').textContent).toBe('Verifikasi Siswa');
        expect(items()).toEqual(['Budi']);

        document.querySelector('[data-admin-filter="incomplete"]').click();
        expect(items()).toEqual(['Citra']);
        document.querySelector('[data-admin-filter="all"]').click();
        const search = document.querySelector('[data-admin-search]');
        search.value = 'SD Harapan';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        expect(items()).toEqual(['Dina']);
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
