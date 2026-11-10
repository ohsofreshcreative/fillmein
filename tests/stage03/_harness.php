<?php

/**
 * Minimalny harness testowy etapu 03.
 *
 * W repozytorium nie ma PHPUnit/Pest (require-dev = tylko laravel/pint), a instalowanie
 * runnera zmieniłoby composer.lock motywu. Testy uruchamiamy więc przez `wp acorn`/`wp eval-file`
 * na PRAWDZIWEJ bazie LocalWP: prawdziwe modele, serwisy, polityki i Gate.
 * Testy logiki działają w transakcji wycofywanej na końcu — nie zostawiają danych.
 *
 * Uruchomienie: tests/stage03/run.sh
 */

final class Harness
{
	public static array $results = [];

	public static function test(string $name, callable $fn): void
	{
		try {
			$fn();
			self::$results[] = ['name' => $name, 'ok' => true, 'message' => ''];
			fwrite(STDOUT, "  PASS  {$name}\n");
		} catch (\Throwable $e) {
			self::$results[] = ['name' => $name, 'ok' => false, 'message' => $e->getMessage()];
			fwrite(STDOUT, "  FAIL  {$name}\n        → " . $e->getMessage() . "\n");
		}
	}

	public static function assert(bool $condition, string $message): void
	{
		if (! $condition) {
			throw new \RuntimeException($message);
		}
	}

	public static function same($expected, $actual, string $message): void
	{
		if ($expected !== $actual) {
			throw new \RuntimeException(sprintf(
				'%s (oczekiwano: %s, otrzymano: %s)',
				$message,
				var_export($expected, true),
				var_export($actual, true),
			));
		}
	}

	/**
	 * Oczekiwany wyjątek danej klasy; opcjonalnie fragment komunikatu.
	 */
	public static function throws(string $class, callable $fn, string $message, ?string $contains = null): void
	{
		try {
			$fn();
		} catch (\Throwable $e) {
			if (! $e instanceof $class) {
				throw new \RuntimeException($message . ' — zgłoszono ' . get_class($e) . ': ' . $e->getMessage());
			}

			if ($contains !== null && ! str_contains($e->getMessage(), $contains)) {
				throw new \RuntimeException($message . ' — komunikat nie zawiera „' . $contains . '”: ' . $e->getMessage());
			}

			return;
		}

		throw new \RuntimeException($message . ' — nie zgłoszono żadnego wyjątku');
	}

	public static function summary(string $suite): int
	{
		$total = count(self::$results);
		$failed = count(array_filter(self::$results, fn ($r) => ! $r['ok']));

		fwrite(STDOUT, sprintf("\n%s: %d/%d PASS, %d FAIL\n", $suite, $total - $failed, $total, $failed));

		return $failed === 0 ? 0 : 1;
	}
}
