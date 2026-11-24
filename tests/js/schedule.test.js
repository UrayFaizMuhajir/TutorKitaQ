import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Jadwal Saya (ROADMAP 2.2): pratinjau slot mingguan dari server + cookie zona waktu (tk_tz).
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const scheduleHtml = () => `
    <aside class="sidebar" id="main-sidebar"><nav data-sidebar-nav data-spa-nav>
        <button class="nav-link" type="button" data-page-link="dashboard" data-page-title="Beranda">Beranda</button>
        <button class="nav-link" type="button" data-page-link="schedule" data-page-title="Jadwal Saya">Jadwal Saya</button>
    </nav><button class="sidebar-user-profile" type="button" data-profile-toggle>Akun</button></aside>
    <header class="topbar"><strong data-page-heading></strong><input type="search" data-dashboard-search>
        <div class="dropdown-wrap"><button type="button" data-notification-toggle>N</button><div data-notification-menu hidden></div></div>
        <div class="dropdown-wrap"><button class="profile-trigger" type="button" data-profile-toggle>Akun</button><div id="profile-menu" data-profile-menu hidden></div></div>
    </header>
    <main id="main-content">
        <div class="spa-page" data-spa-page="dashboard"></div>
        <section class="spa-page" data-spa-page="schedule" hidden>
            <form data-slot-form data-preview-url="/dashboard/tutor/slots/preview">
                <input type="hidden" name="timezone" value="Asia/Jakarta" data-user-timezone>
                <input name="date" value="2099-01-07"><input name="start_time" value="15:00"><input name="end_time" value="16:00">
                <label><input type="checkbox" name="repeat_weekly" value="1" data-slot-repeat></label>
                <label data-slot-repeat-until hidden><input name="repeat_until"></label>
                <div data-slot-preview hidden></div>
                <button type="button" data-slot-preview-button>Pratinjau</button>
            </form>
        </section>
    </main>
    <div data-toast-region></div>`;

describe('Jadwal Saya', () => {
    let consoleError;

    beforeEach(() => {
        window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        window.scrollTo = () => {};
        Element.prototype.scrollTo = () => {};
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => { consoleError.mockRestore(); vi.restoreAllMocks(); });

    it('pratinjau menampilkan tanggal yang dibuat (hijau) dan yang bentrok (merah) dari server', async () => {
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({
            create_count: 1,
            occurrences: [
                { label: 'Rabu, 7 Jan · 15.00–16.00 WIB', ok: true, reason: null },
                { label: 'Rabu, 14 Jan · 15.00–16.00 WIB', ok: false, reason: 'Bentrok dengan slot Rabu, 14 Jan · 15.30–16.30 WIB' },
            ],
        }) }));
        window.history.replaceState(null, '', '/dashboard#schedule');
        document.body.className = 'dashboard-body';
        document.body.innerHTML = scheduleHtml();
        vi.resetModules();
        await import('../../resources/js/dashboard.js');

        const repeat = document.querySelector('[data-slot-repeat]');
        repeat.checked = true;
        repeat.dispatchEvent(new Event('change'));
        expect(document.querySelector('[data-slot-repeat-until]').hidden).toBe(false);

        document.querySelector('[data-slot-preview-button]').click();
        await flush(); await flush();
        expect(globalThis.fetch).toHaveBeenCalledWith('/dashboard/tutor/slots/preview', expect.objectContaining({ method: 'POST' }));
        const rows = [...document.querySelectorAll('.slot-preview-list li')];
        expect(rows.map((row) => row.className)).toEqual(['is-ok', 'is-conflict']);
        expect(rows[1].textContent).toContain('Bentrok dengan slot');
        expect(document.querySelector('[data-slot-preview]').textContent).toContain('1 slot akan dibuat, 1 dilewati');
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('menulis cookie tk_tz dari Intl dan mengisi field timezone tersembunyi', async () => {
        vi.spyOn(Intl, 'DateTimeFormat').mockReturnValue({ resolvedOptions: () => ({ timeZone: 'Asia/Makassar' }) });
        document.cookie = 'tk_tz=Asia%2FMakassar; path=/';
        document.body.innerHTML = '<input type="hidden" name="timezone" value="Asia/Jakarta" data-user-timezone>';
        vi.resetModules();
        await import('../../resources/js/timezone.js');
        expect(document.cookie).toContain('tk_tz=Asia%2FMakassar');
        expect(document.querySelector('[data-user-timezone]').value).toBe('Asia/Makassar');
    });
});
