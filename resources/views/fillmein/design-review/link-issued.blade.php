@extends('fillmein.layouts.app')

@section('title', 'Link recenzji dla klienta')

@section('content')
  <a href="{{ route('fmi.design-review.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Design Review" title="Link recenzji został utworzony" />

  <div class="mt-8 max-w-2xl">
    <x-ui.card>
      <div class="flex flex-col gap-4" x-data="{ copied: false, copy() { $refs.url.select(); navigator.clipboard?.writeText($refs.url.value); document.execCommand('copy'); this.copied = true; } }">
        <x-ui.alert variant="warning" title="Skopiuj go teraz">
          To jedyny moment, w którym widać pełny adres. W bazie jest tylko jego skrót, więc nie da się go odzyskać.
        </x-ui.alert>

        <x-ui.input name="review_url" label="Adres dla klienta" :value="$url" readonly x-ref="url" x-on:focus="$el.select()" />

        <div class="flex flex-wrap items-center justify-end gap-3">
          <p class="text-sm text-success-800" aria-live="polite" x-text="copied ? 'Skopiowano.' : ''"></p>
          <x-ui.button x-on:click="copy()">Kopiuj link</x-ui.button>
          <x-ui.button :href="route('fmi.design-review.show', $project)" variant="secondary">Wróć do recenzji</x-ui.button>
        </div>

        <ul class="flex flex-col gap-1 text-sm text-ink-600" data-issued-summary>
          <li>{{ $access->expires_at ? 'Ważny do ' . $access->expires_at->format('d.m.Y H:i') : 'Bez terminu ważności — działa, dopóki go nie unieważnisz' }}.</li>
          <li>Możliwości: {{ implode(', ', $access->reviewAbilities()) }}.</li>
          @if ($access->hasCode())
            <li>Wymaga kodu dostępu — przekaż go klientowi innym kanałem.</li>
          @endif
          <li>Link nie daje dostępu do pliku w Figmie: prototyp zobaczy osoba, której pozwalają na to ustawienia udostępniania pliku w Figmie.</li>
        </ul>
      </div>
    </x-ui.card>
  </div>
@endsection
