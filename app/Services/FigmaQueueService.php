<?php

namespace App\Services;

use App\Exceptions\FigmaImportException;
use App\Integrations\Figma\FigmaException;
use App\Integrations\Figma\FigmaUrl;
use App\Models\Page;
use App\Models\Project;
use WP_User;

/**
 * Praca z Figmą wywoływana z kolejki w przeglądarce (lista podstron): pobranie frame'u po dodaniu podstrony
 * i masowa „Analiza i import pól”. Każde wywołanie obsługuje jedną podstronę i kończy się po stronie serwera
 * także wtedy, gdy pracownik przejdzie dalej (ignore_user_abort).
 *
 * Blokada (opcja WP dodawana atomowo) chroni przed równoległym pobieraniem tej samej podstrony, np. po
 * przeładowaniu listy w trakcie pracy kolejki.
 */
class FigmaQueueService
{
	private const LOCK_SECONDS = 300;

	public function __construct(private FigmaFrameService $frames, private FigmaImportService $import)
	{
	}

	/**
	 * Podstrony z linkiem do frame'u, którego jeszcze nie pobrano (do kolejki po wejściu na listę).
	 *
	 * @param iterable<Page> $pages
	 * @return int[]
	 */
	public function pending(Project $project, iterable $pages): array
	{
		if (! $this->frames->isConfigured()) {
			return [];
		}

		$pending = [];

		foreach ($pages as $page) {
			$target = $page->figma_frame_url ? FigmaUrl::frame($page->figma_frame_url) : null;

			if ($target && ! $this->frames->frameForTarget($project, $target)) {
				$pending[] = $page->id;
			}
		}

		return $pending;
	}

	/**
	 * @return array{status: string, message: string}
	 */
	public function fetch(Page $page, WP_User $by): array
	{
		return $this->exclusive($page, function () use ($page, $by) {
			$result = $this->frames->fetch($page, $by);
			$name = $result['frame']->node_name ?? $result['frame']->node_id;

			return $result['previews_failed'] > 0
				? ['status' => 'warning', 'message' => sprintf('Pobrano „%s”, ale %d podglądów się nie pobrało.', $name, $result['previews_failed'])]
				: ['status' => 'done', 'message' => sprintf('Pobrano frame „%s”.', $name)];
		});
	}

	/**
	 * Świeże pobranie frame'u, analiza i import zmian zaznaczanych domyślnie w podglądzie (bez ryzykownych:
	 * nic, co ma treść klienta albo pochodzi z ręcznej konfiguracji, nie jest archiwizowane ani zmieniane).
	 *
	 * @return array{status: string, message: string}
	 */
	public function import(Page $page, WP_User $by): array
	{
		return $this->exclusive($page, function () use ($page, $by) {
			// Świeże dane: wcześniejszy cache mógł mieć płytsze drzewo albo nieaktualną makietę.
			$this->frames->fetch($page, $by, true);
			$plan = $this->import->plan($page);

			if (! $plan['has_changes']) {
				return ['status' => 'done', 'message' => 'Bez zmian — pola są zgodne z Figmą.'];
			}

			$counts = $this->import->apply($page, $by, $plan['fingerprint']);
			$skipped = count($plan['selectable']) - count($plan['defaults']);

			return [
				'status' => $skipped > 0 ? 'warning' : 'done',
				'message' => sprintf('Sekcje i pola: nowe %d, zaktualizowane %d, do archiwum %d.', $counts['create'], $counts['update'], $counts['archive'])
					. ($skipped > 0 ? sprintf(' %d zmian wymaga decyzji w „Analiza i import pól”.', $skipped) : ''),
			];
		});
	}

	private function exclusive(Page $page, callable $work): array
	{
		ignore_user_abort(true);

		if (function_exists('set_time_limit')) {
			@set_time_limit(300);
		}

		$key = 'fmi_figma_queue_' . $page->id;
		$started = (int) get_option($key, 0);

		if ($started && $started > time() - self::LOCK_SECONDS) {
			return ['status' => 'running', 'message' => 'Trwa przetwarzanie tej podstrony.'];
		}

		delete_option($key);

		if (! add_option($key, time(), '', false)) {
			return ['status' => 'running', 'message' => 'Trwa przetwarzanie tej podstrony.'];
		}

		try {
			return $work();
		} catch (FigmaException|FigmaImportException $e) {
			return ['status' => 'error', 'message' => $e->getMessage()];
		} finally {
			delete_option($key);
		}
	}
}
