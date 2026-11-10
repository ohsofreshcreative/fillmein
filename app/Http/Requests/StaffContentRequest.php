<?php

namespace App\Http\Requests;

use Illuminate\Support\Facades\Gate;

/**
 * Zapis treści przez pracownika. Autoryzacja: ProjectPolicy@editContent. Reguły jak u klienta.
 */
class StaffContentRequest extends ClientContentRequest
{
	public function authorize(): bool
	{
		Gate::authorize('editContent', $this->route('project'));

		return true;
	}
}
