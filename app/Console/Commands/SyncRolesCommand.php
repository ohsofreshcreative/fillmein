<?php

namespace App\Console\Commands;

use App\Services\RoleManager;
use Illuminate\Console\Command;

class SyncRolesCommand extends Command
{
	protected $signature = 'fmi:roles';

	protected $description = 'Fill Me In: dodaje brakujące role i capabilities (operacja addytywna)';

	public function handle(RoleManager $roles): int
	{
		$roles->sync();

		foreach (RoleManager::CAPABILITIES as $role => $capabilities) {
			$this->line(sprintf('<info>%s</info> (%s): %s', RoleManager::LABELS[$role], $role, implode(', ', $capabilities)));
		}

		$this->info('Role zsynchronizowane.');

		return self::SUCCESS;
	}
}
