{{-- Strony błędów tras aplikacji (/app, /p). Szablony WordPressa (404.blade.php w root views) są osobne. --}}
@extends('fillmein.layouts.base')

@section('body')
  <main class="flex min-h-full flex-col items-center justify-center px-6 py-16 text-center">
    <x-ui.logo :with-name="false" class="mb-8" />
    <p class="font-mono text-sm text-ink-500">@yield('code')</p>
    <h1 class="mt-2 text-2xl font-semibold tracking-tighter">@yield('heading')</h1>
    <p class="mt-2 max-w-md text-md text-ink-600">@yield('message')</p>
    <div class="mt-8 flex flex-col gap-2 sm:flex-row">
      <x-ui.button :href="home_url('/app')" variant="secondary">Wróć do pulpitu</x-ui.button>
      @if (is_user_logged_in())
        <form method="POST" action="{{ home_url('/app/logout') }}">
          <input type="hidden" name="_token" value="{{ rescue(fn () => csrf_token(), '', false) }}">
          <x-ui.button type="submit" variant="ghost">Wyloguj się</x-ui.button>
        </form>
      @endif
    </div>
  </main>
@endsection
