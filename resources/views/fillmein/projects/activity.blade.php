@extends('fillmein.layouts.app')

@section('title', 'Aktywność: ' . $project->name)

@section('content')
  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Dziennik" title="Aktywność projektu" description="Synchronizacje z Figmą, zmiany struktury i zapisy treści." />

  <form method="GET" action="{{ route('fmi.projects.activity', $project) }}" class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    <x-ui.select name="typ" label="Typ" :options="array_map(fn ($area) => $area['label'], \App\Models\Activity::AREAS)" :value="$filters['typ']" placeholder="Wszystkie" />
    <x-ui.select name="aktor" label="Autor" :options="$actors" :value="$filters['aktor']" placeholder="Wszyscy" />
    <x-ui.select name="strona" label="Podstrona" :options="$pages" :value="$filters['strona']" placeholder="Wszystkie" />
    <x-ui.input name="od" label="Od" type="date" :value="$filters['od']" />
    <x-ui.input name="do" label="Do" type="date" :value="$filters['do']" />
    <div class="flex gap-2 sm:col-span-2 lg:col-span-5">
      <x-ui.button type="submit" variant="secondary" size="sm">Filtruj</x-ui.button>
      @if (array_filter($filters))
        <x-ui.button :href="route('fmi.projects.activity', $project)" variant="ghost" size="sm">Wyczyść</x-ui.button>
      @endif
    </div>
  </form>

  <x-ui.card class="mt-6">
    @include('fillmein.projects.partials.activity', ['activity' => $activity])
  </x-ui.card>

  <x-ui.pagination
    class="mt-6"
    :page="$page"
    :last-page="$lastPage"
    :total="$total"
    :url="fn ($number) => route('fmi.projects.activity', [$project, ...array_filter($filters), 'page' => $number])"
  />
@endsection
