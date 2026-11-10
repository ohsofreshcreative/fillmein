<?php

namespace App\Http\Controllers\App;

use App\Exceptions\FieldConfigException;
use Illuminate\Validation\ValidationException;

/**
 * Zamiana FieldConfigException na błąd walidacji: formularz wraca z komunikatem, a żądanie JSON dostaje 422.
 */
trait HandlesFieldConfig
{
	private function configuring(callable $operation): mixed
	{
		try {
			return $operation();
		} catch (FieldConfigException $e) {
			if ($e->requiresConfirmation) {
				session()->flash('confirm_type_change', true);
			}

			throw ValidationException::withMessages([$e->field => $e->getMessage()]);
		}
	}
}
