<?php

namespace App\Enums;

enum ReviewRevisionStatus: string
{
	case Draft = 'draft';
	case InReview = 'in_review';
	case ChangesRequested = 'changes_requested';
	case Approved = 'approved';
	case Superseded = 'superseded';

	public function label(): string
	{
		return match ($this) {
			// Rewizja bez decyzji (od etapu 10 Design Review): czeka na akceptację albo prośbę o poprawki.
			self::Draft => 'Oczekuje na decyzję',
			self::InReview => 'W recenzji',
			self::ChangesRequested => 'Do poprawy',
			self::Approved => 'Zaakceptowana',
			self::Superseded => 'Zastąpiona',
		};
	}

	public function isPending(): bool
	{
		return $this === self::Draft || $this === self::InReview;
	}

	/**
	 * Wariant komponentu <x-ui.badge>.
	 */
	public function badge(): string
	{
		return match ($this) {
			self::Draft => 'neutral',
			self::InReview => 'fresh',
			self::ChangesRequested => 'warning',
			self::Approved => 'success',
			self::Superseded => 'muted',
		};
	}
}
