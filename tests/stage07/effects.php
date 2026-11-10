<?php

/**
 * Etap 07 — skutki żądań w bazie i przeszukanie miejsc, gdzie token nie może się pojawić:
 * tabela linków, dziennik, wartości, pliki sesji, debug.log i logi aplikacji. Tokeny nie są wypisywane.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Models\Activity;
use App\Models\Field;
use App\Models\FieldRevision;
use App\Models\FieldValue;
use App\Models\RepeaterRow;
use Illuminate\Support\Facades\DB;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) @file_get_contents('/tmp/fmi-stage07-ids.json'), true);
$newToken = trim((string) @file_get_contents('/tmp/fmi-stage07-ids.json.new'));
@unlink('/tmp/fmi-stage07-ids.json.new');
$tokens = array_values(array_filter($ids['tokens'] + ['new' => $newToken]));
$field = fn (string $name) => Field::query()->findOrFail($ids['fields'][$name]);
$value = fn (string $name, string $row = '') => FieldValue::query()->where('field_id', $ids['fields'][$name])->where('row_key', $row)->first();

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];

$leaks = function (string $text) use ($tokens): int {
	return count(array_filter($tokens, fn ($token) => str_contains($text, $token)));
};

$t('Zapisy klienta: wartości, aktor i rewizje', function () use ($value, $ids, $same, $ok) {
	$same('Tytuł klienta', $value('text')->value, 'Tekst');
	$same('Opis', $value('textarea')->value, 'Textarea');
	$same('<p><strong>Mocno</strong></p>x', $value('wysiwyg')->value, 'WYSIWYG');
	$same(['label' => 'Więcej', 'url' => 'https://example.com/x'], json_decode($value('link')->value, true), 'Link');
	$same('Karta', $value('row_field', $ids['rows']['a'])->value, 'Wartość wiersza');
	$same('client', $value('text')->updated_by_type?->value, 'Aktor');
	$ok(FieldRevision::query()->where('field_value_id', $value('text')->id)->exists(), 'Rewizja');
});

$t('Odrzucone zapisy nic nie zmieniły', function () use ($ids, $same) {
	foreach (['hidden', 'archived', 'child_of_archived', 'in_archived_section', 'in_hidden_section', 'in_archived_page', 'foreign', 'foreign_row_field', 'image', 'gallery'] as $name) {
		$same(0, FieldValue::query()->where('field_id', $ids['fields'][$name])->count(), "Bez wartości: {$name}");
	}

	$same(1, RepeaterRow::query()->where('field_id', $ids['fields']['foreign_repeater'])->count(), 'Wiersze B bez zmian');
	$same('Tytuł klienta', FieldValue::query()->where('field_id', $ids['fields']['text'])->value('value'), 'Treść po odrzuconych zapisach');
});

$t('Klient nie zmienił struktury', function () use ($field, $same) {
	$same('Nagłówek', $field('text')->label, 'Etykieta');
	$same('text', $field('text')->type->value, 'Typ');
});

$t('Wiersze: lista po operacjach, usunięty wiersz miękko', function () use ($value, $ids, $same, $ok) {
	$list = json_decode($value('repeater')->value, true);

	$same(2, count($list), 'Dwa wiersze');
	$same($ids['rows']['a'], $list[1], 'Kolejność');
	$same(1, RepeaterRow::onlyTrashed()->where('field_id', $ids['fields']['repeater'])->count(), 'Jeden w koszu');
});

$t('Dziennik: wpisy klienta i panelu', function () use ($manifest, $ok) {
	$events = Activity::query()->where('id', '>', (int) $manifest['activity_watermark'])->where('project_id', $manifest['projects']['a'])->pluck('event');

	foreach (['content.updated', 'content.row_added', 'content.row_removed', 'content.rows_reordered', 'client_access.generated', 'client_access.revoked'] as $event) {
		$ok($events->contains($event), "Wpis {$event}");
	}
});

$t('fmi_client_accesses: tylko hashe', function () use ($manifest, $leaks, $same, $ok) {
	$rows = DB::table('fmi_client_accesses')->whereIn('project_id', array_values($manifest['projects']))->get();

	$ok($rows->count() >= 6, 'Wiersze testowe');
	$same(0, $leaks($rows->toJson()), 'Tokeny w tabeli');

	foreach ($rows as $row) {
		$ok((bool) preg_match('/^[a-f0-9]{64}$/', $row->token_hash), 'Format hasha');
	}
});

$t('fmi_activity_log: brak tokenu', function () use ($manifest, $leaks, $same) {
	$same(0, $leaks(Activity::query()->where('id', '>', (int) $manifest['activity_watermark'])->get()->toJson()), 'Tokeny w dzienniku');
});

$t('Pliki sesji zmienione w tym przebiegu: brak tokenu', function () use ($leaks, $same, $ok) {
	$dir = (string) config('session.files');
	$since = time() - 900;
	$checked = 0;
	$found = 0;

	foreach (glob($dir . '/*') ?: [] as $file) {
		if (is_file($file) && filemtime($file) >= $since) {
			$checked++;
			$found += $leaks((string) file_get_contents($file));
		}
	}

	$ok($checked > 0, 'Przeszukano pliki sesji');
	$same(0, $found, 'Tokeny w sesjach');
});

$t('debug.log i logi aplikacji: brak tokenu', function () use ($leaks, $same) {
	$found = 0;
	$files = array_merge([WP_CONTENT_DIR . '/debug.log'], glob(storage_path('logs') . '/*.log') ?: []);

	foreach ($files as $file) {
		if (is_file($file) && filesize($file) < 200 * 1024 * 1024) {
			$found += $leaks((string) file_get_contents($file));
		}
	}

	$same(0, $found, 'Tokeny w logach');
});

exit(Harness::summary('ETAP 07 — skutki i wycieki'));
