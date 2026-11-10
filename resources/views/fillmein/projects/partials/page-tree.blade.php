{{--
  Rekurencyjne drzewo podstron. Dane: $nodes (App\Models\Page::tree), $project, $depth, $canManage, $pageProgress,
  $queueUrls (adresy kolejki Figmy wg ID podstrony), $canImport (masowa „Analiza i import pól”). Status kolejki: figmaQueue.
  Hierarchia jest oddana semantycznie zagnieżdżonymi listami, nie samym wcięciem.
--}}
@php($depth = $depth ?? 0)

<ul @class(['flex flex-col gap-2', 'mt-2 border-l border-ink-150 pl-4' => $depth > 0])>
  @foreach ($nodes as $node)
    @php($page = $node['page'])
    @php($stats = $pageProgress[$page->id] ?? ['total' => 0, 'filled' => 0, 'percent' => 0])
    <li>
      <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink-150 bg-white px-4 py-3" data-page-row="{{ $page->id }}">
        <div class="flex min-w-0 items-start gap-3">
        @if (($canImport ?? false) && isset(($queueUrls ?? [])[$page->id]['import']))
          <input
            type="checkbox"
            value="{{ $page->id }}"
            x-model="selected"
            class="mt-1 size-4 shrink-0 cursor-pointer rounded accent-ink-900"
            aria-label="Zaznacz do analizy i importu pól: {{ $page->name }}"
            data-figma-bulk-page
          >
        @endif
        <div class="min-w-0">
          <p class="flex flex-wrap items-center gap-2 font-medium text-ink-950">
            {{ $page->name }}
            @if ($page->client_can_duplicate)
              <x-ui.badge variant="fresh">Klient może duplikować</x-ui.badge>
            @endif
            @if ($page->duplicated_from_id)
              <x-ui.badge variant="muted">Duplikat</x-ui.badge>
            @endif
          </p>
          <p class="mt-0.5 truncate text-sm text-ink-500">
            <span class="font-mono text-xs">/{{ $page->slug }}</span>
            · {{ $page->source->label() }}
            @if ($page->figma_frame_url)
              ·
              <a href="{{ $page->figma_frame_url }}" target="_blank" rel="noopener noreferrer" class="underline decoration-ink-300 underline-offset-2 hover:decoration-ink-900">
                frame Figmy
              </a>
            @else
              · <span class="text-warning-800">bez frame'u Figmy</span>
            @endif
          </p>
          @if (isset(($queueUrls ?? [])[$page->id]))
            <p x-show="status[{{ $page->id }}]" x-cloak class="mt-1 text-sm" role="status" aria-live="polite" data-figma-status="{{ $page->id }}">
              <span
                x-bind:class="{ 'text-success-800': status[{{ $page->id }}]?.state === 'done', 'text-warning-800': status[{{ $page->id }}]?.state === 'warning', 'text-danger-700': status[{{ $page->id }}]?.state === 'error', 'text-ink-500': ['queued', 'running'].includes(status[{{ $page->id }}]?.state) }"
                x-text="label({{ $page->id }})"
              ></span>
            </p>
          @endif
        </div>
        </div>

        <x-ui.progress
          class="w-full sm:w-44"
          size="sm"
          :name="'Wypełnienie podstrony: ' . $page->name"
          :value="$stats['percent']"
          :hint="\App\Support\ProjectProgress::hint($stats)"
        />

        <div class="flex shrink-0 flex-wrap items-center gap-2">
          <x-ui.button :href="route('fmi.projects.pages.preview', [$project, $page])" variant="fresh" size="sm" :aria-label="'Podgląd podstrony: ' . $page->name">
            Podgląd
          </x-ui.button>
        @if ($canManage)
            <x-ui.button :href="route('fmi.projects.pages.edit', [$project, $page])" variant="ghost" size="sm" :aria-label="'Edytuj podstronę: ' . $page->name">
              Edytuj
            </x-ui.button>

            <x-ui.confirm
              :action="route('fmi.projects.pages.duplicate', [$project, $page])"
              title="Duplikować podstronę?"
              confirm="Duplikuj"
              trigger="Duplikuj"
              variant="primary"
              trigger-variant="ghost"
              trigger-size="sm"
            >
              Powstanie nowa podstrona „{{ $page->name }} (kopia)” z kopią sekcji i pól.
              Treść klienta <strong>nie</strong> jest kopiowana, a duplikat ma własne, niezależne wartości.
            </x-ui.confirm>

            <x-ui.confirm
              :action="route('fmi.projects.pages.archive', [$project, $page])"
              title="Zarchiwizować podstronę?"
              confirm="Archiwizuj"
              trigger="Archiwizuj"
              trigger-variant="ghost"
              trigger-size="sm"
            >
              Podstrona „{{ $page->name }}” i jej podstrony podrzędne znikną z edytora klienta.
              Definicje i zapisana treść zostają w bazie — można je przywrócić.
            </x-ui.confirm>
        @endif
        </div>
      </div>

      @if ($node['children'] !== [])
        @include('fillmein.projects.partials.page-tree', [
          'nodes' => $node['children'],
          'project' => $project,
          'depth' => $depth + 1,
          'canManage' => $canManage,
          'queueUrls' => $queueUrls ?? [],
          'canImport' => $canImport ?? false,
        ])
      @endif
    </li>
  @endforeach
</ul>
