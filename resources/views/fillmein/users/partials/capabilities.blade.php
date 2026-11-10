{{-- Dodatkowe uprawnienia ponad rolę (RoleManager::EXTRA_CAPABILITIES). --}}
<fieldset class="flex flex-col gap-3">
  <legend class="mb-1 text-sm font-medium text-ink-800">Dodatkowe uprawnienia</legend>
  @foreach ($extraCapabilities as $capability => $label)
    <x-ui.checkbox
      name="capabilities[]"
      :value="$capability"
      :label="$label"
      :checked="in_array($capability, old('capabilities', $granted ?? []), true)"
      :disabled="$disabled ?? false"
    />
  @endforeach
  <p class="text-sm text-ink-500">Administrator ma te uprawnienia zawsze, Project Manager — eksport. Zaznaczenie nadaje je niezależnie od roli.</p>
</fieldset>
