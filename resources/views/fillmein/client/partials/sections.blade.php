{{-- Status zapisu i sekcje podstrony, wspólne dla edytora klienta i panelu. Dane: $structure, $progress, $historyUrl, opcjonalnie $previews i $previewUrl (podglądy z Figmy) oraz $highlights (prostokąty pól na podglądzie). --}}
@php($previews ??= collect())
@php($highlights ??= [])
@php($notes ??= null)
<div class="sticky top-16 z-20 flex items-center gap-3 border-b border-ink-150 bg-ink-50/95 py-3 backdrop-blur">
  @include('fillmein.client.partials.section-nav')
  <p
    role="status"
    aria-live="polite"
    x-data
    x-show="$store.saveStatus.message"
    x-cloak
    x-text="$store.saveStatus.message"
    x-bind:class="['conflict', 'error'].includes($store.saveStatus.state) ? 'text-danger-700' : 'text-success-800'"
    class="ml-auto shrink-0 rounded-full border border-ink-150 bg-white px-3 py-1 text-sm font-medium shadow-xs"
  ></p>
</div>

@forelse ($structure->sections as $section)
  @php($sectionName = \App\Support\ClientLabels::section($section, $structure))
  @php($preview = $previews[$section->id] ?? null)
  @php($rects = $preview ? ($highlights[$section->id] ?? []) : [])
  {{-- Podświetlenie pola na podglądzie: fokus w polu → prostokąt jego warstwy z Figmy (najbliższe pole nadrzędne, gdy własnej warstwy brak). --}}
  <section
    id="sekcja-{{ $section->id }}"
    aria-labelledby="sekcja-{{ $section->id }}-tytul"
    class="scroll-mt-44"
    @if ($rects)
      x-data="{ hlRects: @js((object) $rects), hlRect: null, hlPick(event) { let el = event.target.closest('[data-field-id]'); while (el) { const rect = this.hlRects[el.dataset.fieldId]; if (rect) { this.hlRect = rect; return; } el = el.parentElement?.closest('[data-field-id]'); } this.hlRect = null; } }"
      x-on:focusin="hlPick($event)"
      x-on:focusout="if (! $el.contains($event.relatedTarget)) hlRect = null"
    @endif
  >
    <x-ui.card :clip="false">
      <x-slot:header>
        <div class="flex min-w-0 items-center gap-3">
          <span aria-hidden="true" class="flex size-10 shrink-0 items-center justify-center rounded-full bg-fresh-400 text-base font-semibold text-ink-950">{{ $loop->iteration }}</span>
          <div class="min-w-0">
            <p class="text-sm text-ink-500">Sekcja {{ $loop->iteration }} z {{ $loop->count }}</p>
            <h2 id="sekcja-{{ $section->id }}-tytul" class="text-md font-semibold">{{ $sectionName }}</h2>
          </div>
        </div>
        @php($sectionStats = $progress['sections'][$section->id] ?? null)
        @if ($sectionStats && $sectionStats['total'] > 0)
          <x-ui.progress class="w-32 shrink-0 sm:w-48" size="sm" :name="'Postęp sekcji ' . $sectionName" :value="$sectionStats['percent']" :data-progress-key="'section-' . $section->id" />
        @endif
      </x-slot:header>

      <div @class(['grid items-start gap-6 xl:grid-cols-2 xl:gap-8' => $preview])>
        @if ($preview)
          @include('fillmein.client.partials.section-preview', ['url' => $previewUrl($section, $preview), 'highlight' => (bool) $rects])
        @endif

        <div class="flex min-w-0 flex-col gap-6">
          @forelse ($structure->children($section) as $field)
            @include('fillmein.client.partials.field', ['row' => null])
          @empty
            <p class="text-sm text-ink-500">Ta sekcja nie ma pól do uzupełnienia.</p>
          @endforelse

          @if ($notes !== null)
            @include('fillmein.client.partials.section-note', ['note' => $notes[$section->id] ?? null])
          @endif
        </div>
      </div>
    </x-ui.card>
  </section>
@empty
  <x-ui.empty-state title="Tu jeszcze nic nie ma" description="Ta podstrona nie ma pól do uzupełnienia." />
@endforelse
