{{-- Layout ekranów bez logowania (logowanie, ustawienie hasła). --}}
@extends('fillmein.layouts.base')

@section('referrer', 'no-referrer')
@section('body-class', 'bg-white')

@section('body')
  <div class="grid min-h-full lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
    <main id="main" class="flex flex-col px-6 py-8 sm:px-10">
      <a href="{{ route('fmi.login') }}" class="self-start rounded-lg"><x-ui.logo /></a>

      <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-12">
        @yield('content')
      </div>

      <p class="text-xs text-ink-500">OhSoFresh Creative · narzędzie wewnętrzne</p>
    </main>

    <aside class="relative hidden overflow-hidden bg-ink-950 lg:block" aria-hidden="true">
      <div class="absolute inset-0 flex flex-col justify-center px-16">
        <div class="flex flex-col gap-4">
          <div class="h-3 w-56 rounded-full bg-ink-800"></div>
          <div class="h-3 w-80 rounded-full bg-ink-800"></div>
          <div class="flex items-center gap-1">
            <div class="h-3 w-40 rounded-full bg-fresh-400"></div>
            <div class="h-7 w-0.5 animate-pulse rounded-full bg-fresh-400"></div>
          </div>
          <div class="h-3 w-64 rounded-full bg-ink-800"></div>
        </div>
        <p class="mt-14 max-w-sm text-2xl font-semibold leading-snug tracking-tighter text-white">
          Treści od klientów, dokładnie tam, gdzie zaprojektowane.
        </p>
        <p class="mt-3 max-w-sm text-md text-ink-400">Fill Me In łączy projekty z Figmy z treściami, które wpisują klienci.</p>
      </div>
    </aside>
  </div>
@endsection
