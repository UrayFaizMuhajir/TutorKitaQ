{{-- Halaman Verifikasi Siswa milik Admin (TT-10 / ROADMAP 5.2). Data siswa di sini hanya untuk Admin — tidak untuk tutor. --}}
@php
    $studentStatusLabels = ['incomplete' => 'Belum lengkap', 'pending' => 'Menunggu', 'verified' => 'Terverifikasi', 'rejected' => 'Ditolak'];
    $studentReviewLabels = ['submitted' => 'Dikirim', 'resubmitted' => 'Dikirim ulang', 'approve' => 'Disetujui', 'reject' => 'Ditolak'];
    $documentKinds = \App\Models\StudentProfile::DOCUMENT_KINDS;
@endphp
<section class="dashboard-page spa-page verification-page" data-spa-page="student-verifications" data-admin-student-verifications hidden>
    <div class="verification-heading"><div><span class="section-kicker">PANEL ADMIN</span><h1>Verifikasi <em>Siswa</em></h1><p>Periksa profil dan dokumen pendukung siswa. Siswa baru bisa mengajukan sesi setelah terverifikasi.</p></div></div>

    <div class="admin-list" data-admin-list data-default-filter="pending">
        @include('dashboard.partials.admin-list-tools', [
            'searchLabel' => 'Cari siswa',
            'searchPlaceholder' => 'Cari nama, kontak, atau sekolah...',
            'filters' => ['all' => 'Semua', 'pending' => 'Menunggu', 'incomplete' => 'Belum lengkap', 'verified' => 'Terverifikasi', 'rejected' => 'Ditolak'],
            'counts' => $admin_student_counts,
            'activeFilter' => 'pending',
        ])
        @foreach ($admin_students as $student)
            @php
                $studentProfile = $student['profile'];
            @endphp
            <article class="panel tutor-review-card verification-application" data-admin-item data-status="{{ $student['status'] }}" data-search="{{ \Illuminate\Support\Str::lower($student['name'].' '.$student['contact'].' '.($studentProfile?->school ?? '').' '.($student['occupation'] ?? '')) }}" @if ($student['status'] !== 'pending') hidden @endif>
                <div class="tutor-review-head">
                    <span class="verification-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($student['name'], 0, 1)) }}</span>
                    <div>
                        <h3>{{ $student['name'] }}</h3>
                        @if ($student['occupation'])<p class="verification-occupation">{{ $student['occupation'] }}</p>@endif
                        <p>{{ $student['contact'] }}</p>
                        <small>@if ($studentProfile?->submitted_at)Dikirim {{ $studentProfile->submitted_at->locale('id')->isoFormat('D MMMM YYYY, HH:mm') }}@else Belum mengirim data verifikasi @endif</small>
                    </div>
                    <span class="verification-badge is-{{ $student['status'] }}">{{ $studentStatusLabels[$student['status']] ?? $student['status'] }}</span>
                </div>
                @if ($studentProfile)
                    <dl>
                        <dt>Sekolah</dt><dd>{{ $studentProfile->school ?: '—' }}</dd>
                        <dt>Jenjang · kelas</dt><dd>{{ $studentProfile->gradeLevel?->name ?? '—' }} · {{ $studentProfile->class_name ?: '—' }}</dd>
                        <dt>Wali</dt><dd>{{ $studentProfile->guardian_name ?: '—' }}</dd>
                        <dt>Kontak wali</dt><dd>{{ $studentProfile->guardian_contact ?: '—' }}</dd>
                        <dt>Kecamatan / Kota</dt><dd>{{ $studentProfile->region ?: '—' }}</dd>
                        @if ($student['status'] === 'rejected')<dt>Alasan penolakan</dt><dd>{{ $studentProfile->rejection_reason }}</dd>@endif
                        <dt>Dokumen pendukung</dt>
                        <dd>@if ($studentProfile->document_kind)<span class="verification-note">{{ $documentKinds[$studentProfile->document_kind] ?? $studentProfile->document_kind }}</span>@endif
                            @include('dashboard.partials.document-preview', ['url' => $student['document_url'], 'isPdf' => $student['document_is_pdf'], 'alt' => 'Dokumen pendukung '.$student['name'], 'emptyText' => 'Belum diunggah.'])</dd>
                    </dl>
                    @if ($studentProfile->reviews->isNotEmpty())
                        <details class="verification-history"><summary>Riwayat keputusan ({{ $studentProfile->reviews->count() }})</summary><ul>@foreach ($studentProfile->reviews as $review)<li><strong>{{ $studentReviewLabels[$review->action] ?? $review->action }}</strong> · {{ $review->created_at?->locale('id')->isoFormat('D MMM YYYY, HH:mm') }}@if ($review->reason) — {{ $review->reason }}@endif</li>@endforeach</ul></details>
                    @endif
                    @if ($student['status'] === 'pending')
                        @include('dashboard.partials.decision-forms', ['action' => route('dashboard.admin.student-profiles.decide', $studentProfile), 'idSuffix' => 'student-'.$studentProfile->id])
                    @endif
                @else
                    <p class="verification-note">Siswa ini belum mengisi profil siswa.</p>
                @endif
            </article>
        @endforeach
        <p class="role-state verification-empty" data-admin-empty @if ($admin_student_counts['pending'] > 0) hidden @endif>Tidak ada siswa yang cocok.</p>
    </div>
</section>
