{{-- Znak Fill Me In: pole tekstowe z kursorem. --}}
@props(['withName' => true])

<span {{ $attributes->class(['inline-flex items-center gap-2.5']) }}>
  <span class="flex size-8 items-center justify-center rounded-lg bg-fresh-400 shadow-xs" aria-hidden="true">
    <svg class="size-4 text-ink-950" viewBox="0 0 16 16" fill="none">
      <rect x="2" y="4" width="7" height="2" rx="1" fill="currentColor" opacity="0.45" />
      <rect x="2" y="10" width="5" height="2" rx="1" fill="currentColor" opacity="0.45" />
      <rect x="11.25" y="2" width="1.75" height="12" rx="0.875" fill="currentColor" />
    </svg>
  </span>
  @if ($withName)
    <span class="text-md font-semibold tracking-tight text-ink-950">Fill Me In</span>
  @endif
</span>
