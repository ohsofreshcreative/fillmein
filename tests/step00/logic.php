<?php

/**
 * Krok 0 — testy regresyjne poprawek P0 (logika, bez sieci):
 * 1. import z Figmy przy przyciętym drzewie (`truncated`) nie archiwizuje niewidocznych elementów,
 * 2. reguły wykluczeń dla warstw wewnątrz instancji (`I12:34;56:78`),
 * 3. duplikowanie podstron: rodzic, zarchiwizowany rodzic, limit głębokości, poddrzewa.
 *
 * Wszystkie zapisy działają w transakcji wycofywanej na końcu — baza zostaje nietknięta.
 */

require __DIR__ . '/../stage03/_harness.php';

use App\Enums\ElementSource;
use App\Exceptions\FigmaImportException;
use App\Exceptions\PageStructureException;
use App\Http\Requests\DuplicatePageRequest;
use App\Http\Requests\UpdateFigmaNodeRuleRequest;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Field;
use App\Models\FigmaFrame;
use App\Models\FigmaNodeRule;
use App\Models\Page;
use App\Models\Section;
use App\Services\FigmaImportService;
use App\Services\PageDuplicationService;
use App\Services\PageService;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

/** Tekst w formacie przyciętego drzewa (FigmaFrameService::prune). */
$text = fn (string $id, string $name, string $characters, int $y) => [
	'id' => $id, 'name' => $name, 'type' => 'TEXT', 'characters' => $characters,
	'box' => ['x' => 100, 'y' => $y, 'width' => 600, 'height' => 40],
];

/** Frame z sekcjami „Hero” i „Oferta”; tekst w „Oferta” pochodzi z instancji komponentu. */
$document = function (bool $withOffer = true, bool $truncateHero = false) use ($text): array {
	$hero = ['id' => '1:10', 'name' => 'Hero', 'type' => 'FRAME', 'box' => ['x' => 0, 'y' => 0, 'width' => 1440, 'height' => 600]];

	if ($truncateHero) {
		$hero['truncated'] = true; // dzieci odcięte limitem głębokości / liczby warstw
	} else {
		$hero['children'] = [$text('1:11', 'Tytuł', 'Witaj w naszej firmie', 100), $text('1:12', 'Opis', 'Krótki opis firmy', 200)];
	}

	$children = [$hero];

	if ($withOffer) {
		$children[] = ['id' => '1:20', 'name' => 'Oferta', 'type' => 'FRAME', 'box' => ['x' => 0, 'y' => 600, 'width' => 1440, 'height' => 600],
			'children' => [$text('1:21', 'Nagłówek oferty', 'Nasza oferta', 700), $text('I1:30;2:5', 'Etykieta', 'Tekst z instancji', 800)]];
	}

	return ['id' => '1:1', 'name' => 'Strona', 'type' => 'FRAME', 'box' => ['x' => 0, 'y' => 0, 'width' => 1440, 'height' => 1200], 'children' => $children];
};

DB::beginTransaction();

