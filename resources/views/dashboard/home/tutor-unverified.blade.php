{{-- Beranda tutor yang belum terverifikasi (ROADMAP 1.2). Mengelola materi/tugas dibuka setelah admin menyetujui. --}}
@php
    $verification = $tutor_verification;
    $verificationSteps = [
        ['done' => $verification['profile_complete'], 'title' => 'Lengkapi profil', 'detail' => 'Kampus, jurusan/prodi, pendidikan terakhir, tahun lulus, biografi, mata pelajaran, dan foto.'],
        ['done' => $verification['card_uploaded'], 'title' => 'Unggah kartu mahasiswa', 'detail' => 'JPG, PNG, atau PDF, maksimal 2 MB. Disimpan privat, hanya kamu dan admin yang bisa membuka.'],
        ['done' => $verification['submitted'], 'title' => 'Kirim untuk verifikasi', 'detail' => 'Admin memeriksa data dan kartu mahasiswamu.'],
    ];
@endphp
<div class="welcome-banner animate-in">
    <div class="welcome-copy">
        <span class="eyebrow"><span class="eyebrow-dot"></span> {{ $today_label }}</span>
        <h1>Selamat datang, <span>{{ $user['name'] }}</span>!</h1>
        <p>Selesaikan verifikasi tutor agar kamu bisa mulai berbagi materi dengan siswa.</p>
        <button class="button button-white" type="button" data-page-link="verification">Buka Profil &amp; Verifikasi <x-icon name="arrow-right" class="icon-sm" /></button>
    </div>
    <div class="welcome-art" aria-hidden="true" style="--welcome-image: url('{{ asset('assets/images/tutorkita-hero-reference.jpg') }}')">
        <span class="art-orbit orbit-one"></span><span class="art-orbit orbit-two"></span>
        <div class="art-book"><x-icon name="graduation-cap" /></div>
        <div class="art-note note-one"><x-icon name="check" /></div>
    </div>
</div>

<section class="panel role-home-panel verification-status-panel" aria-labelledby="verification-home-heading" data-verification-status="{{ $verification['status'] }}">
    <div class="panel-heading">
        <div><span class="section-kicker">STATUS VERIFIKASI</span><h2 id="verification-home-heading">Verifikasi akun tutor</h2><p>Status: <span class="verification-badge is-{{ $verification['status'] }}">{{ $verification['label'] }}</span></p></div>
    </div>
    @if ($verification['status'] === 'rejected')
        <p class="verification-alert is-rejected" role="status"><strong>Pengajuan ditolak admin.</strong> Alasan: {{ $verification['rejection_reason'] }} Perbaiki data di halaman Profil &amp; Verifikasi, lalu kirim ulang.</p>
    @elseif ($verification['status'] === 'pending')
        <p class="verification-alert is-pending" role="status">Datamu sedang diperiksa admin. Kamu akan mendapat notifikasi setelah ada keputusan.</p>
    @endif
    <ol class="verification-steps">
        @foreach ($verificationSteps as $index => $step)
            <li class="{{ $step['done'] ? 'is-done' : '' }}">
                <span class="verification-step-mark" aria-hidden="true">{{ $step['done'] ? '✓' : $index + 1 }}</span>
                <span class="role-home-list-copy"><strong>{{ $step['title'] }}</strong><small>{{ $step['detail'] }}</small></span>
                <span class="role-home-badge {{ $step['done'] ? 'is-published' : 'is-draft' }}">{{ $step['done'] ? 'Selesai' : 'Belum' }}</span>
            </li>
        @endforeach
    </ol>
    <p class="verification-note">Sebelum terverifikasi, kamu belum bisa membuat atau mengubah materi dan tugas, dan belum tampil di katalog tutor.</p>
</section>

<footer class="dashboard-footer"><span>© {{ date('Y') }} TutorKita</span><span>Bimbingan belajar gratis untuk semua.</span><a href="#dashboard-top">Kembali ke atas ↑</a></footer>
