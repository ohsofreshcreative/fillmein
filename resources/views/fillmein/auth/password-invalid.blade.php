@extends('fillmein.layouts.guest')

@section('title', 'Link nieaktywny')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tighter">Link wygasł lub jest nieprawidłowy</h1>
  <p class="mt-2 text-md text-ink-600">
    Link do ustawienia hasła działa tylko raz i przez ograniczony czas. Poproś administratora Fill Me In
    o ponowne wysłanie zaproszenia albo zresetuj hasło.
  </p>
  <div class="mt-8 flex flex-col gap-2 sm:flex-row">
    <x-ui.button :href="route('fmi.login')">Przejdź do logowania</x-ui.button>
    <x-ui.button :href="wp_lostpassword_url()" variant="secondary">Zresetuj hasło</x-ui.button>
  </div>
@endsection
