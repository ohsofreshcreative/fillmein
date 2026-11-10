{{-- Pasek postępu z wartością liczbową. $value: 0–100. $name: nazwa dla czytników, gdy brak widocznej etykiety. --}}
@props([
  'value' => 0,
  'label' => null,
  'name' => null,
  'hint' => null,
  'size' => 'md',
])

@php
  $percent = max(0, min(100, (int) $value));
@endphp

<div {{ $attributes->class(['flex flex-col gap-1.5']) }}>
  @if ($label || $hint)
    <div class="flex items-baseline justify-between gap-3">
      @if ($label)
        <span class="text-sm font-medium text-ink-800">{{ $label }}</span>
      @endif
      @if ($hint)
        <span data-progress-hint class="text-sm text-ink-500">{{ $hint }}</span>
      @endif
    </div>
  @endif

  <div
    role="progressbar"
    aria-valuenow="{{ $percent }}"
    aria-valuemin="0"
    aria-valuemax="100"
    aria-label="{{ $label ?? $name ?? 'Postęp wypełnienia' }}"
    @class([
      'w-full overflow-hidden rounded-full bg-ink-150',
      'h-1.5' => $size === 'sm',
      'h-2.5' => $size !== 'sm',
    ])
  >
    <div
      @class([
        'h-full rounded-full transition-[width] duration-300 ease-out-soft',
        'bg-ink-300' => $percent === 0,
        'bg-fresh-400' => $percent > 0 && $percent < 100,
        'bg-success-600' => $percent === 100,
      ])
      data-progress-fill
      style="width: {{ $percent }}%"
    ></div>
  </div>
</div>
