{{-- Pole tekstowe z etykietą, podpowiedzią i komunikatem błędu (automatycznie z $errors). --}}
@props([
  'name',
  'label' => null,
  'type' => 'text',
  'value' => null,
  'hint' => null,
  'error' => null,
  'required' => false,
  'bag' => 'default',
])

@php
  $id = $attributes->get('id', 'f-' . str_replace(['[', ']', '.'], '-', $name));
  $error ??= $errors->getBag($bag)->first(str_replace(['[', ']'], ['.', ''], $name));
  $describedBy = collect([$hint ? "{$id}-hint" : null, $error ? "{$id}-error" : null])->filter()->implode(' ');
@endphp

<div class="flex flex-col gap-1.5">
  @if ($label)
    <label for="{{ $id }}" class="text-sm font-medium text-ink-800">
      {{ $label }}
      @if ($required) <span class="text-danger-600" aria-hidden="true">*</span> @endif
    </label>
  @endif

  <input
    id="{{ $id }}"
    name="{{ $name }}"
    type="{{ $type }}"
    value="{{ old(str_replace(['[', ']'], ['.', ''], $name), $value) }}"
    @if ($required) required @endif
    @if ($error) aria-invalid="true" @endif
    @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
    {{ $attributes->except('id')->class([
      'h-10 w-full rounded-lg border bg-white px-3 text-base text-ink-900 shadow-xs transition duration-150',
      'placeholder:text-ink-400 focus:ring-focus disabled:cursor-not-allowed disabled:bg-ink-100 disabled:text-ink-500',
      'border-ink-200 hover:border-ink-300' => ! $error,
      'border-danger-600 focus:border-danger-600 focus:ring-danger-200' => $error,
    ]) }}
  >

  @if ($error)
    <p id="{{ $id }}-error" class="text-sm text-danger-700">{{ $error }}</p>
  @elseif ($hint)
    <p id="{{ $id }}-hint" class="text-sm text-ink-500">{{ $hint }}</p>
  @endif
</div>
