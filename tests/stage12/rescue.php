<?php

/**
 * Etap 12 — sprzątanie awaryjne po przerwanym teście: usuwa wyłącznie elementy źródła tego przebiegu.
 */

use App\Services\Import\ImportCleanup;

$state = json_decode((string) @file_get_contents('/tmp/fmi-stage12-state.json'), true);

if (! empty($state['source'])) {
	$counts = app(ImportCleanup::class)->apply($state['source']);
	fwrite(STDOUT, 'Sprzątanie awaryjne: ' . array_sum($counts) . " elementów.\n");
}
