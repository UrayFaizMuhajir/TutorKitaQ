{{-- "Sesi Mengajar" tutor (ROADMAP 3.3 · TT-15): terima/tolak pengajuan PER SISWA, tautan kelas. Waktu dalam zona pengguna.
     PRIVASI (Aturan no. 11): per siswa hanya nama + data sesi (mapel, jenjang, topik). --}}
@php
    $teaching = $tutor_teaching ?? ['tabs' => [], 'counts' => []];
    $canAccept = $tutor_can_manage ?? false;
    $teachingErrors = $errors->getBag('teaching');
    $statusTones = ['pending' => 'is-pending', 'confirmed' => 'is-verified', 'in_progress' => 'is-verified', 'completed' => 'is-incomplete', 'awaiting_completion' => 'is-pending', 'cancelled' => 'is-rejected', 'rejected' => 'is-rejected', 'expired' => 'is-incomplete'];
    $tabLabels = ['pending' => 'Pengajuan', 'upcoming' => 'Mendatang', 'attendance' => 'Presensi', 'history' => 'Riwayat'];
    $attendanceLabels = \App\Models\LearningSession::ATTENDANCE_LABELS;
    $emptyText = [
        'pending' => 'Belum ada pengajuan yang perlu dijawab.',
        'upcoming' => 'Belum ada sesi yang diterima.',
        'attendance' => 'Belum ada sesi yang perlu dipresensi. Presensi dibuka setelah sesi dimulai.',
        'history' => 'Belum ada riwayat sesi.',
    ];
