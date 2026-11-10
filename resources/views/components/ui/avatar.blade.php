{{-- Inicjał osoby (WP_User). --}}
@props(['person', 'size' => 'md'])

<span {{ $attributes->class([
  'flex shrink-0 items-center justify-center rounded-full font-semibold',
  'size-6 text-xs bg-ink-150 text-ink-700' => $size === 'sm',
  'size-9 text-sm bg-ink-150 text-ink-700' => $size === 'md',
  'size-14 text-lg bg-ink-900 text-white' => $size === 'lg',
]) }} aria-hidden="true">{{ mb_strtoupper(mb_substr($person->first_name ?: $person->display_name, 0, 1)) }}</span>
