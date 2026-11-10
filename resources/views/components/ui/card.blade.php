{{-- Karta-kontener. Sloty opcjonalne: header, footer. `clip=false`, gdy w środku jest element sticky. --}}
@props(['padding' => true, 'clip' => true])

<div {{ $attributes->class(['rounded-2xl border border-ink-150 bg-white shadow-xs', 'overflow-hidden' => $clip]) }}>
  @isset($header)
    <div class="flex flex-wrap items-center justify-between gap-4 border-b border-ink-150 px-5 py-4 sm:px-6">
      {{ $header }}
    </div>
  @endisset

  <div @class(['px-5 py-5 sm:px-6' => $padding])>
    {{ $slot }}
  </div>

  @isset($footer)
    <div class="border-t border-ink-150 bg-ink-50 px-5 py-3 sm:px-6">
      {{ $footer }}
    </div>
  @endisset
</div>
