<?php

/**
 * Etap 14 — plan macierzy: każda trasa `fmi.*` z routera (nie z ręcznej listy, więc nowa trasa nie umknie).
 * Kolumny TSV: nazwa, metoda, strefa, ścieżka właściwa, ścieżka IDOR (`-` gdy trasa nie ma obcego elementu).
 * Tryb `hash`: skrót treści projektów, żeby odmowy dało się potwierdzić także brakiem zapisu.
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$ids = json_decode((string) file_get_contents('/tmp/fmi-stage14-ids.json'), true);
$mode = $args[0] ?? 'plan';

if ($mode === 'hash') {
	$rows = [];

	foreach (['fmi_sections', 'fmi_fields', 'fmi_field_values', 'fmi_field_revisions', 'fmi_repeater_rows', 'fmi_assets', 'fmi_pages'] as $table) {
		$rows[$table] = DB::table($table)->whereIn('project_id', [$ids['a']['project'], $ids['b']['project']])->orderBy('id')->get();
	}

	$rows['projects'] = DB::table('fmi_projects')->whereIn('id', [$ids['a']['project'], $ids['b']['project']])->orderBy('id')->get();
	fwrite(STDOUT, md5(json_encode($rows)) . "\n");

	return;
}

$fill = function (string $uri, array $context, string $token) use ($ids) {
	return preg_replace_callback('/\{(\w+)\}/', function ($match) use ($context, $token, $ids) {
		return match ($match[1]) {
			'token' => $token,
			'format' => 'json',
			'variant' => 'original',
			'node' => '1-2',
			'staffUser' => (string) $ids['staff_user'],
			default => (string) $context[$match[1]],
		};
	}, $uri);
};

$lines = [];

foreach (Route::getRoutes() as $route) {
	$name = (string) $route->getName();

	if (! str_starts_with($name, 'fmi.')) {
		continue;
	}

	$uri = $route->uri();
	$zone = match (true) {
		str_starts_with($uri, 'p/') => 'client',
		str_starts_with($uri, 'r/'), $uri === 'review', str_starts_with($uri, 'review/') => 'review',
		in_array('fmi.app:guest', $route->gatherMiddleware(), true) => 'guest',
		str_starts_with($name, 'fmi.users.'), str_starts_with($name, 'fmi.settings.') => 'users',
		default => 'staff',
	};
	$params = array_values(array_diff($route->parameterNames(), ['token', 'format', 'variant', 'node', 'staffUser']));
	$own = $fill($uri, $ids['a'], $ids['tokens']['a']);
	$foreign = '-';

	// Obcy jest tylko ostatni element: reszta ścieżki należy do projektu A, więc odmowa dowodzi kontroli zakresu.
	if ($zone === 'staff' && $params !== [] && end($params) !== 'project') {
		$last = end($params);
		$foreign = $fill(str_replace('{' . $last . '}', (string) $ids['b'][$last], $uri), $ids['a'], '');
	} elseif ($zone === 'client' && $params !== []) {
		$last = end($params);
		$foreign = $fill(str_replace('{' . $last . '}', (string) $ids['b'][$last], $uri), $ids['a'], $ids['tokens']['a']);
	}

	foreach ($route->methods() as $method) {
		if ($method !== 'HEAD') {
			$lines[] = implode("\t", [$name, $method, $zone, '/' . ltrim($own, '/'), $foreign === '-' ? '-' : '/' . ltrim($foreign, '/')]);
		}
	}
}

// Zapisy niszczące na końcu: najpierw elementy głębsze, projekt ostatni, wylogowanie po wszystkim.
$rank = function (string $line) {
	$name = strtok($line, "\t");

	return match (true) {
		str_ends_with($name, 'logout') => 9,
		str_ends_with($name, 'projects.destroy') => 8,
		str_ends_with($name, 'projects.pages.archive') => 7,
		str_contains($name, 'sections.archive') => 6,
		str_contains($name, 'fields.archive') => 5,
		str_contains($name, 'client-access') => 4,
		default => 0,
	};
};

usort($lines, fn ($a, $b) => $rank($a) <=> $rank($b) ?: strcmp($a, $b));
file_put_contents('/tmp/fmi-stage14-plan.tsv', implode("\n", $lines) . "\n");
chmod('/tmp/fmi-stage14-plan.tsv', 0600);
fwrite(STDOUT, sprintf("Plan macierzy: %d tras.\n", count($lines)));
