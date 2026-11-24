{{-- Kotak cari + filter status untuk daftar admin (dipakai verifikasi tutor, tutor terverifikasi, verifikasi siswa).
     Parameter: $searchLabel, $searchPlaceholder, $filters [key => label], $counts [key => angka], $activeFilter. --}}
<div class="admin-list-tools">
    <label class="admin-list-search"><x-icon name="search" /><span class="sr-only">{{ $searchLabel }}</span><input type="search" placeholder="{{ $searchPlaceholder }}" autocomplete="off" data-admin-search></label>
    <nav class="verification-filters" aria-label="Filter status" role="tablist">
        @foreach ($filters as $filterKey => $filterLabel)
            <button type="button" role="tab" class="{{ $filterKey === $activeFilter ? 'is-active' : '' }}" aria-selected="{{ $filterKey === $activeFilter ? 'true' : 'false' }}" data-admin-filter="{{ $filterKey }}">{{ $filterLabel }} <span>{{ $counts[$filterKey] ?? 0 }}</span></button>
        @endforeach
    </nav>
</div>
