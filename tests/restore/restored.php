<?php

/**
 * Sprawdzenia na bazie odtworzonej w tymczasowym serwerze MySQL (bez sieci, tylko socket, root bez hasła).
 * Argumenty: socket, nazwa bazy, prefiks tabel, ścieżka pliku wynikowego JSON.
 */

require __DIR__ . '/checks.php';

[, $socket, $database, $prefix, $out] = $argv + [null, '', '', '', ''];

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli('localhost', 'root', '', $database, 0, $socket);

$result = fmi_restore_checks(fn (string $sql) => $db->query($sql)->fetch_all(MYSQLI_ASSOC), $prefix);
$result['prefix'] = $prefix;

file_put_contents($out, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
