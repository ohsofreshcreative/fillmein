<?php

namespace App\Services;

use App\Models\ClientAccess;

/**
 * Wynik wygenerowania linku: rekord + jawny token, dostępny wyłącznie w tym obiekcie.
 * Link Treści ma kopię tokenu zaszyfrowaną w bazie (panel ją pokazuje); token linku recenzji przepada po wyświetleniu.
 */
final class IssuedClientAccess
{
	public function __construct(
		public readonly ClientAccess $access,
		#[\SensitiveParameter] public readonly string $token,
		private readonly string $prefix = '/p/',
	) {
	}

	public function url(): string
	{
		return home_url($this->prefix . $this->token);
	}

	public function __debugInfo(): array
	{
		return ['access' => $this->access, 'token' => '[ukryty]'];
	}
}
