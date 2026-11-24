@extends('layouts.auth')

@section('title', 'Daftar')

@section('intro')
    <h1 id="auth-heading">Buat Akun baru</h1>
    <p>Daftar sekarang dan mulai perjalanan belajar bersama TutorKita</p>
@endsection

@section('form')
    <form class="auth-form" method="post" action="{{ route('register') }}" data-auth-form="register" novalidate>
        @csrf
        <div class="auth-field">
            <label class="sr-only" for="register-name">Nama Lengkap</label>
            <input id="register-name" name="name" type="text" value="{{ old('name') }}" placeholder="Nama Lengkap" autocomplete="name" required data-auth-field>
        </div>
        <div class="auth-field">
            <label class="sr-only" for="register-contact">Email atau nomor telepon</label>
            <input id="register-contact" name="contact" type="text" value="{{ old('contact') }}" placeholder="Email atau nomor telepon" autocomplete="username" required data-auth-field>
        </div>
        <div class="auth-field">
            <label class="sr-only" for="register-password">Kata Sandi</label>
            <input id="register-password" name="password" type="password" placeholder="Kata Sandi" autocomplete="new-password" minlength="8" required data-auth-field>
        </div>
        <div class="auth-field">
            <label class="sr-only" for="register-password-confirmation">Konfirmasi Kata Sandi</label>
            <input id="register-password-confirmation" name="password_confirmation" type="password" placeholder="Konfirmasi Kata Sandi" autocomplete="new-password" required data-auth-field>
        </div>
        @php
            $accountType = old('account_type') === 'tutor' ? 'tutor' : 'student';
        @endphp
        {{-- Hanya Siswa atau Tutor; Admin tidak pernah tersedia lewat pendaftaran publik. --}}
        <fieldset class="auth-role-choice" data-account-type>
            <legend>Daftar sebagai</legend>
            <label class="auth-role-option"><input type="radio" name="account_type" value="student" @checked($accountType === 'student')><span><strong>Saya ingin belajar</strong><small>Siswa</small></span></label>
            <label class="auth-role-option"><input type="radio" name="account_type" value="tutor" @checked($accountType === 'tutor')><span><strong>Saya ingin mengajar</strong><small>Tutor</small></span></label>
            <p class="auth-role-hint" data-tutor-hint @if ($accountType !== 'tutor') hidden @endif>Akun tutor perlu diverifikasi admin dengan kartu mahasiswa sebelum bisa mengunggah materi.</p>
        </fieldset>
        <label class="auth-terms"><input type="checkbox" name="terms" required data-auth-field><span>Saya setuju dengan Syarat dan Ketentuan serta Kebijakan Privasi TutorKita</span></label>
        <button class="auth-submit" type="submit">Daftar Sekarang</button>
    </form>
    <p class="auth-divider"><span>atau daftar dengan</span></p>
    <div class="auth-register-socials">@include('auth.partials.social-buttons')</div>
@endsection

@section('switch')
    <p class="auth-switch">Sudah punya akun? <a href="{{ route('login') }}">Masuk</a></p>
@endsection

@section('aside')
    <h2>Bergabung Bersama<br>TutorKita</h2>
    <p>Jadilah bagian dari gerakan pendidikan gratis untuk masa depan yang lebih baik</p>
@endsection
