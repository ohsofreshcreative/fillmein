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
		// Treści i Design Review to ta sama lista projektów z filtrem modułu (?module=).
		$module = request()->routeIs('fmi.projects.index') ? (string) request()->query('module') : '';

		return [
			'staffUser' => $user,
			'staffRole' => $user ? Staff::roleLabel($user) : null,
			'navigation' => array_values(array_filter([
				['label' => 'Pulpit', 'url' => route('fmi.dashboard'), 'active' => request()->routeIs('fmi.dashboard'), 'icon' => 'home'],
				['label' => 'Projekty', 'url' => route('fmi.projects.index'), 'active' => request()->routeIs('fmi.projects.index', 'fmi.projects.create', 'fmi.projects.edit') && $module === '', 'icon' => 'folder'],
				['label' => 'Treści', 'url' => route('fmi.projects.index', ['module' => 'content']), 'active' => $module === 'content' || (request()->routeIs('fmi.projects.*') && ! request()->routeIs('fmi.projects.index', 'fmi.projects.create', 'fmi.projects.edit')), 'icon' => 'pencil'],
				['label' => 'Design Review', 'url' => route('fmi.projects.index', ['module' => 'review']), 'active' => $module === 'review' || request()->routeIs('fmi.design-review.*'), 'icon' => 'review'],
				['label' => 'Powiadomienia', 'url' => route('fmi.notifications.index'), 'active' => request()->routeIs('fmi.notifications.*'), 'icon' => 'bell', 'count' => $user ? Notification::query()->for($user)->unread()->count() : 0],
				Gate::allows('manage-users')
					? ['label' => 'Użytkownicy', 'url' => route('fmi.users.index'), 'active' => request()->routeIs('fmi.users.*'), 'icon' => 'users']
					: null,
				Gate::allows('manage-integrations')
					? ['label' => 'Figma', 'url' => route('fmi.settings.figma'), 'active' => request()->routeIs('fmi.settings.figma*'), 'icon' => 'figma']
					: null,
			])),
			'profileUrl' => route('fmi.profile'),
		];
	}
}
