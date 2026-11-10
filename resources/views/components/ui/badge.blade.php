{{-- Etykieta statusu. Warianty: neutral, fresh, success, warning, danger, muted. --}}
@props(['variant' => 'neutral'])

<span {{ $attributes->class([
  'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset',
  match ($variant) {
    'fresh' => 'bg-fresh-100 text-fresh-900 ring-fresh-300',
    'success' => 'bg-success-50 text-success-800 ring-success-200',
    'warning' => 'bg-warning-50 text-warning-800 ring-warning-200',
    'danger' => 'bg-danger-50 text-danger-800 ring-danger-200',
    'muted' => 'bg-ink-100 text-ink-500 ring-ink-200',
    default => 'bg-white text-ink-700 ring-ink-200',
  },
]) }}>
  <span class="size-1.5 rounded-full bg-current opacity-70" aria-hidden="true"></span>
  {{ $slot }}
</span>
