{{-- Frame Figmy podstrony: stan cache, metadane i podglądy (z prywatnego dysku przez trasę z kontrolą dostępu). --}}
@php
  $framePreview = $figmaPreviews->firstWhere('node_id', $figmaFrame?->node_id);
  $sectionPreviews = $figmaPreviews->reject(fn ($preview) => $preview->node_id === $figmaFrame?->node_id);
  $canFetch = $page->figma_node_id && $figmaConfigured;
@endphp

<x-ui.card>
  <x-slot:header>
    <h2 class="text-md font-semibold">Frame z Figmy</h2>
    @if ($figmaFrame)
      <x-ui.badge :variant="$figmaFrame->isFresh() ? 'success' : 'muted'">
        {{ $figmaFrame->isFresh() ? 'Aktualny' : 'Do odświeżenia' }}
      </x-ui.badge>
    @else
      <x-ui.badge variant="muted">Nie pobrano</x-ui.badge>
    @endif
  </x-slot:header>

  <div class="flex flex-col gap-5">
    @if (! $page->figma_node_id)
      <p class="text-base text-ink-600">Dodaj link do frame'u Figmy (z parametrem node-id) i zapisz podstronę, aby pobrać jej strukturę i podglądy.</p>
    @elseif (! $figmaConfigured)
      <x-ui.alert variant="warning" title="Brak tokenu Figma API">
        Pobieranie z Figmy jest wyłączone.
        @can('manage-integrations')
          <a href="{{ route('fmi.settings.figma') }}" class="font-medium underline">Połącz Figmę w ustawieniach</a>.
        @else
          Poproś administratora o połączenie Figmy w ustawieniach.
        @endcan
      </x-ui.alert>
    @endif

    @if ($figmaFrame)
      <dl class="grid gap-x-6 gap-y-3 text-base sm:grid-cols-2">
        <div class="flex justify-between gap-3">
          <dt class="text-sm text-ink-500">Frame</dt>
          <dd class="truncate font-medium">{{ $figmaFrame->node_name ?? $figmaFrame->node_id }}</dd>
        </div>
        <div class="flex justify-between gap-3">
          <dt class="text-sm text-ink-500">Typ warstwy</dt>
          <dd class="font-mono text-xs">{{ $figmaFrame->node_type }}</dd>
        </div>
        @if ($figmaFrame->width && $figmaFrame->height)
          <div class="flex justify-between gap-3">
            <dt class="text-sm text-ink-500">Wymiary</dt>
            <dd class="font-medium">{{ $figmaFrame->width }} × {{ $figmaFrame->height }} px</dd>
          </div>
        @endif
        <div class="flex justify-between gap-3">
          <dt class="text-sm text-ink-500">Warstwy w cache</dt>
          <dd class="font-medium">{{ $figmaFrame->nodes_count }}</dd>
        </div>
        @if ($figmaFrame->file_name)
          <div class="flex justify-between gap-3">
            <dt class="text-sm text-ink-500">Plik</dt>
            <dd class="truncate font-medium">{{ $figmaFrame->file_name }}</dd>
          </div>
        @endif
        @if ($figmaFrame->file_version)
          <div class="flex justify-between gap-3">
            <dt class="text-sm text-ink-500">Wersja pliku</dt>
            <dd class="font-mono text-xs">{{ $figmaFrame->file_version }}</dd>
          </div>
        @endif
        @if ($figmaFrame->file_last_modified)
          <div class="flex justify-between gap-3">
            <dt class="text-sm text-ink-500">Zmiana w Figmie</dt>
            <dd class="font-medium">{{ $figmaFrame->file_last_modified->timezone(wp_timezone())->format('d.m.Y H:i') }}</dd>
          </div>
        @endif
        <div class="flex justify-between gap-3">
          <dt class="text-sm text-ink-500">Pobrano</dt>
          <dd class="font-medium">{{ $figmaFrame->fetched_at?->timezone(wp_timezone())->format('d.m.Y H:i') }}</dd>
        </div>
      </dl>

      <div class="flex flex-col gap-3 rounded-xl border border-fresh-300 bg-fresh-50 p-4 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-base text-ink-700">Przeanalizuj warstwy frame'u, sprawdź proponowane sekcje i pola, a potem zatwierdź import.</p>
        <x-ui.button :href="route('fmi.projects.pages.figma.import', [$project, $page])" variant="primary" size="sm">Analiza i import pól</x-ui.button>
      </div>

      @if ($framePreview && $framePreview->path)
        <figure class="flex flex-col gap-2">
          {{-- Wysoki frame przewija się w ramce — region musi być osiągalny z klawiatury (WCAG 2.1.1). --}}
          <div
            class="max-h-96 overflow-auto rounded-xl border border-ink-150 bg-ink-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900"
            tabindex="0"
            role="region"
            aria-label="Podgląd całego frame'u (przewijany)"
          >
            <img
              src="{{ route('fmi.projects.pages.figma.preview', [$project, $page, \App\Integrations\Figma\FigmaUrl::encodeNodeId($framePreview->node_id)]) }}"
              alt="Podgląd frame'u {{ $figmaFrame->node_name ?? $figmaFrame->node_id }}"
              width="{{ $framePreview->width }}"
              height="{{ $framePreview->height }}"
              loading="lazy"
              class="h-auto w-full"
            >
          </div>
          <figcaption class="text-sm text-ink-500">Podgląd całego frame'u</figcaption>
        </figure>
      @endif

      @if ($sectionPreviews->isNotEmpty())
        <div class="flex flex-col gap-3">
          <h3 class="text-base font-semibold">Warstwy najwyższego poziomu ({{ $sectionPreviews->count() }})</h3>
          <ul class="grid gap-3 sm:grid-cols-2" role="list">
            @foreach ($sectionPreviews as $preview)
              <li class="flex flex-col gap-2 rounded-xl border border-ink-150 p-3">
                @if ($preview->path)
                  <div class="flex h-32 items-center justify-center overflow-hidden rounded-lg bg-ink-50">
                    <img
                      src="{{ route('fmi.projects.pages.figma.preview', [$project, $page, \App\Integrations\Figma\FigmaUrl::encodeNodeId($preview->node_id)]) }}"
                      alt="Podgląd warstwy {{ $preview->node_name ?? $preview->node_id }}"
                      width="{{ $preview->width }}"
                      height="{{ $preview->height }}"
                      loading="lazy"
                      class="max-h-32 w-auto object-contain"
                    >
                  </div>
                @else
                  <div class="flex h-32 items-center justify-center rounded-lg bg-ink-50 text-sm text-ink-500">Brak podglądu</div>
                @endif
                <p class="truncate text-sm font-medium">{{ $preview->node_name ?? $preview->node_id }}</p>
              </li>
            @endforeach
          </ul>
        </div>
      @endif
    @endif
  </div>

  @if ($page->figma_node_id)
    <x-slot:footer>
      <form method="POST" action="{{ route('fmi.projects.pages.figma.fetch', [$project, $page]) }}" class="flex flex-wrap items-center justify-end gap-3" data-loading-form>
        @csrf
        @if ($figmaFrame)
          <input type="hidden" name="force" value="1">
        @endif
        <p class="mr-auto text-sm text-ink-500">Pobierany jest tylko ten frame, nie cały plik.</p>
        <x-ui.button type="submit" variant="secondary" size="sm" :disabled="! $canFetch">
          {{ $figmaFrame ? 'Odśwież z Figmy' : 'Pobierz z Figmy' }}
        </x-ui.button>
      </form>
    </x-slot:footer>
  @endif
</x-ui.card>
