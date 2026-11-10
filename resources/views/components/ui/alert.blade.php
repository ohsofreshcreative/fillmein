{{-- Komunikat. Warianty: info, success, warning, danger. --}}
@props([
  'variant' => 'info',
  'title' => null,
  'dismissible' => false,
])

@php
  $styles = match ($variant) {
    'success' => ['box' => 'border-success-200 bg-success-50 text-success-800', 'icon' => 'text-success-600'],
    'warning' => ['box' => 'border-warning-200 bg-warning-50 text-warning-800', 'icon' => 'text-warning-600'],
    'danger' => ['box' => 'border-danger-200 bg-danger-50 text-danger-800', 'icon' => 'text-danger-600'],
    default => ['box' => 'border-info-200 bg-info-50 text-info-800', 'icon' => 'text-info-600'],
  };
@endphp

<div
  role="{{ $variant === 'danger' ? 'alert' : 'status' }}"
  @if ($dismissible) x-data="{ open: true }" x-show="open" x-transition.opacity @endif
  {{ $attributes->class(['flex animate-rise-in items-start gap-3 rounded-xl border px-4 py-3', $styles['box']]) }}
>
  <svg class="mt-0.5 size-4 shrink-0 {{ $styles['icon'] }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
    @if ($variant === 'success')
      <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.86-9.81a.75.75 0 0 0-1.22-.88l-3.24 4.5-1.6-1.6a.75.75 0 1 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.09l3.73-5.24Z" clip-rule="evenodd" />
    @elseif ($variant === 'info')
      <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.25v2.75a.75.75 0 0 0 1.5 0V9.75A.75.75 0 0 0 10 9H9Z" clip-rule="evenodd" />
    @else
      <path fill-rule="evenodd" d="M8.49 2.87c.66-1.16 2.36-1.16 3.02 0l6.28 11.03c.65 1.15-.18 2.6-1.51 2.6H3.72c-1.33 0-2.16-1.45-1.5-2.6L8.48 2.87ZM10 6.75a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-1.5 0v-3a.75.75 0 0 1 .75-.75Zm0 7.75a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd" />
    @endif
  </svg>

  <div class="min-w-0 flex-1 text-sm">
    @if ($title)
      <p class="font-semibold">{{ $title }}</p>
    @endif
    <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
  </div>

  @if ($dismissible)
    <button type="button" x-on:click="open = false" class="-m-1 rounded-md p-1 opacity-70 transition hover:opacity-100" aria-label="Zamknij komunikat">
      <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
    </button>
  @endif
</div>
