{{-- Awaryjny szablon WordPressa. W praktyce front WP przekierowuje do /app (app/setup.php). --}}
@extends('fillmein.layouts.base')

@section('body')
  <main class="flex min-h-full flex-col items-center justify-center px-6 py-16 text-center">
    <x-ui.logo />
    <p class="mt-6 text-md text-ink-600">Aplikacja do zbierania treści od klientów OhSoFresh Creative.</p>
    <div class="mt-8">
      <x-ui.button :href="home_url('/app')">Przejdź do aplikacji</x-ui.button>
    </div>
  </main>
@endsection
