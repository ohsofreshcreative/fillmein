@extends('fillmein.layouts.app')

@section('title', 'Treści')

@section('content')
  <x-ui.page-header title="Treści" description="Treści projektów klientów, do których masz dostęp.">
    @if ($canCreate)
      <x-slot:actions>
        <x-ui.button :href="route('fmi.projects.create')">
          <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z" /></svg>
          Nowy projekt
        </x-ui.button>
      </x-slot:actions>
    @endif
  </x-ui.page-header>

  <form method="GET" action="{{ route('fmi.projects.index') }}" class="mt-8 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_12rem_auto] sm:items-end" role="search">
    <x-ui.input name="q" label="Szukaj" :value="$filters['q']" placeholder="Nazwa projektu lub klienta" type="search" />
    <x-ui.select name="status" label="Status" :options="$statuses" :value="$filters['status']" placeholder="Aktywne (bez archiwum)" />
    <x-ui.select name="sort" label="Sortuj" :options="$sorts" :value="$filters['sort']" />

    <div class="flex gap-2">
      <x-ui.button type="submit" variant="secondary">Filtruj</x-ui.button>
      @if (array_filter($filters, fn ($value, $key) => $value !== '' && $key !== 'sort', ARRAY_FILTER_USE_BOTH))
        <x-ui.button :href="route('fmi.projects.index')" variant="ghost">Wyczyść</x-ui.button>
      @endif
    </div>

    <div class="sm:col-span-full">
      <x-ui.checkbox name="mine" label="Tylko projekty, których jestem autorem lub opiekunem" :checked="$filters['mine'] === '1'" />
    </div>
  </form>

  <div class="mt-6">
    @if ($projects->isEmpty())
      <x-ui.empty-state
        :title="array_filter($filters) ? 'Brak wyników' : 'Nie ma jeszcze projektów'"
        :description="array_filter($filters) ? 'Zmień kryteria wyszukiwania lub wyczyść filtry.' : 'Utwórz pierwszy projekt i dodaj jego podstrony.'"
      >
        @if ($canCreate && ! array_filter($filters))
          <x-slot:action>
            <x-ui.button :href="route('fmi.projects.create')">Nowy projekt</x-ui.button>
          </x-slot:action>
        @endif
      </x-ui.empty-state>
    @else
      <div x-data="projectSelection(@js($deletable))">
      @if ($deletable->isNotEmpty())
        <div x-show="selected.length > 0" x-cloak class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-danger-200 bg-danger-50 px-4 py-2">
          <p class="text-sm font-medium text-ink-900" aria-live="polite">Zaznaczone projekty: <span x-text="selected.length"></span></p>
          <x-ui.confirm
            :action="route('fmi.projects.destroy-selected')"
            form-id="projects-selection"
            title="Usunąć zaznaczone projekty?"
            confirm="Usuń projekty"
            trigger="Usuń zaznaczone"
            trigger-variant="danger"
            trigger-size="sm"
          >
            <p>Projekty znikną z listy, a linki klientów przestaną działać.</p>
            <ul class="mt-3 flex max-h-48 list-disc flex-col gap-1 overflow-y-auto pl-5 text-sm text-ink-800">
              <template x-for="name in names" :key="name">
                <li x-text="name"></li>
              </template>
            </ul>
          </x-ui.confirm>
        </div>
      @endif
      <div class="relative overflow-x-auto rounded-2xl border border-ink-150 bg-white shadow-xs">
        <table class="w-full text-left text-sm">
          <caption class="sr-only">Lista projektów</caption>
          <thead class="border-b border-ink-150 bg-ink-50 text-xs text-ink-500">
            <tr>
              @if ($deletable->isNotEmpty())
                <th scope="col" class="w-10 py-3 pl-5 sm:pl-6">
                  <input
                    type="checkbox"
                    aria-label="Zaznacz wszystkie projekty na tej stronie"
                    x-on:change="toggleAll()"
                    x-bind:checked="allSelected"
                    x-bind:indeterminate="selected.length > 0 && ! allSelected"
                    class="size-4 cursor-pointer rounded accent-ink-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900"
                  >
                </th>
              @endif
              <th scope="col" class="px-5 py-3 font-medium sm:px-6">Projekt</th>
              <th scope="col" class="hidden px-4 py-3 font-medium lg:table-cell">Opiekun</th>
              <th scope="col" class="hidden px-4 py-3 font-medium xl:table-cell">Autor</th>
              <th scope="col" class="px-4 py-3 font-medium">Status</th>
              <th scope="col" class="hidden px-4 py-3 font-medium md:table-cell">Podstrony</th>
              <th scope="col" class="hidden px-4 py-3 font-medium sm:table-cell">Postęp</th>
              <th scope="col" class="hidden px-5 py-3 font-medium lg:table-cell sm:px-6">Aktualizacja</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-ink-150">
            @foreach ($projects as $project)
              @php
                $stats = $progress[$project->id] ?? ['percent' => 0, 'filled' => 0, 'total' => 0];
                $manager = $people[$project->manager_id] ?? null;
                $author = $people[$project->author_id] ?? null;
              @endphp
              <tr class="transition-colors hover:bg-ink-100/60">
                @if ($deletable->isNotEmpty())
                  <td class="w-10 py-3 pl-5 sm:pl-6">
                    @if ($deletable->has($project->id))
                      <input
                        type="checkbox"
                        name="ids[]"
                        value="{{ $project->id }}"
                        form="projects-selection"
                        aria-label="Zaznacz projekt {{ $project->name }}"
                        x-model="selected"
                        class="size-4 cursor-pointer rounded accent-ink-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900"
                      >
                    @endif
                  </td>
                @endif
                <td class="px-5 py-3 sm:px-6">
                  <p class="font-medium text-ink-950">
                    <a href="{{ route('fmi.projects.show', $project) }}" class="rounded hover:underline hover:decoration-ink-300 hover:underline-offset-2">{{ $project->name }}</a>
                  </p>
                  <p class="mt-0.5 truncate text-xs text-ink-500">{{ $project->client_name ?: 'Bez nazwy klienta' }}</p>
                  <p class="mt-0.5 text-xs text-ink-500 lg:hidden">
                    Opiekun: {{ $manager ? \App\Support\Staff::fullName($manager) : 'Nieprzypisany' }}
                  </p>
                </td>
                <td class="hidden px-4 py-3 text-ink-700 lg:table-cell">
                  {{ $manager ? \App\Support\Staff::fullName($manager) : 'Nieprzypisany' }}
                </td>
                <td class="hidden px-4 py-3 text-ink-700 xl:table-cell">
                  {{ $author ? \App\Support\Staff::fullName($author) : 'Konto usunięte' }}
                </td>
                <td class="px-4 py-3">
                  <x-ui.badge :variant="$project->status->badge()">{{ $project->status->label() }}</x-ui.badge>
                </td>
                <td class="hidden px-4 py-3 text-ink-700 md:table-cell">{{ $project->pages_count }}</td>
                <td class="hidden w-40 px-4 py-3 sm:table-cell">
                  <x-ui.progress
                    size="sm"
                    :name="'Postęp wypełnienia: ' . $project->name"
                    :value="$stats['percent']"
                    :hint="$stats['total'] > 0 ? $stats['percent'] . '%' : 'Brak pól'"
                  />
                </td>
                <td class="hidden px-5 py-3 text-xs text-ink-500 lg:table-cell sm:px-6">
                  {{ $project->updated_at?->format('d.m.Y H:i') }}
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      </div>

      <x-ui.pagination class="mt-6" :page="$page" :last-page="$lastPage" :url="$pageUrl" :total="$total" />
    @endif
  </div>
@endsection
