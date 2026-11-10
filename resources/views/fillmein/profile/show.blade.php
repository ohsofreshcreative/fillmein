@extends('fillmein.layouts.app')

@section('title', 'Profil')

@section('content')
  <x-ui.page-header eyebrow="Profil" :title="\App\Support\Staff::fullName($person)" description="Twoje dane i bezpieczeństwo konta." />

  <div class="mt-8 grid items-start gap-6 lg:grid-cols-3">
    <div class="flex flex-col gap-6 lg:col-span-2">
      <form method="POST" action="{{ route('fmi.profile.update') }}" data-loading-form novalidate>
        @csrf
        <x-ui.card>
          <x-slot:header><h2 class="text-md font-semibold">Dane</h2></x-slot:header>

          <div class="flex flex-col gap-5">
            <div class="grid gap-5 sm:grid-cols-2">
              <x-ui.input name="first_name" label="Imię" :value="$person->first_name" autocomplete="given-name" required />
              <x-ui.input name="last_name" label="Nazwisko" :value="$person->last_name" autocomplete="family-name" required />
            </div>
            <x-ui.input name="email_display" label="E-mail" :value="$person->user_email" disabled hint="Zmianę adresu e-mail zgłoś administratorowi." />
          </div>

          <x-slot:footer>
            <div class="flex justify-end"><x-ui.button type="submit">Zapisz dane</x-ui.button></div>
          </x-slot:footer>
        </x-ui.card>
      </form>

      <form method="POST" action="{{ route('fmi.profile.password') }}" data-loading-form novalidate>
        @csrf
        <x-ui.card>
          <x-slot:header><h2 id="password-heading" class="text-md font-semibold">Zmiana hasła</h2></x-slot:header>

          <div class="flex flex-col gap-5">
            <x-ui.input name="current_password" type="password" label="Aktualne hasło" autocomplete="current-password" :value="''" bag="password" required />
            <div class="grid gap-5 sm:grid-cols-2">
              <x-ui.input name="password" type="password" label="Nowe hasło" autocomplete="new-password" :value="''" bag="password" :hint="'Co najmniej ' . $minLength . ' znaków.'" :minlength="$minLength" required />
              <x-ui.input name="password_confirmation" type="password" label="Powtórz nowe hasło" autocomplete="new-password" :value="''" bag="password" required />
            </div>
            <p class="text-sm text-ink-500">Po zmianie hasła pozostałe sesje (inne urządzenia) zostaną wylogowane.</p>
          </div>

          <x-slot:footer>
            <div class="flex justify-end"><x-ui.button type="submit">Zmień hasło</x-ui.button></div>
          </x-slot:footer>
        </x-ui.card>
      </form>
    </div>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Dostęp</h2></x-slot:header>

      <dl class="flex flex-col gap-3 text-base">
        <div class="flex justify-between gap-4"><dt class="text-ink-500">Rola</dt><dd class="text-right">{{ $roleLabel }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-ink-500">Ostatnie logowanie</dt><dd>{{ $lastLogin ? get_date_from_gmt($lastLogin, 'd.m.Y H:i') : '—' }}</dd></div>
      </dl>

      <p class="mt-5 text-sm font-medium text-ink-800">Uprawnienia</p>
      <ul class="mt-2 flex flex-col gap-1.5 text-sm text-ink-600">
        @foreach ($capabilities as $label)
          <li class="flex items-start gap-2">
            <svg class="mt-0.5 size-4 shrink-0 text-fresh-700" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L8 12.58l7.3-7.3a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" /></svg>
            {{ $label }}
          </li>
        @endforeach
      </ul>
      <p class="mt-4 text-sm text-ink-500">Rolę i uprawnienia zmienia administrator.</p>

      <x-slot:footer>
        <form method="POST" action="{{ route('fmi.logout') }}">
          @csrf
          <x-ui.button type="submit" variant="secondary" size="sm">
            @include('fillmein.partials.icon', ['name' => 'logout', 'class' => 'size-4'])
            Wyloguj się
          </x-ui.button>
        </form>
      </x-slot:footer>
    </x-ui.card>
  </div>
@endsection
