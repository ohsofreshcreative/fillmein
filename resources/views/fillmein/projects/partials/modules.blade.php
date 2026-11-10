{{-- Moduły wspólnego projektu. Dane: $selected (string[] wartości ProjectModule). --}}
@php($selected = old('modules_sent') ? (array) old('modules', []) : $selected)
<fieldset class="flex flex-col gap-3" @error('modules') aria-describedby="modules-error" @enderror>
  <legend class="text-sm font-medium text-ink-800">Moduły projektu</legend>
  <input type="hidden" name="modules_sent" value="1">
  @foreach (\App\Enums\ProjectModule::cases() as $module)
    <x-ui.checkbox
      name="modules[]"
      :value="$module->value"
      :label="$module->label()"
      :description="$module->description()"
      :checked="in_array($module->value, $selected, true)"
    />
  @endforeach
  @error('modules')
    <p id="modules-error" class="text-sm text-danger-700">{{ $message }}</p>
  @enderror
  <p class="text-sm text-ink-500">Ten sam projekt, klient i zespół w obu modułach. Wyłączenie modułu niczego nie usuwa.</p>
</fieldset>
