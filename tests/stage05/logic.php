<?php

/**
 * Etap 05 — testy analizy warstw (parser) i importu sekcji/pól (idempotencja, wykluczenia,
 * ochrona pól ręcznych i treści) na fixture `fixtures/nodes-landing.json`, bez sieci.
 *
 * Wszystkie zapisy działają w transakcji wycofywanej na końcu — baza zostaje nietknięta.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ElementSource;
use App\Enums\FieldType;
use App\Exceptions\FigmaImportException;
use App\Integrations\Figma\FrameAnalyzer;
use App\Models\Activity;
use App\Models\Field;
use App\Models\FieldValue;
use App\Models\FigmaFrame;
use App\Models\FigmaNodeRule;
use App\Models\Section;
use App\Services\ContentService;
use App\Services\FigmaFrameService;
use App\Services\FigmaImportService;
use App\Services\PageDuplicationService;
use App\Services\PageService;
use App\Services\ProjectService;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;

if (! $admin) {
	fwrite(STDERR, "BLOCKED: brak użytkownika administratora do wykonania testów.\n");
	exit(2);
}

wp_set_current_user($admin->ID);

$t = [Harness::class, 'test'];
$ok = [Harness::class, 'assert'];
$same = [Harness::class, 'same'];
$throws = [Harness::class, 'throws'];

$raw = json_decode((string) file_get_contents(__DIR__ . '/fixtures/nodes-landing.json'), true)['nodes']['10:1']['document'];

/** Przycięte drzewo (jak w cache etapu 04) z opcjonalną modyfikacją surowego dokumentu. */
$prune = function (?callable $mutate = null) use ($raw): array {
	$document = $raw;

	if ($mutate) {
		$mutate($document);
	}

	$count = 0;

	return FigmaFrameService::prune($document, 8, $count, 5000);
};

$analyze = fn (array $rules = [], ?callable $mutate = null, ?int $maxDepth = null) => (new FrameAnalyzer($rules, null, $maxDepth))->analyze($prune($mutate));

/** Pole propozycji po ID warstwy (w całym drzewie). */
$find = function (array $fields, string $nodeId, string $role = 'field') use (&$find): ?array {
	foreach ($fields as $field) {
		if ($field['node_id'] === $nodeId && $field['role'] === $role) {
			return $field;
		}

		if ($found = $find($field['children'], $nodeId, $role)) {
			return $found;
		}
	}

	return null;
};

$allNodeIds = function (array $analysis): array {
	$ids = [];
	$walk = function (array $fields) use (&$walk, &$ids) {
		foreach ($fields as $field) {
			$ids[] = $field['node_id'];
			$walk($field['children']);
		}
	};

	foreach ($analysis['sections'] as $section) {
		$ids[] = $section['node_id'];
		$walk($section['fields']);
	}

	return $ids;
};

$section = fn (array $analysis, string $nodeId) => collect($analysis['sections'])->firstWhere('node_id', $nodeId);

/** Zmiana węzła surowego dokumentu po ID. */
$patch = function (array &$node, string $id, callable $change) use (&$patch): bool {
	if (($node['id'] ?? null) === $id) {
		$change($node);

		return true;
	}

	foreach ($node['children'] ?? [] as $index => $child) {
		if ($patch($node['children'][$index], $id, $change)) {
			return true;
		}
	}

	return false;
};

$remove = function (array &$node, string $id) use (&$remove): bool {
	foreach ($node['children'] ?? [] as $index => $child) {
		if (($child['id'] ?? null) === $id) {
			array_splice($node['children'], $index, 1);

			return true;
		}

		if ($remove($node['children'][$index], $id)) {
			return true;
		}
	}

	return false;
};

// ===================================================================== Parser (C4)
echo "== C4: analiza warstw (parser) ==\n";

$base = $analyze();

$t('Przycinanie zachowuje style z białej listy i wskazówki, bez wypełnień i pełnych stylów', function () use ($prune, $ok, $same) {
	$tree = $prune();
	$json = json_encode($tree);
	$hero = collect($tree['children'])->firstWhere('id', '10:200');
	$title = collect($hero['children'])->firstWhere('id', '10:201');
	$link = collect($hero['children'])->firstWhere('id', '10:212');
	$about = collect($tree['children'])->firstWhere('id', '10:300');

	$same(['fontFamily' => 'Inter', 'fontWeight' => 700, 'fontSize' => 64, 'lineHeightPx' => 72, 'textCase' => 'ORIGINAL'], $title['text_style'], 'text_style tytułu');
	$same('https://example.com/oferta', $link['hyperlink'], 'Adres hiperłącza');
	$ok(collect($about['children'])->firstWhere('id', '10:303')['list'] === true, 'Lista punktowana');
	$ok(collect($about['children'])->firstWhere('id', '10:304')['inline_links'] === true, 'Link w treści');
	$same('5:1', collect($hero['children'])->firstWhere('id', '10:210')['component_id'], 'componentId instancji');
	$ok(! str_contains($json, '"fills"') && ! str_contains($json, '"style"') && ! str_contains($json, 'imageRef'), 'Bez wypełnień, surowych stylów i referencji obrazów');
});

