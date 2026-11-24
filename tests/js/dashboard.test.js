import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Kerangka minimal halaman dashboard. Urutan elemen sengaja mengikuti Blade asli:
// kartu sesi (dengan data-session-tutor) berada SEBELUM dialog pengajuan sesi.
const dashboardHtml = ({ withSession = true, student = true } = {}) => `
    <aside class="sidebar" id="main-sidebar">
        <nav data-spa-nav>
            <button type="button" data-page-link="dashboard">Beranda</button>
            <button type="button" data-page-link="sessions">Sesi Belajar</button>
        </nav>
        <button class="sidebar-user-profile" type="button" aria-expanded="false" aria-controls="profile-menu" data-profile-toggle>gudti ⌄</button>
    </aside>
    <header class="topbar">
        <button type="button" data-sidebar-toggle aria-expanded="false">Menu</button>
        <strong data-page-heading>Dashboard</strong>
        <input type="search" data-dashboard-search>
        <div class="dropdown-wrap">
            <button type="button" aria-expanded="false" data-notification-toggle>Notifikasi</button>
            <div data-notification-menu hidden><button type="button" data-mark-read>Tandai dibaca</button></div>
        </div>
        <div class="dropdown-wrap profile-wrap">
            <button class="profile-trigger" type="button" aria-expanded="false" aria-controls="profile-menu" data-profile-toggle>gudti</button>
            <div id="profile-menu" data-profile-menu hidden>
                <form method="post" action="/logout"><button type="submit">Keluar</button></form>
            </div>
        </div>
    </header>
    <main id="main-content">
        <div class="spa-page" data-spa-page="dashboard"></div>
        <section class="spa-page" data-spa-page="sessions" data-session-config data-can-manage-sessions="${student ? '1' : '0'}" hidden>
            <button type="button" data-session-filter="all">Semua Sesi</button>
            ${student ? '<button type="button" data-session-dialog-open>Ajukan Sesi Baru</button>' : ''}
            <h2 data-session-heading></h2><p data-session-description></p><strong data-session-count></strong>
            <div class="session-list" data-session-list>
                ${withSession ? `
                <article class="session-card" data-session-card data-session-status="pending" data-session-start="2099-11-25T14:12:00+07:00" data-session-tutor="Alya Putri" data-session-detail-url="/dashboard/sessions/1" data-session-cancel-url="/dashboard/sessions/1/cancel">
                    <h3>Alya Putri</h3>
                    <button type="button" data-session-detail>Lihat Detail</button>
                </article>` : ''}
                <div data-session-empty ${withSession ? 'hidden' : ''}></div>
                <div data-session-filter-empty hidden></div>
            </div>
            ${student ? `
            <dialog data-session-create-dialog>
                <form data-session-create-form action="/dashboard/sessions" method="post" data-subject-levels='{"Matematika":["SD","SMP"],"IPA":["SMP"]}' data-active-intervals="[]">
                    <!-- Sejak pengajuan berbasis slot (ROADMAP 3.2): jenjang dari peta mapel, bukan atribut opsi tutor. -->
                    <select name="tutor_id" data-session-tutor-select>
                        <option value="5" data-subjects="Matematika|IPA">Alya Putri</option>
                    </select>
                    <select name="slot_id" data-session-slot-select><option value="">Pilih</option><option value="9" data-tutor="5" data-start="100" data-end="200">Slot</option></select>
                    <p data-session-conflict-warning hidden></p>
                    <select name="subject" data-session-subject></select>
                    <select name="grade_level" data-session-level></select>
                    <p data-session-form-error hidden></p>
                    <button type="submit" data-session-submit>Ajukan</button>
                </form>
            </dialog>` : ''}
            <dialog data-session-detail-dialog>
                <div data-session-detail-content></div>
                <button type="button" data-session-detail-close>Tutup</button>
                <button type="button" data-session-cancel hidden>Batalkan Sesi</button>
            </dialog>
        </section>
    </main>
    <div data-toast-region></div>
`;

const sessionJson = {
    session: {
        id: 1, tutor_name: 'Alya Putri', tutor_university: 'Universitas Indonesia', subject: 'IPA', topic: 'Uji',
        grade_level: 'SMP', starts_at: '2099-11-25T14:12:00+07:00', date_label: 'Rabu, 25 November 2099',
        time_label: '14:12 – 15:12', duration_minutes: 60, meeting_platform: 'Google Meet', meeting_url: null, status: 'pending',
    },
};

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const loadDashboard = async (html, prepare = () => {}) => {
    document.body.className = 'dashboard-body';
    document.body.innerHTML = html;
    prepare();
    vi.resetModules();
    await import('../../resources/js/dashboard.js');
};

const profileMenu = () => document.querySelector('[data-profile-menu]');