@endphp
<section class="dashboard-page spa-page verification-page" data-spa-page="teaching" data-tutor-teaching data-timezone-sensitive hidden>
    <div class="verification-heading"><div><span class="section-kicker">SESI MENGAJAR</span><h1>Sesi <em>Mengajar</em></h1><p>Jawab pengajuan siswa per slot. Pengajuan yang tidak dijawab sampai waktu mulai otomatis kedaluwarsa dan kursinya kembali kosong. Jam dalam zona waktumu ({{ $user_timezone_abbr }}).</p></div></div>
    @if ($teachingErrors->any())<p class="verification-alert is-rejected" role="alert" data-teaching-error>{{ $teachingErrors->first() }}</p>@endif
    @unless ($canAccept)
        <p class="verification-alert is-pending" role="status">Akunmu sedang dinonaktifkan admin. Kamu belum bisa menerima pengajuan baru, tetapi masih bisa menolak (agar siswa diberi kabar) dan mengisi tautan kelas.</p>
    @endunless

    <nav class="admin-tabs" role="tablist" aria-label="Kelompok sesi" data-admin-tabs>
        @foreach ($tabLabels as $tabKey => $tabLabel)
            <button type="button" role="tab" @class(['is-active' => $tabKey === 'pending']) aria-selected="{{ $tabKey === 'pending' ? 'true' : 'false' }}" data-admin-tab="{{ $tabKey }}">{{ $tabLabel }} <span>{{ $teaching['counts'][$tabKey] ?? 0 }}</span></button>
        @endforeach
    </nav>
    @foreach ($tabLabels as $tabKey => $tabLabel)
        @php
            $groups = $teaching['tabs'][$tabKey] ?? [];
        @endphp
        <div class="admin-tab-panel" data-admin-tab-panel="{{ $tabKey }}" @if ($tabKey !== 'pending') hidden @endif>
            <div class="admin-list" data-admin-list data-default-filter="all">
                <div class="admin-list-tools"><label class="admin-list-search"><x-icon name="search" /><span class="sr-only">Cari sesi</span><input type="search" placeholder="Cari tanggal, siswa, atau mapel..." autocomplete="off" data-admin-search></label></div>
                @foreach ($groups as $group)
                    <article @class(['panel', 'slot-card', 'is-overdue' => $group['overdue'] ?? false]) data-admin-item data-teaching-group data-status="all" data-search="{{ $group['search'] }}">
                        <div class="slot-card-head">
                            <div><h3>{{ $group['label'] }}</h3>@if ($group['slot'])<p class="slot-capacity">Terisi {{ $group['slot']->booked_count }}/{{ $group['slot']->capacity }}</p>@endif</div>
                        </div>
                        @if ($group['overdue'] ?? false)
                            <p class="verification-alert is-rejected teaching-overdue" role="alert" data-overdue-alert>Sudah lebih dari {{ config('tutorkita.completion_reminder_hours') }} jam sejak sesi berakhir. Isi presensi dan tandai selesai — siswa masih menunggu.</p>
                        @endif
                        @if ($tabKey === 'attendance' && $group['slot'])
                            <form method="post" action="{{ route('dashboard.tutor.slots.attendance', $group['slot']->id) }}" class="role-form teaching-attendance-form" data-attendance-form>@csrf
                        @endif
                        <ul class="slot-bookings teaching-list">
                            @foreach ($group['sessions'] as $row)
                                <li @class(['teaching-row', 'is-overdue' => $row['overdue']]) data-teaching-session="{{ $row['id'] }}" data-status="{{ $row['status'] }}" @if ($row['overdue']) data-overdue @endif>
                                    <div class="teaching-row-head">
                                        <div><strong>{{ $row['student_name'] }}</strong><span>{{ $row['subject'] }} · {{ $row['grade_level'] }} · {{ $row['topic'] }}</span></div>
                                        <span class="verification-badge {{ $statusTones[$row['status']] ?? 'is-pending' }}">{{ $row['status_label'] }}</span>
                                    </div>
                                    @if ($row['reason'])<p class="verification-note">Alasan: {{ $row['reason'] }}</p>@endif
                                    @if ($tabKey === 'attendance')
                                        <fieldset class="attendance-options"><legend class="sr-only">Presensi {{ $row['student_name'] }}</legend>
                                            @foreach ($attendanceLabels as $value => $label)
                                                <label><input type="radio" name="attendance[{{ $row['id'] }}]" value="{{ $value }}" @checked($row['attendance'] === $value)> {{ $label }}</label>
                                            @endforeach
                                        </fieldset>
                                        <label class="sr-only" for="tutor-note-{{ $row['id'] }}">Catatan untuk {{ $row['student_name'] }}</label>
                                        <input id="tutor-note-{{ $row['id'] }}" class="attendance-note" name="notes[{{ $row['id'] }}]" maxlength="500" value="{{ $row['tutor_note'] }}" placeholder="Catatan untuk {{ $row['student_name'] }} (opsional)">
                                    @elseif ($row['attendance_label'])
                                        <p class="verification-note">Kehadiran: <strong>{{ $row['attendance_label'] }}</strong>@if ($row['tutor_note']) · Catatan: {{ $row['tutor_note'] }}@endif</p>
                                    @endif
                                    @if ($row['meeting_url'])<p class="verification-note">Tautan kelas ({{ $row['meeting_platform'] }}): <a href="{{ $row['meeting_url'] }}" target="_blank" rel="noopener noreferrer">{{ $row['meeting_url'] }}</a></p>@endif
                                    @if ($row['can_decide'])
                                        <div class="slot-actions">
                                            @if ($canAccept)
                                                <form method="post" action="{{ route('dashboard.tutor.sessions.accept', $row['id']) }}" class="teaching-accept-form">@csrf
                                                    <label class="sr-only" for="accept-link-{{ $row['id'] }}">Tautan kelas (opsional)</label>
                                                    <input id="accept-link-{{ $row['id'] }}" type="url" name="meeting_url" maxlength="500" pattern="https://.+" placeholder="Tautan kelas https:// (opsional, bisa menyusul)">
                                                    <button type="submit" class="button button-primary">Terima</button>
                                                </form>
                                            @endif
                                            <form method="post" action="{{ route('dashboard.tutor.sessions.reject', $row['id']) }}" class="slot-cancel-form">@csrf
                                                <label class="sr-only" for="reject-reason-{{ $row['id'] }}">Alasan penolakan</label>
                                                <input id="reject-reason-{{ $row['id'] }}" name="reason" minlength="5" maxlength="500" required placeholder="Alasan menolak (wajib, dikirim ke siswa)">
                                                <button type="submit" class="button button-outline">Tolak</button>
                                            </form>
                                        </div>
                                    @elseif ($row['can_set_link'])
                                        <form method="post" action="{{ route('dashboard.tutor.sessions.meeting-link', $row['id']) }}" class="teaching-accept-form">@csrf
                                            <label class="sr-only" for="meeting-link-{{ $row['id'] }}">Tautan kelas</label>
                                            <input id="meeting-link-{{ $row['id'] }}" type="url" name="meeting_url" maxlength="500" pattern="https://.+" required value="{{ $row['meeting_url'] }}" placeholder="Tautan kelas https://meet.google.com/...">
                                            <button type="submit" class="button button-outline">{{ $row['meeting_url'] ? 'Ganti tautan' : 'Simpan tautan' }}</button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($tabKey === 'attendance' && $group['slot'])
                                <label>Catatan sesi (dibaca semua siswa di slot ini, opsional)<textarea name="session_note" rows="3" maxlength="2000" placeholder="Ringkasan materi, PR, atau hal yang perlu diulang.">{{ $group['session_note'] }}</textarea></label>
                                <div class="role-dialog-actions"><button type="submit" class="button button-outline">Simpan presensi</button><button type="submit" name="complete" value="1" class="button button-primary">Simpan &amp; tandai selesai</button></div>
                                <small class="verification-note">Tandai selesai butuh presensi semua siswa. Setelah selesai, data tidak bisa diubah lagi.</small>
                            </form>
                        @elseif ($group['session_note'])
                            <p class="verification-note">Catatan sesi: {{ $group['session_note'] }}</p>
                        @endif
                    </article>
                @endforeach
                <p class="role-state verification-empty" data-admin-empty @if ($groups !== []) hidden @endif>{{ $emptyText[$tabKey] }}</p>
            </div>
        </div>
    @endforeach
</section>
