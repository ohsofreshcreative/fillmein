<?php

namespace App\View\Composers;

use App\Models\Notification;
use App\Support\Staff;
use Illuminate\Support\Facades\Gate;
use Roots\Acorn\View\Composer;

/**
 * Dane layoutu panelu pracowników Fill Me In (/app).
 */
class AppLayout extends Composer
{
	protected static $views = [
		'fillmein.layouts.app',
	];

	public function with(): array
	{
		$user = Staff::current();

		return [
			'staffUser' => $user,
			'staffRole' => $user ? Staff::roleLabel($user) : null,
			'navigation' => array_values(array_filter([
				['label' => 'Pulpit', 'route' => 'fmi.dashboard', 'active' => 'fmi.dashboard', 'icon' => 'home'],
				['label' => 'Projekty', 'route' => 'fmi.design-review.index', 'active' => 'fmi.design-review.*', 'icon' => 'review'],
				['label' => 'Treści', 'route' => 'fmi.projects.index', 'active' => 'fmi.projects.*', 'icon' => 'folder'],
				['label' => 'Powiadomienia', 'route' => 'fmi.notifications.index', 'active' => 'fmi.notifications.*', 'icon' => 'bell', 'count' => $user ? Notification::query()->for($user)->unread()->count() : 0],
				Gate::allows('manage-users')
					? ['label' => 'Użytkownicy', 'route' => 'fmi.users.index', 'active' => 'fmi.users.*', 'icon' => 'users']
					: null,
				Gate::allows('manage-integrations')
					? ['label' => 'Figma', 'route' => 'fmi.settings.figma', 'active' => 'fmi.settings.figma*', 'icon' => 'figma']
					: null,
			])),
			'profileUrl' => route('fmi.profile'),
		];
	}
}
