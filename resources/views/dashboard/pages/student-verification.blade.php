{{-- Halaman "Profil & Verifikasi" siswa (ROADMAP 5.1). PRIVASI: data ini hanya untuk siswa ini & Admin, tidak untuk tutor. --}}
@php
    $studentState = $student_verification;
    $studentProfile = $studentState['profile'];
    $documentKinds = \App\Models\StudentProfile::DOCUMENT_KINDS;
@endphp
<section class="dashboard-page spa-page verification-page" data-spa-page="student-verification" data-student-verification hidden>
    <div class="verification-heading"><div><span class="section-kicker">VERIFIKASI SISWA</span><h1>Profil &amp; <em>Verifikasi</em></h1><p>Lengkapi profil siswa dan dokumen pendukung. Setelah admin memverifikasi, kamu bisa mengajukan sesi belajar. Data ini hanya bisa dilihat olehmu dan admin.</p></div><span class="verification-badge is-{{ $studentState['status'] }}">{{ $studentState['label'] }}</span></div>

    @if ($errors->any())<p class="verification-alert is-rejected" role="alert">{{ $errors->first() }}</p>@endif
    @if ($studentState['status'] === 'rejected')
        <p class="verification-alert is-rejected" role="status"><strong>Ditolak admin.</strong> Alasan: {{ $studentState['rejection_reason'] }} Perbaiki data di bawah, lalu kirim ulang.</p>
    @elseif ($studentState['status'] === 'pending')
        <p class="verification-alert is-pending" role="status">Data sedang diperiksa admin, jadi belum bisa diubah. Kamu akan mendapat notifikasi setelah ada keputusan.</p>
    @elseif ($studentState['status'] === 'verified')
        <p class="verification-alert is-verified" role="status">Profilmu sudah terverifikasi. Kamu bisa mengajukan sesi belajar. Profil masih boleh diperbarui, tetapi dokumen pendukung terkunci.</p>
    @endif

    <div class="verification-grid">
        <section class="panel verification-card" aria-labelledby="student-profile-title">
            <h2 id="student-profile-title"><span class="verification-step-mark {{ $studentState['profile_complete'] ? 'is-done' : '' }}" aria-hidden="true">{{ $studentState['profile_complete'] ? '✓' : '1' }}</span> Profil siswa</h2>
            <form method="post" action="{{ route('dashboard.student-verification.profile') }}" class="role-form" data-student-profile-form>
                @csrf
                <fieldset class="verification-fieldset" @disabled($studentState['profile_locked'])>
                    <label>Nama sekolah<input name="school" maxlength="180" value="{{ old('school', $studentProfile?->school) }}" required></label>
                    <div class="role-form-two">
                        <label>Jenjang<select name="grade_level_id" required><option value="">Pilih jenjang</option>@foreach ($subject_grade_levels as $gradeLevel)<option value="{{ $gradeLevel->id }}" @selected((string) old('grade_level_id', $studentProfile?->grade_level_id) === (string) $gradeLevel->id)>{{ $gradeLevel->name }}</option>@endforeach</select></label>
                        <label>Kelas<input name="class_name" maxlength="20" placeholder="Contoh: 8B" value="{{ old('class_name', $studentProfile?->class_name) }}" required></label>
                    </div>
                    <label>Nama wali (orang tua/wali)<input name="guardian_name" maxlength="120" value="{{ old('guardian_name', $studentProfile?->guardian_name) }}" required></label>
                    <label>Kontak wali (email atau nomor telepon)<input name="guardian_contact" maxlength="60" value="{{ old('guardian_contact', $studentProfile?->guardian_contact) }}" required></label>
                    <label>Kecamatan / Kota<input name="region" maxlength="100" placeholder="Contoh: Kec. Sukajadi, Bandung" value="{{ old('region', $studentProfile?->region) }}" required><small>Cukup kecamatan dan kota. Jangan tulis alamat lengkap rumah.</small></label>
                    <div class="role-dialog-actions"><button type="submit" class="button button-primary">Simpan Profil</button></div>
                </fieldset>
            </form>
        </section>

        <div class="verification-side">
            <section class="panel verification-card" aria-labelledby="student-document-title">
                <h2 id="student-document-title"><span class="verification-step-mark {{ $studentState['document_uploaded'] ? 'is-done' : '' }}" aria-hidden="true">{{ $studentState['document_uploaded'] ? '✓' : '2' }}</span> Dokumen pendukung</h2>
                @if ($studentState['document_uploaded'])
                    <p class="verification-note">{{ $documentKinds[$studentProfile->document_kind] ?? 'Dokumen' }}</p>
                    @include('dashboard.partials.document-preview', ['url' => route('dashboard.student-verification.document.show'), 'isPdf' => $studentState['document_is_pdf'], 'alt' => 'Dokumen pendukung milikmu'])
                @else
                    <p class="verification-note">Belum ada dokumen. Unggah kartu pelajar atau surat keterangan dari sekolah.</p>
                @endif
                @unless ($studentState['document_locked'])
                    <form method="post" action="{{ route('dashboard.student-verification.document.store') }}" enctype="multipart/form-data" class="role-form" data-student-document-form>
                        @csrf
                        <label>Jenis dokumen<select name="document_kind" required>@foreach ($documentKinds as $kindKey => $kindLabel)<option value="{{ $kindKey }}" @selected(old('document_kind', $studentProfile?->document_kind) === $kindKey)>{{ $kindLabel }}</option>@endforeach</select></label>
                        <label>{{ $studentState['document_uploaded'] ? 'Ganti dokumen' : 'Unggah dokumen' }}<input type="file" name="student_document" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required data-student-card-input data-document-label="Dokumen pendukung"><small>JPG, PNG, atau PDF · maks. 2 MB · disimpan privat.</small></label>
                        <p class="verification-inline-error" data-student-card-error role="alert" hidden></p>
                        <div class="role-dialog-actions"><button type="submit" class="button button-primary">Simpan Dokumen</button></div>
                    </form>
                @endunless
            </section>

            <section class="panel verification-card" aria-labelledby="student-submit-title">
                <h2 id="student-submit-title"><span class="verification-step-mark {{ $studentState['submitted'] ? 'is-done' : '' }}" aria-hidden="true">{{ $studentState['submitted'] ? '✓' : '3' }}</span> Kirim untuk verifikasi</h2>
                @if ($studentState['submitted'])
                    <p class="verification-note">Terkirim{{ $studentProfile?->submitted_at ? ' pada '.$studentProfile->submitted_at->locale('id')->isoFormat('D MMMM YYYY, HH:mm') : '' }}.</p>
                @else
                    @if (! $studentState['can_submit'])
                        <p class="verification-note">Masih kurang: {{ implode(', ', array_merge($studentState['missing'], $studentState['document_uploaded'] ? [] : ['Dokumen pendukung'])) }}.</p>
                    @endif
                    <form method="post" action="{{ route('dashboard.student-verification.submit') }}">
                        @csrf
                        <button type="submit" class="button button-primary" @disabled(! $studentState['can_submit'])>{{ $studentState['status'] === 'rejected' ? 'Kirim Ulang untuk Verifikasi' : 'Kirim untuk Verifikasi' }}</button>
                    </form>
                @endif
            </section>
        </div>
    </div>
</section>