$t('Przycinanie: hiperłącze javascript: nie jest zapisywane jako adres', function () use ($prune, $same) {
	$tree = $prune(function (array &$doc) {
		$doc['children'][3]['children'][3]['style']['hyperlink'] = ['type' => 'URL', 'url' => 'javascript:alert(1)'];
	});
	$same('URL', collect(collect($tree['children'])->firstWhere('id', '10:200')['children'])->firstWhere('id', '10:212')['hyperlink'], 'Tylko typ łącza');
});

$t('Sekcje: widoczne dzieci frame\'u w kolejności czytania (nie kolejności warstw w pliku)', function () use ($base, $same, $ok) {
	$same(['10:200', '10:300', '10:400', '10:500', '10:600'], array_column($base['sections'], 'node_id'), 'Kolejność sekcji');
	$same(['Hero', 'O nas', 'Oferta', 'Zespół', 'Kontakt'], array_column($base['sections'], 'name'), 'Nazwy sekcji');
	$same([0, 1, 2, 3, 4], array_column($base['sections'], 'position'), 'Pozycje');
	$same(3, $base['sections'][0]['meta']['index'], 'Indeks warstwy w pliku zachowany w meta');
	$same(['x' => 0.0, 'y' => 120.0, 'width' => 1440.0, 'height' => 700.0], $base['sections'][0]['meta']['box'], 'Pozycja (box) zachowana');
	$ok($base['wrappers'] === [], 'Brak wrappera do rozpakowania');
});

$t('Hero: nagłówek, lead, przycisk i link z uzasadnieniem typu', function () use ($base, $section, $same) {
	$fields = $section($base, '10:200')['fields'];

	$same(['10:201', '10:202', '10:211', '10:212'], array_column($fields, 'node_id'), 'Pola hero w kolejności czytania');
	$same(['text', 'textarea', 'link', 'link'], array_column($fields, 'type'), 'Typy pól hero');
	$same(['short', 'long', 'link_name', 'hyperlink'], array_column($fields, 'reason'), 'Uzasadnienia');
	$same('Umów rozmowę', $fields[2]['label'], 'Etykieta przycisku z treści (nazwa „Label” jest domyślna)');
	$same('https://example.com/oferta', $fields[3]['meta']['url'], 'Adres linku z Figmy');
	$same(64, $fields[0]['meta']['text_style']['fontSize'], 'Styl nagłówka w meta');
	$same('Świeże strony <script>alert(1)</script>', $fields[0]['figma_text'], 'Tekst z Figmy bez zmian (escaping w widoku)');
});

$t('Akapity i lista: textarea, wysiwyg (lista, link w treści), spłaszczony kontener domyślny', function () use ($base, $section, $same) {
	$fields = $section($base, '10:300')['fields'];

	$same(['10:301', '10:302', '10:303', '10:304', '10:311'], array_column($fields, 'node_id'), 'Pola „O nas” (Podpis z „Frame 12” na poziomie sekcji)');
	$same(['text', 'textarea', 'wysiwyg', 'wysiwyg', 'text'], array_column($fields, 'type'), 'Typy');
	$same(['short', 'multiline', 'list', 'rich', 'short'], array_column($fields, 'reason'), 'Uzasadnienia');
});

$t('Karty: repeater z wrappera, elementy w kolejności wizualnej, pola z pierwszej karty', function () use ($base, $section, $same) {
	$fields = $section($base, '10:400')['fields'];
	$repeater = $fields[1];

	$same('repeater', $repeater['type'], 'Typ');
	$same('10:405', $repeater['node_id'], 'ID warstwy wrappera „Karty”');
	$same('Karty', $repeater['label'], 'Etykieta z nazwy wrappera');
	$same(['10:410', '10:420', '10:430'], array_column($repeater['meta']['items'], 'node_id'), 'Elementy wg pozycji x (w pliku inna kolejność)');
	$same(['Usługa A', 'Opis usługi A w dwóch zdaniach. Krótko i konkretnie.'], $repeater['meta']['items'][0]['texts'], 'Próbki tekstów karty');
	$same(['10:411', '10:412', '10:413'], array_column($repeater['children'], 'node_id'), 'Pola elementu z pierwszej karty, ze zdjęciem');
});

$t('Repeater bez wrappera, grupa i lista punktów jako repeater tekstów', function () use ($base, $section, $same) {
	$team = $section($base, '10:500')['fields'];
	$same(['text', 'repeater'], array_column($team, 'type'), 'Zespół: nagłówek + repeater');
	$same('Lista: Osoba', $team[1]['label'], 'Etykieta bez numeru elementu');
	$same('10:510', $team[1]['node_id'], 'ID = pierwszy element');
	$same(['10:511', '10:512'], array_column($team[1]['children'], 'node_id'), 'Pola osoby');

	$contact = $section($base, '10:600')['fields'];
	$same(['text', 'group', 'repeater'], array_column($contact, 'type'), 'Kontakt: nagłówek, grupa, repeater');
	$same('Dane kontaktowe', $contact[1]['label'], 'Grupa z nazwy kontenera');
	$same(['10:611', '10:612'], array_column($contact[1]['children'], 'node_id'), 'Pola grupy');
	$same('repeated_texts', $contact[2]['reason'], 'Repeater punktów listy');
	$same(3, count($contact[2]['meta']['items']), 'Trzy punkty');
	$same('10:621', $contact[2]['children'][0]['node_id'], 'Jedno pole tekstowe w elemencie');
});

