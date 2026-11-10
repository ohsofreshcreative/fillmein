<?php

/**
 * Scenariusz odbiorczy (QA.md) — dane w manifeście etapu 03 (sprzątanie: tests/stage03/teardown.php, kaskady).
 * Q: wspólny projekt (Treści + Design Review), opiekun = manager, członek = member; podstrona, sekcja, pole tekstowe
 *    z istniejącą treścią i „starym” linkiem Treści (/p/{token}) utworzonym przed Design Review.
 * F: obcy projekt z uwagą.
 * Wynik: /tmp/fmi-review-acceptance.json (0600).
 */

use App\Models\Project;
use App\Services\ClientAccessService;
use App\Services\ContentService;
use App\Services\PageService;
use App\Services\ProjectAssignmentService;
use App\Services\ProjectService;
use App\Services\ReviewCommentService;
use App\Services\SectionService;
use App\Support\Actor;

$manifestPath = '/tmp/fmi-stage03-fixtures.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$admin = get_user_by('id', (int) $manifest['users']['admin']);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
$member = get_user_by('id', (int) $manifest['users']['member']);
wp_set_current_user($admin->ID);

$projects = app(ProjectService::class);
$q = $projects->create(['name' => 'TEST odbiór Q ' . $manifest['suffix'], 'client_name' => 'Klient', 'manager_id' => $manager->ID, 'modules' => ['content']], $admin);
app(ProjectAssignmentService::class)->addMember($q, $member->ID, $admin);
$page = app(PageService::class)->create($q, ['name' => 'Strona główna'], $admin);
$sections = app(SectionService::class);
$section = $sections->create($page, ['name' => 'Hero'], $admin);
$field = $sections->createField($section, ['type' => 'text', 'label' => 'Nagłówek'], $admin);
app(ContentService::class)->save($field, 'Istniejąca treść klienta', 0, Actor::user($admin));
$old = app(ClientAccessService::class)->generate($q, $manager, now()->addDays(30), 'Treści (stary link)');
// Design Review włączony później w tym samym projekcie (wspólny system projektów).
Project::query()->whereKey($q->id)->update(['review_enabled' => true]);

$f = $projects->create(['name' => 'TEST odbiór F ' . $manifest['suffix'], 'manager_id' => $admin->ID, 'modules' => ['review']], $admin);
\App\Models\ReviewRevision::create(['project_id' => $f->id, 'number' => 1, 'title' => 'Rewizja 1', 'status' => 'draft', 'figma_file_key' => 'ZZtestNoAccess0000000000', 'created_by' => $admin->ID]);
$ft = app(ReviewCommentService::class)->create($f, ['body' => 'Obca uwaga', 'context' => 'X'], $admin);

$manifest['projects']['acceptance_q'] = $q->id;
$manifest['projects']['acceptance_f'] = $f->id;
file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$path = '/tmp/fmi-review-acceptance.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode(['q' => $q->id, 'f' => $f->id, 'ft' => $ft->id, 'field' => $field->id, 'old' => $old->token, 'old_access' => $old->access->id]));
echo "Odbiór: dane gotowe.\n";
