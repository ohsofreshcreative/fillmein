@extends('fillmein.layouts.app')

@section('title', 'Sekcje i pola: ' . $page->name)

@section('content')
  <a href="{{ route('fmi.projects.pages.edit', [$project, $page]) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $page->name }}
  </a>

  <x-ui.page-header eyebrow="Struktura podstrony" title="Sekcje i pola" description="Elementy dodane tutaj są oznaczone jako ręczne — import z Figmy ich nie zmienia." />

  @php($structureErrors = collect($errors->getMessages())->except('name')->flatten())
  @if ($structureErrors->isNotEmpty())
    <x-ui.alert variant="danger" class="mt-6">
      @foreach ($structureErrors as $message)
        <p>{{ $message }}</p>
      @endforeach
    </x-ui.alert>
  @endif

  <div class="mt-8 flex flex-col gap-6">
    @if ($sections->isEmpty())
      <x-ui.empty-state title="Ta podstrona nie ma jeszcze sekcji" description="Dodaj sekcję ręcznie albo zaimportuj ją z frame'u Figmy." />
    @else
      <div x-data="sortable({ url: @js(route('fmi.projects.pages.sections.reorder', [$project, $page])) })">
        <p class="sr-only" role="status" x-text="status"></p>
        <ul data-sortable-list x-on:dragover="dragOver($event)" x-on:drop.prevent class="flex flex-col gap-4">
          @foreach ($sections as $section)
            <li data-sortable-item="{{ $section->id }}" id="sekcja-{{ $section->id }}" class="scroll-mt-6">
              <x-ui.card>
                <x-slot:header>
                  <div class="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-2">
                      <x-ui.sort-controls :label="$section->name" :first="$loop->first" :last="$loop->last" />
                      <h2 class="min-w-0 truncate text-md font-semibold">{{ $section->name }}</h2>
                      <x-ui.badge variant="muted">{{ $section->source->label() }}</x-ui.badge>
                      @if ($section->is_hidden)
                        <x-ui.badge variant="warning">Ukryta</x-ui.badge>
                      @endif
                    </div>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                      <x-ui.button :href="route('fmi.projects.pages.sections.edit', [$project, $page, $section])" variant="ghost" size="sm" :aria-label="'Edytuj sekcję: ' . $section->name">Edytuj</x-ui.button>
                      <x-ui.confirm
                        :action="route('fmi.projects.pages.sections.archive', [$project, $page, $section])"
                        title="Zarchiwizować sekcję?"
                        confirm="Archiwizuj"
                        trigger="Archiwizuj"
                        trigger-variant="ghost"
                        trigger-size="sm"
                        :aria-label="'Archiwizuj sekcję: ' . $section->name"
                      >
                        Sekcja „{{ $section->name }}” zniknie z edytora klienta. Pola i treść zostają — można ją przywrócić.
                      </x-ui.confirm>
                    </div>
                  </div>
                </x-slot:header>

                @include('fillmein.projects.pages.structure.partials.fields', [
                  'section' => $section,
                  'parent' => null,
                  'fields' => ($fieldsByParent['section-' . $section->id] ?? collect())->reject->isArchived()->values(),
                ])

                @if (($archivedFields[$section->id] ?? collect())->isNotEmpty())
                  <details class="mt-4 rounded-xl border border-ink-150 px-4 py-3">
                    <summary class="cursor-pointer text-sm font-medium text-ink-700">Pola w archiwum ({{ $archivedFields[$section->id]->count() }})</summary>
                    <ul class="mt-3 flex flex-col gap-2">
                      @foreach ($archivedFields[$section->id] as $archived)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                          <span class="min-w-0 text-sm text-ink-700">
                            {{ $archived->label }} <span class="font-mono text-xs text-ink-500">{{ $archived->name }}</span>
                          </span>
                          <form method="POST" action="{{ route('fmi.projects.pages.fields.restore', [$project, $page, $archived]) }}" data-loading-form>
                            @csrf
                            <x-ui.button type="submit" variant="secondary" size="sm" :aria-label="'Przywróć pole: ' . $archived->label">Przywróć</x-ui.button>
                          </form>
                        </li>
                      @endforeach
                    </ul>
                  </details>
                @endif
              </x-ui.card>
            </li>
          @endforeach
        </ul>
      </div>
    @endif

    <form method="POST" action="{{ route('fmi.projects.pages.sections.store', [$project, $page]) }}" data-loading-form>
      @csrf
      <x-ui.card>
        <x-slot:header>
          <h2 class="text-md font-semibold">Nowa sekcja</h2>
        </x-slot:header>

        <div class="flex flex-col gap-4">
          <x-ui.input name="name" label="Nazwa sekcji" required maxlength="190" />
          <x-ui.checkbox name="is_hidden" label="Ukryj sekcję przed klientem" />
        </div>

        <x-slot:footer>
          <div class="flex justify-end">
            <x-ui.button type="submit">Dodaj sekcję</x-ui.button>
          </div>
        </x-slot:footer>
      </x-ui.card>
    </form>

    @if ($archivedSections->isNotEmpty())
      <x-ui.card>
        <x-slot:header>
          <h2 class="text-md font-semibold">Sekcje w archiwum ({{ $archivedSections->count() }})</h2>
        </x-slot:header>

        <ul class="flex flex-col gap-2">
          @foreach ($archivedSections as $archived)
            <li class="flex flex-wrap items-center justify-between gap-2">
              <span class="min-w-0 text-base text-ink-700">{{ $archived->name }}</span>
              <form method="POST" action="{{ route('fmi.projects.pages.sections.restore', [$project, $page, $archived]) }}" data-loading-form>
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm" :aria-label="'Przywróć sekcję: ' . $archived->name">Przywróć</x-ui.button>
              </form>
            </li>
          @endforeach
        </ul>
      </x-ui.card>
    @endif
  </div>
@endsection
