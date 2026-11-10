<?php

namespace App\Support;

/**
 * Szyfrowanie sekretów przechowywanych w bazie. Klucz pochodzi z soli WordPressa z `wp-config.php`, więc sam zrzut
 * bazy nie wystarcza do odczytu. Zmiana soli unieważnia zapisane dane: `open()` zwraca wtedy null.
 */
final class SecretBox
{
	private const CONTEXT = 'fmi-secret-box-v1';

	public static function seal(#[\SensitiveParameter] string $plain): string
	{
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

		return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
	}

	public static function open(string $sealed): ?string
	{
		$raw = base64_decode($sealed, true);

		if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
			return null;
		}

		$plain = sodium_crypto_secretbox_open(
			substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
			self::key(),
		);

		return $plain === false ? null : $plain;
	}

	private static function key(): string
	{
		return hash_hmac('sha256', self::CONTEXT, wp_salt('auth'), true);
	}
}
