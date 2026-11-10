{{-- Nagłówek strony aplikacji. Slot opcjonalny: actions. --}}
@props(['title', 'description' => null, 'eyebrow' => null])

<header {{ $attributes->class(['flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
  <div class="min-w-0">
    @if ($eyebrow)
      <p class="mb-1 text-sm font-medium text-ink-500">{{ $eyebrow }}</p>
    @endif
    <h1 class="text-2xl font-semibold tracking-tighter">{{ $title }}</h1>
    @if ($description)
      <p class="mt-1.5 max-w-2xl text-md text-ink-600">{{ $description }}</p>
    @endif
  </div>

  @isset($actions)
    <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
  @endisset
</header>
