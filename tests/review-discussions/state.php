<?php

/**
 * Stan dla testu HTTP: `state.php thread <id>` → status|wersja|wiadomości|wpisy historii; `state.php rid <uuid>` → liczba wiadomości.
 */

use App\Models\ReviewComment;
use App\Models\ReviewThread;

[$what, $value] = [$args[0] ?? '', $args[1] ?? ''];

if ($what === 'rid') {
	echo ReviewComment::query()->where('request_id', $value)->count();

	return;
}

$thread = ReviewThread::query()->withCount(['comments', 'events'])->findOrFail((int) $value);
echo implode('|', [$thread->status->value, $thread->lock_version, $thread->comments_count, $thread->events_count]);
