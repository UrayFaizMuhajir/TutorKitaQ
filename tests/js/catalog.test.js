import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// Katalog tutor asli (ROADMAP 3.1): filter mapel/jenjang/slot, cari, urutan, dialog dari <template>, favorit per id.
const card = (id, name, subjects, levels, available, next, created) => `
    <article class="catalog-card" data-catalog-card data-tutor-id="${id}" data-name="${name.toLowerCase()}" data-searchable="${name.toLowerCase()} ${subjects.toLowerCase()}" data-subjects="${subjects.toLowerCase()}" data-levels="${levels}" data-available="${available}" data-next="${next}" data-created="${created}">
        <button type="button" data-catalog-favorite="${id}">♥</button>
        <h2>${name}</h2>
        <button type="button" data-catalog-profile="${id}">Lihat Profil</button>
        <template data-catalog-profile-template="${id}"><h2 id="catalog-profile-name">${name}</h2>
            <ul><li><button type="button" data-catalog-pick-slot="9${id}" data-tutor-id="${id}">Pilih slot ini</button></li></ul></template>
    </article>`;

const html = () => `
    <aside class="sidebar" id="main-sidebar"><nav data-sidebar-nav data-spa-nav>
        <button class="nav-link" type="button" data-page-link="dashboard" data-page-title="Beranda">Beranda</button>
        <button class="nav-link" type="button" data-page-link="tutors" data-page-title="Katalog Tutor">Katalog Tutor</button>
    </nav><button class="sidebar-user-profile" type="button" data-profile-toggle>Akun</button></aside>
    <header class="topbar"><strong data-page-heading></strong><input type="search" data-dashboard-search>
        <div class="dropdown-wrap"><button type="button" data-notification-toggle>N</button><div data-notification-menu hidden></div></div>
        <div class="dropdown-wrap"><button class="profile-trigger" type="button" data-profile-toggle>Akun</button><div id="profile-menu" data-profile-menu hidden></div></div>
    </header>
    <main id="main-content">
        <div class="spa-page" data-spa-page="dashboard"><button type="button" data-catalog-open="2">Lihat profil</button></div>
        <section class="spa-page catalog-page" data-spa-page="tutors" hidden>
            <form data-catalog-search-form><input type="search" data-catalog-search></form>
            <select data-catalog-filter="subject"><option value="all">Semua</option><option value="matematika">Matematika</option><option value="ipa">IPA</option></select>
            <select data-catalog-filter="level"><option value="all">Semua</option><option value="SD">SD</option><option value="SMA/SMK">SMA/SMK</option></select>
            <input type="checkbox" data-catalog-filter="available">
            <strong data-catalog-total></strong>
            <select data-catalog-sort><option value="nearest">Slot terdekat</option><option value="name-asc">Nama</option></select>
            <div class="catalog-grid" data-catalog-grid>
                ${card(1, 'Zahra', 'Matematika', 'SD|SMP', '1', '2000', 0)}
                ${card(2, 'Ahmad', 'IPA', 'SMP|SMA/SMK', '1', '1000', 1)}
                ${card(3, 'Budi', 'Matematika', 'SMA/SMK', '0', '', 2)}
                <p data-catalog-empty hidden>kosong</p>
            </div>
            <dialog data-catalog-profile-dialog><button type="button" data-catalog-dialog-close>×</button><div data-catalog-profile-content></div></dialog>
        </section>
    </main>
    <div data-toast-region></div>`;

const visibleNames = () => [...document.querySelectorAll('[data-catalog-card]')].filter((item) => !item.hidden).map((item) => item.querySelector('h2').textContent);

describe('Katalog tutor asli', () => {
    let consoleError;

    beforeEach(async () => {
        HTMLDialogElement.prototype.showModal = function showModal() { this.open = true; };
        HTMLDialogElement.prototype.close = function close() { this.open = false; };
        window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
        window.scrollTo = () => {};
        Element.prototype.scrollTo = () => {};
        localStorage.clear();
        consoleError = vi.spyOn(console, 'error').mockImplementation(() => {});
        window.history.replaceState(null, '', '/dashboard#tutors');
        document.body.className = 'dashboard-body';
        document.body.innerHTML = html();
        vi.resetModules();
        await import('../../resources/js/dashboard.js');
    });

    afterEach(() => { consoleError.mockRestore(); vi.restoreAllMocks(); });

    it('urutan slot terdekat, filter mapel/jenjang/slot kosong, dan cari', () => {
        expect(visibleNames()).toEqual(['Ahmad', 'Zahra', 'Budi']);
        const subject = document.querySelector('[data-catalog-filter="subject"]');
        subject.value = 'matematika'; subject.dispatchEvent(new Event('change'));
        expect(visibleNames()).toEqual(['Zahra', 'Budi']);
        const available = document.querySelector('[data-catalog-filter="available"]');
        available.checked = true; available.dispatchEvent(new Event('change'));
        expect(visibleNames()).toEqual(['Zahra']);
        subject.value = 'all'; subject.dispatchEvent(new Event('change'));
        const level = document.querySelector('[data-catalog-filter="level"]');
        level.value = 'SMA/SMK'; level.dispatchEvent(new Event('change'));
        expect(visibleNames()).toEqual(['Ahmad']);
        level.value = 'SD'; level.dispatchEvent(new Event('change'));
        const search = document.querySelector('[data-catalog-search]');
        search.value = 'ahmad'; search.dispatchEvent(new Event('input'));
        expect(visibleNames()).toEqual([]);
        expect(document.querySelector('[data-catalog-empty]').hidden).toBe(false);
        expect(document.querySelector('[data-catalog-total]').textContent).toBe('0');
        expect(consoleError).not.toHaveBeenCalled();
    });

    it('dialog profil diisi dari template tutor; tombol beranda membuka profil tutor yang sama', () => {
        document.querySelector('[data-catalog-profile="1"]').click();
        expect(document.querySelector('[data-catalog-profile-dialog]').open).toBe(true);
        expect(document.querySelector('[data-catalog-profile-content]').textContent).toContain('Zahra');

        document.querySelector('[data-catalog-dialog-close]').click();
        document.querySelector('.nav-link[data-page-link="dashboard"]').click();
        document.querySelector('[data-catalog-open="2"]').click();
        expect(document.querySelector('[data-spa-page="tutors"]').hidden).toBe(false);
        expect(document.querySelector('[data-catalog-profile-content]').textContent).toContain('Ahmad');
    });

    it('"Pilih slot ini" dikirim sebagai event tutorkita:pick-slot berisi slot & tutor', () => {
        const received = [];
        document.addEventListener('tutorkita:pick-slot', (event) => { event.preventDefault(); received.push(event.detail); });
        document.querySelector('[data-catalog-profile="1"]').click();
        document.querySelector('[data-catalog-pick-slot="91"]').click();
        expect(received).toEqual([{ slotId: '91', tutorId: '1' }]);
        expect(document.querySelector('[data-catalog-profile-dialog]').open).toBe(false);
    });

    it('favorit disimpan per id tutor (bukan nama)', () => {
        document.querySelector('[data-catalog-favorite="2"]').click();
        expect(JSON.parse(localStorage.getItem('tutorkita-favorite-tutor-ids'))).toEqual(['2']);
        expect(document.querySelector('[data-catalog-favorite="2"]').getAttribute('aria-pressed')).toBe('true');
    });
});