describe('dashboard.js', () => {
    let consoleError;

    beforeEach(() => {
        // Fitur browser yang belum ada di jsdom.
        HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
        HTMLDialogElement.prototype.close = function close() { this.open = false; };
        window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        window.scrollTo = () => {};
        Element.prototype.scrollTo = () => {};
        window.history.replaceState(null, '', '/dashboard#sessions');
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => sessionJson }));
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleError.mockRestore();
        vi.restoreAllMocks();
    });

    it('tidak error saat ada kartu sesi sebelum dialog pengajuan (regresi data-session-tutor)', async () => {
        await loadDashboard(dashboardHtml());

        expect(consoleError).not.toHaveBeenCalled();
        const subjects = [...document.querySelectorAll('[data-session-subject] option')].map((option) => option.value);
        expect(subjects).toEqual(['Matematika', 'IPA']);
        const levels = [...document.querySelectorAll('[data-session-level] option')].map((option) => option.value);
        expect(levels).toEqual(['SD', 'SMP']);
    });

    it('menu profil di kanan atas dan tombol ⌄ di sidebar sama-sama membuka menu berisi logout', async () => {
        await loadDashboard(dashboardHtml());

        document.querySelector('.profile-trigger').click();
        expect(profileMenu().hidden).toBe(false);
        expect(profileMenu().querySelector('form[action="/logout"]')).not.toBeNull();

        document.body.click();
        expect(profileMenu().hidden).toBe(true);

        document.querySelector('.sidebar-user-profile').click();
        expect(profileMenu().hidden).toBe(false);
        expect(document.querySelector('.sidebar-user-profile').getAttribute('aria-expanded')).toBe('true');
    });

    it('Lihat Detail membuka dialog detail dan menampilkan tombol Batalkan untuk sesi menunggu', async () => {
        await loadDashboard(dashboardHtml());

        document.querySelector('[data-session-detail]').click();
        await flush();
        await flush();

        expect(document.querySelector('[data-session-detail-dialog]').open).toBe(true);
        expect(globalThis.fetch).toHaveBeenCalledWith('/dashboard/sessions/1', expect.anything());
        expect(document.querySelector('[data-session-detail-content]').textContent).toContain('Alya Putri');
        expect(document.querySelector('[data-session-cancel]').hidden).toBe(false);
    });

    it('detail sesi yang ditolak tutor menampilkan label status, alasan penolakan, dan tanpa tombol Batalkan (TT-15)', async () => {
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ session: { ...sessionJson.session, status: 'rejected', status_label: 'Ditolak Tutor', rejection_reason: 'Topik di luar keahlian saya.' } }) }));
        await loadDashboard(dashboardHtml());

        document.querySelector('[data-session-detail]').click();
        await flush();
        await flush();

        const content = document.querySelector('[data-session-detail-content]').textContent;
        expect(content).toContain('Ditolak Tutor');
        expect(content).toContain('Alasan penolakan');
        expect(content).toContain('Topik di luar keahlian saya.');
        expect(document.querySelector('[data-session-cancel]').hidden).toBe(true);
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('Riwayat Sesi menampilkan kehadiran dan catatan tutor sebagai teks biasa (TT-16)', async () => {
        const completed = { ...sessionJson.session, status: 'completed', status_label: 'Selesai', attendance_label: 'Hadir', session_note: 'Bab 3 <b>PR</b>', tutor_note: 'Aktif bertanya.' };
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ session: completed }) }));
        const history = `
            <section class="spa-page" data-spa-page="history" hidden>
                <div data-history-list><article data-history-card data-id="1" data-status="completed" data-subject="IPA" data-date="1" data-search="ipa" data-detail-url="/dashboard/sessions/1"><button type="button" data-history-detail>Lihat Detail</button></article></div>
                <dialog data-history-detail-dialog><div data-history-detail-content></div><button type="button" data-history-dialog-close>Tutup</button></dialog>
            </section>`;
        await loadDashboard(dashboardHtml().replace('</main>', `${history}</main>`));

        document.querySelector('[data-history-detail]').click();
        await flush();
        await flush();

        const content = document.querySelector('[data-history-detail-content]');
        expect(content.textContent).toContain('Kehadiran');
        expect(content.textContent).toContain('Hadir');
        expect(content.textContent).toContain('Catatan untukmu: Aktif bertanya.');
        expect(content.querySelector('b')).toBeNull(); // catatan tidak dirender sebagai HTML
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('sesi dengan data aneh yang membuat render daftar sesi gagal tidak mematikan menu profil maupun Lihat Detail', async () => {
        await loadDashboard(dashboardHtml(), () => {
            Object.defineProperty(document.querySelector('[data-session-card]'), 'innerText', {
                get() { throw new Error('data sesi rusak'); },
            });
        });

        expect(consoleError).toHaveBeenCalledWith(expect.stringContaining('Gagal memuat daftar sesi'), expect.any(Error));

        document.querySelector('.profile-trigger').click();
        expect(profileMenu().hidden).toBe(false);

        document.querySelector('[data-session-detail]').click();
        await flush();
        expect(document.querySelector('[data-session-detail-dialog]').open).toBe(true);
    });

    it('tutor/admin (tanpa dialog pengajuan) tetap bisa melihat detail, tetapi tombol Batalkan disembunyikan', async () => {
        await loadDashboard(dashboardHtml({ student: false }));

        expect(consoleError).not.toHaveBeenCalled();
        expect(document.querySelector('[data-session-create-form]')).toBeNull();

        document.querySelector('.sidebar-user-profile').click();
        expect(profileMenu().hidden).toBe(false);

        document.querySelector('[data-session-detail]').click();
        await flush();
        await flush();
        expect(document.querySelector('[data-session-detail-dialog]').open).toBe(true);
        expect(document.querySelector('[data-session-detail-content]').textContent).toContain('Alya Putri');
        expect(document.querySelector('[data-session-cancel]').hidden).toBe(true);
    });

    it('tetap berfungsi tanpa sesi sama sekali', async () => {
        await loadDashboard(dashboardHtml({ withSession: false }));

        expect(consoleError).not.toHaveBeenCalled();
        document.querySelector('.profile-trigger').click();
        expect(profileMenu().hidden).toBe(false);
    });
});
