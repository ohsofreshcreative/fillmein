{{-- Lista wyboru z etykietą, podpowiedzią i błędem. $options: [wartość => etykieta]. --}}
@props([
  'name',
  'label' => null,
  'options' => [],
  'value' => null,
  'placeholder' => null,
  'hint' => null,
  'error' => null,
  'required' => false,
  'bag' => 'default',
])

@php
  $id = $attributes->get('id', 'f-' . str_replace(['[', ']', '.'], '-', $name));
  $error ??= $errors->getBag($bag)->first($name);
  $selected = (string) old($name, $value);
  $describedBy = collect([$hint ? "{$id}-hint" : null, $error ? "{$id}-error" : null])->filter()->implode(' ');
@endphp

<div class="flex flex-col gap-1.5">
  @if ($label)
    <label for="{{ $id }}" class="text-sm font-medium text-ink-800">
      {{ $label }}
      @if ($required) <span class="text-danger-600" aria-hidden="true">*</span> @endif
    </label>
  @endif

  <div class="relative">
    <select
      id="{{ $id }}"
      name="{{ $name }}"
      @if ($required) required @endif
      @if ($error) aria-invalid="true" @endif
      @if ($describedBy) aria-describedby="{{ $describedBy }}" @endif
      {{ $attributes->except('id')->class([
        'h-10 w-full appearance-none rounded-lg border bg-white pl-3 pr-9 text-base text-ink-900 shadow-xs transition duration-150',
        'focus:ring-focus disabled:cursor-not-allowed disabled:bg-ink-100 disabled:text-ink-500',
        'border-ink-200 hover:border-ink-300' => ! $error,
        'border-danger-600 focus:border-danger-600 focus:ring-danger-200' => $error,
      ]) }}
    >
      @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
      @endif
      @foreach ($options as $optionValue => $optionLabel)
        <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
      @endforeach
    </select>
    <svg class="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-ink-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" /></svg>
  </div>

  @if ($error)
    <p id="{{ $id }}-error" class="text-sm text-danger-700">{{ $error }}</p>
  @elseif ($hint)
    <p id="{{ $id }}-hint" class="text-sm text-ink-500">{{ $hint }}</p>
  @endif
</div>
