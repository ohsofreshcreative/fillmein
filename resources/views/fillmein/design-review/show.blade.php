@extends('fillmein.layouts.app')

@section('title', $project->name . ' — Design Review')

@section('content')
  @php
    $latest = $overview['revision'];
  @endphp
  <a href="{{ route('fmi.design-review.index') }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    Projekty
  </a>

  <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
      <p class="mb-1 text-sm font-medium break-words text-ink-500">{{ $project->client_name ?: 'Design Review' }}</p>
      <h1 class="text-2xl font-semibold tracking-tighter break-words">{{ $project->name }}</h1>
    </div>
    <div class="flex shrink-0 flex-wrap items-center gap-2">
      @if ($project->usesModule(\App\Enums\ProjectModule::Content))
        <x-ui.button variant="ghost" size="sm" :href="route('fmi.projects.show', $project)">Treści projektu</x-ui.button>
      @else
        <x-ui.button variant="ghost" size="sm" :href="route('fmi.projects.show', $project)">Karta projektu</x-ui.button>
      @endif
    </div>
  </header>

  <dl class="mt-8 grid gap-4 sm:grid-cols-3">
    <div class="rounded-2xl border border-ink-150 bg-white p-5">
      <dt class="text-sm text-ink-500">Otwarte uwagi</dt>
      <dd class="mt-1 text-xl font-semibold" data-open-threads>{{ $overview['open_threads'] }}</dd>
    </div>
    <div class="rounded-2xl border border-ink-150 bg-white p-5">
      <dt class="text-sm text-ink-500">Status akceptacji</dt>
      <dd class="mt-2" data-approval>@include('fillmein.design-review.partials.status', ['revision' => $latest])</dd>
    </div>
    <div class="rounded-2xl border border-ink-150 bg-white p-5">
      <dt class="text-sm text-ink-500">Ostatnia aktywność</dt>
      <dd class="mt-1 text-md font-medium"><time datetime="{{ $overview['last_activity']?->toIso8601String() }}">{{ $overview['last_activity']?->format('d.m.Y H:i') ?? '—' }}</time></dd>
    </div>
  </dl>

  @if ($latest)
    <x-ui.card class="mt-6" data-review-canvas>
      <x-slot:header>
        <div>
          <h2 class="text-md font-semibold">Recenzja — {{ $latest->title }}</h2>
          <p class="mt-0.5 text-sm text-ink-500">Całe podstrony z Figmy. Kliknij w miejsce na ekranie, żeby dodać uwagę.</p>
        </div>
      </x-slot:header>
      @if ($embedInvalid)
        <x-ui.alert variant="danger" title="Niepoprawny link prototypu" class="mb-4" data-embed-invalid>Zapisany link nie jest poprawnym linkiem prototypu Figmy. Wklej link z „Share prototype” ponownie.</x-ui.alert>
      @endif
      @include('fillmein.design-review.partials.composer', ['project' => $project, 'revision' => $latest, 'screens' => $screens, 'threads' => $threads, 'openUrl' => $embed['open_url'] ?? null])
    </x-ui.card>
  @endif

  <div class="mt-6 grid items-start gap-6 lg:grid-cols-3">
    <x-ui.card class="lg:col-span-2" data-subpages>
      <x-slot:header>
        <div>
          <h2 class="text-md font-semibold">Podstrony</h2>
          <p class="mt-0.5 text-sm text-ink-500">Każda podstrona to frame z Figmy: nazwa i link do frame'u. Klient komentuje je w dymkach.</p>
        </div>
      </x-slot:header>

      @if ($screens->isEmpty())
        <p class="mb-5 rounded-xl border border-dashed border-ink-200 bg-ink-50 px-4 py-3 text-sm text-ink-600" data-screens-empty>Brak podstron. Dodaj pierwszą — recenzja i rewizja utworzą się same.</p>
      @else
        <ol class="mb-5 flex flex-col gap-2" data-screens>
          @foreach ($screens as $screen)
            <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-ink-150 p-3">
              <span class="flex min-w-0 items-center gap-2">
                <span class="text-xs font-medium text-ink-500">{{ $loop->iteration }}.</span>
                <span class="min-w-0 text-sm font-medium break-words text-ink-950">{{ $screen->name }}</span>
              </span>
              @if ($screen->surface_confirmed)
                <x-ui.badge variant="success">Pinezki</x-ui.badge>
              @else
                <x-ui.badge variant="muted">Bez pinezek</x-ui.badge>
              @endif
            </li>
          @endforeach
        </ol>
      @endif

      @if ($canManage)
        @php
          $oldRows = old('screens');
          $rows = is_array($oldRows) && $oldRows !== [] ? array_values($oldRows) : [['name' => '', 'url' => '']];
        @endphp
        <form
          method="POST"
          action="{{ route('fmi.design-review.screens.store', $project) }}"
          class="flex flex-col gap-3"
          data-loading-form
          data-subpages-form
          novalidate
          x-data="{ rows: @js(array_map(fn ($row) => ['name' => (string) ($row['name'] ?? ''), 'url' => (string) ($row['url'] ?? '')], $rows)), add() { this.rows.push({ name: '', url: '' }); this.$nextTick(() => [...this.$el.querySelectorAll('[data-subpage-name]')].at(-1)?.focus()); }, remove(index) { this.rows.splice(index, 1); if (! this.rows.length) this.add(); } }"
        >
          @csrf
          <div class="hidden grid-cols-[minmax(0,14rem)_minmax(0,1fr)_2.5rem] gap-2 text-sm font-medium text-ink-800 sm:grid" aria-hidden="true">
            <span>Nazwa</span>
            <span>Link do frame'u w Figmie</span>
          </div>
          <ol class="flex flex-col gap-3 sm:gap-2">
            <template x-for="(row, index) in rows" :key="index">
              <li class="grid gap-2 rounded-xl border border-ink-150 p-3 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_2.5rem] sm:items-start sm:border-0 sm:p-0" data-subpage-row>
                <label class="flex flex-col gap-1">
                  <span class="text-sm font-medium text-ink-800 sm:sr-only" x-text="'Nazwa podstrony ' + (index + 1)"></span>
                  <input data-subpage-name type="text" maxlength="190" x-model="row.name" x-bind:name="'screens[' + index + '][name]'" placeholder="np. Strona główna" class="h-10 w-full rounded-lg border border-ink-200 bg-white px-3 text-base text-ink-900 shadow-xs transition duration-150 placeholder:text-ink-400 hover:border-ink-300 focus:ring-focus">
                </label>
                <label class="flex flex-col gap-1">
                  <span class="text-sm font-medium text-ink-800 sm:sr-only" x-text="'Link do frame\'u, podstrona ' + (index + 1)"></span>
                  <input data-subpage-url type="url" inputmode="url" maxlength="500" x-model="row.url" x-bind:name="'screens[' + index + '][url]'" placeholder="https://www.figma.com/design/…?node-id=…" class="h-10 w-full rounded-lg border border-ink-200 bg-white px-3 text-base text-ink-900 shadow-xs transition duration-150 placeholder:text-ink-400 hover:border-ink-300 focus:ring-focus">
                </label>
                <button type="button" data-subpage-remove x-on:click="remove(index)" x-bind:aria-label="'Usuń wiersz ' + (index + 1)" class="inline-flex h-10 items-center justify-center rounded-lg text-lg text-ink-500 transition hover:bg-ink-50 hover:text-danger-700 focus:ring-focus">×</button>
              </li>
            </template>
          </ol>
          @php
            $subpageErrors = collect($errors->getMessages())->filter(fn ($messages, $key) => $key === 'screens' || str_starts_with($key, 'screens.'))->flatten()->unique();
          @endphp
          @if ($subpageErrors->isNotEmpty())
            <ul class="flex flex-col gap-1 text-sm text-danger-700" role="alert" data-subpages-errors>
              @foreach ($subpageErrors as $message)
                <li>{{ $message }}</li>
              @endforeach
            </ul>
          @endif
          <p class="text-sm text-ink-500">Link do frame'u: w Figmie zaznacz frame → prawy przycisk → „Copy link to selection”. Puste pole nazwy = nazwa frame'u z Figmy.</p>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <x-ui.button type="button" variant="ghost" size="sm" data-subpage-add x-on:click="add()" x-bind:disabled="rows.length >= {{ \App\Http\Requests\StoreReviewScreenRequest::MAX_ROWS }}"><span aria-hidden="true">+</span> Dodaj podstronę</x-ui.button>
            <x-ui.button type="submit">Zapisz podstrony</x-ui.button>
          </div>
        </form>

        <details class="mt-6 rounded-xl border border-ink-150 p-4" data-prototype @if ($errors->has('figma_url')) open @endif>
          <summary class="cursor-pointer text-sm font-medium text-ink-800">Link do prototypu <span class="font-normal text-ink-500">(opcjonalnie — przycisk „Otwórz prototyp w Figmie”)</span></summary>
          <form method="POST" action="{{ route('fmi.design-review.prototype', $project) }}" class="mt-4 flex flex-col gap-4" data-loading-form novalidate>
            @csrf
            <x-ui.input
              name="figma_url"
              label="Link do prototypu"
              type="url"
              inputmode="url"
              :value="$latest?->figma_url"
              maxlength="{{ \App\Integrations\Figma\FigmaUrl::PROTOTYPE_MAX_LENGTH }}"
              placeholder="https://www.figma.com/proto/…"
              required
              :hint="$latest && $latest->status !== \App\Enums\ReviewRevisionStatus::Draft ? 'Ostatnia rewizja jest już w recenzji — zapis utworzy nową rewizję (wymaga nowej decyzji klienta).' : 'Link z „Share prototype” w Figmie.'"
            />
            <div class="flex justify-end">
              <x-ui.button type="submit" variant="secondary" size="sm">Zapisz link</x-ui.button>
            </div>
          </form>
        </details>
      @else
        <x-ui.alert title="Tylko podgląd">Podstrony i link prototypu zmienia opiekun projektu lub osoba z uprawnieniem do zarządzania recenzjami.</x-ui.alert>
      @endif
    </x-ui.card>

    <div class="flex min-w-0 flex-col gap-6">
    @if ($latest)
      @include('fillmein.design-review.partials.decision', [
        'revision' => $latest,
        'action' => Gate::allows('decideReview', $project) ? route('fmi.design-review.decision.store', $project) : null,
        'history' => $revisions,
        'project' => $project,
        'canCreate' => $canManage,
        'who' => 'Twoje konto (' . wp_get_current_user()->display_name . ')',
      ])
    @endif

    @if ($canShare)
      @include('fillmein.design-review.partials.links', ['project' => $project, 'links' => $links])
    @endif

    <x-ui.card>
      <x-slot:header>
        <h2 class="text-md font-semibold">Rewizje</h2>
      </x-slot:header>

      @if ($revisions->isEmpty())
        <p class="text-sm text-ink-500">Brak rewizji. Pierwsza powstanie po dodaniu podstron.</p>
      @else
        <ol class="flex flex-col gap-3" data-revisions>
          @foreach ($revisions as $revision)
            <li class="flex flex-col gap-1 rounded-xl border border-ink-150 p-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm font-semibold text-ink-950">{{ $revision->title }}</span>
                <x-ui.badge :variant="$revision->status->badge()">{{ $revision->status->label() }}</x-ui.badge>
              </div>
              <span class="text-xs text-ink-500">Utworzona {{ $revision->created_at?->format('d.m.Y H:i') }}</span>
            </li>
          @endforeach
        </ol>
      @endif
    </x-ui.card>
    </div>
  </div>

  @include('fillmein.design-review.partials.threads', ['project' => $project, 'revision' => $latest, 'threads' => $threads, 'listed' => $listed, 'filter' => $filter, 'counts' => $counts])
@endsection
