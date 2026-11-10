<?php

namespace App\Http\Requests\Guest;

use App\Http\Requests\StoreReviewCommentRequest;

/**
 * Nowa uwaga gościa. Uprawnienie (link recenzji z `can_comment`) sprawdza middleware `fmi.review-guest:comment`;
 * projekt wynika wyłącznie z linku w sesji — pole projektu w żądaniu nie istnieje.
 */
class StoreGuestReviewCommentRequest extends StoreReviewCommentRequest
{
	public function authorize(): bool
	{
		return true;
	}
}
