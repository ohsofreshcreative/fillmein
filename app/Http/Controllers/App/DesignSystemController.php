<?php

namespace App\Http\Controllers\App;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Podgląd fundamentów design systemu (kolory, typografia, komponenty, stany).
 */
class DesignSystemController extends Controller
{
	public function __invoke(): View
	{
		return view('fillmein.design-system', [
			'statuses' => ProjectStatus::cases(),
		]);
	}
}
