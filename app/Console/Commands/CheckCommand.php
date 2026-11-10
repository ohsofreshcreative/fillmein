<?php

namespace App\Console\Commands;

use App\Services\RoleManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Diagnostyka fundamentu: połączenie z bazą, tabele, migracje, role, trasy, assety.
 * Tylko odczyt — niczego nie zmienia.
 */
class CheckCommand extends Command
{
	protected $signature = 'fmi:check';

	protected $description = 'Fill Me In: sprawdza, czy fundament aplikacji jest poprawnie zainstalowany';

	private const TABLES = [
		'fmi_projects',
		'fmi_project_members',
		'fmi_client_accesses',
		'fmi_pages',
		'fmi_sections',
		'fmi_fields',
		'fmi_repeater_rows',
		'fmi_field_values',
		'fmi_field_revisions',
		'fmi_assets',
		'fmi_field_value_assets',
		'fmi_wp_mappings',
		'fmi_exports',
		'fmi_activity_log',
		'fmi_figma_frames',
		'fmi_figma_previews',
		'fmi_figma_node_rules',
	];

	private const ROUTES = [
		'fmi.login',
		'fmi.logout',
		'fmi.password.set',
		'fmi.dashboard',
		'fmi.profile',
		'fmi.settings.figma',
		'fmi.settings.figma.callback',
		'fmi.projects.index',
		'fmi.projects.create',
		'fmi.projects.store',
		'fmi.projects.show',
		'fmi.projects.edit',
		'fmi.projects.update',
		'fmi.projects.status',
		'fmi.projects.members',
		'fmi.projects.destroy',
		'fmi.projects.pages.store',
		'fmi.projects.pages.edit',
		'fmi.projects.pages.update',
		'fmi.projects.pages.duplicate',
		'fmi.projects.pages.archive',
		'fmi.projects.pages.restore',
		'fmi.projects.pages.reorder',
		'fmi.projects.mapping',
		'fmi.projects.mapping.update',
		'fmi.projects.exports',
		'fmi.projects.exports.store',
		'fmi.projects.exports.download',
		'fmi.projects.activity',
		'fmi.projects.review',
		'fmi.projects.review.mark',
		'fmi.projects.review.clear',
		'fmi.projects.pages.figma.fetch',
		'fmi.projects.pages.figma.preview',
		'fmi.projects.pages.figma.import',
		'fmi.projects.pages.figma.import.apply',
		'fmi.projects.pages.figma.rules',
		'fmi.projects.pages.structure',
		'fmi.projects.pages.sections.store',
		'fmi.projects.pages.sections.reorder',
		'fmi.projects.pages.sections.edit',
		'fmi.projects.pages.sections.update',
		'fmi.projects.pages.sections.archive',
		'fmi.projects.pages.sections.restore',
		'fmi.projects.pages.sections.fields.create',
		'fmi.projects.pages.sections.fields.store',
		'fmi.projects.pages.sections.fields.reorder',
		'fmi.projects.pages.fields.edit',
		'fmi.projects.pages.fields.update',
		'fmi.projects.pages.fields.archive',
		'fmi.projects.pages.fields.restore',
		'fmi.users.index',
		'fmi.users.create',
		'fmi.users.edit',
		'fmi.projects.client-access.store',
		'fmi.projects.client-access.regenerate',
		'fmi.projects.client-access.revoke',
		'fmi.client.editor',
		'fmi.client.fields.save',
		'fmi.client.rows.store',
		'fmi.client.rows.reorder',
		'fmi.client.rows.destroy',
		'fmi.client.fields.restore',
		'fmi.client.assets.store',
		'fmi.client.assets.reorder',
		'fmi.client.assets.detach',
		'fmi.client.assets.alt',
		'fmi.projects.assets.show',
		'fmi.projects.pages.content.assets.store',
		'fmi.projects.pages.content.assets.reorder',
		'fmi.projects.pages.content.assets.detach',
		'fmi.projects.pages.content.assets.alt',
		'fmi.projects.pages.content.edit',
		'fmi.projects.pages.content.history',
		'fmi.projects.pages.content.save',
		'fmi.projects.pages.content.restore',
		'fmi.projects.pages.content.rows.store',
		'fmi.projects.pages.content.rows.reorder',
		'fmi.projects.pages.content.rows.destroy',
	];

	private bool $failed = false;

