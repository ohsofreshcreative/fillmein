<?php

namespace App\Http\Requests\Guest;

use App\Http\Requests\StoreReviewDecisionRequest;

/**
 * Decyzja gościa. Uprawnienie (`can_approve`) — middleware `fmi.review-guest:approve`; projekt tylko z linku.
 */
class StoreGuestReviewDecisionRequest extends StoreReviewDecisionRequest
{
	public function authorize(): bool
	{
		return true;
	}
}
