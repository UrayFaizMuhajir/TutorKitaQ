import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Form pengajuan berbasis slot (ROADMAP 3.2): sinkron tutor → slot → mapel → jenjang, peringatan bentrok merah,
// "Pilih slot ini" dari katalog, dan pilihan slot untuk ganti jadwal (TT-25).
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

const html = () => `
    <aside class="sidebar" id="main-sidebar"><nav data-sidebar-nav data-spa-nav>
        <button class="nav-link" type="button" data-page-link="dashboard" data-page-title="Beranda">Beranda</button>
        <button class="nav-link" type="button" data-page-link="sessions" data-page-title="Sesi Belajar">Sesi</button>
    </nav><button class="sidebar-user-profile" type="button" data-profile-toggle>Akun</button></aside>
    <header class="topbar"><strong data-page-heading></strong><input type="search" data-dashboard-search>
        <div class="dropdown-wrap"><button type="button" data-notification-toggle>N</button><div data-notification-menu hidden></div></div>
        <div class="dropdown-wrap"><button class="profile-trigger" type="button" data-profile-toggle>Akun</button><div id="profile-menu" data-profile-menu hidden></div></div>
    </header>
    <main id="main-content">
        <div class="spa-page" data-spa-page="dashboard"></div>
        <section class="spa-page" data-spa-page="sessions" data-session-config data-can-manage-sessions="1" data-reschedule-url="/dashboard/sessions/__ID__/reschedule" hidden>
            <h2 data-session-heading></h2><p data-session-description></p><strong data-session-count></strong>
            <div data-session-list>
                <article data-session-card data-session-status="pending" data-session-tutor="Alya" data-session-detail-url="/dashboard/sessions/1"><button type="button" data-session-detail>Detail</button></article>
                <div data-session-empty hidden></div><div data-session-filter-empty hidden></div>
            </div>
            <dialog data-session-create-dialog>
                <form data-session-create-form action="/dashboard/sessions" data-subject-levels='{"Matematika":["SD","SMP"],"IPA":["SMP","SMA/SMK"]}'
                      data-active-intervals='[{"id":1,"start":1000,"end":4600,"label":"Matematika, Rabu 15.00–16.00 WIB"}]'>
                    <select name="tutor_id" data-session-tutor-select><option value="">Pilih tutor</option><option value="5" data-subjects="Matematika|IPA">Alya</option><option value="6" data-subjects="IPA">Bima</option></select>
                    <select name="slot_id" data-session-slot-select><option value="">Pilih tutor dulu</option>
                        <option value="11" data-tutor="5" data-start="2000" data-end="5600">Slot A (bentrok)</option>
                        <option value="12" data-tutor="5" data-start="9000" data-end="12600">Slot B</option>
                        <option value="21" data-tutor="6" data-start="20000" data-end="23600">Slot C</option>
                    </select>
                    <p data-session-conflict-warning hidden></p>
                    <select name="subject" data-session-subject></select><select name="grade_level" data-session-level></select>
                    <p data-session-form-error hidden></p><button type="submit" data-session-submit>Ajukan</button>
                </form>
            </dialog>
            <dialog data-session-detail-dialog><div data-session-detail-content></div>
                <div data-session-reschedule hidden><select data-session-reschedule-select></select><p data-reschedule-conflict hidden></p><p data-reschedule-error hidden></p><button type="button" data-session-reschedule-submit>Simpan</button></div>
                <button type="button" data-session-detail-close>Tutup</button><button type="button" data-session-reschedule-open hidden>Ganti jadwal</button><button type="button" data-session-cancel hidden>Batal</button>
            </dialog>
        </section>
    </main>
    <div data-toast-region></div>`;

const values = (selector) => [...document.querySelectorAll(`${selector} option`)].filter((option) => !option.hidden).map((option) => option.value);

describe('Form pengajuan sesi berbasis slot', () => {
    let consoleError;

    beforeEach(async () => {
        HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
        HTMLDialogElement.prototype.close = function close() { this.open = false; };
        if (!('innerText' in HTMLElement.prototype)) Object.defineProperty(HTMLElement.prototype, 'innerText', { configurable: true, get() { return this.textContent; } });
        window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        window.scrollTo = () => {};
        Element.prototype.scrollTo = () => {};
        globalThis.fetch = vi.fn(async () => ({ ok: true, json: async () => ({ session: {
            id: 1, tutor_id: '5', slot_id: 11, tutor_name: 'Alya', subject: 'Matematika', topic: 'Aljabar', grade_level: 'SMP',
            starts_at: '2099-01-01T08:00:00+00:00', date_label: 'Kamis', time_label: '15.00–16.00 WIB', meeting_platform: 'Google Meet',
            status: 'pending', conflicts: ['IPA, Kamis 15.30 WIB'], can_reschedule: true,
        } }) }));
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        window.history.replaceState(null, '', '/dashboard#sessions');
        document.body.className = 'dashboard-body';
        document.body.innerHTML = html();
        vi.resetModules();
        await import('../../resources/js/dashboard.js');
    });

    afterEach(() => { consoleError.mockRestore(); vi.restoreAllMocks(); });

    it('memilih tutor menyaring slot & mapel, mapel menentukan jenjang, slot bentrok diberi peringatan merah', () => {
        const tutor = document.querySelector('[data-session-tutor-select]');
        tutor.value = '5'; tutor.dispatchEvent(new Event('change'));
        expect(values('[data-session-slot-select]')).toEqual(['', '11', '12']);
        expect(values('[data-session-subject]')).toEqual(['Matematika', 'IPA']);
        expect(values('[data-session-level]')).toEqual(['SD', 'SMP']);
        const subject = document.querySelector('[data-session-subject]');
        subject.value = 'IPA'; subject.dispatchEvent(new Event('change'));
        expect(values('[data-session-level]')).toEqual(['SMP', 'SMA/SMK']);

        const slot = document.querySelector('[data-session-slot-select]');
        const warning = document.querySelector('[data-session-conflict-warning]');
        slot.value = '11'; slot.dispatchEvent(new Event('change'));
        expect(warning.hidden).toBe(false);
        expect(warning.textContent).toContain('Bentrok dengan sesi Matematika, Rabu 15.00–16.00 WIB');
        expect(document.querySelector('[data-session-submit]').disabled).toBe(false); // tetap boleh lanjut
        slot.value = '12'; slot.dispatchEvent(new Event('change'));
        expect(warning.hidden).toBe(true);
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('event "Pilih slot ini" membuka form dengan tutor dan slot terpilih', () => {
        const event = new CustomEvent('tutorkita:pick-slot', { cancelable: true, detail: { slotId: '21', tutorId: '6' } });
        document.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        expect(document.querySelector('[data-session-create-dialog]').open).toBe(true);
        expect(document.querySelector('[data-session-tutor-select]').value).toBe('6');
        expect(document.querySelector('[data-session-slot-select]').value).toBe('21');
        expect(values('[data-session-subject]')).toEqual(['IPA']);
    });

    it('detail sesi menampilkan bentrok dan "Ganti jadwal" hanya menawarkan slot lain dari tutor yang sama', async () => {
        document.querySelector('[data-session-detail]').click();
        await flush(); await flush();
        expect(document.querySelector('[data-session-detail-content]').textContent).toContain('Bentrok dengan sesi IPA');
        const open = document.querySelector('[data-session-reschedule-open]');
        expect(open.hidden).toBe(false);
        open.click();
        expect(document.querySelector('[data-session-reschedule]').hidden).toBe(false);
        expect([...document.querySelectorAll('[data-session-reschedule-select] option')].map((option) => option.value)).toEqual(['12']);
    });
});
