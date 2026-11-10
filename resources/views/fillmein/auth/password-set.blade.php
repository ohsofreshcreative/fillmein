@extends('fillmein.layouts.guest')

@section('title', 'Ustaw hasło')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tighter">Ustaw hasło</h1>
  <p class="mt-1.5 text-md text-ink-600">
    Witaj{{ $user->first_name ? ', ' . $user->first_name : '' }}! Ustaw hasło do konta <strong class="font-medium text-ink-900">{{ $user->user_email }}</strong>.
  </p>

  <form method="POST" action="{{ route('fmi.password.store') }}" class="mt-8 flex flex-col gap-5" data-loading-form novalidate>
    @csrf

    <x-ui.input
      name="password"
      type="password"
      label="Nowe hasło"
      autocomplete="new-password"
      :value="''"
      :hint="'Co najmniej ' . $minLength . ' znaków. Najlepiej kilka słów lub menedżer haseł.'"
      :minlength="$minLength"
      required
      autofocus
    />

    <x-ui.input
      name="password_confirmation"
      type="password"
      label="Powtórz hasło"
      autocomplete="new-password"
      :value="''"
      required
    />

    <x-ui.button type="submit" size="lg" class="w-full">Zapisz hasło</x-ui.button>
  </form>
@endsection
