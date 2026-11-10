<?php

/**
 * Macierz QA — uzupełnia dane scenariusza odbiorczego (tests/review-acceptance/fixtures.php): prototyp (plik
 * niedostępny w Figmie), ekran z obrazem testowym i uwaga z pinezką, decyzja, link recenzji (komentarz + akceptacja).
 * Wynik: /tmp/fmi-review-qa.json (0600, z tokenami tylko dla testu).
 */

use App\Models\Project;
use App\Services\ClientAccessService;
use App\Services\ReviewCommentService;
use App\Services\ReviewService;

$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$ids = json_decode((string) file_get_contents('/tmp/fmi-review-acceptance.json'), true);
$manager = get_user_by('id', (int) $manifest['users']['manager']);
wp_set_current_user($manager->ID);
$q = Project::findOrFail($ids['q']);

app(ReviewService::class)->savePrototype($q, 'https://www.figma.com/proto/ZZtestNoAccess0000000000/Odbior?node-id=1-2', $manager);
$args = ['screen', (string) $q->id];
ob_start();
require __DIR__ . '/../review-acceptance/admin.php';
$screen = (int) trim((string) ob_get_clean());
$comments = app(ReviewCommentService::class);
$pinned = $comments->create($q, ['body' => 'Pinezka QA', 'screen_id' => $screen, 'x' => 0.3, 'y' => 0.25], $manager);
$comments->create($q, ['body' => str_repeat('Bardzo długa uwaga bez spacji_', 40), 'context' => str_repeat('Długi kontekst ', 10)], $manager);
$link = app(ClientAccessService::class)->generateReview($q, $manager, now()->addDays(7), 'QA', true, true);

$path = '/tmp/fmi-review-qa.json';
touch($path);
chmod($path, 0600);
file_put_contents($path, json_encode(['q' => $q->id, 'screen' => $screen, 'pin' => $pinned->number, 'review' => $link->token, 'old' => $ids['old']]));
echo "QA: dane gotowe.\n";
