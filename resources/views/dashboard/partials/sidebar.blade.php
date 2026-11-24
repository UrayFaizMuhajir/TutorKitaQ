<aside class="sidebar" id="main-sidebar" aria-label="Navigasi utama">
    <div class="sidebar-brand"><a href="{{ route('dashboard') }}" aria-label="TutorKita Dashboard"><img src="{{ asset('assets/tutorkita/tutorkita-logo.svg') }}" alt="TutorKita" width="150" height="50"></a><button class="icon-button sidebar-close" type="button" aria-label="Tutup navigasi" data-sidebar-close><x-icon name="x" /></button></div>
    @php
        $dashboardSpa = request()->routeIs('dashboard');
        $navigation = $navigation ?? \App\Support\DashboardNavigation::forRole($user['role'] ?? null);
    @endphp
    <div class="sidebar-label">{{ $navigation['label'] }}</div>
    {{-- Menu per role berasal dari App\Support\DashboardNavigation (satu sumber definisi). --}}
    <nav class="sidebar-nav" aria-label="{{ $navigation['aria_label'] }}" data-sidebar-nav data-role="{{ $navigation['role'] }}" @if ($dashboardSpa) data-spa-nav @endif>
        @foreach ($navigation['items'] as $item)
            @if (isset($item['page']))
                @if ($dashboardSpa)
                    <button class="nav-link {{ $item['page'] === 'dashboard' ? 'is-current' : '' }}" type="button" data-page-link="{{ $item['page'] }}" data-page-title="{{ $item['label'] }}" @if ($item['page'] === 'dashboard') aria-current="page" @endif><x-icon :name="$item['icon']" /><span>{{ $item['label'] }}</span>@if ($item['page'] === 'notifications' && $notification_unread_count)<i class="sidebar-unread-dot"></i>@endif @if (! empty($item['count']))<span class="profile-menu-count nav-count" aria-label="{{ $item['count'] }} menunggu">{{ $item['count'] }}</span>@endif</button>
                @else
                    <a class="nav-link" href="{{ route('dashboard') }}#{{ $item['page'] }}"><x-icon :name="$item['icon']" /><span>{{ $item['label'] }}</span>@if ($item['page'] === 'notifications' && $notification_unread_count)<i class="sidebar-unread-dot"></i>@endif</a>
                @endif
            @else
                <button class="nav-link nav-action" type="button" data-open-{{ $item['action'] }} aria-haspopup="dialog"><x-icon :name="$item['icon']" /><span>{{ $item['label'] }}</span>@if (! empty($item['count']))<span class="profile-menu-count nav-count" aria-label="{{ $item['count'] }} menunggu">{{ $item['count'] }}</span>@endif</button>
            @endif
        @endforeach
    </nav>
    <div class="sidebar-bottom">
        <section class="help-card" aria-label="Motivasi belajar">
            <div class="catalog-help-card">
                <x-icon name="graduation-cap" />
                <strong>Pendidikan Hari Ini Masa Depan yang Lebih Baik</strong>
                <button type="button" aria-label="Mulai belajar" data-toast="Semangat belajar bersama TutorKita!"><x-icon name="arrow-right" class="icon-sm" /></button>
            </div>
        </section>
        <button class="sidebar-user-profile" type="button" aria-label="Menu profil {{ $user['full_name'] }}" aria-expanded="false" aria-controls="profile-menu" data-profile-toggle>
            <span class="sidebar-user-avatar" aria-hidden="true">{{ $user['initials'] }}</span>
            <span><strong>{{ $user['full_name'] }}</strong><small data-user-subtitle>{{ $user['subtitle'] ?? $user['role_label'] }}</small></span>
            <x-icon name="chevron-down" class="icon-sm" />
        </button>
    </div>
</aside>
