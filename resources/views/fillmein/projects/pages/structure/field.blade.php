@extends('fillmein.layouts.app')

@php
  $settings = $field?->settings ?? [];
  $settingInputs = [
    'max_length' => ['label' => 'Limit znaków', 'type' => 'number', 'min' => 1, 'max' => 100000],
    'placeholder' => ['label' => 'Tekst zastępczy', 'type' => 'text', 'maxlength' => 190],
    'min_width' => ['label' => 'Minimalna szerokość (px)', 'type' => 'number', 'min' => 1, 'max' => 20000],
    'min_height' => ['label' => 'Minimalna wysokość (px)', 'type' => 'number', 'min' => 1, 'max' => 20000],
    'min_items' => ['label' => 'Minimalna liczba elementów', 'type' => 'number', 'min' => 0, 'max' => config('fillmein.fields.max_items')],
    'max_items' => ['label' => 'Maksymalna liczba elementów', 'type' => 'number', 'min' => 1, 'max' => config('fillmein.fields.max_items')],
    'require_alt' => ['label' => 'Wymagaj tekstu alternatywnego', 'type' => 'checkbox'],
  ];
  $title = $field ? 'Pole: ' . $field->label : 'Nowe pole';
@endphp

@section('title', $title)

@section('content')
  <a href="{{ route('fmi.projects.pages.structure', [$project, $page]) }}" class="mb-6 inline-flex items-center gap-1.5 text-sm font-medium text-ink-500 transition hover:text-ink-950">
    @include('fillmein.partials.icon', ['name' => 'arrow-left', 'class' => 'size-4'])
    Sekcje i pola
  </a>

  <x-ui.page-header :eyebrow="'Sekcja: ' . $section->name" :title="$title" :description="$parent ? 'Pole podrzędne: ' . $parent->label : null" />

  <form
    method="POST"
    action="{{ $field ? route('fmi.projects.pages.fields.update', [$project, $page, $field]) : route('fmi.projects.pages.sections.fields.store', [$project, $page, $section]) }}"
    class="mt-8 max-w-2xl"
    x-data="{ type: @js(old('type', $field?->type->value ?? 'text')) }"
    data-loading-form
  >
    @csrf
    @if (! $field && $parent)
      <input type="hidden" name="parent_id" value="{{ $parent->id }}">
    @endif

    <x-ui.card>
      <div class="flex flex-col gap-5">
        @if ($errors->hasAny(['parent_id', 'section']))
          <x-ui.alert variant="danger">{{ $errors->first('parent_id') ?: $errors->first('section') }}</x-ui.alert>
        @endif

        @if (session('confirm_type_change'))
          <x-ui.alert variant="warning" title="Zmiana typu usunie treść">
            Zapisana treść tego pola zostanie wyczyszczona i zostanie tylko w historii zmian.
          </x-ui.alert>
          <x-ui.checkbox name="confirm_type_change" label="Rozumiem, zmień typ i wyczyść treść" />
        @endif

        <x-ui.input name="label" label="Etykieta" :value="$field?->label" required maxlength="190" />

        <x-ui.input
          name="name"
          label="Klucz"
          :value="$field?->name"
          maxlength="60"
          hint="Małe litery, cyfry i podkreślenia. Unikalny na tym poziomie. Puste pole: klucz powstanie z etykiety."
        />

        <x-ui.select name="type" label="Typ pola" :options="$types" :value="$field?->type->value ?? 'text'" required x-model="type" />

        <x-ui.textarea name="instructions" label="Podpowiedź dla klienta" :value="$field?->instructions" rows="3" maxlength="2000" />

        <x-ui.checkbox name="is_required" label="Pole wymagane" :checked="(bool) old('is_required', $field?->is_required)" />
        <x-ui.checkbox name="is_hidden" label="Ukryj pole przed klientem" :checked="(bool) old('is_hidden', $field?->is_hidden)" />

        @foreach ($settingInputs as $key => $input)
          <div x-show="@js($settingTypes[$key]).includes(type)">
            @if ($input['type'] === 'checkbox')
              <x-ui.checkbox :name="'settings[' . $key . ']'" :label="$input['label']" :checked="(bool) ($settings[$key] ?? false)" />
            @else
              <x-ui.input
                :name="'settings[' . $key . ']'"
                :label="$input['label']"
                :value="$settings[$key] ?? null"
                :type="$input['type']"
                :min="$input['min'] ?? null"
                :max="$input['max'] ?? null"
                :maxlength="$input['maxlength'] ?? null"
              />
            @endif
          </div>
        @endforeach
      </div>

      <x-slot:footer>
        <div class="flex justify-end gap-2">
          <x-ui.button :href="route('fmi.projects.pages.structure', [$project, $page])" variant="secondary">Anuluj</x-ui.button>
          <x-ui.button type="submit">{{ $field ? 'Zapisz pole' : 'Dodaj pole' }}</x-ui.button>
        </div>
      </x-slot:footer>
    </x-ui.card>
  </form>
@endsection
