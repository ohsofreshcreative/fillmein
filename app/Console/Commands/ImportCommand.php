<?php

namespace App\Console\Commands;

use App\Services\ExportValidator;
use App\Services\Import\ExportPackage;
use App\Services\Import\ImportEnvironment;
use App\Services\Import\ImportRefused;
use App\Services\Import\WpImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportCommand extends Command
{
	public const REFUSED = 4;

	private const ROWS = ['media' => 'Media', 'groups' => 'Grupy ACF', 'fields' => 'Pola ACF', 'pages' => 'Strony', 'values' => 'Wartości'];

	protected $signature = 'fmi:import {path : plik export.zip albo export.json} {--apply : wykonaj zapis} {--backup= : świeży backup bazy}';

	protected $description = 'Fill Me In: import eksportu do WordPressa (domyślnie dry-run, tylko fillmein.local)';

	public function handle(ImportEnvironment $environment, ExportValidator $validator, WpImporter $importer): int
	{
		$apply = (bool) $this->option('apply');

		try {
			$environment->assertSite();

			if ($apply) {
				$environment->assertBackup($this->option('backup'));
			}

			$environment->loadAcf();

			$package = new ExportPackage((string) $this->argument('path'), $validator);
			$report = $apply ? $importer->apply($package) : $importer->plan($package);
		} catch (ImportRefused $e) {
			$this->error('ODMOWA: ' . $e->getMessage());

			return self::REFUSED;
		} catch (Throwable $e) {
			report($e);
			$this->error('Import przerwany: ' . $e->getMessage() . ' Ponowne uruchomienie dokończy zapis.');

			return self::FAILURE;
		}

		$this->line(($apply ? 'Import' : 'Dry-run (bez zapisu)') . ', źródło: ' . $report['source']);
		$this->table(
			['Element', 'Utworzyć', 'Zmienić', 'Usunąć', 'Bez zmian'],
			array_map(fn (string $key, string $label) => [$label, $report[$key]['create'], $report[$key]['update'], $report[$key]['delete'], $report[$key]['skip']], array_keys(self::ROWS), self::ROWS),
		);

		foreach ($report['warnings'] as $warning) {
			$this->warn($warning);
		}

		$sum = fn (string $action) => array_sum(array_map(fn (string $key) => $report[$key][$action], array_keys(self::ROWS)));

		$this->line(sprintf('Razem: utworzyć %d, zmienić %d, usunąć %d, bez zmian %d', $sum('create'), $sum('update'), $sum('delete'), $sum('skip')));

		return self::SUCCESS;
	}
}
