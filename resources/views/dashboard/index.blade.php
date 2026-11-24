@extends('layouts.dashboard')

@section('title', 'Dashboard')

@section('content')
    <div class="dashboard-page spa-page" data-spa-page="dashboard">
        @include('dashboard.home.'.$navigation['home'])
    </div>
    @include('dashboard.pages.workspace')
@endsection
