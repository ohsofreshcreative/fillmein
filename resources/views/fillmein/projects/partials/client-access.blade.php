{{-- Linki klienta projektu. Dane: $project, $clientAccesses. Adres aktywnego linku jest stale widoczny (kopia tokenu zaszyfrowana SecretBoxem). --}}
{{-- Link generujemy raz i bez terminu: przy aktywnym linku zostaje tylko „Wygeneruj od nowa” (unieważnia poprzedni). --}}
@php($hasActive = $clientAccesses->contains(fn ($access) => $access->isActive()))

<x-ui.card id="linki-klienta">
  <x-slot:header>
    <div>
      <h2 class="text-md font-semibold">Linki dla klienta</h2>
      <p class="mt-0.5 text-sm text-ink-500">Jeden link na projekt. Działa bez terminu, dopóki go nie unieważnisz.</p>
    </div>
  </x-slot:header>

  @if ($clientAccesses->isEmpty())
    <p class="text-base text-ink-600">Ten projekt nie ma jeszcze linku dla klienta.</p>
  @else
    <ul class="flex flex-col gap-2">
      @foreach ($clientAccesses as $access)
        @php([$statusLabel, $statusVariant] = $access->statusBadge())
        <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-ink-150 px-4 py-3">
          <div class="min-w-0">
            <p class="flex flex-wrap items-center gap-2 font-medium text-ink-950">
              {{ $access->label ?: 'Link #' . $access->id }}
              <x-ui.badge :variant="$statusVariant">{{ $statusLabel }}</x-ui.badge>
            </p>
            <p class="mt-0.5 text-sm text-ink-500">
              Utworzono {{ $access->created_at?->format('d.m.Y H:i') }}
              · ważny {{ $access->expires_at ? 'do ' . $access->expires_at->format('d.m.Y H:i') : 'bez terminu' }}
              · ostatnie użycie: {{ $access->last_used_at?->format('d.m.Y H:i') ?? 'brak' }}
            </p>
          </div>

          @if ($access->isActive() && ($accessUrl = $access->contentUrl()))
            <div class="flex w-full flex-col gap-2 sm:flex-row sm:items-center" x-data="{ copied: false, copy() { $refs.url.select(); navigator.clipboard?.writeText($refs.url.value); document.execCommand('copy'); this.copied = true; setTimeout(() => this.copied = false, 2000); } }" data-client-access-url>
              <label class="sr-only" for="client-url-{{ $access->id }}">Adres dla klienta</label>
              <input id="client-url-{{ $access->id }}" type="text" value="{{ $accessUrl }}" readonly x-ref="url" x-on:focus="$el.select()" class="h-10 w-full min-w-0 flex-1 rounded-lg border border-ink-200 bg-ink-50 px-3 text-sm text-ink-900 focus:ring-focus">
              <div class="flex shrink-0 items-center gap-2">
                <x-ui.button size="sm" x-on:click="copy()"><span x-text="copied ? 'Skopiowano' : 'Kopiuj link'">Kopiuj link</span></x-ui.button>
                <x-ui.button size="sm" variant="secondary" :href="$accessUrl" target="_blank" rel="noopener noreferrer">Otwórz</x-ui.button>
              </div>
            </div>
          @elseif ($access->isActive())
            <p class="w-full text-sm text-ink-500">Adres tego linku nie jest zapisany (link sprzed zmiany). Wygeneruj go od nowa, żeby był tu widoczny.</p>
          @endif

          @if ($access->isActive())
            <x-ui.confirm
              :action="route('fmi.projects.client-access.revoke', [$project, $access])"
              title="Unieważnić link?"
              confirm="Unieważnij"
              trigger="Unieważnij"
              trigger-variant="ghost"
              trigger-size="sm"
              :aria-label="'Unieważnij link: ' . ($access->label ?: '#' . $access->id)"
            >
              Klient straci dostęp przez ten link. Zapisana treść zostaje.
            </x-ui.confirm>
          @endif
        </li>
      @endforeach
    </ul>
  @endif

  @unless ($hasActive)
    <form method="POST" action="{{ route('fmi.projects.client-access.store', $project) }}" class="mt-5 flex flex-col gap-4 border-t border-ink-150 pt-5" data-loading-form data-client-access-form>
      @csrf
      <x-ui.input name="label" label="Opis linku" maxlength="190" hint="Opcjonalnie, np. imię osoby po stronie klienta." />

      <div class="flex justify-end">
        <x-ui.button type="submit">Wygeneruj link</x-ui.button>
      </div>
    </form>
  @else
    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-ink-150 pt-5">
      <p class="text-sm text-ink-500">Nowy link zastąpi obecny — poprzedni przestanie działać.</p>
      <x-ui.confirm
        :action="route('fmi.projects.client-access.regenerate', $project)"
        title="Wygenerować link od nowa?"
        confirm="Unieważnij i wygeneruj"
        trigger="Wygeneruj od nowa"
        trigger-variant="secondary"
        trigger-size="sm"
      >
        Aktywny link tego projektu przestanie działać, a powstanie nowy, bez terminu ważności. Zapisana treść zostaje.
      </x-ui.confirm>
    </div>
  @endunless
</x-ui.card>