	public function handle(): int
	{
		$this->check('Połączenie z bazą (PDO)', function () {
			DB::connection()->getPdo();

			return DB::connection()->getDatabaseName();
		});

		foreach (self::TABLES as $table) {
			$this->check("Tabela {$table}", fn () => Schema::hasTable($table) ?: throw new \RuntimeException('brak — uruchom `wp acorn migrate`'));
		}

		$this->check('Migracje', function () {
			$files = collect(glob(database_path('migrations/*.php')))->map(fn ($file) => basename($file, '.php'));
			$ran = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration') : collect();
			$pending = $files->diff($ran);

			return $pending->isEmpty() ? 'wszystkie wykonane' : throw new \RuntimeException('oczekujące: ' . $pending->implode(', '));
		});

		foreach (array_keys(RoleManager::CAPABILITIES) as $role) {
			$this->check("Rola {$role}", function () use ($role) {
				$wpRole = get_role($role) ?? throw new \RuntimeException('brak — uruchom `wp acorn fmi:roles`');
				$missing = array_diff(RoleManager::CAPABILITIES[$role], array_keys(array_filter($wpRole->capabilities)));

				return $missing ? throw new \RuntimeException('brak capabilities: ' . implode(', ', $missing)) : 'ok';
			});
		}

		foreach (self::ROUTES as $name) {
			$this->check("Trasa {$name}", fn () => Route::has($name) ? '/' . Route::getRoutes()->getByName($name)->uri() : throw new \RuntimeException('niezarejestrowana'));
		}

		$this->check('Hierarchia podstron (etap 03)', function () {
			$required = ['parent_id', 'parent_key', 'figma_frame_url', 'client_can_duplicate', 'duplicated_from_id'];
			$missing = array_values(array_filter($required, fn ($column) => ! Schema::hasColumn('fmi_pages', $column)));

			return $missing ? throw new \RuntimeException('brak kolumn w fmi_pages: ' . implode(', ', $missing) . ' — uruchom `wp acorn migrate`') : 'ok';
		});

		$this->check('Analiza warstw Figmy (etap 05)', function () {
			$missing = array_values(array_filter(
				['fmi_sections', 'fmi_fields'],
				fn ($table) => ! Schema::hasColumn($table, 'figma_meta'),
			));

			return $missing ? throw new \RuntimeException('brak kolumny figma_meta w: ' . implode(', ', $missing) . ' — uruchom `wp acorn migrate`') : 'ok';
		});

		// Informacyjnie: bez tokenu działa wszystko poza pobieraniem z Figmy. Wartości nie wypisujemy.
		$this->check('Dostęp do Figma API', fn () => match (true) {
			\App\Integrations\Figma\FigmaClient::configuredToken() !== null => 'stała FMI_FIGMA_TOKEN',
			app(\App\Integrations\Figma\FigmaConnection::class)->isConnected() => 'połączenie OAuth',
			default => 'brak — pobieranie frame\'ów z Figmy niedostępne',
		});

		$this->check('Katalog storage (sesje, cache)', fn () => is_writable(storage_path('framework/sessions')) ? storage_path() : throw new \RuntimeException('brak zapisu: ' . storage_path('framework/sessions')));

		$this->check('Prywatny magazyn plików (dysk fmi)', function () {
			$path = (string) config('fillmein.assets.path');
			$public = rtrim(str_replace('\\', '/', ABSPATH), '/');

			if (str_starts_with(str_replace('\\', '/', $path), $public . '/')) {
				throw new \RuntimeException("{$path} leży w katalogu publicznym — ustaw FMI_STORAGE_PATH poza {$public}");
			}

			$writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));

			return $writable ? $path . (is_dir($path) ? '' : ' (zostanie utworzony przy pierwszym uploadzie)') : throw new \RuntimeException("brak zapisu: {$path}");
		});

		$this->check('Assety aplikacji (yarn build)', function () {
			$manifest = public_path('build/manifest.json');
			$entries = is_file($manifest) ? json_decode((string) file_get_contents($manifest), true) : [];

			return isset($entries['resources/css/fillmein.css'], $entries['resources/js/fillmein.js'])
				? 'ok'
				: throw new \RuntimeException('brak wpisów fillmein w public/build/manifest.json — uruchom `yarn build`');
		});

		$this->newLine();
		$this->failed ? $this->error('Wykryto problemy.') : $this->info('Fundament Fill Me In działa poprawnie.');

		return $this->failed ? self::FAILURE : self::SUCCESS;
	}

	private function check(string $label, callable $callback): void
	{
		try {
			$result = $callback();
			$this->line(sprintf('  <info>✔</info> %s%s', $label, is_string($result) ? " — {$result}" : ''));
		} catch (Throwable $e) {
			$this->failed = true;
			$this->line(sprintf('  <error>✘</error> %s — %s', $label, $e->getMessage()));
		}
	}
}
