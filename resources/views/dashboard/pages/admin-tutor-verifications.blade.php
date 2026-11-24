{{-- Halaman Verifikasi Tutor milik Admin: tab Pengajuan (ROADMAP 1.2) dan Tutor Terverifikasi (ROADMAP 1.3). --}}
@php
    $applicationStatusLabels = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];
    $reviewActionLabels = ['submitted' => 'Dikirim', 'resubmitted' => 'Dikirim ulang', 'approve' => 'Disetujui', 'reject' => 'Ditolak'];
    $statusActionLabels = ['deactivate' => 'Dinonaktifkan', 'activate' => 'Diaktifkan kembali'];
    $adminTab = request()->query('admin_tab') === 'tutors' ? 'tutors' : 'applications';
@endphp
<section class="dashboard-page spa-page verification-page" data-spa-page="tutor-verifications" data-admin-verifications hidden>
    <div class="verification-heading"><div><span class="section-kicker">PANEL ADMIN</span><h1>Verifikasi <em>Tutor</em></h1><p>Periksa pengajuan calon tutor, lalu kelola status tutor yang sudah terverifikasi.</p></div></div>

    <nav class="admin-tabs" role="tablist" aria-label="Bagian verifikasi tutor" data-admin-tabs>
        <button type="button" role="tab" class="{{ $adminTab === 'applications' ? 'is-active' : '' }}" aria-selected="{{ $adminTab === 'applications' ? 'true' : 'false' }}" data-admin-tab="applications">Pengajuan <span>{{ $admin_tutor_application_counts['pending'] }}</span></button>
        <button type="button" role="tab" class="{{ $adminTab === 'tutors' ? 'is-active' : '' }}" aria-selected="{{ $adminTab === 'tutors' ? 'true' : 'false' }}" data-admin-tab="tutors">Tutor Terverifikasi <span>{{ $admin_verified_tutor_counts['all'] }}</span></button>
    </nav>

    {{-- Tab Pengajuan --}}
    <div class="admin-tab-panel" data-admin-tab-panel="applications" @if ($adminTab !== 'applications') hidden @endif>
        <div class="admin-list" data-admin-list data-default-filter="pending">
            @include('dashboard.partials.admin-list-tools', [
                'searchLabel' => 'Cari pengajuan tutor',
                'searchPlaceholder' => 'Cari nama, kontak, atau kampus...',
                'filters' => ['all' => 'Semua', 'pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'],
                'counts' => $admin_tutor_application_counts,
                'activeFilter' => 'pending',
            ])
            @foreach ($admin_tutor_applications as $application)
                <article class="panel tutor-review-card verification-application" data-admin-item data-status="{{ $application->status }}" data-search="{{ \Illuminate\Support\Str::lower($application->candidate_name.' '.$application->candidate_contact.' '.$application->institution.' '.$application->candidate_occupation) }}" @if ($application->status !== 'pending') hidden @endif>
                    <div class="tutor-review-head">
                        <img src="{{ $application->candidate_photo_url }}" alt="Foto {{ $application->candidate_name }}" loading="lazy">
                        <div>
                            <h3>{{ $application->candidate_name }}</h3>
                            @if ($application->candidate_occupation)<p class="verification-occupation">{{ $application->candidate_occupation }}</p>@endif
                            <p>{{ $application->candidate_contact }}</p>
                            <small>{{ $application->origin_label }} · diajukan {{ $application->submitted_at?->locale('id')->isoFormat('D MMMM YYYY, HH:mm') ?? '—' }}</small>
                        </div>
                        <span class="verification-badge is-{{ \App\Support\TutorVerification::statusFromApplication($application->status) }}">{{ $applicationStatusLabels[$application->status] ?? $application->status }}</span>
                    </div>
                    <dl>
                        <dt>Pendidikan</dt><dd>{{ $application->education_level }} · {{ $application->institution }}</dd>
                        <dt>Program studi</dt><dd>{{ $application->program }} · lulus {{ $application->graduation_year }}</dd>
                        <dt>Mata pelajaran</dt><dd>{{ $application->subjects->pluck('name')->join(', ') ?: '—' }}</dd>
                        <dt>Biografi</dt><dd>{{ $application->bio }}</dd>
                        <dt>Pengalaman</dt><dd>{{ $application->experience ?: 'Belum dicantumkan' }}</dd>
                        @if ($application->additional_info)<dt>Informasi pendukung</dt><dd>{{ $application->additional_info }}</dd>@endif
                        @if ($application->status === 'rejected')<dt>Alasan penolakan</dt><dd>{{ $application->rejection_reason }}</dd>@endif
                        <dt>Kartu mahasiswa</dt>
                        <dd>@include('dashboard.partials.document-preview', ['url' => $application->card_url, 'isPdf' => $application->card_is_pdf, 'alt' => 'Kartu mahasiswa '.$application->candidate_name])</dd>
                    </dl>
                    @if ($application->reviews->isNotEmpty())
                        <details class="verification-history"><summary>Riwayat keputusan ({{ $application->reviews->count() }})</summary><ul>@foreach ($application->reviews as $review)<li><strong>{{ $reviewActionLabels[$review->action] ?? $review->action }}</strong> · {{ $review->created_at?->locale('id')->isoFormat('D MMM YYYY, HH:mm') }}@if ($review->reason) — {{ $review->reason }}@endif</li>@endforeach</ul></details>
                    @endif
                    @if ($application->status === 'pending')
                        @include('dashboard.partials.decision-forms', ['action' => route('dashboard.admin.tutor-applications.decide', $application), 'idSuffix' => 'tutor-'.$application->id])
                    @endif
                </article>
            @endforeach
            <p class="role-state verification-empty" data-admin-empty @if ($admin_tutor_application_counts['pending'] > 0) hidden @endif>Tidak ada pengajuan yang cocok.</p>
        </div>
    </div>

    {{-- Tab Tutor Terverifikasi (ROADMAP 1.3) --}}
    <div class="admin-tab-panel" data-admin-tab-panel="tutors" @if ($adminTab !== 'tutors') hidden @endif>
        <div class="admin-list" data-admin-list data-default-filter="all">
            @include('dashboard.partials.admin-list-tools', [
                'searchLabel' => 'Cari tutor terverifikasi',
                'searchPlaceholder' => 'Cari nama, kontak, atau kampus...',
                'filters' => ['all' => 'Semua', 'active' => 'Aktif', 'inactive' => 'Nonaktif'],
                'counts' => $admin_verified_tutor_counts,
                'activeFilter' => 'all',
            ])
            @foreach ($admin_verified_tutors as $tutor)
                @php
                    $tutorStatus = $tutor->is_active ? 'active' : 'inactive';
                    $lastChange = $tutor->latest_status_change;
                @endphp
                <article class="panel tutor-review-card verification-application" data-admin-item data-status="{{ $tutorStatus }}" data-search="{{ \Illuminate\Support\Str::lower($tutor->account_name.' '.$tutor->account_contact.' '.$tutor->institution.' '.$tutor->account_occupation) }}">
                    <div class="tutor-review-head">
                        <img src="{{ $tutor->photo_url }}" alt="Foto {{ $tutor->account_name }}" loading="lazy">
                        <div>
                            <h3>{{ $tutor->account_name }}</h3>
                            @if ($tutor->account_occupation)<p class="verification-occupation">{{ $tutor->account_occupation }}</p>@endif
                            <p>{{ $tutor->account_contact }}</p>
                        </div>
                        <span class="verification-badge {{ $tutor->is_active ? 'is-verified' : 'is-rejected' }}">{{ $tutor->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                    </div>
                    <dl>
                        <dt>Kampus</dt><dd>{{ $tutor->institution ?: '—' }}</dd>
                        <dt>Program studi</dt><dd>{{ $tutor->program ?: '—' }}</dd>
                        <dt>Mata pelajaran</dt><dd>{{ $tutor->subjects->pluck('name')->join(', ') ?: '—' }}</dd>
                        <dt>Perubahan status terakhir</dt><dd>@if ($lastChange){{ $statusActionLabels[$lastChange->action] ?? $lastChange->action }} · {{ $lastChange->created_at?->locale('id')->isoFormat('D MMM YYYY, HH:mm') }} — {{ $lastChange->reason }}@else Belum pernah diubah @endif</dd>
                    </dl>
                    <div class="tutor-review-actions">
                        <form method="post" action="{{ route('dashboard.admin.tutors.status', $tutor->user_id) }}" data-tutor-status-form>
                            @csrf
                            <input type="hidden" name="action" value="{{ $tutor->is_active ? 'deactivate' : 'activate' }}">
                            <label class="sr-only" for="tutor-status-reason-{{ $tutor->user_id }}">Alasan</label>
                            @if ($tutor->is_active && $tutor->impact_sessions > 0)
                                <p class="verification-alert is-rejected tutor-impact-warning" data-tutor-impact>Tutor ini masih punya {{ $tutor->impact_sessions }} sesi mendatang dengan {{ $tutor->impact_students }} siswa. Mereka akan mendapat notifikasi; sesinya tidak otomatis dibatalkan.</p>
                                <label class="tutor-impact-confirm"><input type="checkbox" name="confirm_impact" value="1" required> Saya paham</label>
                            @endif
                            <input id="tutor-status-reason-{{ $tutor->user_id }}" name="reason" placeholder="{{ $tutor->is_active ? 'Alasan menonaktifkan (wajib)' : 'Alasan mengaktifkan kembali (wajib)' }}" minlength="5" maxlength="2000" required>
                            <button class="button {{ $tutor->is_active ? 'button-outline' : 'button-primary' }}" type="submit">{{ $tutor->is_active ? 'Nonaktifkan' : 'Aktifkan kembali' }}</button>
                        </form>
                    </div>
                </article>
            @endforeach
            <p class="role-state verification-empty" data-admin-empty @if ($admin_verified_tutor_counts['all'] > 0) hidden @endif>Tidak ada tutor yang cocok.</p>
        </div>
    </div>
</section>
