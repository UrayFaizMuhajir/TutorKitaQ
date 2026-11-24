<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LearningSessionController;
use App\Http\Controllers\LearningMaterialController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\AdminTutorController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\StudentVerificationController;
use App\Http\Controllers\SubjectsController;
use App\Http\Controllers\TutorApplicationController;
use App\Http\Controllers\TutorVerificationController;

Route::get('/', LandingController::class)->name('home');


Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/dashboard/sessions/{learningSession}', [LearningSessionController::class, 'show'])->name('dashboard.sessions.show');
    // Mengajukan sesi: hanya siswa terverifikasi (ROADMAP 5.1). Membatalkan sesi tetap boleh.
    Route::post('/dashboard/sessions', [LearningSessionController::class, 'store'])->middleware(['role:student', 'student.verified'])->name('dashboard.sessions.store');
    Route::post('/dashboard/sessions/{learningSession}/cancel', [LearningSessionController::class, 'cancel'])->middleware('role:student')->name('dashboard.sessions.cancel');
    Route::get('/dashboard/materials/{learningMaterial}', [LearningMaterialController::class, 'show'])->name('dashboard.materials.show');
    // Mengelola materi/tugas: hanya tutor terverifikasi (cek role + verifikasi di middleware tutor.verified).
    Route::post('/dashboard/materials', [LearningMaterialController::class, 'store'])->middleware('tutor.verified')->name('dashboard.materials.store');
    Route::put('/dashboard/materials/{learningMaterial}', [LearningMaterialController::class, 'update'])->middleware('tutor.verified')->name('dashboard.materials.update');
    Route::delete('/dashboard/materials/{learningMaterial}', [LearningMaterialController::class, 'destroy'])->middleware('tutor.verified')->name('dashboard.materials.destroy');
    Route::post('/dashboard/materials/{learningMaterial}/bookmark', [LearningMaterialController::class, 'toggleBookmark'])->name('dashboard.materials.bookmark');
    Route::post('/dashboard/materials/{learningMaterial}/complete', [LearningMaterialController::class, 'complete'])->name('dashboard.materials.complete');
    Route::get('/dashboard/materials/{learningMaterial}/file', [LearningMaterialController::class, 'file'])->name('dashboard.materials.file');
    Route::get('/dashboard/assignments/{assignment}', [AssignmentController::class, 'show'])->name('dashboard.assignments.show');
    Route::post('/dashboard/assignments/{assignment}/submissions', [AssignmentController::class, 'submit'])->name('dashboard.assignments.submit');
    Route::get('/dashboard/assignments/{assignment}/submissions/{submission}/file', [AssignmentController::class, 'file'])->name('dashboard.assignments.submission-file');
    Route::post('/dashboard/assignments', [AssignmentController::class, 'create'])->middleware('tutor.verified')->name('dashboard.assignments.create');
    Route::post('/dashboard/assignments/{assignment}/submissions/{submission}/grade', [AssignmentController::class, 'grade'])->middleware('tutor.verified')->name('dashboard.assignments.grade');
    Route::post('/dashboard/notifications/{notification}/read', [NotificationController::class, 'read'])->name('dashboard.notifications.read');
    Route::post('/dashboard/notifications/read-all', [NotificationController::class, 'readAll'])->name('dashboard.notifications.read-all');
    Route::post('/dashboard/notifications/preferences', [NotificationController::class, 'preference'])->name('dashboard.notifications.preference');
    Route::get('/subjects', fn () => redirect('/dashboard?materials=subjects#materials'))->name('subjects.index');
    Route::get('/subjects/catalog', [SubjectsController::class, 'catalog'])->name('subjects.catalog');
    Route::post('/subjects/{subject}/selection', [SubjectsController::class, 'select'])->name('subjects.select');
    Route::put('/subjects/{subject}/selection', [SubjectsController::class, 'update'])->name('subjects.update');
    Route::delete('/subjects/{subject}/selection', [SubjectsController::class, 'destroy'])->name('subjects.destroy');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/dashboard/profile', [TutorApplicationController::class, 'updateProfile'])->name('dashboard.profile.update');
    Route::get('/dashboard/profile/photo/{userId}', [TutorApplicationController::class, 'photo'])->whereNumber('userId')->name('dashboard.profile.photo');
    Route::post('/dashboard/profile/tutor-application', [TutorApplicationController::class, 'submit'])->middleware('role:student')->name('dashboard.tutor-applications.store');
    Route::post('/dashboard/admin/tutor-applications/{tutorApplication}/decision', [TutorApplicationController::class, 'decide'])->middleware('role:admin')->name('dashboard.admin.tutor-applications.decide');
    Route::post('/dashboard/admin/tutors/{userId}/status', [AdminTutorController::class, 'updateStatus'])->middleware('role:admin')->whereNumber('userId')->name('dashboard.admin.tutors.status');

    // Verifikasi tutor yang mendaftar sendiri (halaman "Profil & Verifikasi").
    Route::middleware('role:tutor')->prefix('/dashboard/tutor-verification')->name('dashboard.tutor-verification.')->group(function (): void {
        Route::post('/profile', [TutorVerificationController::class, 'saveProfile'])->name('profile');
        Route::post('/student-card', [TutorVerificationController::class, 'uploadCard'])->name('student-card.store');
        Route::get('/student-card', [TutorVerificationController::class, 'ownCard'])->name('student-card.show');
        Route::post('/submit', [TutorVerificationController::class, 'submit'])->name('submit');
    });
    // Profil & verifikasi siswa (ROADMAP 5.1). Data ini tidak pernah untuk tutor.
    Route::middleware('role:student')->prefix('/dashboard/student-verification')->name('dashboard.student-verification.')->group(function (): void {
        Route::post('/profile', [StudentVerificationController::class, 'saveProfile'])->name('profile');
        Route::post('/document', [StudentVerificationController::class, 'uploadDocument'])->name('document.store');
        Route::get('/document', [StudentVerificationController::class, 'ownDocument'])->name('document.show');
        Route::post('/submit', [StudentVerificationController::class, 'submit'])->name('submit');
    });
    // Dokumen siswa: hanya Admin atau pemiliknya (dicek di controller).
    Route::get('/dashboard/student-profiles/{studentProfile}/document', [StudentVerificationController::class, 'profileDocument'])->name('dashboard.student-profiles.document');
    // Kartu mahasiswa pada pengajuan: hanya Admin atau pemilik pengajuan (dicek di controller).
    Route::get('/dashboard/tutor-applications/{tutorApplication}/student-card', [TutorVerificationController::class, 'applicationCard'])->name('dashboard.tutor-applications.student-card');
});
