{{--
  Nagłówek wspólny dla ekranów projektu: powrót do listy, nazwa i zakładki modułów (Treści / Design Review) + Ustawienia.
  Dane: $project, $current ('content' | 'review'). Zakładka wyłączonego modułu jest pokazana wyszarzona z informacją.
--}}
@php
  $tabs = [
    'content' => ['label' => 'Treści', 'icon' => 'folder', 'url' => route('fmi.projects.show', $project), 'on' => $project->usesModule(\App\Enums\ProjectModule::Content)],
    'review' => ['label' => 'Design Review', 'icon' => 'review', 'url' => route('fmi.design-review.show', $project), 'on' => $project->usesModule(\App\Enums\ProjectModule::Review)],
  ];
  $canUpdate = \Illuminate\Support\Facades\Gate::allows('update', $project);
@endphp

<a href="{{ route('fmi.projects.index') }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
  @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
  Projekty
</a>

<x-ui.page-header :eyebrow="$project->client_name ?: 'Projekt'" :title="$project->name">
  <x-slot:actions>
    <x-ui.badge :variant="$project->status->badge()">{{ $project->status->label() }}</x-ui.badge>
    @if ($canUpdate)
      <x-ui.button :href="route('fmi.projects.edit', $project)" variant="secondary" size="sm">Ustawienia</x-ui.button>
    @endif
  </x-slot:actions>
</x-ui.page-header>

<nav class="mt-6 flex gap-1 overflow-x-auto border-b border-ink-150" aria-label="Moduły projektu">
  @foreach ($tabs as $key => $tab)
    @if ($tab['on'])
      <a
        href="{{ $tab['url'] }}"
        @if ($current === $key) aria-current="page" @endif
        @class([
          '-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-medium whitespace-nowrap transition',
          'border-ink-950 text-ink-950' => $current === $key,
          'border-transparent text-ink-500 hover:border-ink-300 hover:text-ink-950' => $current !== $key,
        ])
      >
        @include('fillmein.partials.icon', ['name' => $tab['icon'], 'class' => 'size-4'])
        {{ $tab['label'] }}
      </a>
    @elseif ($canUpdate)
      <a href="{{ route('fmi.projects.edit', $project) }}" class="-mb-px inline-flex items-center gap-2 border-b-2 border-transparent px-4 py-2.5 text-sm font-medium whitespace-nowrap text-ink-400 transition hover:text-ink-700" title="Moduł wyłączony — włączysz go w ustawieniach projektu">
        @include('fillmein.partials.icon', ['name' => $tab['icon'], 'class' => 'size-4'])
        {{ $tab['label'] }}
        <span class="rounded-full bg-ink-100 px-2 py-0.5 text-xs text-ink-500">+ włącz</span>
      </a>
    @endif
  @endforeach
</nav>
