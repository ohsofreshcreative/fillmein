<?php

/**
 * Etap 12 — po sprzątaniu nie ma plików zaimportowanych załączników.
 */

$files = json_decode((string) file_get_contents('/tmp/fmi-stage12-meta.json'), true) ?: [];
$left = array_filter($files, 'file_exists');

fwrite(STDOUT, sprintf("  %s  Pliki załączników usunięte: %d z %d\n", $left ? 'FAIL' : 'PASS', count($files) - count($left), count($files)));

exit($left || ! $files ? 1 : 0);
