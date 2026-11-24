<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use App\Support\LocalUserStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(Request $request, LocalUserStore $users): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'contact' => [
                'required', 'string', 'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && ! LocalUserStore::isValidContact($value)) {
                        $fail('Masukkan email atau nomor telepon yang valid.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'terms' => ['accepted'],
            // Hanya Siswa atau Tutor. Admin tidak pernah bisa dipilih lewat form (field `role` diabaikan).
            'account_type' => ['nullable', 'string', Rule::in(['student', 'tutor'])],
        ], [
            'name.required' => 'Nama lengkap perlu diisi.',
            'name.max' => 'Nama lengkap maksimal 120 karakter.',
            'contact.required' => 'Email atau nomor telepon perlu diisi.',
            'contact.max' => 'Email atau nomor telepon terlalu panjang.',
            'password.required' => 'Password perlu diisi.',
            'password.min' => 'Gunakan password minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi password belum sama.',
            'terms.accepted' => 'Persetujuan syarat dan ketentuan diperlukan.',
            'account_type.in' => 'Pilih daftar sebagai Siswa atau Tutor.',
            'account_type.string' => 'Pilih daftar sebagai Siswa atau Tutor.',
        ]);
        $role = ($data['account_type'] ?? null) === 'tutor' ? 'tutor' : 'student';

        $record = $users->create($data['name'], $data['contact'], $data['password'], $role);

        if (! $record) {
            return redirect()->route('register')
                ->withErrors(['contact' => 'Email atau nomor telepon sudah terdaftar.'])
                ->withInput($request->only('name', 'contact', 'account_type'));
        }

        $userId = (string) $record['id'];
        DB::transaction(function () use ($userId, $role): void {
            DB::table('user_roles')->insertOrIgnore(['user_id' => $userId, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
            if ($role === 'tutor') {
                DB::table('user_role_changes')->insert([
                    'user_id' => $userId, 'actor_id' => $userId, 'old_role' => null, 'new_role' => 'tutor',
                    'source' => 'registration', 'created_at' => now(), 'updated_at' => now(),
                ]);
                // Draf profil tutor. Tidak pernah aktif sebelum admin menyetujui verifikasi.
                TutorProfile::query()->firstOrCreate(['user_id' => $userId], ['is_active' => false]);
            }
        });

        return redirect()->route('login')->with('status', $role === 'tutor'
            ? 'Pendaftaran tutor berhasil. Masuk, lalu lengkapi profil dan kartu mahasiswa untuk diverifikasi admin.'
            : 'Pendaftaran berhasil. Silakan masuk menggunakan akunmu.');
    }
}