try {
	$project = app(ProjectService::class)->create(['name' => 'TEST krok00'], $admin);
	$pages = app(PageService::class);
	$duplication = app(PageDuplicationService::class);
	$service = app(FigmaImportService::class);

	$page = $pages->create($project, ['name' => 'Import', 'figma_frame_url' => 'https://www.figma.com/design/STEP00key001/T?node-id=1-1'], $admin);

	$frame = FigmaFrame::create([
		'project_id' => $project->id, 'file_key' => 'STEP00key001', 'node_id' => '1:1', 'node_name' => 'Strona',
		'node_type' => 'FRAME', 'file_version' => '1', 'nodes_count' => 7, 'document' => $document(), 'fetched_at' => now(),
	]);

	$refetch = function (array $doc, string $version) use ($frame) {
		$frame->forceFill(['document' => $doc, 'file_version' => $version, 'fetched_at' => now()->addSeconds((int) $version)])->save();
	};

	$active = fn () => [
		'sections' => Section::query()->where('page_id', $page->id)->whereNull('archived_at')->count(),
		'fields' => Field::query()->whereIn('section_id', Section::query()->where('page_id', $page->id)->select('id'))->whereNull('archived_at')->count(),
	];

	echo "== P0-1: import przy przyciętym drzewie ==\n";

	$service->apply($page, $admin, $service->plan($page)['fingerprint']);
	$initial = $active();

	$t('Import pełnego drzewa: 2 sekcje i 4 pola (w tym pole z instancji)', function () use ($initial, $same, $page) {
		$same(['sections' => 2, 'fields' => 4], $initial, 'Stan po imporcie');
		$same(1, Field::query()->where('project_id', $page->project_id)->where('figma_node_id', 'I1:30;2:5')->count(), 'Pole z warstwy instancji');
	});

	$t('Przycięte drzewo: brakująca sekcja i pola NIE trafiają do archiwum, plan ma ostrzeżenie', function () use ($service, $page, $admin, $refetch, $document, $active, $initial, $same, $ok) {
		$refetch($document(withOffer: false, truncateHero: true), '2');
		$plan = $service->plan($page);

		$ok($plan['truncated'] === true, 'Plan oznacza drzewo jako przycięte');
		$same(0, $plan['counts']['archive'], 'Brak operacji archiwizacji');
		$ok(count($plan['archive']) === 5 && collect($plan['archive'])->every(fn ($i) => $i['action'] === 'keep' && $i['unverified'] === true),
			'Sekcja „Oferta” i 4 pola (2 z „Oferty”, 2 z odciętego „Hero”) są zachowane jako niezweryfikowane');

		$service->apply($page, $admin, $plan['fingerprint']);
		$same($initial, $active(), 'Po zatwierdzeniu nic nie zostało zarchiwizowane');
	});

	$t('Pełne drzewo bez sekcji (kontrola): brakujące elementy trafiają do archiwum', function () use ($service, $page, $admin, $refetch, $document, $active, $same, $ok) {
		$refetch($document(withOffer: false), '3');
		$plan = $service->plan($page);

		$ok($plan['truncated'] === false, 'Drzewo pełne');
		$same(3, $plan['counts']['archive'], 'Sekcja „Oferta” i jej 2 pola do archiwum');

		$service->apply($page, $admin, $plan['fingerprint']);
		$same(['sections' => 1, 'fields' => 2], $active(), 'Archiwizacja wykonana (archived_at, bez usuwania)');
		$same(4, Field::query()->whereIn('section_id', Section::query()->where('page_id', $page->id)->select('id'))->count(), 'Wiersze zostały w bazie');
	});

	$t('Etap 10: przycięte drzewo nie archiwizuje niczego, także warstwy obecnej w drzewie, której nie zaproponowano', function () use ($service, $page, $admin, $refetch, $document, $same) {
		$refetch($document(), '4');
		$service->apply($page, $admin, $service->plan($page)['fingerprint']); // przywraca „Oferta”

		$doc = $document();
		$doc['children'][0]['children'][] = ['id' => '1:99', 'name' => 'Głęboka', 'type' => 'FRAME', 'truncated' => true];
		$doc['children'][1]['visible'] = false; // „Oferta” jest w drzewie, ale ukryta → wykluczona
		$refetch($doc, '5');

		$plan = $service->plan($page);
		$same(true, $plan['truncated'], 'Drzewo przycięte gdzie indziej');
		$same(0, $plan['counts']['archive'], 'Ukryta sekcja obecna w drzewie zostaje, bo plan z przyciętego drzewa nie archiwizuje');
	});

	$t('Pole-kontener (grupa, repeater) przypięte do przyciętego węzła → keep + unverified, nie archive', function () use ($service, $page, $admin, $refetch, $document, $same, $ok) {
		$refetch($document(), '7');
		$service->apply($page, $admin, $service->plan($page)['fingerprint']);

		$offer = Section::query()->where('page_id', $page->id)->where('figma_node_id', '1:20')->firstOrFail();
		$containers = collect([['1:40', 'Karty', 'group'], ['1:41', 'Lista', 'repeater']])->map(fn ($c) => Field::create([
			'project_id' => $page->project_id, 'section_id' => $offer->id, 'type' => $c[2], 'name' => 'kontener_' . $c[2], 'label' => $c[1],
			'source' => ElementSource::Figma, 'depth' => 0, 'figma_node_id' => $c[0], 'position' => 90,
		]));

		$items = fn (array $plan) => collect($plan['archive'])->filter(fn ($i) => $i['kind'] === 'field' && $containers->contains('id', $i['model']->id));

		$doc = $document();
		$doc['children'][1]['children'][] = ['id' => '1:40', 'name' => 'Karty', 'type' => 'FRAME', 'truncated' => true];
		$doc['children'][1]['children'][] = ['id' => '1:41', 'name' => 'Lista', 'type' => 'FRAME', 'truncated' => true];
		$refetch($doc, '8');
		$plan = $service->plan($page);

		$ok($plan['truncated'] === true, 'Plan oznacza drzewo jako przycięte');
		$same(2, $items($plan)->count(), 'Oba kontenery w planie archiwum');
		$ok($items($plan)->every(fn ($i) => $i['action'] === 'keep' && $i['unverified'] === true), 'Grupa i repeater: keep + unverified');
		$same(0, $plan['counts']['archive'], 'Brak operacji archiwizacji');

		$refetch($document(), '9');
		$plan = $service->plan($page);

		$ok($plan['truncated'] === false, 'Kontrola: drzewo pełne, bez warstw kontenerów');
		$ok($items($plan)->count() === 2 && $items($plan)->every(fn ($i) => $i['action'] === 'archive' && $i['unverified'] === false), 'Kontrola: grupa i repeater → archive');
		$same(2, $plan['counts']['archive'], 'Kontrola: tylko 2 kontenery do archiwum');
	});

	echo "\n== P0-2: warstwy wewnątrz instancji ==\n";

	$t('FigmaUrl::isLayerId: instancje dozwolone, śmieci odrzucone', function () use ($ok) {
		foreach (['12:34', 'I12:34;56:78', 'I1:2;3:4;5:6'] as $id) {
			$ok(FigmaUrl::isLayerId($id), "Poprawny identyfikator {$id}");
		}

		foreach (['', 'abc', 'I12:34', '12:34;56:78', '1-2', '../1:2', '1:2;', 'I1:2;3:4 OR 1=1', 'I1:2' . str_repeat(';1234567:1234567', 20)] as $id) {
			$ok(! FigmaUrl::isLayerId($id), 'Odrzucony identyfikator ' . var_export($id, true));
		}

		$ok(! FigmaUrl::isNodeId('I12:34;56:78'), 'Link podstrony nadal wymaga zwykłego frame\'u');
	});

	$t('Walidacja reguły przyjmuje ID instancji (wcześniej 422)', function () use ($ok) {
		$rules = (new UpdateFigmaNodeRuleRequest())->rules();

		$ok(Validator::make(['node' => 'I1:30;2:5', 'action' => 'exclude'], $rules)->passes(), 'ID instancji');
		$ok(Validator::make(['node' => '1:21', 'action' => 'exclude'], $rules)->passes(), 'Zwykłe ID');
		$ok(Validator::make(['node' => 'I1:30;2:5<script>', 'action' => 'exclude'], $rules)->fails(), 'Śmieci odrzucone');
		$ok(Validator::make(['node' => ['I1:30;2:5'], 'action' => 'exclude'], $rules)->fails(), 'Tablica odrzucona');
	});

	$t('setRule: warstwa instancji z frame\'u podstrony → reguła zapisana i stosowana', function () use ($service, $page, $admin, $refetch, $document, $same) {
		$refetch($document(), '6');
		$service->setRule($page, 'I1:30;2:5', 'exclude', $admin);

		$same('exclude', FigmaNodeRule::query()->where('page_id', $page->id)->where('node_id', 'I1:30;2:5')->value('action'), 'Reguła zapisana');
		$same(true, collect($service->plan($page)['analysis']['excluded'])->contains('node_id', 'I1:30;2:5'), 'Warstwa wykluczona w analizie');

		$service->setRule($page, 'I1:30;2:5', 'reset', $admin);
	});

	$t('setRule: ID instancji spoza frame\'u tej podstrony → odmowa (autoryzacja bez zmian)', function () use ($service, $page, $admin, $throws) {
		$throws(FigmaImportException::class, fn () => $service->setRule($page, 'I9:9;9:9', 'exclude', $admin), 'Obca warstwa');
	});

	echo "\n== P0-3: duplikowanie podstron ==\n";

	$root = $pages->create($project, ['name' => 'Usługi'], $admin);
	$child = $pages->create($project, ['name' => 'Projektowanie', 'parent_id' => $root->id], $admin);

	$t('Duplikat podstrony podrzędnej zostaje pod tym samym rodzicem (domyślnie)', function () use ($duplication, $child, $root, $admin, $same) {
		$copy = $duplication->duplicate($child, ['name' => 'Projektowanie kopia'], $admin);
		$same((int) $root->id, (int) $copy->parent_id, 'Rodzic zachowany');
	});

	$t('DuplicatePageRequest: brak parent_id = bez klucza (rodzic źródła); puste = poziom główny', function () use ($same, $ok, $duplication, $child, $admin) {
		$options = function (array $input) {
			$request = DuplicatePageRequest::create('/', 'POST', $input);
			$request->setContainer(app());
			$request->setValidator(Validator::make($request->all(), $request->rules()));

			return $request->options();
		};

		$ok(! array_key_exists('parent_id', $options(['name' => 'X'])), 'Formularz bez parent_id nie wymusza korzenia');
		$explicit = $options(['parent_id' => '']);
		$ok(array_key_exists('parent_id', $explicit) && ! $explicit['parent_id'], 'Puste parent_id = jawny korzeń');

		$copy = $duplication->duplicate($child, $options(['name' => 'Z formularza']), $admin);
		$same((int) $child->parent_id, (int) $copy->parent_id, 'Duplikat z formularza obok źródła');
	});

	$t('Zarchiwizowany rodzic: duplikat nie może trafić pod archiwalną podstronę', function () use ($duplication, $pages, $project, $child, $admin, $throws) {
		$archived = $pages->create($project, ['name' => 'Archiwum'], $admin);
		$pages->archive($archived, $admin);

		$throws(PageStructureException::class, fn () => $duplication->duplicate($child, ['parent_id' => $archived->id], $admin), 'Archiwalny rodzic', 'zarchiwizowan');
	});

	$chain = [$pages->create($project, ['name' => 'L0'], $admin)];

	for ($i = 1; $i <= 3; $i++) {
		$chain[$i] = $pages->create($project, ['name' => 'L' . $i, 'parent_id' => $chain[$i - 1]->id], $admin);
	}

	$t('Limit głębokości: duplikat pod podstroną na maksymalnym poziomie → odmowa', function () use ($duplication, $chain, $root, $admin, $throws) {
		$throws(PageStructureException::class, fn () => $duplication->duplicate($root, ['parent_id' => $chain[3]->id], $admin), 'Poziom 4 > 3', 'zagnieżdżenia');
	});

	$t('Limit głębokości z poddrzewem: with_children liczy wysokość kopiowanego poddrzewa', function () use ($duplication, $pages, $project, $chain, $admin, $throws, $same) {
		$branch = $pages->create($project, ['name' => 'Gałąź'], $admin);
		$twig = $pages->create($project, ['name' => 'Gałązka', 'parent_id' => $branch->id], $admin);
		$pages->create($project, ['name' => 'Liść', 'parent_id' => $twig->id], $admin);

		// Poddrzewo „Gałęzi” ma wysokość 2: pod L1 (poziom 1) kopia sięgnęłaby poziomu 4.
		$throws(PageStructureException::class, fn () => $duplication->duplicate($branch, ['parent_id' => $chain[1]->id, 'with_children' => true], $admin), 'Poddrzewo za głębokie', 'zagnieżdżenia');

		$copy = $duplication->duplicate($branch, ['parent_id' => $chain[1]->id], $admin);
		$same(2, $copy->depth(), 'Bez poddrzewa ta sama operacja jest dozwolona');
	});

	$t('Poddrzewo: zarchiwizowane podstrony nie są kopiowane ani liczone do limitu', function () use ($duplication, $pages, $project, $chain, $admin, $same) {
		$source = $pages->create($project, ['name' => 'Źródło'], $admin);
		$old = $pages->create($project, ['name' => 'Stara', 'parent_id' => $source->id], $admin);
		$pages->archive($old, $admin);

		$copy = $duplication->duplicate($source, ['parent_id' => $chain[2]->id, 'with_children' => true], $admin);

		$same(3, $copy->depth(), 'Kopia na maksymalnym poziomie');
		$same(0, Page::query()->where('parent_id', $copy->id)->count(), 'Zarchiwizowane dziecko nie zostało skopiowane');
	});

	$t('Poddrzewo: duplikat z dziećmi odtwarza strukturę pod tym samym rodzicem', function () use ($duplication, $pages, $project, $root, $admin, $same) {
		$parent = $pages->create($project, ['name' => 'Rodzic', 'parent_id' => $root->id], $admin);
		$kid = $pages->create($project, ['name' => 'Dziecko', 'parent_id' => $parent->id], $admin);

		$copy = $duplication->duplicate($parent, ['with_children' => true], $admin);
		$kids = Page::query()->where('parent_id', $copy->id)->get();

		$same((int) $root->id, (int) $copy->parent_id, 'Kopia obok źródła');
		$same(1, $kids->count(), 'Jedno dziecko w kopii');
		$same(0, Page::query()->where('parent_id', $kid->id)->count(), 'Źródło bez zmian');
		$same(ElementSource::Manual, $copy->source, 'Duplikat jako manual');
	});

	$t('Duplikat nie może trafić do własnego poddrzewa (regresja)', function () use ($duplication, $root, $child, $admin, $throws) {
		$throws(PageStructureException::class, fn () => $duplication->duplicate($root, ['parent_id' => $child->id], $admin), 'Własne poddrzewo');
	});
} finally {
	DB::rollBack();
}

exit(Harness::summary('Krok 00 — logika'));
