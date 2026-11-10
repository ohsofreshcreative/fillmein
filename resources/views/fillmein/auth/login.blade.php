@extends('fillmein.layouts.guest')

@section('title', 'Logowanie')

@section('content')
  <h1 class="text-2xl font-semibold tracking-tighter">Zaloguj się</h1>
  <p class="mt-1.5 text-md text-ink-600">Panel zespołu OhSoFresh.</p>

  <div class="mt-8 flex flex-col gap-3">
    @if (session('status'))
      <x-ui.alert variant="success">{{ session('status') }}</x-ui.alert>
    @endif

    @if ($reason === 'expired' && ! $errors->any())
      <x-ui.alert variant="warning" title="Sesja wygasła">Zaloguj się ponownie, aby kontynuować.</x-ui.alert>
    @endif
  </div>

  <form method="POST" action="{{ route('fmi.login.store') }}" class="mt-6 flex flex-col gap-5" data-loading-form novalidate>
    @csrf
    <input type="hidden" name="redirect_to" value="{{ old('redirect_to', $redirectTo) }}">

    <x-ui.input
      name="login"
      label="Login lub adres e-mail"
      autocomplete="username"
      autocapitalize="none"
      spellcheck="false"
      required
      autofocus
    />

    <x-ui.input
      name="password"
      type="password"
      label="Hasło"
      autocomplete="current-password"
      :value="''"
      required
    />

    <div class="flex items-center justify-between gap-4">
      <x-ui.checkbox name="remember" label="Zapamiętaj mnie" :checked="(bool) old('remember')" />
      <a href="{{ wp_lostpassword_url() }}" class="rounded text-sm font-medium text-ink-600 underline decoration-ink-300 underline-offset-2 hover:text-ink-950">Nie pamiętasz hasła?</a>
    </div>

    <x-ui.button type="submit" size="lg" class="w-full">Zaloguj się</x-ui.button>
  </form>
@endsection
