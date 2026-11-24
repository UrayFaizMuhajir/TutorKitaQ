{{-- "Jadwal Saya" tutor (ROADMAP 2.2 · TT-7, TT-8). Waktu tampil dalam zona pengguna ({{ $user_timezone }}).
     PRIVASI (Aturan no. 11): daftar pemesan hanya nama, mapel, topik. --}}
@php
    $slotStatusLabels = ['open' => 'Tersedia', 'full' => 'Penuh', 'cancelled' => 'Dibatalkan', 'past' => 'Sudah lewat'];
    $slotStatusTones = ['open' => 'is-verified', 'full' => 'is-pending', 'cancelled' => 'is-rejected', 'past' => 'is-incomplete'];
    $upcomingSlots = $tutor_slots->where('is_past', false)->values();
    $pastSlots = $tutor_slots->where('is_past', true)->sortByDesc('starts_at')->values();
    $canManageSlots = $tutor_can_manage ?? false;
    $maxCapacity = (int) config('tutorkita.slot_max_capacity');
@endphp
<section class="dashboard-page spa-page verification-page" data-spa-page="schedule" data-tutor-schedule data-timezone-sensitive hidden>
    <div class="verification-heading"><div><span class="section-kicker">JADWAL MENGAJAR</span><h1>Jadwal <em>Saya</em></h1><p>Buka slot waktu mengajar. Satu slot bisa diikuti hingga {{ $maxCapacity }} siswa. Semua jam ditampilkan dalam zona waktumu ({{ $user_timezone_abbr }} · {{ $user_timezone }}).</p></div></div>
    @if ($errors->any())<p class="verification-alert is-rejected" role="alert">{{ $errors->first() }}</p>@endif

    @if ($canManageSlots)
        <section class="panel verification-card" aria-labelledby="slot-form-title">
            <h2 id="slot-form-title">Tambah slot</h2>
            <form method="post" action="{{ route('dashboard.tutor.slots.store') }}" class="role-form slot-form" data-slot-form data-preview-url="{{ route('dashboard.tutor.slots.preview') }}">
                @csrf
                <input type="hidden" name="timezone" value="{{ $user_timezone }}" data-user-timezone>
                <div class="slot-form-grid">
                    <label>Tanggal<input type="date" name="date" min="{{ \App\Support\UserTime::local(now())->format('Y-m-d') }}" value="{{ old('date') }}" required></label>
                    <label>Jam mulai<input type="time" name="start_time" value="{{ old('start_time', '15:00') }}" required></label>
                    <label>Jam selesai<input type="time" name="end_time" value="{{ old('end_time', '16:00') }}" required></label>
                    <label>Kapasitas<input type="number" name="capacity" min="1" max="{{ $maxCapacity }}" value="{{ old('capacity', $maxCapacity) }}" required></label>
                </div>
                <label class="slot-repeat"><input type="checkbox" name="repeat_weekly" value="1" @checked(old('repeat_weekly')) data-slot-repeat> Ulangi tiap minggu</label>
                <label data-slot-repeat-until @unless (old('repeat_weekly')) hidden @endunless>Sampai tanggal (maks. {{ config('tutorkita.slot_max_repeat_weeks') }} minggu)<input type="date" name="repeat_until" value="{{ old('repeat_until') }}"></label>
                <small class="verification-note">Durasi {{ config('tutorkita.slot_min_minutes') }}–{{ config('tutorkita.slot_max_minutes') }} menit. Tanggal yang bentrok dengan slotmu sendiri akan dilewati.</small>
                <div class="slot-preview" data-slot-preview hidden aria-live="polite"></div>
                <div class="role-dialog-actions"><button type="button" class="button button-outline" data-slot-preview-button>Pratinjau</button><button type="submit" class="button button-primary">Simpan Slot</button></div>
            </form>
        </section>
    @else
        <p class="verification-alert is-pending" role="status">Akunmu sedang dinonaktifkan admin. Kamu masih bisa melihat jadwal dan membatalkan slot yang sudah dipesan, tetapi belum bisa menambah atau mengubah slot.</p>
    @endif

    <nav class="admin-tabs" role="tablist" aria-label="Waktu slot" data-admin-tabs>
        <button type="button" role="tab" class="is-active" aria-selected="true" data-admin-tab="upcoming">Mendatang <span>{{ $upcomingSlots->count() }}</span></button>
        <button type="button" role="tab" aria-selected="false" data-admin-tab="past">Lewat <span>{{ $pastSlots->count() }}</span></button>
    </nav>
    @foreach (['upcoming' => $upcomingSlots, 'past' => $pastSlots] as $panelKey => $panelSlots)
        <div class="admin-tab-panel" data-admin-tab-panel="{{ $panelKey }}" @if ($panelKey !== 'upcoming') hidden @endif>
            <div class="admin-list" data-admin-list data-default-filter="all">
                @include('dashboard.partials.admin-list-tools', [
                    'searchLabel' => 'Cari slot', 'searchPlaceholder' => 'Cari tanggal atau nama siswa...',
                    'filters' => ['all' => 'Semua', 'open' => 'Tersedia', 'full' => 'Penuh', 'cancelled' => 'Dibatalkan'],
                    'counts' => ['all' => $panelSlots->count(), 'open' => $panelSlots->where('status', 'open')->count(), 'full' => $panelSlots->where('status', 'full')->count(), 'cancelled' => $panelSlots->where('status', 'cancelled')->count()],
                    'activeFilter' => 'all',
                ])
                @foreach ($panelSlots as $slot)
                    <article class="panel slot-card" data-admin-item data-slot-card data-status="{{ $slot->status }}" data-search="{{ \Illuminate\Support\Str::lower($slot->label.' '.collect($slot->bookings)->pluck('name')->join(' ')) }}">
                        <div class="slot-card-head">
                            <div><h3>{{ $slot->label }}</h3><p class="slot-capacity" data-slot-capacity>Terisi {{ $slot->booked_count }}/{{ $slot->capacity }}</p></div>
                            <span class="verification-badge {{ $slotStatusTones[$slot->display_status] }}">{{ $slotStatusLabels[$slot->display_status] }}</span>
                        </div>
                        @if ($slot->status === 'cancelled' && $slot->cancel_reason)<p class="verification-note">Alasan pembatalan: {{ $slot->cancel_reason }}</p>@endif
                        @if ($slot->bookings)
                            <ul class="slot-bookings" data-slot-bookings>@foreach ($slot->bookings as $booking)<li><strong>{{ $booking['name'] }}</strong><span>{{ $booking['subject'] }} · {{ $booking['topic'] }}</span></li>@endforeach</ul>
                        @elseif ($slot->status !== 'cancelled')
                            <p class="verification-note">Belum ada pemesan.</p>
                        @endif
                        @if (! $slot->is_past && $slot->status !== 'cancelled')
                            <div class="slot-actions">
                                @if ($canManageSlots)
                                    <details class="slot-edit"><summary>Ubah</summary>
                                        <form method="post" action="{{ route('dashboard.tutor.slots.update', $slot->id) }}" class="role-form">@csrf @method('PUT')
                                            <input type="hidden" name="timezone" value="{{ $user_timezone }}" data-user-timezone>
                                            @if ($slot->booked_count > 0)
                                                <p class="verification-note">Slot sudah dipesan: tanggal dan jam terkunci. Kapasitas minimal {{ $slot->booked_count }}.</p>
                                                <label>Kapasitas<input type="number" name="capacity" min="{{ $slot->booked_count }}" max="{{ $maxCapacity }}" value="{{ $slot->capacity }}" required></label>
                                            @else
                                                <div class="slot-form-grid">
                                                    <label>Tanggal<input type="date" name="date" value="{{ $slot->local_date }}" required></label>
                                                    <label>Jam mulai<input type="time" name="start_time" value="{{ $slot->local_start }}" required></label>
                                                    <label>Jam selesai<input type="time" name="end_time" value="{{ $slot->local_end }}" required></label>
                                                    <label>Kapasitas<input type="number" name="capacity" min="1" max="{{ $maxCapacity }}" value="{{ $slot->capacity }}" required></label>
                                                </div>
                                            @endif
                                            <button type="submit" class="button button-primary">Simpan Perubahan</button>
                                        </form>
                                    </details>
                                    @if ($slot->booked_count === 0)
                                        <form method="post" action="{{ route('dashboard.tutor.slots.destroy', $slot->id) }}">@csrf @method('DELETE')<button type="submit" class="button button-outline">Hapus</button></form>
                                    @endif
                                @endif
                                @if ($slot->booked_count > 0)
                                    <form method="post" action="{{ route('dashboard.tutor.slots.cancel', $slot->id) }}" class="slot-cancel-form">@csrf
                                        <label class="sr-only" for="slot-cancel-{{ $slot->id }}">Alasan pembatalan</label>
                                        <input id="slot-cancel-{{ $slot->id }}" name="reason" minlength="5" maxlength="2000" required placeholder="Alasan membatalkan (wajib, dikirim ke siswa)">
                                        <button type="submit" class="button button-outline">Batalkan slot</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </article>
                @endforeach
                <p class="role-state verification-empty" data-admin-empty @if ($panelSlots->isNotEmpty()) hidden @endif>{{ $panelKey === 'upcoming' ? 'Belum ada slot mendatang. Tambahkan slot agar siswa bisa memesan.' : 'Belum ada slot yang lewat.' }}</p>
            </div>
        </div>
    @endforeach
</section>
