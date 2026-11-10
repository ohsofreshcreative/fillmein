@extends('fillmein.layouts.app')

@section('title', 'Pulpit')

@section('content')
  <x-ui.page-header
    eyebrow="Pulpit"
    :title="'Cześć, ' . ($user->first_name ?: $user->display_name)"
    description="Przegląd projektów, w których bierzesz udział."
  >
    <x-slot:actions>
      @if ($canCreate)
        <x-ui.button :href="route('fmi.projects.create')">Nowy projekt</x-ui.button>
      @endif
      <x-ui.button :href="route('fmi.projects.index')" variant="secondary">Wszystkie projekty</x-ui.button>
    </x-slot:actions>
  </x-ui.page-header>

  <section class="mt-10" aria-labelledby="stats-heading">
    <h2 id="stats-heading" class="sr-only">Projekty według statusu</h2>
    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      @foreach ($statuses as $item)
        <div class="animate-rise-in rounded-2xl border border-ink-150 bg-white px-5 py-4 shadow-xs">
          <dt class="text-sm text-ink-500">{{ $item['status']->label() }}</dt>
          <dd class="mt-1 text-2xl font-semibold tracking-tighter text-ink-950">{{ $item['total'] }}</dd>
        </div>
      @endforeach
    </dl>
  </section>

  <section class="mt-6" aria-labelledby="totals-heading">
    <h2 id="totals-heading" class="sr-only">Podsumowanie</h2>
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-3">
      <div class="rounded-2xl border border-ink-150 bg-white px-5 py-4 shadow-xs">
        <dt class="text-sm text-ink-500">Projekty z dostępem</dt>
        <dd class="mt-1 text-xl font-semibold text-ink-950">{{ $totals['projects'] }}</dd>
      </div>
      <div class="rounded-2xl border border-ink-150 bg-white px-5 py-4 shadow-xs">
        <dt class="text-sm text-ink-500">Aktywne podstrony</dt>
        <dd class="mt-1 text-xl font-semibold text-ink-950">{{ $totals['pages'] }}</dd>
      </div>
      <div class="rounded-2xl border border-ink-150 bg-white px-5 py-4 shadow-xs">
        <dt class="text-sm text-ink-500">Aktywne linki klientów</dt>
        <dd class="mt-1 text-xl font-semibold text-ink-950">{{ $totals['clientLinks'] }}</dd>
      </div>
    </dl>
  </section>

  <section class="mt-10" aria-labelledby="mine-heading">
    <h2 id="mine-heading" class="mb-4 text-lg font-semibold">Moje projekty</h2>

    @if ($mine->isEmpty())
      <x-ui.empty-state
        title="Nie prowadzisz żadnego projektu"
        description="Tutaj pojawią się projekty, w których jesteś autorem lub opiekunem."
      />
    @else
      @include('fillmein.projects.partials.list', ['projects' => $mine, 'progress' => $progress])
    @endif
  </section>

  <section class="mt-10" aria-labelledby="recent-heading">
    <h2 id="recent-heading" class="mb-4 text-lg font-semibold">Ostatnio aktualizowane</h2>

    @if ($recent->isEmpty())
      <x-ui.empty-state title="Brak projektów" description="Projekty, do których zostaniesz przypisany, pojawią się tutaj." />
    @else
      @include('fillmein.projects.partials.list', ['projects' => $recent, 'progress' => $progress])
    @endif
  </section>
@endsection
