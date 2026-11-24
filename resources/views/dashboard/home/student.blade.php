{{-- Beranda Siswa (isi lama dari dashboard/index.blade.php, dipindah apa adanya). --}}
    <div class="welcome-banner animate-in">
        <div class="welcome-copy">
            <span class="eyebrow"><span class="eyebrow-dot"></span> {{ $today_label }}</span>
            <h1>Selamat datang kembali, <span>{{ $user['name'] }}</span>!</h1>
            <p>Terus melangkah, satu sesi belajar hari ini bisa membawa kamu lebih dekat ke cita-cita.</p>
            <a class="button button-white" href="#jadwal">Lihat jadwal belajar <x-icon name="arrow-right" class="icon-sm" /></a>
        </div>
        <div class="welcome-art" aria-hidden="true" style="--welcome-image: url('{{ asset('assets/images/tutorkita-hero-reference.jpg') }}')">
            <span class="art-orbit orbit-one"></span><span class="art-orbit orbit-two"></span>
            <div class="art-book"><x-icon name="book-open" /></div>
            <div class="art-note note-one"><x-icon name="sparkle" /></div>
            <div class="art-note note-two"><span>{{ $material_progress_percent }}%</span><small>progress</small></div>
        </div>
    </div>

    @if (($student_verification['status'] ?? 'verified') !== 'verified')
        {{-- ROADMAP 5.1: ajakan melengkapi profil siswa; pengajuan sesi terkunci sampai terverifikasi. --}}
        <section class="panel role-home-panel student-verification-banner" aria-labelledby="student-verification-home" data-student-verification-banner>
            <div class="panel-heading">
                <div><span class="section-kicker">VERIFIKASI SISWA</span><h2 id="student-verification-home">Lengkapi profil untuk mengajukan sesi</h2><p>Status: <span class="verification-badge is-{{ $student_verification['status'] }}">{{ $student_verification['label'] }}</span></p></div>
                <button class="button button-primary" type="button" data-page-link="student-verification">Profil &amp; Verifikasi</button>
            </div>
            @if ($student_verification['status'] === 'rejected')
                <p class="verification-alert is-rejected" role="status"><strong>Ditolak admin.</strong> Alasan: {{ $student_verification['rejection_reason'] }}</p>
            @elseif ($student_verification['status'] === 'pending')
                <p class="verification-alert is-pending" role="status">Datamu sedang diperiksa admin.</p>
            @else
                <p class="verification-note">Materi, Mata Pelajaranku, dan tugas tetap bisa kamu pakai sekarang. Pengajuan sesi dibuka setelah admin memverifikasi profil dan dokumen pendukungmu.</p>
            @endif
        </section>
    @endif

    <section class="stats-grid" aria-label="Ringkasan belajar">
        @foreach ($stats as $stat)
            <x-dashboard.stat-card :stat="$stat" />
        @endforeach
    </section>

    <div class="content-grid">
        <section class="panel schedule-panel" id="jadwal" aria-labelledby="schedule-heading">
            <div class="panel-heading">
                <div><span class="section-kicker">RUTINITAS BELAJAR</span><h2 id="schedule-heading">Jadwal belajar</h2><p>Atur waktumu, raih lebih banyak hal.</p></div>
                <a class="text-link" href="#jadwal-list">Lihat semua <x-icon name="arrow-right" class="icon-sm" /></a>
            </div>
            <div class="tabs" role="tablist" aria-label="Filter jadwal">
                <button class="tab-button is-active" type="button" role="tab" aria-selected="true" data-schedule-filter="all">Semua <span>{{ count($schedules) }}</span></button>
                <button class="tab-button" type="button" role="tab" aria-selected="false" data-schedule-filter="today">Hari ini <span>{{ $today_schedule_count }}</span></button>
                <button class="tab-button" type="button" role="tab" aria-selected="false" data-schedule-filter="upcoming">Mendatang <span>{{ $upcoming_schedule_count }}</span></button>
            </div>
            <div class="schedule-list" id="jadwal-list">
                @foreach ($schedules as $index => $schedule)
                    <x-dashboard.schedule-item :schedule="$schedule" :index="$index" />
                @endforeach
                <p class="empty-state" data-schedule-empty hidden>Tidak ada jadwal pada pilihan ini.</p>
            </div>
        </section>

        <section class="panel weekly-panel" id="progress" aria-labelledby="weekly-heading">
            <div class="panel-heading compact-heading"><div><span class="section-kicker">KONSISTENSI</span><h2 id="weekly-heading">Progress belajar</h2></div><button class="icon-button quiet-button" type="button" aria-label="Informasi progress" data-toast="Durasi dihitung dari sesi belajar yang sudah selesai minggu ini."><x-icon name="info" /></button></div>
            <div class="progress-summary"><div><strong>{{ $progress['weekly_hours'] }} <small>jam</small></strong><span>Minggu ini</span></div><span class="trend-pill"><x-icon name="trend" class="icon-sm" /> {{ $progress['weekly_session_count'] }} sesi</span></div>
            <div class="weekly-chart" role="img" aria-label="Grafik jam belajar dari Senin sampai Minggu">
                @foreach ($progress['weekly'] as $day)
                    <div class="chart-column"><div class="chart-track"><span class="chart-bar" style="--bar-height: {{ $day['value'] }}%"></span></div><span class="chart-day">{{ $day['day'] }}</span></div>
                @endforeach
            </div>
            <div class="chart-legend"><span><i></i> Durasi sesi selesai</span><span>{{ $progress['weekly_session_count'] }} sesi minggu ini</span></div>
            <div class="subject-progress" id="mata-pelajaran">
                <div class="subsection-title"><h3>Progress mata pelajaran</h3><button class="text-link small-link" type="button" data-toast="Progress dihitung dari materi yang sudah selesai.">Detail</button></div>
                @forelse ($progress['subjects'] as $subject)
                    <div class="subject-progress-row"><div class="subject-progress-label"><span>{{ $subject['name'] }}</span><strong>{{ $subject['value'] }}%</strong></div><div class="progress-track"><span class="progress-fill fill-{{ $subject['color'] }}" style="width: {{ $subject['value'] }}%"></span></div></div>
                @empty
                    <p class="empty-state">Progress mata pelajaran akan muncul setelah kamu menyelesaikan materi.</p>
                @endforelse
            </div>
        </section>
    </div>

    {{-- Tutor asli (ROADMAP 3.1): maks. 3 tutor terverifikasi & aktif, diurutkan slot kosong terdekat. --}}
    <section class="panel tutors-section" id="tutor" aria-labelledby="tutor-heading">
        <div class="panel-heading">
            <div><span class="section-kicker">TUTOR RELAWAN</span><h2 id="tutor-heading">Kenali tutor relawan kami</h2><p>Tutor terverifikasi yang siap membantumu belajar.</p></div>
            <button class="text-link" type="button" data-page-link="tutors">Lihat katalog <x-icon name="arrow-right" class="icon-sm" /></button>
        </div>
        <div class="tutor-grid home-tutor-grid" data-home-tutors>
            @forelse ($tutors as $tutor)
                <article class="home-tutor-card" data-home-tutor="{{ $tutor['id'] }}">
                    <span class="home-tutor-avatar" aria-hidden="true">@if ($tutor['photo_url'])<img src="{{ $tutor['photo_url'] }}" alt="" loading="lazy">@else{{ $tutor['initials'] }}@endif</span>
                    <div class="home-tutor-copy">
                        <h3>{{ $tutor['name'] }}</h3>
                        <p>{{ $tutor['occupation'] ?: $tutor['institution'] }}</p>
                        <div class="catalog-tags">@foreach (array_slice($tutor['subjects'], 0, 3) as $subject)<span>{{ $subject }}</span>@endforeach</div>
                        <small>{{ $tutor['slots'] ? 'Slot terdekat: '.$tutor['slots'][0]['label'] : 'Belum ada slot kosong' }}</small>
                    </div>
                    <button class="button button-outline" type="button" data-catalog-open="{{ $tutor['id'] }}">Lihat profil <x-icon name="arrow-right" class="icon-sm" /></button>
                </article>
            @empty
                <p class="empty-state" data-home-tutors-empty>Belum ada tutor yang tersedia saat ini. Tutor relawan sedang menyiapkan jadwal — cek lagi nanti.</p>
            @endforelse
        </div>
    </section>

    <section class="closing-banner" aria-label="Ajakan belajar"><div class="closing-icon"><x-icon name="sparkle" /></div><div><h2>Setiap langkah kecil berarti.</h2><p>Konsisten belajar hari ini, lebih siap menyambut masa depan.</p></div><a class="button button-white" href="#jadwal">Mulai belajar <x-icon name="arrow-right" class="icon-sm" /></a></section>
    <footer class="dashboard-footer"><span>© {{ date('Y') }} TutorKita</span><span>Bimbingan belajar gratis untuk semua.</span><a href="#dashboard-top">Kembali ke atas ↑</a></footer>
