<?php

namespace App\Support;

use App\Enums\ActorType;
use App\Models\ClientAccess;
use WP_User;

/**
 * Gość recenzji (link klienta zakresu `review`): link + nazwa, którą sam podał. Nazwa jest deklarowana,
 * niepotwierdzona — w interfejsie zawsze z dopiskiem „gość”. Nie jest kontem ani podpisem.
 */
final class ReviewGuest
{
	public const NAME_MAX = 80;

	public function __construct(public readonly ClientAccess $access, public readonly ?string $name)
	{
	}

	/**
	 * Pola autora wpisu (wątek / wiadomość) dla pracownika albo gościa.
	 *
	 * @return array{type: ActorType, user: ?int, access: ?int, name: ?string, actor: Actor}
	 */
	public static function author(WP_User|self $by): array
	{
		if ($by instanceof WP_User) {
			return ['type' => ActorType::User, 'user' => $by->ID, 'access' => null, 'name' => null, 'actor' => Actor::user($by), 'label' => Staff::fullName($by)];
		}

		return ['type' => ActorType::Client, 'user' => null, 'access' => $by->access->id, 'name' => $by->name, 'actor' => Actor::client($by->access), 'label' => ($by->name ?: 'Gość') . ' (gość)'];
	}
}
