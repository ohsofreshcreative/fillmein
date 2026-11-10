{{-- Pole wyboru z etykietą i opcjonalnym opisem. --}}
@props([
  'name',
  'label',
  'value' => '1',
  'checked' => false,
  'description' => null,
])

@php
  $id = $attributes->get('id', 'f-' . str_replace(['[', ']', '.'], '-', $name) . '-' . \Illuminate\Support\Str::slug((string) $value));
@endphp

<div class="flex items-start gap-3">
  <input
    id="{{ $id }}"
    type="checkbox"
    name="{{ $name }}"
    value="{{ $value }}"
    @checked($checked)
    @if ($description) aria-describedby="{{ $id }}-desc" @endif
    {{ $attributes->except('id')->class([
      'mt-0.5 size-4 shrink-0 cursor-pointer rounded accent-ink-900',
      'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ink-900 disabled:cursor-not-allowed disabled:opacity-50',
    ]) }}
  >
  <div class="min-w-0">
    <label for="{{ $id }}" class="cursor-pointer text-base text-ink-900">{{ $label }}</label>
    @if ($description)
      <p id="{{ $id }}-desc" class="text-sm text-ink-500">{{ $description }}</p>
    @endif
  </div>
</div>
