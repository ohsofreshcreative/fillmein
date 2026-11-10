<?php

// Treść do podglądu: wartość z HTML (ucieczka), pole puste, pole ukryte przed klientem. Dane etapu 03.

use App\Models\Page;
use App\Models\Project;
use App\Services\ContentService;
use App\Services\SectionService;
use App\Support\Actor;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
wp_set_current_user($admin->ID);

$page = Page::query()->findOrFail($manifest['pages']['a']);
$service = app(SectionService::class);
$section = $service->create($page, ['name' => 'Sekcja podglądu'], $admin);
$make = fn (array $data) => $service->createField($section, $data + ['type' => 'text'], $admin);

$filled = $make(['label' => 'Pole wypełnione']);
$make(['label' => 'Pole puste']);
$hidden = $make(['label' => 'Pole ukryte przed klientem']);
$hidden->forceFill(['is_hidden' => true])->save();

app(ContentService::class)->save($filled, '<script>alert(1)</script>Treść klienta', 0, Actor::user($admin));
