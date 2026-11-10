<?php

namespace App\Providers;

use Roots\Acorn\Sage\SageServiceProvider;

/**
 * Bazowy provider Sage (widoki, hierarchia szablonów). Rejestracja aplikacji: FillMeInServiceProvider.
 */
class ThemeServiceProvider extends SageServiceProvider
{
	public function register()
	{
		parent::register();
	}

	public function boot()
	{
		parent::boot();
	}
}
