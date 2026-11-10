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
 * i masowa „Analiza i import pól”. Każde wywołanie obsługuje jedną podstronę i jest krótkie: struktura warstw
 * osobno, podglądy PNG w porcjach (previews) — pełne pobranie z podglądami trwa nawet minutę, a hosting
 * przerywa dłuższe żądania błędem 500.
 *
 * Blokada (opcja WP dodawana atomowo) chroni przed równoległym pobieraniem tej samej podstrony, np. po
 * przeładowaniu listy w trakcie pracy kolejki.
 */
class FigmaQueueService
{
	private const LOCK_SECONDS = 300;

	/** Budżet czasu jednej porcji podglądów — mieści się w typowym limicie 30 s hostingu. */
	private const PREVIEW_BUDGET = 12.0;

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
	 * Struktura warstw bez podglądów; status `continue` = kolejka dociąga podglądy przez previews().
	 *
	 * @return array{status: string, message: string}
	 */
	public function fetch(Page $page, WP_User $by): array
	{
		return $this->exclusive($page, function () use ($page, $by) {
			$result = $this->frames->fetch($page, $by, false, false);
			$name = $result['frame']->node_name ?? $result['frame']->node_id;

			return ['status' => 'continue', 'message' => sprintf('Pobrano strukturę „%s”, pobieranie podglądów…', $name)];
		});
	}

	/**
	 * Porcja podglądów PNG; `continue`, dopóki zostały pliki do pobrania i poprzednia porcja coś pobrała.
	 *
	 * @return array{status: string, message: string}
	 */
	public function previews(Page $page): array
	{
		return $this->exclusive($page, function () use ($page) {
			$batch = $this->frames->previewBatch($page, self::PREVIEW_BUDGET);
			$name = $this->frames->frameFor($page)?->node_name ?? 'frame';

			if ($batch['remaining'] > 0 && $batch['downloaded'] > 0) {
				return ['status' => 'continue', 'message' => sprintf('Pobieranie podglądów — zostało %d.', $batch['remaining'])];
			}

			$missing = $batch['failed'] + $batch['remaining'];

			return $missing > 0
				? ['status' => 'warning', 'message' => sprintf('Pobrano „%s”, ale %d podglądów się nie pobrało — spróbuj „Pobierz z Figmy”.', $name, $missing)]
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
			// Świeże dane: wcześniejszy cache mógł mieć płytsze drzewo albo nieaktualną makietę. Podglądy nie są
			// potrzebne do importu — pobiera je przycisk „Pobierz z Figmy” albo kolejka po dodaniu podstrony.
			$this->frames->fetch($page, $by, true, false);
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
		// Hosting bywa z wyłączonymi funkcjami (disable_functions) — wtedy w PHP 8 nawet wywołanie rzuca Error.
		if (function_exists('ignore_user_abort')) {
			ignore_user_abort(true);
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit(120);
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