$t('Wykluczenia automatyczne: nawigacja, menu, stopka, breadcrumbs, ukryte, puste i techniczne', function () use ($base, $allNodeIds, $same, $ok) {
	// Pusty tekst (10:214) jest pomijany, ale nie trafia na listę — nie ma treści do przywrócenia.
	$reasons = array_column($base['excluded'], 'reason', 'node_id');
	ksort($reasons);

	$same([
		'10:100' => 'navigation', '10:150' => 'breadcrumbs', '10:213' => 'hidden', '10:220' => 'navigation',
		'10:700' => 'technical', '10:800' => 'technical', '10:900' => 'footer', '10:950' => 'technical',
	], $reasons, 'Wykluczone warstwy i powody');

	$ids = $allNodeIds($base);

	foreach (['10:100', '10:101', '10:150', '10:213', '10:214', '10:220', '10:221', '10:700', '10:701', '10:900', '10:901'] as $id) {
		$ok(! in_array($id, $ids, true), "Warstwa {$id} nie trafia do pól");
	}

	$same('Hero', collect($base['excluded'])->firstWhere('node_id', '10:220')['path'], 'Ścieżka wykluczonej warstwy');
	$same(2, collect($base['excluded'])->firstWhere('node_id', '10:900')['texts'], 'Liczba tekstów w wykluczonej stopce');
	$same(['sections' => 5, 'fields' => 20, 'groups' => 1, 'repeaters' => 3, 'excluded' => 8, 'restored' => 0], $base['stats'], 'Statystyki');
});

$t('Reguły pracownika: przywrócenie stopki i ukrytej warstwy, ręczne wykluczenie sekcji', function () use ($analyze, $section, $find, $same, $ok) {
	$result = $analyze(['10:900' => 'include', '10:213' => 'include', '10:300' => 'exclude']);

	$ok($section($result, '10:900') !== null, 'Stopka jest sekcją');
	$ok($section($result, '10:300') === null, '„O nas” pominięta');
	$same('manual', collect($result['excluded'])->firstWhere('node_id', '10:300')['reason'], 'Powód: wykluczona przez pracownika');
	$same(['10:213', '10:900'], collect($result['restored'])->pluck('node_id')->sort()->values()->all(), 'Lista przywróconych');
	$same('hidden', collect($result['restored'])->firstWhere('node_id', '10:213')['reason'], 'Powód automatyczny zachowany');
	$ok($find($section($result, '10:200')['fields'], '10:213') !== null, 'Ukryty tekst przywrócony jako pole');
});

$t('Bez zgadywania: różne struktury nie dają repeatera; „menu” jako treść nie jest wykluczane', function () use ($analyze, $patch, $section, $same, $ok) {
	$result = $analyze([], function (array &$doc) use ($patch) {
		// Druga osoba dostaje dodatkowy tekst → struktura inna → brak repeatera.
		$patch($doc, '10:520', function (array &$node) {
			$node['children'][] = ['id' => '10:523', 'name' => 'Opis', 'type' => 'TEXT', 'characters' => 'Dodatkowy opis', 'absoluteBoundingBox' => ['x' => 460, 'y' => 2780, 'width' => 300, 'height' => 30]];
		});
		// Duża sekcja „Menu restauracji” to treść, a nie nawigacja.
		$doc['children'][] = ['id' => '10:960', 'name' => 'Menu restauracji', 'type' => 'FRAME', 'absoluteBoundingBox' => ['x' => 0, 'y' => 3850, 'width' => 1440, 'height' => 600], 'children' => [
			['id' => '10:961', 'name' => 'Danie', 'type' => 'TEXT', 'characters' => 'Pierogi', 'absoluteBoundingBox' => ['x' => 120, 'y' => 3900, 'width' => 300, 'height' => 30]],
		]];
	});

	$team = $section($result, '10:500')['fields'];
	$ok(! in_array('repeater', array_column($team, 'type'), true), 'Brak repeatera dla różnych struktur');
	$same(['text', 'group', 'group'], array_column($team, 'type'), 'Osoby jako osobne grupy');
	$same([['10:511', '10:512'], ['10:521', '10:522', '10:523']], [array_column($team[1]['children'], 'node_id'), array_column($team[2]['children'], 'node_id')], 'Pola grup');
	$ok($section($result, '10:960') !== null, 'Wysoka sekcja „Menu restauracji” zostaje');
});

