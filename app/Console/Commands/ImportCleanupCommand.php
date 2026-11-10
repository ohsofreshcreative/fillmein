<?php

namespace App\Console\Commands;

use App\Services\Import\ImportCleanup;
use App\Services\Import\ImportEnvironment;
use App\Services\Import\ImportRefused;
use Illuminate\Console\Command;

class ImportCleanupCommand extends Command
{
	protected $signature = 'fmi:import:cleanup {--source= : tylko jedno źródło importu} {--apply : wykonaj usuwanie} {--backup= : świeży backup bazy}';

	protected $description = 'Fill Me In: usuwa elementy importu (domyślnie dry-run, tylko fillmein.local)';

	public function handle(ImportEnvironment $environment, ImportCleanup $cleanup): int
	{
		$apply = (bool) $this->option('apply');
		$source = $this->option('source') ?: null;

		try {
			$environment->assertSite();

			if ($apply) {
				$environment->assertBackup($this->option('backup'));
			}

			$counts = $apply ? $cleanup->apply($source) : $cleanup->plan($source)['counts'];
		} catch (ImportRefused $e) {
			$this->error('ODMOWA: ' . $e->getMessage());

			return ImportCommand::REFUSED;
		}

		$this->line($apply ? 'Sprzątanie' : 'Dry-run (bez zapisu)');
		$this->table(['Typ wpisu', 'Do usunięcia'], array_map(fn (string $type, int $count) => [$type, $count], array_keys($counts), $counts));
		$this->line('Razem: ' . array_sum($counts));

		return self::SUCCESS;
	}
}
