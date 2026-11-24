{{-- Halaman "Profil & Verifikasi" untuk tutor yang belum terverifikasi (ROADMAP 1.2). --}}
@php
    $verification = $tutor_verification;
    $verificationProfile = $verification['profile'];
    $verificationLocked = $verification['locked'];
    $selectedSubjectIds = $verificationProfile?->subjects->modelKeys() ?? [];
    $hasProfilePhoto = (bool) ($verificationProfile?->avatar_path || $user['avatar_url']);
@endphp
<section class="dashboard-page spa-page verification-page" data-spa-page="verification" data-tutor-verification hidden>
    <div class="verification-heading"><div><span class="section-kicker">VERIFIKASI TUTOR</span><h1>Profil &amp; <em>Verifikasi</em></h1><p>Lengkapi data berikut. Admin memeriksa profil dan kartu mahasiswamu sebelum akun tutor aktif.</p></div><span class="verification-badge is-{{ $verification['status'] }}">{{ $verification['label'] }}</span></div>

    @if ($errors->any())<p class="verification-alert is-rejected" role="alert" data-verification-error>{{ $errors->first() }}</p>@endif
    @if ($verification['status'] === 'rejected')
        <p class="verification-alert is-rejected" role="status"><strong>Ditolak admin.</strong> Alasan: {{ $verification['rejection_reason'] }} Perbaiki data di bawah, lalu kirim ulang.</p>
    @elseif ($verification['status'] === 'pending')
        <p class="verification-alert is-pending" role="status">Data sedang diperiksa admin, jadi belum bisa diubah. Kamu akan mendapat notifikasi setelah ada keputusan.</p>
    @endif

    <div class="verification-grid">
        <section class="panel verification-card" aria-labelledby="verification-profile-title">
            <h2 id="verification-profile-title"><span class="verification-step-mark {{ $verification['profile_complete'] ? 'is-done' : '' }}" aria-hidden="true">{{ $verification['profile_complete'] ? '✓' : '1' }}</span> Lengkapi profil</h2>
            <form method="post" action="{{ route('dashboard.tutor-verification.profile') }}" enctype="multipart/form-data" class="role-form" data-verification-profile-form>
                @csrf
                <fieldset class="verification-fieldset" @disabled($verificationLocked)>
                    <div class="role-form-two">
                        <label>Pendidikan terakhir<select name="education_level" required><option value="">Pilih pendidikan</option>@foreach (\App\Support\TutorVerification::EDUCATION_LEVELS as $level)<option value="{{ $level }}" @selected(old('education_level', $verificationProfile?->education_level) === $level)>{{ $level }}</option>@endforeach</select></label>
                        <label>Tahun lulus<input type="number" name="graduation_year" min="1950" max="{{ now()->year + 8 }}" value="{{ old('graduation_year', $verificationProfile?->graduation_year) }}" required></label>
                    </div>
                    <label>Kampus / institusi<input name="institution" maxlength="180" value="{{ old('institution', $verificationProfile?->institution) }}" required></label>
                    <label>Jurusan / program studi<input name="program" maxlength="180" value="{{ old('program', $verificationProfile?->program) }}" required></label>
                    <fieldset><legend>Mata pelajaran yang dikuasai <small>(pilih minimal satu)</small></legend><div class="role-subject-grid">@foreach ($subject_catalog as $subject)<label><input type="checkbox" name="subject_ids[]" value="{{ $subject->id }}" @checked(in_array($subject->id, old('subject_ids', $selectedSubjectIds)))>{{ $subject->name }}</label>@endforeach</div></fieldset>
                    <label>Biografi singkat<textarea name="bio" rows="4" minlength="20" maxlength="2000" required>{{ old('bio', $verificationProfile?->bio) }}</textarea></label>
                    <label>Pengalaman mengajar (opsional)<textarea name="experience" rows="3" maxlength="4000">{{ old('experience', $verificationProfile?->experience) }}</textarea></label>
                    <label>Foto profil <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" @required(! $hasProfilePhoto) data-verification-avatar><small>JPG, PNG, atau WebP · maks. 2 MB.{{ $hasProfilePhoto ? ' Foto sudah ada; unggah lagi hanya bila ingin mengganti.' : '' }}</small></label>
                    <div class="role-dialog-actions"><button type="submit" class="button button-primary">Simpan Profil</button></div>
                </fieldset>
            </form>
        </section>

        <div class="verification-side">
            <section class="panel verification-card" aria-labelledby="verification-card-title">
                <h2 id="verification-card-title"><span class="verification-step-mark {{ $verification['card_uploaded'] ? 'is-done' : '' }}" aria-hidden="true">{{ $verification['card_uploaded'] ? '✓' : '2' }}</span> Kartu mahasiswa</h2>
                @if ($verification['card_uploaded'])
                    <div class="verification-card-preview">
                        @if (($verification['card_path'] && ! $verification['card_is_pdf']) || (! $verification['card_path'] && ! \App\Support\TutorVerification::isPdf($verification['application']?->student_card_path)))
                            <img src="{{ route('dashboard.tutor-verification.student-card.show') }}" alt="Pratinjau kartu mahasiswa milikmu" loading="lazy">
                        @endif
                        <a class="button button-outline" href="{{ route('dashboard.tutor-verification.student-card.show') }}" target="_blank" rel="noopener">Buka kartu mahasiswa</a>
                    </div>
                @else
                    <p class="verification-note">Belum ada kartu mahasiswa.</p>
                @endif
                @unless ($verificationLocked)
                    <form method="post" action="{{ route('dashboard.tutor-verification.student-card.store') }}" enctype="multipart/form-data" class="role-form" data-verification-card-form>
                        @csrf
                        <label>{{ $verification['card_uploaded'] ? 'Ganti kartu mahasiswa' : 'Unggah kartu mahasiswa' }}<input type="file" name="student_card" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required data-student-card-input><small>JPG, PNG, atau PDF · maks. 2 MB · disimpan privat.</small></label>
                        <p class="verification-inline-error" data-student-card-error role="alert" hidden></p>
                        <div class="role-dialog-actions"><button type="submit" class="button button-primary">Simpan Kartu</button></div>
                    </form>
                @endunless
            </section>

            <section class="panel verification-card" aria-labelledby="verification-submit-title">
                <h2 id="verification-submit-title"><span class="verification-step-mark {{ $verification['submitted'] ? 'is-done' : '' }}" aria-hidden="true">{{ $verification['submitted'] ? '✓' : '3' }}</span> Kirim untuk verifikasi</h2>
                @if ($verificationLocked)
                    <p class="verification-note">Terkirim{{ $verification['application']?->submitted_at ? ' pada '.$verification['application']->submitted_at->locale('id')->isoFormat('D MMMM YYYY, HH:mm') : '' }}.</p>
                @else
                    @if (! $verification['can_submit'])
                        <p class="verification-note">Masih kurang: {{ implode(', ', array_merge($verification['missing'], $verification['card_uploaded'] ? [] : ['Kartu mahasiswa'])) }}.</p>
                    @endif
                    <form method="post" action="{{ route('dashboard.tutor-verification.submit') }}" data-verification-submit-form>
                        @csrf
                        <button type="submit" class="button button-primary" @disabled(! $verification['can_submit'])>{{ $verification['status'] === 'rejected' ? 'Kirim Ulang untuk Verifikasi' : 'Kirim untuk Verifikasi' }}</button>
                    </form>
                @endif
            </section>
        </div>
    </div>
</section>
