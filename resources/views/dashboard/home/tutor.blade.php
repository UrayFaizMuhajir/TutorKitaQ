{{-- Beranda Tutor. Grafik lengkap menyusul di ROADMAP 6.1; sesi & tugas menyusul di 3.3 dan 4.1. --}}
<div class="welcome-banner animate-in">
    <div class="welcome-copy">
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ $today_label }}</span>
        <h1>Selamat datang kembali, <span>{{ $user['name'] }}</span>!</h1>
        <p>Terima kasih sudah berbagi ilmu. Kelola materimu agar siswa bisa terus belajar.</p>
        <button class="button button-white" type="button" data-page-link="materials">Kelola materi <x-icon name="arrow-right" class="icon-sm" /></button>
    </div>
    <div class="welcome-art" aria-hidden="true" style="--welcome-image: url('{{ asset('assets/images/tutorkita-hero-reference.jpg') }}')">
        <span class="art-orbit orbit-one"></span><span class="art-orbit orbit-two"></span>
        <div class="art-book"><x-icon name="book-open" /></div>
        <div class="art-note note-one"><x-icon name="sparkle" /></div>
        <div class="art-note note-two"><span>{{ $tutor_home['stats'][0]['value'] }}</span><small>materi</small></div>
    </div>
</div>

@if (! ($tutor_can_manage ?? true))
    {{-- ROADMAP 1.3: tutor dinonaktifkan admin — masih bisa membaca, tidak bisa mengelola materi/tugas. --}}
    <section class="panel role-home-panel" aria-labelledby="tutor-inactive-heading" data-tutor-inactive>
        <div class="panel-heading"><div><span class="section-kicker">STATUS AKUN TUTOR</span><h2 id="tutor-inactive-heading">Akun dinonaktifkan</h2><p>Status: <span class="verification-badge is-rejected">Nonaktif</span></p></div></div>
        <p class="verification-alert is-rejected" role="status"><strong>Admin menonaktifkan akun tutormu.</strong>@if ($tutor_deactivation?->reason) Alasan: {{ $tutor_deactivation->reason }}@endif Kamu masih bisa membaca materi, tetapi belum bisa membuat atau mengubah materi dan tugas, dan tidak tampil di katalog tutor.</p>
    </section>
@endif

<section class="stats-grid" aria-label="Ringkasan mengajar">
    @foreach ($tutor_home['stats'] as $stat)
        <x-dashboard.stat-card :stat="$stat" />
    @endforeach
</section>

<section class="panel role-home-panel" aria-labelledby="tutor-materials-heading">
    <div class="panel-heading">
        <div><span class="section-kicker">MATERI MILIKMU</span><h2 id="tutor-materials-heading">Materi terbaru</h2><p>Lima materi yang terakhir kamu ubah, termasuk draf.</p></div>
        <button class="text-link" type="button" data-page-link="materials">Lihat semua <x-icon name="arrow-right" class="icon-sm" /></button>
    </div>
    <ul class="role-home-list">
        @forelse ($tutor_home['recent_materials'] as $material)
            <li>
                <span class="role-home-list-icon"><x-icon name="document" /></span>
                <span class="role-home-list-copy"><strong>{{ $material->title }}</strong><small>{{ $material->subject }} · {{ $material->grade_level }} · diubah {{ $material->updated_at?->locale('id')->diffForHumans() }}</small></span>
                <span class="role-home-badge {{ $material->is_published ? 'is-published' : 'is-draft' }}">{{ $material->is_published ? 'Terbit' : 'Draf' }}</span>
            </li>
        @empty
            <li class="role-home-empty">Belum ada materi. Tambahkan materi pertamamu dari halaman Materi Pembelajaran.</li>
        @endforelse
    </ul>
</section>

<footer class="dashboard-footer"><span>© {{ date('Y') }} TutorKita</span><span>Bimbingan belajar gratis untuk semua.</span><a href="#dashboard-top">Kembali ke atas ↑</a></footer>
