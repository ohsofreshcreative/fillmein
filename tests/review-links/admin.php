<?php

/**
 * Operacje na linkach w trakcie testu: `admin.php revoke <id>`, `expire <id>`, `comment <access_id>` (ostatnia
 * wiadomość gościa: typ|access|nazwa|projekt), `approve <id> 0|1` (test middleware dla możliwości „approve”).
 */

use App\Http\Middleware\ResolveReviewGuest;
use App\Models\ClientAccess;
use App\Models\ReviewComment;
use App\Services\ClientAccessService;
use Illuminate\Http\Request;

[$what, $id] = [$args[0] ?? '', (int) ($args[1] ?? 0)];
$manifest = json_decode((string) file_get_contents('/tmp/fmi-stage03-fixtures.json'), true);
$manager = get_user_by('id', (int) $manifest['users']['manager']);

if ($what === 'revoke') {
	app(ClientAccessService::class)->revoke(ClientAccess::query()->findOrFail($id), $manager);
	echo 'ok';
} elseif ($what === 'expire') {
	ClientAccess::query()->whereKey($id)->update(['expires_at' => now()->subSecond()]);
	echo 'ok';
} elseif ($what === 'comment') {
	$comment = ReviewComment::query()->where('author_access_id', $id)->latest('id')->first();
	echo $comment ? implode('|', [$comment->author_type->value, $comment->author_access_id, $comment->author_name, $comment->project_id, $comment->author_user_id ?? '-']) : 'brak';
} elseif ($what === 'clear-limits') {
	// Tylko klucze limitów wejścia tego testu (adres lokalny), żeby poprzedni przebieg nie blokował kolejnego.
	foreach (['127.0.0.1', '::1'] as $ip) {
		Illuminate\Support\Facades\RateLimiter::clear(md5('fmi-review-link' . 'review-link|' . $ip));
	}
	echo 'ok';
} elseif ($what === 'approve') {
	// Możliwość „approve” egzekwuje middleware `fmi.review-guest:approve` (akcja akceptacji — etap 10).
	$request = Request::create('/review/approval', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
	$request->setLaravelSession($session = app('session')->driver());
	$session->put(ResolveReviewGuest::SESSION_KEY, ['access' => $id, 'name' => 'Test', 'unlocked' => true]);
	$response = app(ResolveReviewGuest::class)->handle($request, fn () => response('passed', 200), 'approve');
	echo $response->getStatusCode();
}
