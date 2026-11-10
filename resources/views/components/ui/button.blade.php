{{-- Przycisk / link w stylu przycisku. Warianty: primary, secondary, ghost, danger, danger-ghost, fresh. Rozmiary: sm, md, lg. --}}
@props([
  'variant' => 'primary',
  'size' => 'md',
  'href' => null,
  'type' => 'button',
  'loading' => false,
])

@php
  $classes = collect([
    'group/btn relative inline-flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg font-medium',
    'transition duration-150 ease-out-soft select-none',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900',
    'disabled:pointer-events-none disabled:opacity-50 aria-disabled:pointer-events-none aria-disabled:opacity-50',
    'data-[loading=true]:text-transparent',
    match ($size) {
      'sm' => 'h-8 px-3 text-sm',
      'lg' => 'h-12 px-6 text-md',
      default => 'h-10 px-4 text-base',
    },
    match ($variant) {
      'secondary' => 'border border-ink-200 bg-white text-ink-900 shadow-xs hover:border-ink-300 hover:bg-ink-50',
      'ghost' => 'text-ink-700 hover:bg-ink-100 hover:text-ink-950',
      'danger-ghost' => 'text-danger-700 hover:bg-danger-50',
      'danger' => 'bg-danger-600 text-white shadow-xs hover:bg-danger-700',
      'fresh' => 'bg-fresh-400 text-ink-950 shadow-xs hover:bg-fresh-500',
      default => 'bg-ink-900 text-white shadow-xs hover:bg-ink-800 active:bg-ink-950',
    },
  ])->implode(' ');
@endphp

@if ($href)
  <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
  <button
    type="{{ $type }}"
    @if ($loading) disabled aria-busy="true" data-loading="true" @endif
    {{ $attributes->class($classes) }}
  >
    {{ $slot }}
    <span class="pointer-events-none absolute inset-0 hidden items-center justify-center group-data-[loading=true]/btn:flex @if ($variant === 'secondary' || $variant === 'ghost') text-ink-900 @else text-white @endif">
      <x-ui.spinner size="sm" />
    </span>
  </button>
@endif
