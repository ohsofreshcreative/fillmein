<?php

namespace App\Support;

use App\Enums\ActorType;
use App\Models\ClientAccess;
use WP_User;

/**
 * Kto wykonuje operację: pracownik (użytkownik WP), klient (link dostępowy) lub system (CLI, zadania).
 */
final class Actor
{
	private function __construct(
		public readonly ActorType $type,
		public readonly ?int $userId = null,
		public readonly ?int $clientAccessId = null,
	) {
	}

	public static function user(WP_User $user): self
	{
		return new self(ActorType::User, userId: $user->ID);
	}

	public static function client(ClientAccess $access): self
	{
		return new self(ActorType::Client, clientAccessId: $access->id);
	}

	public static function system(): self
	{
		return new self(ActorType::System);
	}
}
