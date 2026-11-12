@extends('fillmein.layouts.client')

@section('title', $project->name)
@section('container', 'client-container')

@section('header-meta')
  <p class="truncate text-sm font-medium text-ink-900">{{ $project->name }}</p>
@endsection

@section('content')
  <div data-editor-scope="p{{ $project->id }}" x-data="clientLightbox">
  <x-ui.page-header
    eyebrow="Edytor treści"
    :title="$current?->name ?? $project->name"
    :description="$previews->isNotEmpty() ? 'Przy każdej sekcji widzisz jej projekt, a obok pola do uzupełnienia. Niczego nie musisz zapisywać, zmiany zachowują się same.' : 'Uzupełnij pola poniżej. Niczego nie musisz zapisywać, zmiany zachowują się same.'"
  />

  @if (session('status'))
    <x-ui.alert variant="success" dismissible class="mt-6">{{ session('status') }}</x-ui.alert>
  @endif
  @if (session('error'))
    <x-ui.alert variant="danger" dismissible class="mt-6">{{ session('error') }}</x-ui.alert>
  @endif

  @if (! $current)
    <x-ui.alert class="mt-8" title="Edytor jest w przygotowaniu">
      Podstrony do uzupełnienia pojawią się tu, gdy zespół OhSoFresh skończy konfigurację projektu.
    </x-ui.alert>
  @else
    <div class="mt-8 grid items-start gap-8 lg:grid-cols-[15rem_minmax(0,1fr)]">
      <nav aria-label="Podstrony" class="lg:sticky lg:top-24">
        <details class="rounded-2xl border border-ink-150 bg-white px-4 py-3 lg:hidden">
          <summary class="cursor-pointer text-sm font-medium text-ink-800">Podstrony</summary>
          <div class="mt-3">
            @include('fillmein.client.partials.nav', ['nodes' => $tree, 'depth' => 0])
          </div>
        </details>
        <div class="hidden lg:block">
          @include('fillmein.client.partials.nav', ['nodes' => $tree, 'depth' => 0])
        </div>
      </nav>

      <div class="flex min-w-0 flex-col gap-6">
        @include('fillmein.client.partials.page-actions')
        <div class="grid gap-3 sm:grid-cols-2">
          <x-ui.progress label="Cały projekt" :value="$progress['project']['percent']" :hint="\App\Support\ProjectProgress::hint($progress['project'])" data-progress-key="project" />
          @php($pageStats = $progress['pages'][$current->id] ?? ['total' => 0, 'filled' => 0, 'percent' => 0])
          <x-ui.progress label="Ta podstrona" :value="$pageStats['percent']" :hint="\App\Support\ProjectProgress::hint($pageStats)" data-progress-key="page" />
        </div>

        @include('fillmein.client.partials.sections')
      </div>
    </div>
  @endif

  @include('fillmein.client.partials.lightbox')
  </div>
@endsection
