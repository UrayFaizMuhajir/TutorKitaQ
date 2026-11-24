{{-- Halaman Verifikasi Tutor milik Admin (ROADMAP 1.2), menggantikan dialog verifikasi lama. --}}
@php
    $applicationStatusLabels = ['pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'];
    $reviewActionLabels = ['submitted' => 'Dikirim', 'resubmitted' => 'Dikirim ulang', 'approve' => 'Disetujui', 'reject' => 'Ditolak'];
@endphp
<section class="dashboard-page spa-page verification-page" data-spa-page="tutor-verifications" data-admin-verifications hidden>
    <div class="verification-heading"><div><span class="section-kicker">PANEL ADMIN</span><h1>Verifikasi <em>Tutor</em></h1><p>Periksa data dan kartu mahasiswa calon tutor, lalu setujui atau tolak dengan alasan.</p></div></div>

    <nav class="verification-filters" aria-label="Filter status pengajuan" role="tablist">
        @foreach (['all' => 'Semua', 'pending' => 'Menunggu', 'approved' => 'Disetujui', 'rejected' => 'Ditolak'] as $filterKey => $filterLabel)
            <button type="button" role="tab" class="{{ $filterKey === 'pending' ? 'is-active' : '' }}" aria-selected="{{ $filterKey === 'pending' ? 'true' : 'false' }}" data-verification-filter="{{ $filterKey }}">{{ $filterLabel }} <span>{{ $admin_tutor_application_counts[$filterKey] }}</span></button>
        @endforeach
    </nav>

    <div class="verification-list" data-verification-list>
        @foreach ($admin_tutor_applications as $application)
            <article class="panel tutor-review-card verification-application" data-verification-item data-status="{{ $application->status }}" @if ($application->status !== 'pending') hidden @endif>
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
                    <dd>
                        @if ($application->card_url)
                            <div class="verification-card-preview">
                                @unless ($application->card_is_pdf)<img src="{{ $application->card_url }}" alt="Kartu mahasiswa {{ $application->candidate_name }}" loading="lazy">@endunless
                                <a class="button button-outline" href="{{ $application->card_url }}" target="_blank" rel="noopener">{{ $application->card_is_pdf ? 'Buka PDF' : 'Buka gambar' }}</a>
                            </div>
                        @else
                            <span class="verification-note">Belum ada (data lama).</span>
                        @endif
                    </dd>
                </dl>
                @if ($application->reviews->isNotEmpty())
                    <details class="verification-history"><summary>Riwayat keputusan ({{ $application->reviews->count() }})</summary><ul>@foreach ($application->reviews as $review)<li><strong>{{ $reviewActionLabels[$review->action] ?? $review->action }}</strong> · {{ $review->created_at?->locale('id')->isoFormat('D MMM YYYY, HH:mm') }}@if ($review->reason) — {{ $review->reason }}@endif</li>@endforeach</ul></details>
                @endif
                @if ($application->status === 'pending')
                    <div class="tutor-review-actions">
                        <form method="post" action="{{ route('dashboard.admin.tutor-applications.decide', $application) }}">@csrf<input type="hidden" name="decision" value="approve"><button class="button button-primary" type="submit">Setujui</button></form>
                        <form method="post" action="{{ route('dashboard.admin.tutor-applications.decide', $application) }}">@csrf<input type="hidden" name="decision" value="reject"><label class="sr-only" for="reject-reason-{{ $application->id }}">Alasan penolakan</label><input id="reject-reason-{{ $application->id }}" name="reason" placeholder="Alasan penolakan (wajib)" minlength="5" maxlength="2000" required><button class="button button-outline" type="submit">Tolak</button></form>
                    </div>
                @endif
            </article>
        @endforeach
        <p class="role-state verification-empty" data-verification-empty @if ($admin_tutor_application_counts['pending'] > 0) hidden @endif>Tidak ada pengajuan pada status ini.</p>
    </div>
</section>
