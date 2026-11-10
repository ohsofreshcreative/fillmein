<?php

namespace App\Http\Requests\Concerns;

use App\Models\Project;
use App\Models\ReviewComment;
use App\Models\ReviewThread;

/**
 * Wątek i wiadomość z adresu zawsze w zakresie projektu z adresu (obcy identyfikator → 404, ochrona IDOR).
 * Formularz każdego wątku ma własny worek błędów (`thread{id}`), żeby komunikat trafił pod właściwy wątek.
 */
trait ResolvesReviewThread
{
	private ?ReviewThread $resolvedThread = null;

	private ?ReviewComment $resolvedComment = null;

	public function project(): Project
	{
		return $this->route('project');
	}

	public function thread(): ReviewThread
	{
		return $this->resolvedThread ??= $this->project()->reviewThreads()->whereNotNull('number')->findOrFail((int) $this->route('thread'));
	}

	public function comment(): ReviewComment
	{
		return $this->resolvedComment ??= ReviewComment::query()
			->where('project_id', $this->project()->id)
			->where('thread_id', $this->thread()->id)
			->findOrFail((int) $this->route('comment'));
	}
}
