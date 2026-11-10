{{-- Wskaźnik ładowania. Rozmiary: sm, md, lg. --}}
@props(['size' => 'md', 'label' => 'Ładowanie…'])

<span role="status" {{ $attributes->class(['inline-flex']) }}>
  <svg @class([
    'animate-spin',
    'size-4' => $size === 'sm',
    'size-5' => $size === 'md',
    'size-8' => $size === 'lg',
  ]) viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity="0.2" stroke-width="3" />
    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
  </svg>
  <span class="sr-only">{{ $label }}</span>
</span>