$t('Punkty listy jako sąsiednie teksty o tej samej nazwie (bez wrappera) → repeater tekstów', function () use ($analyze, $patch, $section, $same) {
	$result = $analyze([], function (array &$doc) use ($patch) {
		$patch($doc, '10:300', function (array &$node) {
			foreach ([1, 2, 3] as $i) {
				$node['children'][] = ['id' => '10:33' . $i, 'name' => 'Zaleta', 'type' => 'TEXT', 'characters' => 'Zaleta ' . $i, 'absoluteBoundingBox' => ['x' => 120, 'y' => 1400 + $i * 30, 'width' => 300, 'height' => 24]];
			}
		});
	});

	$fields = $section($result, '10:300')['fields'];
	$last = end($fields);
	$same(['repeater', 'repeated_texts', '10:331', 'Lista: Zaleta'], [$last['type'], $last['reason'], $last['node_id'], $last['label']], 'Repeater z pierwszego punktu');
	$same(['10:331'], array_column($last['children'], 'node_id'), 'Pole punktu (ta sama warstwa, rola: pole)');
	$same(['10:331', '10:332', '10:333'], array_column($last['meta']['items'], 'node_id'), 'Elementy');
});

$t('Konwencje z prawdziwych plików: „__wrapper” (BEM) to kontener, „__header” w treści i tekst „…menu…” nie są nawigacją', function () use ($analyze, $patch, $section, $same, $ok) {
	$result = $analyze([], function (array &$doc) use ($patch) {
		$patch($doc, '10:400', function (array &$node) {
			// Karty opakowane w „__wrapper” → „Slider” (grupa z samym repeaterem jest zbędna).
			$node['children'] = [[
				'id' => '10:450', 'name' => '__wrapper', 'type' => 'FRAME', 'absoluteBoundingBox' => ['x' => 0, 'y' => 1520, 'width' => 1440, 'height' => 800],
				'children' => [$node['children'][0], [
					'id' => '10:451', 'name' => 'Slider', 'type' => 'FRAME', 'absoluteBoundingBox' => ['x' => 120, 'y' => 1700, 'width' => 1300, 'height' => 500],
					'children' => [$node['children'][1]],
				]],
			]];
		});
		$patch($doc, '10:600', function (array &$node) {
			$node['children'][] = ['id' => '10:630', 'name' => '__header', 'type' => 'FRAME', 'absoluteBoundingBox' => ['x' => 120, 'y' => 3500, 'width' => 600, 'height' => 60], 'children' => [
				['id' => '10:631', 'name' => 'Śródtytuł', 'type' => 'TEXT', 'characters' => 'Dojazd', 'absoluteBoundingBox' => ['x' => 120, 'y' => 3510, 'width' => 300, 'height' => 30]],
			]];
		});
		$patch($doc, '10:500', function (array &$node) {
			$node['children'][] = ['id' => '10:530', 'name' => 'Nowe menu sezonowe', 'type' => 'TEXT', 'characters' => 'Nowe menu sezonowe', 'absoluteBoundingBox' => ['x' => 120, 'y' => 2800, 'width' => 300, 'height' => 30]];
		});
	});

	$offer = $section($result, '10:400')['fields'];
	$same(['10:401', '10:405'], array_column($offer, 'node_id'), 'Oferta: nagłówek i repeater kart (bez grupy „Slider”)');
	$same('repeater', $offer[1]['type'], 'Repeater kart');
	$ok(in_array('10:631', array_column($section($result, '10:600')['fields'], 'node_id'), true), '„__header” w treści sekcji nie jest wykluczany');
	$ok(in_array('10:530', array_column($section($result, '10:500')['fields'], 'node_id'), true), 'Tekst „Nowe menu sezonowe” zostaje');
	$ok(collect($result['excluded'])->whereIn('node_id', ['10:450', '10:630', '10:530'])->isEmpty(), 'Brak fałszywych wykluczeń');
});

$t('Limit głębokości: przy max_depth = 0 brak grup i repeaterów (pola płaskie)', function () use ($analyze, $same) {
	$result = $analyze([], null, 0);
	$same(0, $result['stats']['groups'] + $result['stats']['repeaters'], 'Brak kontenerów');
	$same(30, $result['stats']['fields'], 'Wszystkie teksty i 3 zdjęcia jako pola: hero 4, o nas 5, oferta 1+3×3, zespół 1+2×2, kontakt 1+2+3');
});

$t('Wrapper na całą wysokość frame\'u jest rozpakowywany', function () use ($analyze, $same) {
	$result = $analyze([], function (array &$doc) {
		$doc['children'] = [[
			'id' => '10:2', 'name' => 'Content', 'type' => 'FRAME',
			'absoluteBoundingBox' => ['x' => 0, 'y' => 0, 'width' => 1440, 'height' => 4200],
			'children' => $doc['children'],
		]];
	});

	$same(['10:2'], $result['wrappers'], 'Rozpakowany wrapper');
	$same(['10:200', '10:300', '10:400', '10:500', '10:600'], array_column($result['sections'], 'node_id'), 'Te same sekcje');
});

$t('Nazwy techniczne pól (ASCII, podkreślenia)', function () use ($same) {
	$same('naglowek_sekcji', FrameAnalyzer::machineName('Nagłówek sekcji'), 'Polskie znaki');
	$same('pole', FrameAnalyzer::machineName('…'), 'Pusta nazwa');
	$same(60, strlen(FrameAnalyzer::machineName(str_repeat('Bardzo długa etykieta ', 10))), 'Limit 60 znaków');
});

