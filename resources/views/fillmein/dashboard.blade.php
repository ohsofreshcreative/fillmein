@extends('fillmein.layouts.app')

@section('title', 'Pulpit')

@section('content')
  @php
    $hour = (int) now()->format('H');
    $greeting = $hour < 12 ? 'Dzień dobry' : ($hour < 18 ? 'Cześć' : 'Dobry wieczór');
    $active = $statuses->reject(fn ($item) => $item['status'] === \App\Enums\ProjectStatus::Archived);
    $activeTotal = max(1, $active->sum('total'));
    $tiles = [
      ['label' => 'Projekty', 'value' => $totals['projects'], 'icon' => 'folder', 'url' => route('fmi.projects.index')],
      ['label' => 'Aktywne podstrony', 'value' => $totals['pages'], 'icon' => 'home', 'url' => null],
      ['label' => 'Otwarte uwagi', 'value' => $totals['openThreads'], 'icon' => 'review', 'url' => route('fmi.design-review.index')],
      ['label' => 'Aktywne linki klientów', 'value' => $totals['clientLinks'], 'icon' => 'users', 'url' => null],
    ];
  @endphp

  {{-- Powitanie --}}
  <section class="animate-rise-in relative overflow-hidden rounded-3xl bg-ink-950 px-6 py-8 text-white sm:px-10 sm:py-10">
    <div class="pointer-events-none absolute -top-24 -right-16 size-72 rounded-full bg-fresh-500/25 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-28 left-1/3 size-64 rounded-full bg-fresh-300/10 blur-3xl" aria-hidden="true"></div>

    <div class="relative flex flex-col gap-6 sm:flex-row sm:items-end sm:justify-between">
      <div class="min-w-0">
        <p class="text-sm font-medium text-fresh-300">{{ now()->translatedFormat('l, j F') }}</p>
        <h1 class="mt-1 text-3xl font-semibold tracking-tighter">{{ $greeting }}, {{ $user->first_name ?: $user->display_name }}</h1>
        <p class="mt-2 max-w-xl text-md text-ink-300">
          @if ($totals['openThreads'] > 0)
            Czeka {{ $totals['openThreads'] }} {{ $totals['openThreads'] === 1 ? 'otwarta uwaga' : 'otwartych uwag' }} w Design Review.
          @else
            Brak otwartych uwag — wszystko pod kontrolą.
          @endif
        </p>
      </div>
      <div class="flex flex-wrap gap-2">
        @if ($canCreate)
          <x-ui.button :href="route('fmi.projects.create')">Nowy projekt</x-ui.button>
        @endif
        <a href="{{ route('fmi.projects.index') }}" class="inline-flex items-center rounded-lg border border-white/20 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/10 focus-visible:outline-2 focus-visible:outline-white">Wszystkie projekty</a>
      </div>
    </div>
  </section>

  {{-- Liczby --}}
  <section class="mt-6" aria-labelledby="totals-heading">
    <h2 id="totals-heading" class="sr-only">Podsumowanie</h2>
    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
      @foreach ($tiles as $tile)
        <div class="group relative rounded-2xl border border-ink-150 bg-white p-5 shadow-xs transition @if ($tile['url']) hover:border-ink-300 hover:shadow-sm @endif">
          <div class="flex items-center justify-between">
            <dt class="text-sm text-ink-500">
              @if ($tile['url'])
                <a href="{{ $tile['url'] }}" class="after:absolute after:inset-0">{{ $tile['label'] }}</a>
              @else
                {{ $tile['label'] }}
              @endif
            </dt>
            <span class="flex size-9 items-center justify-center rounded-xl bg-fresh-100 text-ink-900">
              @include('fillmein.partials.icon', ['name' => $tile['icon'], 'class' => 'size-4.5'])
            </span>
          </div>
          <dd class="mt-3 text-3xl font-semibold tracking-tighter text-ink-950">{{ $tile['value'] }}</dd>
        </div>
      @endforeach
    </dl>
  </section>

  {{-- Projekty według statusu: jeden pasek zamiast pięciu kafelków --}}
  <section class="mt-6 rounded-2xl border border-ink-150 bg-white p-5 shadow-xs" aria-labelledby="stats-heading">
    <div class="flex items-center justify-between">
      <h2 id="stats-heading" class="text-sm font-semibold text-ink-950">Projekty według statusu</h2>
      <span class="text-xs text-ink-500">bez archiwum: {{ $active->sum('total') }}</span>
    </div>
    @php($colors = ['bg-ink-300', 'bg-info-600', 'bg-fresh-500', 'bg-warning-600', 'bg-success-600', 'bg-danger-600'])
    <div class="mt-3 flex h-2.5 overflow-hidden rounded-full bg-ink-100" aria-hidden="true">
      @foreach ($active->values() as $i => $item)
        @if ($item['total'] > 0)
          <div class="{{ $colors[$i % count($colors)] }}" style="width: {{ round($item['total'] / $activeTotal * 100, 2) }}%"></div>
        @endif
      @endforeach
    </div>
    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
      @foreach ($statuses->values() as $i => $item)
        <div class="flex items-center gap-2 text-sm">
          <span class="size-2.5 rounded-full {{ $item['status'] === \App\Enums\ProjectStatus::Archived ? 'bg-ink-200' : $colors[$active->keys()->search($item['status']->value) % count($colors)] }}" aria-hidden="true"></span>
          <dt class="text-ink-600">{{ $item['status']->label() }}</dt>
          <dd class="font-semibold text-ink-950">{{ $item['total'] }}</dd>
        </div>
      @endforeach
    </dl>
  </section>

  {{-- Projekty --}}
  @foreach ([['id' => 'mine', 'title' => 'Moje projekty', 'items' => $mine, 'empty' => 'Tutaj pojawią się projekty, w których jesteś autorem lub opiekunem.'], ['id' => 'recent', 'title' => 'Ostatnio aktualizowane', 'items' => $recent, 'empty' => 'Projekty, do których zostaniesz przypisany, pojawią się tutaj.']] as $group)
    <section class="mt-10" aria-labelledby="{{ $group['id'] }}-heading">
      <div class="mb-4 flex items-center justify-between">
        <h2 id="{{ $group['id'] }}-heading" class="text-lg font-semibold">{{ $group['title'] }}</h2>
        <a href="{{ route('fmi.projects.index', $group['id'] === 'mine' ? ['mine' => 1] : []) }}" class="text-sm font-medium text-ink-500 hover:text-ink-950">Zobacz wszystkie →</a>
      </div>

      @if ($group['items']->isEmpty())
        <x-ui.empty-state :title="$group['id'] === 'mine' ? 'Nie prowadzisz żadnego projektu' : 'Brak projektów'" :description="$group['empty']" />
      @else
        <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" role="list">
          @foreach ($group['items'] as $project)
            @php
              $stats = $progress[$project->id] ?? null;
              $rev = $review[$project->id] ?? null;
              $hasContent = $project->usesModule(\App\Enums\ProjectModule::Content);
              $hasReview = $project->usesModule(\App\Enums\ProjectModule::Review);
            @endphp
            <li class="relative flex flex-col rounded-2xl border border-ink-150 bg-white p-5 shadow-xs transition hover:-translate-y-0.5 hover:border-ink-300 hover:shadow-sm">
              <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="truncate text-xs text-ink-500">{{ $project->client_name ?: 'Bez nazwy klienta' }}</p>
                  <h3 class="mt-0.5 truncate font-semibold text-ink-950">
                    <a href="{{ $project->homeUrl() }}" class="after:absolute after:inset-0">{{ $project->name }}</a>
                  </h3>
                </div>
                <x-ui.badge :variant="$project->status->badge()">{{ $project->status->label() }}</x-ui.badge>
              </div>

              <div class="mt-4 flex flex-1 flex-col gap-3">
                @if ($hasContent && $stats && $stats['total'] > 0)
                  <x-ui.progress size="sm" :name="'Treści: ' . $project->name" label="Treści" :value="$stats['percent']" :hint="$stats['percent'] . '%'" />
                @endif
                @if ($hasReview && $rev)
                  <p class="flex items-center gap-2 text-sm text-ink-600">
                    @include('fillmein.partials.icon', ['name' => 'review', 'class' => 'size-4 text-ink-400'])
                    <span><span class="font-semibold text-ink-950">{{ $rev['open_threads'] }}</span> otwartych uwag</span>
                  </p>
                @endif
              </div>

              <div class="relative z-10 mt-4 flex items-center justify-between gap-2 border-t border-ink-100 pt-3">
                <div class="flex gap-1.5">
                  @if ($hasContent)
                    <a href="{{ route('fmi.projects.show', $project) }}" class="rounded-full border border-ink-150 px-2.5 py-0.5 text-xs font-medium text-ink-700 hover:border-ink-300 hover:text-ink-950">Treści</a>
                  @endif
                  @if ($hasReview)
                    <a href="{{ route('fmi.design-review.show', $project) }}" class="rounded-full border border-fresh-200 bg-fresh-50 px-2.5 py-0.5 text-xs font-medium text-ink-800 hover:border-fresh-400 hover:text-ink-950">Design Review</a>
                  @endif
                </div>
                <span class="text-xs text-ink-400">{{ $project->updated_at?->diffForHumans() }}</span>
              </div>
            </li>
          @endforeach
        </ul>
      @endif
    </section>
  @endforeach
@endsection
