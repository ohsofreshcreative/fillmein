<?php

/**
 * Wpisy dziennika z testu HTTP: zdarzenia ustawień Figmy powstałe po znaczniku z manifestu etapu 03.
 */

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

$removed = App\Models\Activity::query()
	->where('id', '>', (int) ($manifest['activity_watermark'] ?? PHP_INT_MAX))
	->whereIn('event', ['figma.credentials_saved', 'figma.connected', 'figma.disconnected'])
	->delete();

fwrite(STDOUT, "Sprzątnięto wpisy dziennika Figmy: {$removed}.\n");
