{{-- Layout panelu pracowników (/app). Dane: App\View\Composers\AppLayout. --}}
@extends('fillmein.layouts.base')

@section('body')
  <div x-data="{ nav: false }" class="min-h-full lg:flex">
    <a href="#main" class="sr-only z-50 rounded-md bg-white px-3 py-2 focus:not-sr-only focus:fixed focus:left-4 focus:top-4">Przejdź do treści</a>

    {{-- Pasek mobilny --}}
    <div class="sticky top-0 z-30 flex h-14 items-center justify-between border-b border-ink-150 bg-white/90 px-4 backdrop-blur lg:hidden">
      <a href="{{ route('fmi.dashboard') }}"><x-ui.logo /></a>
      <button type="button" x-on:click="nav = true" class="-mr-2 rounded-lg p-2 text-ink-700 hover:bg-ink-100" aria-label="Otwórz menu" :aria-expanded="nav">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
      </button>
    </div>

    {{-- Tło menu mobilnego --}}
    <div x-cloak x-show="nav" x-transition.opacity x-on:click="nav = false" class="fixed inset-0 z-40 bg-ink-950/30 lg:hidden"></div>

    {{-- Nawigacja --}}
    <aside
      :class="{ 'max-lg:translate-x-0!': nav }"
      x-on:keydown.escape.window="nav = false"
      class="fixed inset-y-0 left-0 z-50 flex w-72 flex-col border-r border-ink-150 bg-white transition-transform duration-200 ease-out-soft max-lg:-translate-x-full lg:sticky lg:top-0 lg:h-screen lg:w-64"
    >
      <div class="flex h-16 items-center justify-between px-5">
        <a href="{{ route('fmi.dashboard') }}"><x-ui.logo /></a>
        <button type="button" x-on:click="nav = false" class="-mr-2 rounded-lg p-2 text-ink-500 hover:bg-ink-100 lg:hidden" aria-label="Zamknij menu">
          <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
        </button>
      </div>

      <nav class="flex-1 overflow-y-auto px-3 py-4" aria-label="Główna nawigacja">
        <ul class="flex flex-col gap-0.5">
          @foreach ($navigation as $item)
            @php($active = request()->routeIs($item['active']))
            <li>
              <a
                href="{{ route($item['route']) }}"
                @if ($active) aria-current="page" @endif
                @class([
                  'flex items-center gap-3 rounded-lg px-3 py-2 text-base font-medium transition duration-150',
                  'bg-ink-100 text-ink-950' => $active,
                  'text-ink-600 hover:bg-ink-50 hover:text-ink-950' => ! $active,
                ])
              >
                @include('fillmein.partials.icon', ['name' => $item['icon'], 'class' => $active ? 'text-ink-950' : 'text-ink-400'])
                {{ $item['label'] }}
                @if (! empty($item['count']))
                  <span class="ml-auto rounded-full bg-fresh-500 px-2 py-0.5 text-xs font-semibold text-ink-950" data-nav-count>{{ $item['count'] }}<span class="sr-only"> nieprzeczytanych</span></span>
                @endif
              </a>
            </li>
          @endforeach
        </ul>
      </nav>

      @if ($staffUser)
        <div class="border-t border-ink-150 p-3">
          <div class="flex items-center gap-1">
            <a
              href="{{ $profileUrl }}"
              @if (request()->routeIs('fmi.profile')) aria-current="page" @endif
              @class([
                'flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-2 transition duration-150 hover:bg-ink-50',
                'bg-ink-100' => request()->routeIs('fmi.profile'),
              ])
            >
              <x-ui.avatar :person="$staffUser" />
              <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink-950">{{ \App\Support\Staff::fullName($staffUser) }}</span>
                <span class="block truncate text-xs text-ink-500">{{ $staffRole }}</span>
              </span>
            </a>
            <form method="POST" action="{{ route('fmi.logout') }}">
              @csrf
              <button type="submit" class="rounded-lg p-2 text-ink-500 transition hover:bg-ink-100 hover:text-ink-900 focus-visible:outline-2 focus-visible:outline-ink-900" title="Wyloguj się">
                @include('fillmein.partials.icon', ['name' => 'logout', 'class' => ''])
                <span class="sr-only">Wyloguj się</span>
              </button>
            </form>
          </div>
        </div>
      @endif
    </aside>

    <main id="main" class="min-w-0 flex-1">
      <div class="app-container py-8 lg:py-12">
        @if (session('status'))
          <x-ui.alert variant="success" dismissible class="mb-6">{{ session('status') }}</x-ui.alert>
        @endif

        @if (session('error'))
          <x-ui.alert variant="danger" dismissible class="mb-6">{{ session('error') }}</x-ui.alert>
        @endif

        @yield('content')
      </div>
    </main>
  </div>
@endsection
