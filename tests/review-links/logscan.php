<?php

/**
 * Liczba wpisów dziennika aktywności (całość) zawierających któryś token albo jego skrót SHA-256.
 */

use App\Models\Activity;

$ids = json_decode((string) file_get_contents('/tmp/fmi-review-links.json'), true);
$needles = [];
foreach ($ids['tokens'] as $token) {
	$needles[] = $token;
	$needles[] = hash('sha256', $token);
}
$count = 0;
Activity::query()->select(['id', 'properties'])->orderBy('id')->chunk(500, function ($rows) use ($needles, &$count) {
	foreach ($rows as $row) {
		$text = json_encode($row->properties);
		foreach ($needles as $needle) {
			if (str_contains((string) $text, $needle)) {
				$count++;
				break;
			}
		}
	}
});
echo $count;
