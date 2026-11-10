{{-- Layout publicznego edytora klienta (/p/{token}). Bez nawigacji panelu i bez danych pracowników. --}}
@extends('fillmein.layouts.base')

@section('referrer', 'no-referrer')

@section('body')
  <div class="flex min-h-full flex-col">
    <header class="sticky top-0 z-30 border-b border-ink-150 bg-white/90 backdrop-blur">
      <div class="@yield('container', 'app-container') flex h-16 items-center justify-between gap-4">
        <x-ui.logo />
        @hasSection('header-meta')
          <div class="min-w-0 text-right">@yield('header-meta')</div>
        @endif
      </div>
    </header>

    <main id="main" class="flex-1">
      <div class="@yield('container', 'app-container') py-8 lg:py-12">
        @yield('content')
      </div>
    </main>

    <footer class="py-6 text-center text-xs text-ink-500">
      Fill Me In · OhSoFresh Creative
    </footer>
  </div>
@endsection