// ===================================================================== Import (C1–C4)
echo "== C1–C4: import, idempotencja, wykluczenia, pola ręczne ==\n";

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST etap05 import'], $admin);
	$page = app(PageService::class)->create($project, [
		'name' => 'Landing',
		'figma_frame_url' => 'https://www.figma.com/design/STAGE05key01/Landing?node-id=10-1',
	], $admin);
	$otherPage = app(PageService::class)->create($project, [
		'name' => 'Landing kopia frame\'u',
		'figma_frame_url' => 'https://www.figma.com/design/STAGE05key01/Landing?node-id=10-1',
	], $admin);

	$frame = FigmaFrame::create([
		'project_id' => $project->id, 'file_key' => 'STAGE05key01', 'node_id' => '10:1', 'node_name' => 'Landing — desktop',
		'node_type' => 'FRAME', 'file_version' => '5555555555', 'nodes_count' => 60, 'document' => $prune(), 'fetched_at' => now(),
	]);

	/** Nowa wersja frame'u w cache (jak po ponownym pobraniu z Figmy). */
	$refetch = function (?callable $mutate = null, string $version = '6666666666') use ($frame, $prune) {
		$frame->forceFill(['document' => $prune($mutate), 'file_version' => $version, 'fetched_at' => now()->addSecond()])->save();
	};

	$service = app(FigmaImportService::class);
	$sections = fn ($p = null) => Section::query()->where('page_id', ($p ?? $page)->id);
	$fields = fn ($p = null) => Field::query()->whereIn('section_id', Section::query()->where('page_id', ($p ?? $page)->id)->select('id'));
	$byNode = fn (string $nodeId, ?string $type = null) => $fields()->where('figma_node_id', $nodeId)
		->when($type, fn ($q) => $q->where('type', $type))->first();

	$t('Podgląd bez zapisu: same operacje „create”, nic nie trafia do bazy', function () use ($service, $page, $sections, $fields, $same, $ok) {
		$plan = $service->plan($page);

		$same(5 + 20 + 1 + 3, $plan['counts']['create'], 'Liczba nowych elementów');
		$same(0, $plan['counts']['update'] + $plan['counts']['archive'] + $plan['counts']['restore'], 'Brak innych operacji');
		$ok($plan['has_changes'], 'Są zmiany do zatwierdzenia');
		$ok(preg_match('/^[a-f0-9]{64}$/', $plan['fingerprint']) === 1, 'Odcisk podglądu');
		$same(0, $sections()->count() + $fields()->count(), 'Podgląd niczego nie zapisał');
	});

	$t('Zatwierdzenie: sekcje i pola z Figmy ze stabilnymi ID, meta i strukturą drzewa', function () use ($service, $page, $admin, $sections, $fields, $byNode, $same, $ok) {
		$counts = $service->apply($page, $admin, $service->plan($page)['fingerprint']);

		$same(29, $counts['create'], 'Utworzono 29 elementów');
		$same(5, $sections()->count(), '5 sekcji');
		$same(24, $fields()->count(), '24 pola (w tym grupa i repeatery)');
		$same(0, $sections()->where('source', '!=', ElementSource::Figma->value)->count() + $fields()->where('source', '!=', ElementSource::Figma->value)->count(), 'Wszystko source=figma');

		$title = $byNode('10:201');
		$same('Tytuł', $title->label, 'Etykieta');
		$same('tytul', $title->name, 'Nazwa techniczna');
		$same(FieldType::Text, $title->type, 'Typ');
		$same(64, $title->figma_meta['text_style']['fontSize'], 'Styl zapisany w figma_meta');
		$ok(strlen((string) $title->uuid) === 36, 'UUID');

		$same(FieldType::Link, $byNode('10:211')->type, 'Przycisk → link');
		$same('https://example.com/oferta', $byNode('10:212')->figma_meta['url'], 'Adres linku w meta');

		$cards = $byNode('10:405', 'repeater');
		$same(0, $cards->depth, 'Repeater na poziomie sekcji');
		$same(['10:411', '10:412', '10:413'], $cards->children()->pluck('figma_node_id')->all(), 'Pola karty jako dzieci repeatera');
		$same([1, 1, 1], $cards->children()->pluck('depth')->all(), 'Głębokość pól karty');
		$same(3, count($cards->figma_meta['items']), 'Elementy kart w meta');

		$points = $byNode('10:620', 'repeater');
		$same(1, $points->children()->count(), 'Repeater punktów („Godziny”) ma jedno pole');
		$same('10:621', $points->children()->first()->figma_node_id, 'Pole punktu z pierwszego elementu');

		$hero = $sections()->where('figma_node_id', '10:200')->first();
		$same('Hero', $hero->name, 'Nazwa sekcji');
		$same(['x' => 0, 'y' => 120, 'width' => 1440, 'height' => 700], array_map('intval', $hero->figma_meta['box']), 'Pozycja sekcji w meta');
		$same(0, $hero->position, 'Kolejność sekcji');
		$same(['10:201', '10:202', '10:211', '10:212'], $hero->fields()->pluck('figma_node_id')->all(), 'Kolejność pól');
	});

	$t('C1: ponowny import bez zmian — brak duplikatów, te same UUID', function () use ($service, $page, $admin, $sections, $fields, $same, $ok) {
		$before = [$sections()->pluck('uuid')->sort()->values()->all(), $fields()->pluck('uuid')->sort()->values()->all()];
		$plan = $service->plan($page);

		$ok(! $plan['has_changes'], 'Brak zmian w podglądzie');
		$same(29, $plan['counts']['unchanged'], 'Wszystko bez zmian');

		$counts = $service->apply($page, $admin, $plan['fingerprint']);
		$same(0, $counts['create'] + $counts['update'] + $counts['archive'] + $counts['restore'], 'Zero operacji');
		$same($before, [$sections()->pluck('uuid')->sort()->values()->all(), $fields()->pluck('uuid')->sort()->values()->all()], 'Identyczne UUID');
	});

	$t('C1: ponowne pobranie tego samego frame\'u (nowy czas cache) i import — nadal bez duplikatów', function () use ($service, $page, $admin, $refetch, $sections, $fields, $same) {
		$refetch(null, '5555555555');
		$service->apply($page, $admin, $service->plan($page)['fingerprint']);
		$same([5, 24], [$sections()->count(), $fields()->count()], 'Te same liczby');
	});

	$t('Nieaktualny podgląd jest odrzucany (zatwierdzasz dokładnie to, co widziałeś)', function () use ($service, $page, $admin, $byNode, $fields, $throws, $same) {
		$fingerprint = $service->plan($page)['fingerprint'];
		$byNode('10:301')->forceFill(['label' => 'Nagłówek „O nas”'])->save();

		$throws(FigmaImportException::class, fn () => $service->apply($page, $admin, $fingerprint), 'Stary odcisk', 'zmieniła się');
		$same(24, $fields()->count(), 'Nic nie zapisano');
		$throws(FigmaImportException::class, fn () => $service->apply($page, $admin, str_repeat('a', 64)), 'Dowolny odcisk');
	});

	// Treść klienta i elementy ręczne przed zmianą struktury w Figmie.
	$content = app(ContentService::class);
	$actor = Actor::user($admin);
	$content->save($byNode('10:202'), 'Lead od klienta', 0, $actor);
	$content->save($byNode('10:201'), 'Tytuł od klienta', 0, $actor);

	$heroId = $sections()->where('figma_node_id', '10:200')->value('id');
	$teamId = $sections()->where('figma_node_id', '10:500')->value('id');
	$manualField = Field::create(['project_id' => $project->id, 'section_id' => $heroId, 'type' => FieldType::Image, 'name' => 'zdjecie_hero', 'label' => 'Zdjęcie hero', 'source' => ElementSource::Manual, 'position' => 9]);
	$manualInTeam = Field::create(['project_id' => $project->id, 'section_id' => $teamId, 'type' => FieldType::Textarea, 'name' => 'notatka', 'label' => 'Notatka zespołu', 'source' => ElementSource::Manual, 'position' => 9]);
	$manualSection = Section::create(['project_id' => $project->id, 'page_id' => $page->id, 'name' => 'Sekcja ręczna', 'source' => ElementSource::Manual, 'position' => 20]);
	$manualSectionField = Field::create(['project_id' => $project->id, 'section_id' => $manualSection->id, 'type' => FieldType::Text, 'name' => 'tekst', 'label' => 'Tekst ręczny', 'source' => ElementSource::Manual]);
	$content->save($manualSectionField, 'Wartość ręcznego pola', 0, $actor);

	// Konfiguracja zespołu na polu z Figmy.
	$byNode('10:201')->forceFill(['label' => 'Główny nagłówek', 'type' => FieldType::Wysiwyg, 'is_required' => true, 'instructions' => 'Max 6 słów', 'position' => 7])->save();

	$manualSnapshot = fn () => [
		Field::query()->whereIn('id', [$manualField->id, $manualInTeam->id, $manualSectionField->id])->orderBy('id')->get(['id', 'label', 'name', 'section_id', 'parent_id', 'position', 'archived_at', 'updated_at'])->toArray(),
		Section::query()->whereKey($manualSection->id)->first(['id', 'name', 'position', 'archived_at', 'updated_at'])->toArray(),
	];
	$manualBefore = $manualSnapshot();

	$t('Zmiana w Figmie: tekst, usunięte pole i sekcja, nowe pole, przeniesione pole — poprawny diff', function () use ($service, $page, $refetch, $patch, $remove, $same, $ok) {
		$refetch(function (array &$doc) use ($patch, $remove) {
			$patch($doc, '10:201', fn (array &$node) => $node['characters'] = 'Nowy nagłówek z Figmy');
			$remove($doc, '10:202'); // Lead
			$remove($doc, '10:500'); // sekcja Zespół
			$patch($doc, '10:300', function (array &$node) {
				$node['children'][] = ['id' => '10:305', 'name' => 'Cytat', 'type' => 'TEXT', 'characters' => 'Nowy cytat', 'absoluteBoundingBox' => ['x' => 120, 'y' => 1300, 'width' => 400, 'height' => 30]];
			});
			// „Podpis” przeniesiony do sekcji Oferta.
			$remove($doc, '10:310');
			$patch($doc, '10:400', function (array &$node) {
				$node['children'][] = ['id' => '10:311', 'name' => 'Podpis', 'type' => 'TEXT', 'characters' => 'Zespół Fill Me In', 'absoluteBoundingBox' => ['x' => 120, 'y' => 2250, 'width' => 400, 'height' => 30]];
			});
		});

		$plan = $service->plan($page);
		$entries = collect($plan['sections'])->flatMap(fn ($s) => $s['fields'])->keyBy(fn ($e) => $e['proposal']['node_id']);
		$archive = collect($plan['archive']);

		$title = $entries['10:201'];
		$same('update', $title['action'], 'Tytuł: aktualizacja');
		$ok(in_array('text', $title['changes'], true), 'Tytuł: zmiana tekstu');
		$ok($title['type_kept'], 'Tytuł: typ zespołu zostaje');
		$same('create', $entries['10:305']['action'], 'Nowy cytat');
		$same('update', $entries['10:311']['action'], 'Podpis przeniesiony (to samo pole)');
		$ok(in_array('move', $entries['10:311']['changes'], true), 'Zmiana: przeniesienie');
		$same('archive', $archive->first(fn ($i) => $i['kind'] === 'field' && $i['model']->figma_node_id === '10:202')['action'], 'Lead do archiwum');
		$same('keep', $archive->first(fn ($i) => $i['kind'] === 'section' && $i['model']->figma_node_id === '10:500')['action'], 'Sekcja Zespół z polem ręcznym zostaje');
		$same('archive', $archive->first(fn ($i) => $i['kind'] === 'field' && $i['model']->figma_node_id === '10:510')['action'], 'Repeater osób do archiwum');
	});

	$t('C3: po imporcie zmian konfiguracja zespołu, pola ręczne i wartości zostają', function () use ($service, $page, $admin, $byNode, $sections, $manualSnapshot, $manualBefore, $manualSectionField, $same, $ok) {
		$service->apply($page, $admin, $service->plan($page)['fingerprint']);

		$title = $byNode('10:201');
		$same(['Główny nagłówek', FieldType::Wysiwyg, true, 'Max 6 słów', 7], [$title->label, $title->type, $title->is_required, $title->instructions, $title->position], 'Konfiguracja zespołu nienaruszona');
		$same('Nowy nagłówek z Figmy', $title->figma_text, 'Tekst źródłowy zaktualizowany');
		$same('Tytuł od klienta', FieldValue::query()->where('field_id', $title->id)->value('value'), 'Treść klienta w tytule');

		$lead = $byNode('10:202');
		$ok($lead->archived_at !== null, 'Lead zarchiwizowany, nie usunięty');
		$same('Lead od klienta', FieldValue::query()->where('field_id', $lead->id)->value('value'), 'Treść leadu zachowana');

		$team = $sections()->where('figma_node_id', '10:500')->first();
		$same(null, $team->archived_at, 'Sekcja z polem ręcznym nie jest archiwizowana');
		$ok($byNode('10:510', 'repeater')->archived_at !== null && $byNode('10:511')->archived_at !== null, 'Pola Figmy z usuniętej sekcji w archiwum');

		$same($manualBefore, $manualSnapshot(), 'Pola i sekcja ręczna bez żadnej zmiany');
		$same('Wartość ręcznego pola', FieldValue::query()->where('field_id', $manualSectionField->id)->value('value'), 'Wartość pola ręcznego');

		$podpis = $byNode('10:311');
		$same($sections()->where('figma_node_id', '10:400')->value('id'), $podpis->section_id, 'Podpis w sekcji Oferta (to samo pole)');
		$same(1, $byNode('10:305') ? 1 : 0, 'Nowe pole Cytat');
	});

	$t('C1: import po zmianach jest idempotentny (drugi przebieg bez operacji)', function () use ($service, $page, $admin, $fields, $same) {
		$count = $fields()->count();
		$counts = $service->apply($page, $admin, $service->plan($page)['fingerprint']);

		$same(0, $counts['create'] + $counts['update'] + $counts['archive'] + $counts['restore'], 'Zero operacji');
		$same($count, $fields()->count(), 'Bez nowych wierszy');
	});

	$t('Powrót usuniętej warstwy w Figmie → przywrócenie tego samego pola z treścią (bez duplikatu)', function () use ($service, $page, $admin, $refetch, $byNode, $fields, $same) {
		$leadId = $byNode('10:202')->id;
		$refetch(null, '7777777777'); // pierwotny dokument

		$plan = $service->plan($page);
		$same('restore', collect($plan['sections'])->flatMap(fn ($s) => $s['fields'])->firstWhere('proposal.node_id', '10:202')['action'], 'Lead: przywrócenie');
		$service->apply($page, $admin, $plan['fingerprint']);

		$same($leadId, $byNode('10:202')->id, 'To samo pole');
		$same(null, $byNode('10:202')->archived_at, 'Aktywne');
		$same(1, $fields()->where('figma_node_id', '10:202')->count(), 'Brak duplikatu');
		$same('Lead od klienta', FieldValue::query()->where('field_id', $leadId)->value('value'), 'Treść wróciła razem z polem');
	});

	$t('C2: przywrócenie wykluczonej stopki i ponowne wykluczenie (ta sama sekcja, bez duplikatu)', function () use ($service, $page, $admin, $sections, $same, $ok) {
		$service->setRule($page, '10:900', 'include', $admin);
		$plan = $service->plan($page);
		$footer = collect($plan['sections'])->firstWhere('proposal.node_id', '10:900');
		$same('create', $footer['action'], 'Stopka w podglądzie jako nowa');
		$same(2, count($footer['fields']), 'Pola stopki');
		$service->apply($page, $admin, $plan['fingerprint']);
		$footerId = $sections()->where('figma_node_id', '10:900')->value('id');
		$ok($footerId !== null, 'Sekcja stopki utworzona');

		$service->setRule($page, '10:900', 'reset', $admin);
		$plan = $service->plan($page);
		$same('archive', collect($plan['archive'])->first(fn ($i) => $i['kind'] === 'section' && $i['model']->id === $footerId)['action'], 'Po resecie stopka do archiwum');
		$service->apply($page, $admin, $plan['fingerprint']);
		$ok($sections()->whereKey($footerId)->value('archived_at') !== null, 'Zarchiwizowana');

		$service->setRule($page, '10:900', 'include', $admin);
		$plan = $service->plan($page);
		$same('restore', collect($plan['sections'])->firstWhere('proposal.node_id', '10:900')['action'], 'Ponowne przywrócenie = restore');
		$service->apply($page, $admin, $plan['fingerprint']);
		$same(1, $sections()->where('figma_node_id', '10:900')->count(), 'Jedna sekcja stopki');
		$same(null, $sections()->whereKey($footerId)->value('archived_at'), 'Ta sama sekcja aktywna');
	});

	$t('C2: ręczne wykluczenie pola z treścią i cofnięcie — treść i UUID zostają', function () use ($service, $page, $admin, $byNode, $content, $actor, $same, $ok) {
		$field = $byNode('10:302');
		$content->save($field, 'Treść o firmie', 0, $actor);

		$service->setRule($page, '10:302', 'exclude', $admin);
		$service->apply($page, $admin, $service->plan($page)['fingerprint']);
		$ok($byNode('10:302')->archived_at !== null, 'Pole w archiwum');
		$same('Treść o firmie', FieldValue::query()->where('field_id', $field->id)->value('value'), 'Treść zachowana');

		$service->setRule($page, '10:302', 'reset', $admin);
		$service->apply($page, $admin, $service->plan($page)['fingerprint']);
		$same([$field->uuid, null], [$byNode('10:302')->uuid, $byNode('10:302')->archived_at], 'To samo pole przywrócone');
	});

	$t('Reguły tylko dla warstw frame\'u tej podstrony; podstrony z tym samym frame\'em są niezależne', function () use ($service, $page, $otherPage, $admin, $sections, $throws, $same) {
		$throws(FigmaImportException::class, fn () => $service->setRule($page, '99:99', 'exclude', $admin), 'Obca warstwa');
		$throws(FigmaImportException::class, fn () => $service->setRule($page, '10:1', 'exclude', $admin), 'Sam frame');
		$same(0, FigmaNodeRule::query()->where('page_id', $otherPage->id)->count(), 'Brak reguł innej podstrony');

		$plan = $service->plan($otherPage);
		$same(29, $plan['counts']['create'], 'Druga podstrona: osobny komplet (reguły pierwszej jej nie dotyczą)');
		$service->apply($otherPage, $admin, $plan['fingerprint']);
		$same(5, $sections($otherPage)->count(), 'Sekcje drugiej podstrony');
		$same(1, $sections()->where('figma_node_id', '10:900')->count(), 'Pierwsza podstrona bez zmian');
	});

	$t('Duplikat podstrony po imporcie zachowuje powiązanie z Figmą (import bez zmian)', function () use ($service, $otherPage, $admin, $sections, $same) {
		$copy = app(PageDuplicationService::class)->duplicate($otherPage, ['name' => 'Duplikat'], $admin);
		$same(5, $sections($copy)->count(), 'Sekcje skopiowane');
		$plan = $service->plan($copy);
		$same(false, $plan['has_changes'], 'Duplikat zgodny z frame\'em');
		$same(29, $plan['counts']['unchanged'], 'Wszystko dopasowane po ID warstw');
	});

	$t('Dziennik: import i reguły zapisane bez treści warstw', function () use ($project, $same, $ok) {
		$events = Activity::query()->where('project_id', $project->id)->whereIn('event', ['figma.import_applied', 'figma.node_rule_changed'])->get();

		$ok($events->where('event', 'figma.import_applied')->count() >= 5, 'Wpisy importu');
		$ok($events->where('event', 'figma.node_rule_changed')->count() >= 5, 'Wpisy reguł');
		$same(29, $events->where('event', 'figma.import_applied')->first()->properties['counts']['create'], 'Liczniki w dzienniku');
		$ok(! str_contains($events->toJson(), 'Lead od klienta') && ! str_contains($events->toJson(), 'Świeże strony'), 'Bez treści');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('ETAP 05 — analiza i import'));
