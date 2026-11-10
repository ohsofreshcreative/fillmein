@extends('fillmein.layouts.app')

@section('title', 'Design system')

@section('content')
  <x-ui.page-header eyebrow="Fundamenty" title="Design system" description="Tokeny i komponenty interfejsu Fill Me In (resources/css/fillmein.css, resources/views/components/ui)." />

  <div class="mt-10 flex flex-col gap-10">
    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Kolory</h2></x-slot:header>
      <div class="flex flex-col gap-5">
        @foreach ([
          'Ink' => ['bg-ink-50', 'bg-ink-100', 'bg-ink-200', 'bg-ink-300', 'bg-ink-400', 'bg-ink-500', 'bg-ink-600', 'bg-ink-700', 'bg-ink-800', 'bg-ink-900', 'bg-ink-950'],
          'Fresh' => ['bg-fresh-50', 'bg-fresh-100', 'bg-fresh-200', 'bg-fresh-300', 'bg-fresh-400', 'bg-fresh-500', 'bg-fresh-600', 'bg-fresh-700', 'bg-fresh-800', 'bg-fresh-900'],
          'Statusy' => ['bg-success-50', 'bg-success-600', 'bg-warning-50', 'bg-warning-600', 'bg-danger-50', 'bg-danger-600', 'bg-info-50', 'bg-info-600'],
        ] as $name => $swatches)
          <div>
            <p class="mb-2 text-sm font-medium text-ink-700">{{ $name }}</p>
            <div class="grid grid-cols-6 gap-2 sm:grid-cols-11">
              @foreach ($swatches as $swatch)
                <div class="h-10 rounded-lg ring-1 ring-ink-950/5 ring-inset {{ $swatch }}" title="{{ $swatch }}"></div>
              @endforeach
            </div>
          </div>
        @endforeach
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Typografia</h2></x-slot:header>
      <div class="flex flex-col gap-3">
        <p class="text-3xl font-semibold tracking-tighter">Nagłówek 3xl — 40/48</p>
        <p class="text-2xl font-semibold tracking-tighter">Nagłówek 2xl — 32/40</p>
        <p class="text-xl font-semibold tracking-tight">Nagłówek xl — 24/32</p>
        <p class="text-lg font-semibold">Nagłówek lg — 20/28</p>
        <p class="text-md">Tekst md — 16/24. Zażółć gęślą jaźń.</p>
        <p class="text-base text-ink-700">Tekst bazowy — 14/22. Domyślny rozmiar interfejsu.</p>
        <p class="text-sm text-ink-500">Tekst sm — 13/20. Opisy i etykiety.</p>
        <p class="font-mono text-sm text-ink-600">Mono — 01JB7Z3K0FQ9 · node-id 12:345</p>
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Przyciski</h2></x-slot:header>
      <div class="flex flex-wrap items-center gap-3">
        <x-ui.button>Primary</x-ui.button>
        <x-ui.button variant="secondary">Secondary</x-ui.button>
        <x-ui.button variant="fresh">Fresh</x-ui.button>
        <x-ui.button variant="ghost">Ghost</x-ui.button>
        <x-ui.button variant="danger">Danger</x-ui.button>
        <x-ui.button loading>Zapisywanie</x-ui.button>
        <x-ui.button disabled>Nieaktywny</x-ui.button>
        <x-ui.button size="sm" variant="secondary">Mały</x-ui.button>
        <x-ui.button size="lg">Duży</x-ui.button>
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Pola formularzy</h2></x-slot:header>
      <div class="grid gap-5 sm:grid-cols-2">
        <x-ui.input name="ds_name" label="Nazwa projektu" placeholder="np. Strona główna 2026" hint="Widoczna dla pracowników i klienta." required />
        <x-ui.input name="ds_error" label="Link do Figmy" value="https://example.com" error="Podaj link do pliku Figmy (figma.com/design/…)." />
        <x-ui.input name="ds_disabled" label="Pole nieaktywne" value="Tylko do odczytu" disabled />
        <x-ui.textarea name="ds_text" label="Opis" placeholder="Kilka zdań o projekcie…" rows="3" />
        <x-ui.select name="ds_role" label="Rola" :options="['fmi_employee' => 'Pracownik', 'fmi_project_manager' => 'Project Manager']" placeholder="Wybierz rolę" />
        <div class="flex flex-col gap-3 pt-7">
          <x-ui.checkbox name="ds_remember" label="Zapamiętaj mnie" checked />
          <x-ui.checkbox name="ds_export" label="Może eksportować treści" description="Uprawnienie ponad rolę." />
        </div>
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Potwierdzenia, awatary, paginacja</h2></x-slot:header>
      <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-3">
          <x-ui.confirm action="#" title="Dezaktywować konto?" confirm="Dezaktywuj" trigger="Otwórz potwierdzenie">
            Przykład okna potwierdzenia niebezpiecznej operacji (formularz POST z CSRF).
          </x-ui.confirm>
          @if ($me = \App\Support\Staff::current())
            <x-ui.avatar :person="$me" size="sm" />
            <x-ui.avatar :person="$me" />
            <x-ui.avatar :person="$me" size="lg" />
          @endif
        </div>
        <x-ui.pagination :page="2" :last-page="5" :url="fn ($page) => '#strona-' . $page" :total="96" />
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Sortowanie</h2></x-slot:header>
      <div x-data="sortable()">
        <p class="sr-only" role="status" x-text="status"></p>
        <ul data-sortable-list x-on:dragover="dragOver($event)" x-on:drop.prevent class="flex flex-col gap-2">
          @foreach (['Nagłówek', 'Treść', 'Przycisk'] as $sortExample)
            <li data-sortable-item="{{ $loop->iteration }}" class="flex items-center gap-2 rounded-xl border border-ink-150 px-3 py-2 text-base">
              <x-ui.sort-controls :label="$sortExample" :first="$loop->first" :last="$loop->last" />
              {{ $sortExample }}
            </li>
          @endforeach
        </ul>
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Postęp</h2></x-slot:header>
      <div class="flex flex-col gap-5">
        <x-ui.progress label="Postęp wypełnienia treści" :value="0" hint="0 z 24 pól (0%)" />
        <x-ui.progress label="Strona główna" :value="45" hint="45%" />
        <x-ui.progress label="Oferta" :value="100" hint="12 z 12 pól (100%)" />
        <x-ui.progress size="sm" name="Postęp projektu" :value="72" hint="72%" class="max-w-40" />
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Komunikaty i statusy</h2></x-slot:header>
      <div class="flex flex-col gap-3">
        <x-ui.alert variant="info" title="Informacja">Treści zapisują się automatycznie.</x-ui.alert>
        <x-ui.alert variant="success" dismissible>Zmiany zostały zapisane.</x-ui.alert>
        <x-ui.alert variant="warning" title="Ktoś zmienił to pole">Wczytaj nowszą wersję przed dalszą edycją.</x-ui.alert>
        <x-ui.alert variant="danger" title="Brak połączenia">Spróbujemy zapisać ponownie, gdy połączenie wróci.</x-ui.alert>
        <div class="mt-2 flex flex-wrap gap-2">
          @foreach ($statuses as $status)
            <x-ui.badge :variant="$status->badge()">{{ $status->label() }}</x-ui.badge>
          @endforeach
          <x-ui.badge variant="danger">Błąd</x-ui.badge>
        </div>
      </div>
    </x-ui.card>

    <x-ui.card>
      <x-slot:header><h2 class="text-md font-semibold">Stany ładowania i puste</h2></x-slot:header>
      <div class="grid gap-6 sm:grid-cols-2">
        <div class="flex flex-col gap-3" aria-label="Ładowanie listy">
          <x-ui.skeleton class="h-4 w-2/3" />
          <x-ui.skeleton class="h-4 w-full" />
          <x-ui.skeleton class="h-4 w-5/6" />
          <x-ui.skeleton class="h-24 w-full rounded-xl" />
          <div class="flex items-center gap-2 text-sm text-ink-500"><x-ui.spinner size="sm" /> Zapisywanie…</div>
        </div>
        <x-ui.empty-state title="Brak sekcji" description="Wybierz frame'y w Figmie, aby wygenerować sekcje." />
      </div>
    </x-ui.card>
  </div>
@endsection
