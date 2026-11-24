{{-- Pratinjau dokumen privat (kartu mahasiswa / dokumen siswa). URL harus route yang mengecek Admin/pemilik.
     Parameter: $url (null = belum ada), $isPdf, $alt, $emptyText (opsional). --}}
@if ($url)
    <div class="verification-card-preview">
        @unless ($isPdf)<img src="{{ $url }}" alt="{{ $alt }}" loading="lazy">@endunless
        <a class="button button-outline" href="{{ $url }}" target="_blank" rel="noopener">{{ $isPdf ? 'Buka PDF' : 'Buka gambar' }}</a>
    </div>
@else
    <span class="verification-note">{{ $emptyText ?? 'Belum ada (data lama).' }}</span>
@endif
