{{-- Katalog tutor ASLI (ROADMAP 3.1 · TT-11): tutor terverifikasi & aktif, slot kosong {{ config('tutorkita.catalog_days') }} hari.
     Rating belum ada (TT-21) → "Belum ada ulasan", tidak ada angka palsu. Jam dalam zona pengguna. --}}
@php
    $catalogCanBook = ($student_verification['status'] ?? null) === 'verified';
@endphp
<section class="dashboard-page spa-page catalog-page" data-spa-page="tutors" data-timezone-sensitive hidden>
    <div class="catalog-heading"><h1>Katalog <em>Tutor</em></h1><p>Temukan tutor relawan terverifikasi dan pilih slot kosong dalam {{ config('tutorkita.catalog_days') }} hari ke depan. Jam ditampilkan dalam zona waktumu ({{ $user_timezone_abbr }}).</p></div>
    <form class="catalog-search-row" data-catalog-search-form>
        <label class="catalog-search"><x-icon name="search"/><span class="sr-only">Cari tutor</span><input type="search" placeholder="Cari nama tutor, kampus, atau mata pelajaran..." data-catalog-search></label>
        <button class="catalog-search-submit" type="submit">Cari Tutor <x-icon name="arrow-right" class="icon-sm"/></button>
    </form>
    <div class="catalog-filters" aria-label="Filter tutor">
        <label class="catalog-filter"><span>Mata Pelajaran</span><span class="catalog-select"><x-icon name="book"/><select data-catalog-filter="subject" aria-label="Mata pelajaran"><option value="all">Semua Mata Pelajaran</option>@foreach ($subject_catalog as $subject)<option value="{{ \Illuminate\Support\Str::lower($subject->name) }}">{{ $subject->name }}</option>@endforeach</select><x-icon name="chevron-down" class="select-chevron"/></span></label>
        <label class="catalog-filter"><span>Jenjang</span><span class="catalog-select"><x-icon name="grid"/><select data-catalog-filter="level" aria-label="Jenjang"><option value="all">Semua Jenjang</option>@foreach ($subject_grade_levels as $gradeLevel)<option value="{{ $gradeLevel->name }}">{{ $gradeLevel->name }}</option>@endforeach</select><x-icon name="chevron-down" class="select-chevron"/></span></label>
        <label class="catalog-available-check catalog-filter-check"><input type="checkbox" data-catalog-filter="available"> Ada slot kosong</label>
    </div>
    <div class="catalog-results-row"><p aria-live="polite">Menampilkan <strong data-catalog-total>{{ $catalog_tutors->count() }}</strong> tutor relawan</p><label class="catalog-sort"><span>Urutkan</span><select data-catalog-sort aria-label="Urutkan tutor"><option value="nearest">Slot terdekat</option><option value="name-asc">Nama A–Z</option><option value="newest">Terbaru</option></select></label></div>
    <div class="catalog-grid" data-catalog-grid>
        @foreach ($catalog_tutors as $tutor)
            <article class="catalog-card" data-catalog-card data-tutor-id="{{ $tutor['id'] }}" data-name="{{ \Illuminate\Support\Str::lower($tutor['name']) }}" data-searchable="{{ $tutor['searchable'] }}" data-subjects="{{ \Illuminate\Support\Str::lower(implode('|', $tutor['subjects'])) }}" data-levels="{{ implode('|', $tutor['levels']) }}" data-available="{{ $tutor['slots'] ? '1' : '0' }}" data-next="{{ $tutor['next_slot_ts'] ?? '' }}" data-created="{{ $tutor['created'] }}">
                <button class="catalog-favorite" type="button" aria-label="Simpan {{ $tutor['name'] }} sebagai favorit" aria-pressed="false" data-catalog-favorite="{{ $tutor['id'] }}"><x-icon name="heart"/></button>
                <div class="catalog-tutor-main"><span class="catalog-photo" aria-hidden="true">@if ($tutor['photo_url'])<img src="{{ $tutor['photo_url'] }}" alt="" loading="lazy">@else{{ $tutor['initials'] }}@endif</span><div class="catalog-tutor-info"><h2>{{ $tutor['name'] }} <span class="catalog-verified" aria-label="Tutor terverifikasi">✓</span></h2>@if ($tutor['occupation'])<p>{{ $tutor['occupation'] }}</p>@endif<p>{{ $tutor['institution'] }}</p></div></div>
                <div class="catalog-rating-row"><span class="catalog-availability {{ $tutor['slots'] ? 'is-available' : 'is-unavailable' }}">{{ $tutor['slots'] ? count($tutor['slots']).' slot kosong' : 'Belum ada slot' }}</span><span class="catalog-rating is-empty">Belum ada ulasan</span></div>
                <div class="catalog-tags">@foreach ($tutor['subjects'] as $tag)<span>{{ $tag }}</span>@endforeach @foreach ($tutor['levels'] as $level)<span class="is-level">{{ $level }}</span>@endforeach</div>
                <button class="catalog-profile-button" type="button" data-catalog-profile="{{ $tutor['id'] }}">Lihat Profil <x-icon name="arrow-right" class="icon-sm"/></button>
                <template data-catalog-profile-template="{{ $tutor['id'] }}">
                    <div class="catalog-profile-head"><span class="catalog-photo is-large" aria-hidden="true">@if ($tutor['photo_url'])<img src="{{ $tutor['photo_url'] }}" alt="" loading="lazy">@else{{ $tutor['initials'] }}@endif</span><div><h2 id="catalog-profile-name">{{ $tutor['name'] }}</h2>@if ($tutor['occupation'])<p class="verification-occupation">{{ $tutor['occupation'] }}</p>@endif<p>{{ $tutor['institution'] }} · {{ $tutor['program'] }}</p><p class="catalog-rating is-empty">Belum ada ulasan</p></div></div>
                    @if ($tutor['bio'])<p class="catalog-profile-bio">{{ $tutor['bio'] }}</p>@endif
                    <p><strong>Mata pelajaran:</strong> {{ implode(', ', $tutor['subjects']) ?: '—' }}</p>
                    <p><strong>Jenjang:</strong> {{ implode(', ', $tutor['levels']) ?: '—' }}</p>
                    <h3>Slot kosong {{ config('tutorkita.catalog_days') }} hari ke depan</h3>
                    @if ($tutor['slots'])
                        @unless ($catalogCanBook)<p class="verification-alert is-pending">Pengajuan sesi dibuka setelah profil siswamu terverifikasi admin.</p>@endunless
                        <ul class="catalog-slot-list">@foreach ($tutor['slots'] as $slot)<li data-catalog-slot="{{ $slot['id'] }}"><span><strong>{{ $slot['label'] }}</strong><small>Terisi {{ $slot['booked'] }}/{{ $slot['capacity'] }}</small></span><button type="button" class="button button-primary" data-catalog-pick-slot="{{ $slot['id'] }}" data-tutor-id="{{ $tutor['id'] }}" @disabled(! $catalogCanBook)>Pilih slot ini</button></li>@endforeach</ul>
                    @else
                        <p class="verification-note">Tutor ini belum membuka slot kosong. Cek lagi nanti.</p>
                    @endif
                </template>
            </article>
        @endforeach
        <p class="catalog-empty" data-catalog-empty @if ($catalog_tutors->isNotEmpty()) hidden @endif>{{ $catalog_tutors->isEmpty() ? 'Belum ada tutor relawan yang tersedia saat ini. Tutor sedang menyiapkan jadwal — cek lagi nanti.' : 'Tidak ada tutor yang cocok. Coba ubah pencarian atau filter.' }}</p>
    </div>
    <dialog class="catalog-profile-dialog" data-catalog-profile-dialog aria-labelledby="catalog-profile-name"><button class="catalog-dialog-close" type="button" aria-label="Tutup" data-catalog-dialog-close>×</button><div data-catalog-profile-content></div></dialog>
</section>
