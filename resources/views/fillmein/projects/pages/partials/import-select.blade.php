{{-- Wybór zmiany w podglądzie synchronizacji. Pole należy do formularza `import-form`. Dane: $value, $action, $name. --}}
@if ($value && ! in_array($action, ['unchanged', 'skipped', 'keep'], true))
  <x-ui.checkbox
    name="selected[]"
    :value="$value"
    :id="'change-' . md5($value)"
    form="import-form"
    label="Zastosuj"
    :checked="true"
    :aria-label="'Zastosuj zmianę: ' . $name"
  />
@endif
