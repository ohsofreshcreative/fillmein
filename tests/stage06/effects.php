<?php

/**
 * Etap 06 — skutki żądań HTTP w bazie (po tests/stage06/http.sh).
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Models\Activity;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\Section;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) @file_get_contents('/tmp/fmi-stage06-ids.json'), true);
$pageA = (int) $manifest['pages']['a'];

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$t('Odrzucone żądania nic nie zapisały: elementy projektu B bez zmian, brak „Hack”', function () use ($ids, $same) {
	$same('Sekcja obca', Section::query()->findOrFail($ids['foreign_section'])->name, 'Sekcja B');
	$same('Pole obce', Field::query()->findOrFail($ids['foreign_field'])->label, 'Pole B');
	$same(null, Section::query()->findOrFail($ids['foreign_section'])->archived_at, 'Sekcja B poza archiwum');
	$same(0, Section::query()->where('name', 'Hack')->count() + Field::query()->where('label', 'Hack')->count(), 'Brak elementów z prób');
});

$t('Formularze zapisały sekcje i pola jako manual', function () use ($pageA, $ids, $same, $ok) {
	$section = Section::query()->where('page_id', $pageA)->where('name', 'Sekcja HTTP <b>')->firstOrFail();
	$same(ElementSource::Manual, $section->source, 'Źródło sekcji');

	$field = Field::query()->where('section_id', $ids['first'])->where('label', 'Pole HTTP')->firstOrFail();
	$same(ElementSource::Manual, $field->source, 'Źródło pola');
	$same(['max_length' => 80], $field->settings, 'Ustawienia');

	$child = Field::query()->where('label', 'Podrzedne HTTP')->firstOrFail();
	$same((int) $ids['repeater'], (int) $child->parent_id, 'Rodzic');
	$same(1, (int) $child->depth, 'Głębokość');

	$text = Field::query()->findOrFail($ids['text']);
	$same('Nagłówek po zmianie', $text->label, 'Edycja pola');
	$same(true, $text->is_required, 'Wymagane');
});

$t('Potwierdzona zmiana typu: treść wyczyszczona, zachowana w rewizji', function () use ($ids, $same, $ok) {
	$field = Field::query()->findOrFail($ids['with_content']);
	$same(FieldType::Image, $field->type, 'Typ');

	$value = FieldValue::query()->where('field_id', $field->id)->firstOrFail();
	$same(null, $value->value, 'Wartość');
	$ok(FieldRevision::query()->where('field_value_id', $value->id)->where('value', 'Treść testowa')->exists(), 'Rewizja');
});

$t('Kolejność, archiwizacja i przywrócenie', function () use ($pageA, $ids, $same, $ok) {
	$sections = Section::query()->where('page_id', $pageA)->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();
	$same([(int) $ids['second'], (int) $ids['first']], array_slice($sections, 0, 2), 'Sekcje');

	$fields = Field::query()->where('section_id', $ids['first'])->whereNull('parent_id')->orderBy('position')->pluck('id')->map(fn ($id) => (int) $id)->all();
	$same([(int) $ids['other'], (int) $ids['text']], array_slice($fields, 0, 2), 'Pola');

	$ok(Field::query()->findOrFail($ids['other'])->archived_at === null, 'Pole przywrócone');
	$ok(Section::query()->findOrFail($ids['second'])->archived_at === null, 'Sekcja przywrócona');
});

$t('Dziennik: wpisy operacji z tego przebiegu', function () use ($manifest, $ok) {
	$events = Activity::query()->where('id', '>', (int) $manifest['activity_watermark'])->where('project_id', $manifest['projects']['a'])->pluck('event');

	foreach (['section.created', 'section.updated', 'section.archived', 'section.restored', 'section.reordered', 'field.created', 'field.updated', 'field.archived', 'field.restored', 'field.reordered'] as $event) {
		$ok($events->contains($event), "Wpis {$event}");
	}
});

exit(Harness::summary('ETAP 06 — skutki HTTP'));
