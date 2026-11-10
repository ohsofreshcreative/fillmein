<?php

namespace App\Services\Import;

/**
 * Warunki pracy importera: wyłącznie fillmein.local, świeży backup i ACF załadowany na czas polecenia.
 */
class ImportEnvironment
{
	public const SITE = 'http://fillmein.local';

	private const BACKUP_MAX_AGE = 3600;

	private const ACF_FILE = '/advanced-custom-fields-pro/acf.php';

	private const ACF_HEALTH_HOOK = 'acf_update_site_health_data';

	private const ACF_HEALTH_OPTION = 'acf_site_health';

	public function assertSite(): void
	{
		foreach (['home_url' => home_url(), 'siteurl' => site_url()] as $name => $url) {
			if (rtrim($url, '/') !== self::SITE) {
				throw new ImportRefused(sprintf('Importer działa tylko na %s (%s jest inny).', self::SITE, $name));
			}
		}
	}

	public function assertBackup(?string $path): void
	{
		if (! $path) {
			throw new ImportRefused('--apply wymaga --backup=<plik>.');
		}

		if (is_link($path) || ! is_file($path) || filesize($path) === 0) {
			throw new ImportRefused('Plik backupu nie istnieje, jest pusty albo jest dowiązaniem.');
		}

		$real = (string) realpath($path);

		if (str_starts_with($real, dirname(__DIR__, 3) . '/')) {
			throw new ImportRefused('Plik backupu leży w katalogu motywu.');
		}

		$age = time() - (int) filemtime($real);

		if ($age < 0 || $age >= self::BACKUP_MAX_AGE) {
			throw new ImportRefused('Plik backupu jest starszy niż 60 min.');
		}
	}

	/**
	 * Wtyczka jest zainstalowana, ale nieaktywna, a aktywacja zmieniłaby opcję `active_plugins`. Ładujemy ją więc
	 * tylko w tym procesie. `init` już minął, stąd ręczne wywołania; updater ACF (hak `init`) się nie uruchamia.
	 */
	public function loadAcf(): void
	{
		if (! function_exists('acf_import_field_group')) {
			$file = WP_PLUGIN_DIR . self::ACF_FILE;

			if (! is_file($file)) {
				throw new ImportRefused('Brak wtyczki ACF Pro.');
			}

			$this->blockAcfWrites();

			require_once $file;

			acf()->register_post_status();
			acf()->init();
			acf()->register_post_types();
		}

		acf_update_setting('json', false);

		if (! function_exists('update_field') || ! acf_get_field_type('repeater')) {
			throw new ImportRefused('ACF Pro nie jest dostępny.');
		}
	}

	/**
	 * Site Health ACF już przy ładowaniu dopisuje zdarzenie do opcji `cron`, a przy zapisie grupy tworzy własną opcję.
	 * Importer nie może zmieniać opcji, także w dry-run, więc oba zapisy są blokowane przed załadowaniem wtyczki.
	 */
	private function blockAcfWrites(): void
	{
		add_filter('pre_schedule_event', fn ($pre, $event) => $event->hook === self::ACF_HEALTH_HOOK ? false : $pre, 10, 2);
		add_filter('pre_update_option_' . self::ACF_HEALTH_OPTION, fn ($value, $old) => $old, 10, 2);
	}
}
