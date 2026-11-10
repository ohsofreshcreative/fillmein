@extends('fillmein.layouts.app')

@section('title', 'Projekty')

@section('content')
  <x-ui.page-header
    eyebrow="Design Review"
    title="Projekty"
    description="Projekty z włączonym modułem Design Review. To te same projekty, klienci i zespół co w Treściach."
  >
    @can('create', \App\Models\Project::class)
      <x-slot:actions>
        <x-ui.button :href="route('fmi.projects.create')">Nowy projekt</x-ui.button>
      </x-slot:actions>
    @endcan
  </x-ui.page-header>

  @if ($projects->isEmpty())
    <x-ui.empty-state class="mt-8" title="Brak projektów w Design Review" description="Projekty z włączonym modułem Design Review, do których masz dostęp, pojawią się tutaj. Moduł włączysz w ustawieniach projektu." />
  @else
    <div class="mt-8 overflow-hidden rounded-2xl border border-ink-150 bg-white">
      <div class="hidden grid-cols-[minmax(0,1fr)_8rem_12rem_9rem_8rem] gap-4 border-b border-ink-150 bg-ink-50 px-5 py-3 text-xs font-medium text-ink-500 lg:grid" aria-hidden="true">
        <span>Projekt i klient</span>
        <span>Otwarte uwagi</span>
        <span>Status akceptacji</span>
        <span>Ostatnia aktywność</span>
        <span class="sr-only">Akcje</span>
      </div>

      <ul role="list" aria-label="Recenzje projektów" class="divide-y divide-ink-150">
        @foreach ($projects as $project)
          @php($stats = $overview[$project->id])
          <li class="grid gap-3 px-5 py-4 lg:grid-cols-[minmax(0,1fr)_8rem_12rem_9rem_8rem] lg:items-center lg:gap-4" data-review-row="{{ $project->id }}">
            <div class="min-w-0">
              <h2 class="text-base font-semibold break-words text-ink-950">
                <a href="{{ route('fmi.design-review.show', $project) }}" class="hover:underline">{{ $project->name }}</a>
              </h2>
              <p class="text-sm break-words text-ink-500">{{ $project->client_name ?: 'Bez nazwy klienta' }}</p>
            </div>
            <p class="text-sm text-ink-700">
              <span class="text-ink-500 lg:sr-only">Otwarte uwagi: </span>
              <span data-open-threads class="font-semibold text-ink-950">{{ $stats['open_threads'] }}</span>
            </p>
            <div class="min-w-0" data-approval>
              <span class="sr-only">Status akceptacji: </span>
              @include('fillmein.design-review.partials.status', ['revision' => $stats['revision']])
            </div>
            <p class="text-sm text-ink-600">
              <span class="text-ink-500 lg:sr-only">Ostatnia aktywność: </span>
              <time datetime="{{ $stats['last_activity']?->toIso8601String() }}">{{ $stats['last_activity']?->format('d.m.Y H:i') ?? '—' }}</time>
            </p>
            <div class="lg:text-right">
              <x-ui.button variant="secondary" size="sm" :href="route('fmi.design-review.show', $project)" :aria-label="'Otwórz recenzję: ' . $project->name">Otwórz</x-ui.button>
            </div>
          </li>
        @endforeach
      </ul>
    </div>

    <x-ui.pagination class="mt-6" :page="$page" :last-page="$lastPage" :url="$pageUrl" :total="$total" />
  @endif
@endsection
