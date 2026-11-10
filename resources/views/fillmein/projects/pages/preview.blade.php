@extends('fillmein.layouts.app')

@section('title', 'Podgląd: ' . $page->name)

@section('content')
  @php($pageStats = $progress['pages'][$page->id] ?? ['total' => 0, 'filled' => 0, 'percent' => 0])

  <div data-page-preview x-data="clientLightbox">
    <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-900">
      @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
      {{ $project->name }}
    </a>

    <x-ui.page-header eyebrow="Podgląd podstrony" :title="$page->name" description="Projekt każdej sekcji, a obok to, co klient już wypełnił i czego jeszcze brakuje.">
      <x-slot:actions>
        <x-ui.button :href="route('fmi.projects.review', [$project, 'strona' => $page->id])" variant="secondary">Uwagi do treści</x-ui.button>
        @if ($canEdit)
          <x-ui.button :href="route('fmi.projects.pages.content.edit', [$project, $page])">Edytuj treść</x-ui.button>
        @endif
      </x-slot:actions>
    </x-ui.page-header>

    <div class="mt-8 flex flex-col gap-6" x-data="{ onlyMissing: false }">
      <x-ui.progress label="Ta podstrona" :value="$pageStats['percent']" :hint="\App\Support\ProjectProgress::hint($pageStats)" />

      <div class="sticky top-14 z-20 flex flex-col gap-3 border-b border-ink-150 bg-ink-50/95 py-3 backdrop-blur">
        @include('fillmein.client.partials.section-nav')
        <x-ui.checkbox name="only_missing" label="Pokaż tylko to, czego brakuje" x-model="onlyMissing" />
      </div>

      @forelse ($structure->sections as $section)
        @php($sectionName = \App\Support\ClientLabels::section($section, $structure))
        @php($preview = $previews[$section->id] ?? null)
        @php($sectionStats = $progress['sections'][$section->id] ?? null)
        <section id="sekcja-{{ $section->id }}" aria-labelledby="sekcja-{{ $section->id }}-tytul" class="scroll-mt-44">
          <x-ui.card :clip="false">
            <x-slot:header>
              <div class="flex min-w-0 items-center gap-3">
                <span aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-full bg-fresh-400 text-base font-semibold text-ink-950">{{ $loop->iteration }}</span>
                <div class="min-w-0">
                  <p class="text-sm text-ink-500">Sekcja {{ $loop->iteration }} z {{ $loop->count }}</p>
                  <h2 id="sekcja-{{ $section->id }}-tytul" class="text-md font-semibold">{{ $sectionName }}</h2>
                </div>
              </div>
              <div class="flex shrink-0 flex-wrap items-center gap-4">
                @if ($sectionStats && $sectionStats['total'] > 0)
                  <x-ui.progress class="w-32 shrink-0 sm:w-48" size="sm" :name="'Wypełnienie sekcji ' . $sectionName" :value="$sectionStats['percent']" :hint="\App\Support\ProjectProgress::hint($sectionStats)" />
                @endif
                @if ($canEdit)
                  <x-ui.button
                    :href="route('fmi.projects.pages.content.edit', [$project, $page]) . '#sekcja-' . $section->id"
                    variant="secondary"
                    size="sm"
                    :aria-label="'Edytuj sekcję: ' . $sectionName"
                  >Edytuj sekcję</x-ui.button>
                @endif
              </div>
            </x-slot:header>

            <div @class(['grid items-start gap-6 xl:grid-cols-2 xl:gap-8' => $preview])>
              @if ($preview)
                @include('fillmein.client.partials.section-preview', ['url' => $previewUrl($section, $preview)])
              @endif

              <div class="flex min-w-0 flex-col gap-4">
                @forelse ($structure->children($section) as $field)
                  @include('fillmein.projects.pages.partials.preview-field', ['row' => null])
                @empty
                  <p class="text-sm text-ink-500">Ta sekcja nie ma pól do uzupełnienia.</p>
                @endforelse
              </div>
            </div>
          </x-ui.card>
        </section>
      @empty
        <x-ui.empty-state title="Tu jeszcze nic nie ma" description="Ta podstrona nie ma pól do uzupełnienia." />
      @endforelse

      <nav aria-label="Kolejne podstrony" class="flex flex-wrap justify-between gap-3">
        @if ($previous)
          <x-ui.button :href="route('fmi.projects.pages.preview', [$project, $previous])" variant="secondary">← {{ $previous->name }}</x-ui.button>
        @endif
        @if ($next)
          <x-ui.button class="ml-auto" :href="route('fmi.projects.pages.preview', [$project, $next])" variant="fresh">{{ $next->name }} →</x-ui.button>
        @endif
      </nav>
    </div>

    @include('fillmein.client.partials.lightbox')
  </div>
@endsection
