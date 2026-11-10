@extends('fillmein.layouts.app')

@section('title', 'Figma')

@section('content')
  <x-ui.page-header eyebrow="Ustawienia" title="Figma" description="Wspólne połączenie z Figmą dla całej aplikacji." />

  <div class="mt-8 flex max-w-3xl flex-col gap-6">
    @if ($tokenOverride)
      <x-ui.alert variant="info" title="Używany jest token z konfiguracji serwera">
        Stała <span class="font-mono text-xs">FMI_FIGMA_TOKEN</span> ma pierwszeństwo przed połączeniem poniżej.
      </x-ui.alert>
    @endif

    @if ($status['unreadable'])
      <x-ui.alert variant="danger" title="Nie można odczytać zapisanych danych">
        Klucze witryny prawdopodobnie się zmieniły. Zapisz dane aplikacji ponownie i połącz z Figmą.
      </x-ui.alert>
    @endif

    <x-ui.card>
      <x-slot:header>
        <div class="flex items-center justify-between gap-3">
          <h2 class="text-md font-semibold">Połączenie</h2>
          <x-ui.badge :variant="$status['connected'] ? 'success' : 'neutral'">{{ $status['connected'] ? 'Połączono' : 'Nie połączono' }}</x-ui.badge>
        </div>
      </x-slot:header>

      @if ($status['connected'])
        <dl class="grid gap-x-6 gap-y-3 text-base sm:grid-cols-2">
          <div>
            <dt class="text-sm text-ink-500">Połączył(a)</dt>
            <dd class="text-ink-900">{{ $connectedBy ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-sm text-ink-500">Data połączenia</dt>
            <dd class="text-ink-900">{{ $status['connected_at'] ? wp_date('j.m.Y, H:i', $status['connected_at']) : '—' }}</dd>
          </div>
        </dl>
        <p class="mt-4 text-base text-ink-600">Dostęp odświeża się automatycznie. Aplikacja widzi te pliki Figmy, do których ma dostęp połączone konto.</p>
      @elseif ($status['has_secret'])
        <p class="text-base text-ink-600">Kliknij „Połącz z Figmą”, zaloguj się i zatwierdź dostęp. Wrócisz tu automatycznie.</p>
      @else
        <p class="text-base text-ink-600">Najpierw zapisz niżej dane aplikacji z Figmy.</p>
      @endif

      <x-slot:footer>
        <div class="flex flex-wrap justify-end gap-3">
          @if ($status['connected'])
            <form method="POST" action="{{ route('fmi.settings.figma.disconnect') }}">
              @csrf
              <x-ui.button type="submit" variant="secondary">Rozłącz</x-ui.button>
            </form>
          @endif
          <form method="POST" action="{{ route('fmi.settings.figma.connect') }}">
            @csrf
            <x-ui.button type="submit" :disabled="! $status['has_secret']">{{ $status['connected'] ? 'Połącz ponownie' : 'Połącz z Figmą' }}</x-ui.button>
          </form>
        </div>
      </x-slot:footer>
    </x-ui.card>

    <form method="POST" action="{{ route('fmi.settings.figma.save') }}" data-loading-form novalidate>
      @csrf
      <x-ui.card>
        <x-slot:header><h2 class="text-md font-semibold">Aplikacja Figma</h2></x-slot:header>

        <div class="flex flex-col gap-5">
          <ul class="flex flex-col gap-1 text-base text-ink-600">
            <li>1. Utwórz w Figmie prywatną aplikację OAuth i powiąż ją ze swoim zespołem.</li>
            <li>2. Ustaw zakres <span class="font-mono text-xs">file_content:read</span> i dodaj poniższy adres powrotu.</li>
            <li>3. Opublikuj aplikację, a Client ID i Client Secret wpisz tutaj.</li>
          </ul>

          <div class="flex flex-col gap-1.5">
            <p class="text-sm font-medium text-ink-800">Adres powrotu</p>
            <p class="rounded-lg bg-ink-100 px-3 py-2 font-mono text-xs break-all text-ink-800">{{ $redirectUri }}</p>
          </div>

          <x-ui.input name="client_id" label="Client ID" :value="$status['client_id']" required autocomplete="off" />
          <x-ui.input
            name="client_secret"
            label="Client Secret"
            type="password"
            autocomplete="new-password"
            :required="! $status['has_secret']"
            :hint="$status['has_secret'] ? 'Sekret jest zapisany. Zostaw puste, aby go nie zmieniać.' : 'Po zapisaniu nie będzie widoczny.'"
          />
        </div>

        <x-slot:footer>
          <div class="flex justify-end"><x-ui.button type="submit">Zapisz dane aplikacji</x-ui.button></div>
        </x-slot:footer>
      </x-ui.card>
    </form>
  </div>
@endsection
