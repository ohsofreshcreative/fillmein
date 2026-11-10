<?php

namespace App\Http\Requests;

use App\Enums\ProjectModule;
use Illuminate\Validation\Rule;

/**
 * Moduły projektu w formularzu: pola `modules[]` + znacznik `modules_sent`. Bez znacznika (np. starsze formularze,
 * skrypty) moduły nie są zmieniane — nowy projekt dostaje wtedy same Treści, jak przed wprowadzeniem modułów.
 */
trait ValidatesProjectModules
{
	protected function moduleRules(): array
	{
		return [
			'modules_sent' => ['sometimes', 'accepted'],
			'modules' => ['required_with:modules_sent', 'array', 'min:1'],
			'modules.*' => ['string', 'distinct', Rule::enum(ProjectModule::class)],
		];
	}

	protected function moduleMessages(): array
	{
		return [
			'modules.required_with' => 'Wybierz co najmniej jeden moduł projektu.',
			'modules.min' => 'Wybierz co najmniej jeden moduł projektu.',
		];
	}
}
