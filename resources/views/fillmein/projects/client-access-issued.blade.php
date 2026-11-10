@extends('fillmein.layouts.app')

@section('title', 'Link dla klienta')

@section('content')
  <a href="{{ route('fmi.projects.show', $project) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    {{ $project->name }}
  </a>

  <x-ui.page-header eyebrow="Link dla klienta" title="Link został wygenerowany" />

  <div class="mt-8 max-w-2xl">
    <x-ui.card>
      <div class="flex flex-col gap-4" x-data="{ copied: false, copy() { $refs.url.select(); navigator.clipboard?.writeText($refs.url.value); document.execCommand('copy'); this.copied = true; } }">
        <x-ui.alert variant="warning" title="Skopiuj go teraz">
          To jedyny moment, w którym widać pełny adres. W bazie jest tylko jego skrót, więc nie da się go odzyskać.
        </x-ui.alert>

        <x-ui.input name="client_url" label="Adres dla klienta" :value="$url" readonly x-ref="url" x-on:focus="$el.select()" />

        <div class="flex flex-wrap items-center justify-end gap-3">
          <p class="text-sm text-success-800" aria-live="polite" x-text="copied ? 'Skopiowano.' : ''"></p>
          <x-ui.button x-on:click="copy()">Kopiuj link</x-ui.button>
          <x-ui.button :href="route('fmi.projects.show', $project)" variant="secondary">Wróć do projektu</x-ui.button>
        </div>

        <p class="text-sm text-ink-500">
          {{ $access->expires_at ? 'Ważny do ' . $access->expires_at->format('d.m.Y H:i') . '.' : 'Bez terminu ważności — działa, dopóki go nie unieważnisz.' }}
        </p>
      </div>
    </x-ui.card>
  </div>
@endsection
