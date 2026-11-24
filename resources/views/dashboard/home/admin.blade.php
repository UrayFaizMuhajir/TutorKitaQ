{{-- Beranda Admin. Kelola mapel/tutor/pengguna menyusul di ROADMAP 1.1–1.4; grafik di 6.1. --}}
<div class="welcome-banner animate-in">
    <div class="welcome-copy">
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ $today_label }}</span>
        <h1>Selamat datang, <span>{{ $user['name'] }}</span>!</h1>
        <p>Periksa pengajuan tutor dan pantau akun TutorKita dari satu tempat.</p>
        <button class="button button-white" type="button" data-open-admin-verification>Periksa pengajuan <x-icon name="arrow-right" class="icon-sm" /></button>
    </div>
    <div class="welcome-art" aria-hidden="true" style="--welcome-image: url('{{ asset('assets/images/tutorkita-hero-reference.jpg') }}')">
        <span class="art-orbit orbit-one"></span><span class="art-orbit orbit-two"></span>
        <div class="art-book"><x-icon name="users" /></div>
        <div class="art-note note-one"><x-icon name="check" /></div>
        <div class="art-note note-two"><span>{{ $admin_home['stats'][0]['value'] }}</span><small>pengajuan</small></div>
    </div>
</div>

<section class="stats-grid" aria-label="Ringkasan admin">
    @foreach ($admin_home['stats'] as $stat)
        <x-dashboard.stat-card :stat="$stat" />
    @endforeach
</section>

<section class="panel role-home-panel" aria-labelledby="admin-applications-heading">
    <div class="panel-heading">
        <div><span class="section-kicker">VERIFIKASI TUTOR</span><h2 id="admin-applications-heading">Pengajuan menunggu</h2><p>Pengajuan tutor terlama ditampilkan lebih dulu.</p></div>
        <button class="text-link" type="button" data-open-admin-verification>Periksa semua <x-icon name="arrow-right" class="icon-sm" /></button>
    </div>
    <ul class="role-home-list">
        @forelse ($admin_home['recent_applications'] as $application)
            <li>
                <span class="role-home-list-icon"><x-icon name="graduation-cap" /></span>
                <span class="role-home-list-copy"><strong>{{ $application->candidate_name }}</strong><small>{{ $application->institution }} · diajukan {{ $application->submitted_at?->locale('id')->diffForHumans() }}</small></span>
                <span class="role-home-badge is-draft">Menunggu</span>
            </li>
        @empty
            <li class="role-home-empty">Belum ada pengajuan tutor yang menunggu pemeriksaan.</li>
        @endforelse
    </ul>
</section>

<footer class="dashboard-footer"><span>© {{ date('Y') }} TutorKita</span><span>Bimbingan belajar gratis untuk semua.</span><a href="#dashboard-top">Kembali ke atas ↑</a></footer>
