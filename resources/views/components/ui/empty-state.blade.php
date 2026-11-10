{{-- Pusty stan listy. Slot opcjonalny: action. --}}
@props(['title', 'description' => null])

<div {{ $attributes->class(['flex flex-col items-center rounded-2xl border border-dashed border-ink-200 bg-white px-6 py-14 text-center']) }}>
  <div class="mb-4 flex size-11 items-center justify-center rounded-xl bg-fresh-100 text-fresh-800" aria-hidden="true">
    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
  </div>
  <p class="text-md font-semibold text-ink-950">{{ $title }}</p>
  @if ($description)
    <p class="mt-1 max-w-sm text-base text-ink-500">{{ $description }}</p>
  @endif
  @isset($action)
    <div class="mt-5">{{ $action }}</div>
  @endisset
</div>
