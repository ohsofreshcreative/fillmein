<?php

/**
 * Osadzenie prototypu — dane testowe w manifeście etapu 03 (sprzątanie: tests/stage03/teardown.php, kaskady).
 * pub: publiczny prototyp z dokumentacji Figmy (Embed Kit 2.0 examples); live: plik z FMI_FIGMA_LIVE_URL jako prototyp
 * (dostęp ograniczony); bad: zapisany niepoprawny link; none: bez linku. Linku `live` nie wypisujemy.
 * Wynik: /tmp/fmi-review-embed.json (id projektów, czy jest client-id — bez jego wartości).
 */

use App\Enums\ReviewRevisionStatus;
use App\Integrations\Figma\FigmaConnection;
use App\Integrations\Figma\FigmaUrl;
use App\Models\ReviewRevision;
use App\Services\ProjectService;
use App\Services\ReviewService;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
wp_set_current_user($admin->ID);
$projects = app(ProjectService::class);
$reviews = app(ReviewService::class);
$make = fn (string $key) => $projects->create(['name' => 'TEST osadzenie ' . $key . ' ' . $manifest['suffix'], 'manager_id' => (int) $manifest['users']['manager'], 'modules' => ['review']], $admin);

$out = ['live' => null];
$pub = $make('pub');
$reviews->savePrototype($pub, 'https://www.figma.com/proto/nrPSsILSYjesyc5UHjYYa4/Embed-Kit-2.0-examples?node-id=5-3&starting-point-node-id=5%3A3', $admin);
$manifest['projects']['embed_pub'] = $out['pub'] = $pub->id;

$liveUrl = (string) getenv('FMI_FIGMA_LIVE_URL');
if (($key = FigmaUrl::fileKey($liveUrl)) !== null) {
	$live = $make('live');
	$node = FigmaUrl::nodeId($liveUrl);
	$reviews->savePrototype($live, 'https://www.figma.com/proto/' . $key . '/Test' . ($node ? '?node-id=' . str_replace(':', '-', $node) : ''), $admin);
	$manifest['projects']['embed_live'] = $out['live'] = $live->id;
}

// Klucz w poprawnym formacie, ale bez pliku/dostępu — Figma pokazuje własny ekran (nieudostępniony prototyp).
$denied = $make('denied');
$reviews->savePrototype($denied, 'https://www.figma.com/proto/ZZtestNoAccess0000000000/Brak-dostepu?node-id=1-2', $admin);
$manifest['projects']['embed_denied'] = $out['denied'] = $denied->id;

$bad = $make('bad');
ReviewRevision::create(['project_id' => $bad->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => ReviewRevisionStatus::Draft, 'figma_url' => 'https://evil.example/proto/abcdefghij12/x', 'created_by' => $admin->ID]);
$manifest['projects']['embed_bad'] = $out['bad'] = $bad->id;
$none = $make('none');
$manifest['projects']['embed_none'] = $out['none'] = $none->id;

$out['client_id'] = preg_match('/^[A-Za-z0-9]{8,64}$/', app(FigmaConnection::class)->status()['client_id']) === 1;
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents('/tmp/fmi-review-embed.json', json_encode($out));
echo 'Osadzenie: dane gotowe (pub, ' . ($out['live'] ? 'live, ' : 'BEZ live, ') . 'denied, bad, none); client-id: ' . ($out['client_id'] ? 'tak' : 'nie') . "\n";
