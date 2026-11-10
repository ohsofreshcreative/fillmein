@extends('fillmein.layouts.app')

@section('title', 'Nowy użytkownik')

@section('content')
  <a href="{{ route('fmi.users.index') }}" class="mb-6 inline-flex items-center gap-1.5 rounded text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    Użytkownicy
  </a>

  <x-ui.page-header title="Nowy użytkownik" description="Pracownik dostanie e-mail z zaproszeniem i sam ustawi hasło." />

  <form method="POST" action="{{ route('fmi.users.store') }}" class="mt-8 max-w-2xl" data-loading-form novalidate>
    @csrf

    <x-ui.card>
      <div class="flex flex-col gap-5">
        <div class="grid gap-5 sm:grid-cols-2">
          <x-ui.input name="first_name" label="Imię" autocomplete="off" required autofocus />
          <x-ui.input name="last_name" label="Nazwisko" autocomplete="off" required />
        </div>

        <x-ui.input name="email" type="email" label="E-mail" autocomplete="off" hint="Na ten adres wyślemy zaproszenie. Adres musi być unikalny w WordPressie." required />

        <x-ui.select name="role" label="Rola" :options="$roles" value="fmi_employee" required />

        @include('fillmein.users.partials.capabilities', ['granted' => []])
      </div>

      <x-slot:footer>
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <x-ui.button :href="route('fmi.users.index')" variant="ghost">Anuluj</x-ui.button>
          <x-ui.button type="submit">Utwórz konto i wyślij zaproszenie</x-ui.button>
        </div>
      </x-slot:footer>
    </x-ui.card>
  </form>
@endsection
