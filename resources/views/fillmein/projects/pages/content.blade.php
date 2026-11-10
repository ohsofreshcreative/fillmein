@extends('fillmein.layouts.app')

@section('title', 'Treść: ' . $page->name)

@section('content')
  <div x-data="clientLightbox" data-editor-scope="s{{ $project->id }}" data-editor-base="{{ route('fmi.projects.pages.content.edit', [$project, $page]) }}">
    <a href="{{ route('fmi.projects.pages.edit', [$project, $page]) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
      @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
      {{ $page->name }}
    </a>

    <x-ui.page-header eyebrow="Treść podstrony" :title="$page->name" description="Zmiany zapisują się automatycznie. Widzisz też pola ukryte przed klientem.">
      <x-slot:actions>
        <x-ui.button :href="route('fmi.projects.pages.preview', [$project, $page])" variant="secondary">Wróć do podglądu</x-ui.button>
      </x-slot:actions>
    </x-ui.page-header>

    <div class="mt-8 flex flex-col gap-6">
      <div class="grid gap-3 sm:grid-cols-2">
        <x-ui.progress label="Cały projekt" :value="$progress['project']['percent']" :hint="\App\Support\ProjectProgress::hint($progress['project'])" data-progress-key="project" />
        @php($pageStats = $progress['pages'][$page->id] ?? ['total' => 0, 'filled' => 0, 'percent' => 0])
        <x-ui.progress label="Ta podstrona" :value="$pageStats['percent']" :hint="\App\Support\ProjectProgress::hint($pageStats)" data-progress-key="page" />
      </div>

      @include('fillmein.client.partials.sections')
    </div>

    @include('fillmein.client.partials.lightbox')
  </div>
@endsection
