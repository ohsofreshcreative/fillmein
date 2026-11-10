<?php

namespace App\Support;

use Closure;

/**
 * Reguły walidacji nowego hasła (ustawienie z zaproszenia, zmiana w profilu).
 * Hasło trafia wyłącznie do natywnych funkcji WordPressa — aplikacja go nie przechowuje.
 */
final class PasswordRules
{
	/**
	 * @param string[] $forbidden Wartości, którymi hasło nie może być (login, e-mail).
	 */
	public static function rules(array $forbidden = []): array
	{
		return [
			'required',
			'string',
			'min:' . (int) config('fillmein.auth.password_min_length', 12),
			'max:200',
			'confirmed',
			function (string $attribute, mixed $value, Closure $fail) use ($forbidden) {
				foreach (array_filter($forbidden) as $word) {
					if (mb_strtolower((string) $value) === mb_strtolower($word)) {
						$fail('Hasło nie może być takie samo jak login ani adres e-mail.');
					}
				}

				if (is_string($value) && trim($value) !== $value) {
					$fail('Hasło nie może zaczynać się ani kończyć spacją.');
				}
			},
		];
	}
}
