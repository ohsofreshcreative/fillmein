{{--
  Potwierdzenie niebezpiecznej operacji: przycisk otwiera natywne okno <dialog> (fokus i Escape
  obsługuje przeglądarka), a dopiero przycisk w oknie wysyła formularz POST z tokenem CSRF.
--}}
@props([
  'action',
  'title',
  'confirm' => 'Potwierdź',
  'trigger' => null,
  'variant' => 'danger',
  'triggerVariant' => 'secondary',
  'triggerSize' => 'md',
  'formId' => null,
])

<div x-data class="contents">
  <x-ui.button :variant="$triggerVariant" :size="$triggerSize" x-on:click="$refs.dialog.showModal()" {{ $attributes->only(['class', 'disabled', 'aria-label']) }}>
    {{ $trigger ?? $confirm }}
  </x-ui.button>

  <dialog
    x-ref="dialog"
    aria-labelledby="{{ $dialogId = 'dlg-' . \Illuminate\Support\Str::random(8) }}"
    class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border border-ink-150 bg-white p-0 text-ink-900 shadow-lg backdrop:bg-ink-950/40 open:animate-rise-in"
  >
    <form @if ($formId) id="{{ $formId }}" @endif method="POST" action="{{ $action }}" data-loading-form class="p-6">
      @csrf
      <h2 id="{{ $dialogId }}" class="text-lg font-semibold tracking-tight">{{ $title }}</h2>
      <div class="mt-2 text-base text-ink-600">{{ $slot }}</div>
      <div class="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <x-ui.button variant="secondary" x-on:click="$refs.dialog.close()">Anuluj</x-ui.button>
        <x-ui.button type="submit" :variant="$variant">{{ $confirm }}</x-ui.button>
      </div>
    </form>
  </dialog>
</div>
