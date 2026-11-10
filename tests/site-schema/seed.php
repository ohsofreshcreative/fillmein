<?php

/**
 * Schemat strony — projekt testowy A: strona główna jak w Figmie (dodatkowa sekcja, brak sekcji tekstu, grupa
 * bez odpowiednika, powtórzony blok CTA) i nowa strona z repeaterem w repeaterze. Treść w części pól.
 */

use App\Models\Project;
use App\Services\ContentService;
use App\Services\PageService;
use App\Services\SectionService;
use App\Support\Actor;

$manifest = json_decode((string) @file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);

if (! is_array($manifest)) {
	fwrite(STDERR, "BLOCKED: brak manifestu danych testowych etapu 03.\n");
	exit(2);
}

$admin = get_userdata((int) $manifest['users']['admin']);
$actor = Actor::user($admin);
$sections = app(SectionService::class);
$content = app(ContentService::class);
$project = Project::query()->findOrFail((int) $manifest['projects']['a']);
$pages = app(PageService::class);

$home = $pages->create($project, ['name' => 'Strona główna', 'slug' => 'strona-glowna'], $admin);
$contact = $pages->create($project, ['name' => 'Kontakt'], $admin);
$f = fn ($section, string $type, string $label, array $extra = []) => $sections->createField($section, ['type' => $type, 'label' => $label] + $extra, $admin);

$hero = $sections->create($home, ['name' => 'Frame 347'], $admin);
$heroTitle = $f($hero, 'text', 'Zachowaj ciepło i oszczędzaj energię');
$heroText = $f($hero, 'textarea', 'Opis');
$heroButton = $f($hero, 'link', 'Przycisk');
$heroImage = $f($hero, 'image', 'Zdjęcie');

$extra = $sections->create($home, ['name' => 'Frame 999'], $admin);
$extraGallery = $f($extra, 'gallery', 'Galeria realizacji');

$slider = $sections->create($home, ['name' => 'Frame 12'], $admin);
$sliderGroup = $f($slider, 'group', 'Nagłówek sekcji');
$sliderTitle = $f($slider, 'text', 'Tytuł', ['parent_id' => $sliderGroup->id]);
$sliderText = $f($slider, 'textarea', 'Opis', ['parent_id' => $sliderGroup->id]);
$cards = $f($slider, 'repeater', 'Karty');
$cardIcon = $f($slider, 'image', 'Ikona', ['parent_id' => $cards->id]);
$cardTitle = $f($slider, 'text', 'Tytuł', ['parent_id' => $cards->id]);

$cta1 = $sections->create($home, ['name' => 'Frame 88'], $admin);
$ctaGroup = $f($cta1, 'group', 'Treść');
$ctaTitle = $f($cta1, 'text', 'Nagłówek', ['parent_id' => $ctaGroup->id]);
$ctaButton = $f($cta1, 'link', 'Przycisk', ['parent_id' => $ctaGroup->id]);

$cta2 = $sections->create($home, ['name' => 'Frame 89'], $admin);
$cta2Title = $f($cta2, 'text', 'Nagłówek');
$cta2Button = $f($cta2, 'link', 'Przycisk');

$features = $sections->create($contact, ['name' => 'Frame 5'], $admin);
$featuresTitle = $f($features, 'text', 'Nagłówek');
$list = $f($features, 'repeater', 'Lista');
$listTitle = $f($features, 'text', 'Tytuł', ['parent_id' => $list->id]);
$inner = $f($features, 'repeater', 'Karty', ['parent_id' => $list->id]);
$innerIcon = $f($features, 'image', 'Ikona', ['parent_id' => $inner->id]);
$innerTitle = $f($features, 'text', 'Tytuł', ['parent_id' => $inner->id]);

$content->save($heroTitle, 'Zachowaj ciepło i oszczędzaj energię', 0, $actor);
$content->save($heroButton, json_encode(['label' => 'Sprawdź', 'url' => 'https://example.com/']), 0, $actor);
$content->save($sliderTitle, 'Nasze produkty', 0, $actor);
[$cardRow] = $content->addRow($cards, 0, $actor);
$content->save($cardTitle, 'Pompa ciepła', 0, $actor, $cardRow);
$content->save($ctaTitle, 'Zadzwoń do nas', 0, $actor);
$content->save($cta2Title, 'Napisz do nas', 0, $actor);
$content->save($featuresTitle, 'Dlaczego my', 0, $actor);
[$listRow] = $content->addRow($list, 0, $actor);
$content->save($listTitle, 'Doświadczenie', 0, $actor, $listRow);
[$innerRow] = $content->addRow($inner, 0, $actor, $listRow);
$content->save($innerTitle, 'Ponad 20 lat', 0, $actor, $innerRow);

$path = '/tmp/fmi-site-schema-ids.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode([
	'pages' => ['home' => $home->id, 'contact' => $contact->id, 'a' => (int) $manifest['pages']['a']],
	'sections' => ['hero' => $hero->id, 'extra' => $extra->id, 'slider' => $slider->id, 'cta1' => $cta1->id, 'cta2' => $cta2->id, 'features' => $features->id],
	'fields' => array_map(fn ($field) => $field->id, compact('heroTitle', 'heroText', 'heroButton', 'heroImage', 'extraGallery', 'sliderGroup', 'sliderTitle', 'sliderText', 'cards', 'cardIcon', 'cardTitle', 'ctaGroup', 'ctaTitle', 'ctaButton', 'cta2Title', 'cta2Button', 'featuresTitle', 'list', 'listTitle', 'inner', 'innerIcon', 'innerTitle')),
]));

fwrite(STDOUT, "Dane schematu strony zasiane.\n");
