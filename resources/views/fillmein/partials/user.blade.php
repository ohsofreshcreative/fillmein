{{-- Osoba (WP_User|null) jako awatar z inicjałem i nazwą. --}}
@if ($person)
  <span class="inline-flex min-w-0 items-center gap-2">
    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-ink-150 text-xs font-semibold text-ink-700" aria-hidden="true">
      {{ mb_strtoupper(mb_substr($person->display_name, 0, 1)) }}
    </span>
    <span class="truncate">{{ $person->display_name }}</span>
  </span>
@else
  <span class="text-ink-500">—</span>
@endif
