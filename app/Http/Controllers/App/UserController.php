<?php

namespace App\Http\Controllers\App;

use App\Enums\AccountStatus;
use App\Exceptions\UserManagementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Activity;
use App\Services\ActivityLogger;
use App\Services\InvitationResult;
use App\Services\RoleManager;
use App\Services\UserService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use WP_User;

/**
 * Panel użytkowników (/app/users). Dostęp: Gate `manage-users` (middleware `can` na grupie tras).
 * Reguły biznesowe (ostatni administrator, własne konto, role WP) egzekwuje UserService.
 */
class UserController extends Controller
{
	public function __construct(private UserService $users)
	{
	}

	public function index(Request $request): View
	{
		$status = AccountStatus::tryFrom((string) $request->query('status'));
		$role = in_array($request->query('role'), RoleManager::STAFF_ROLES, true) ? $request->query('role') : null;
		$query = mb_substr(trim((string) $request->query('q')), 0, 100);

		$result = $this->users->search($query, $role, $status, $request->integer('page', 1));

		return view('fillmein.users.index', $result + [
			'filters' => ['q' => $query, 'role' => $role, 'status' => $status?->value],
			'roles' => [RoleManager::WP_ADMINISTRATOR => RoleManager::label(RoleManager::WP_ADMINISTRATOR)] + RoleManager::APP_ROLES,
			'statuses' => AccountStatus::cases(),
			'currentUserId' => get_current_user_id(),
			'pageUrl' => fn (int $page) => route('fmi.users.index', array_filter(['q' => $query, 'role' => $role, 'status' => $status?->value, 'page' => $page])),
		]);
	}

	public function create(): View
	{
		return view('fillmein.users.create', [
			'roles' => RoleManager::APP_ROLES,
			'extraCapabilities' => RoleManager::EXTRA_CAPABILITIES,
		]);
	}

	public function store(StoreUserRequest $request): RedirectResponse
	{
		try {
			['user' => $user, 'invitation' => $invitation] = $this->users->invite($request->validated(), Staff::current());
		} catch (UserManagementException $e) {
			return back()->withInput()->withErrors([$e->field ?? 'email' => $e->getMessage()]);
		}

		return $this->invitationRedirect($user, $invitation, 'Konto zostało utworzone.');
	}

	public function edit(WP_User $staffUser): View
	{
		return view('fillmein.users.edit', [
			'person' => $staffUser,
			'role' => RoleManager::roleOf($staffUser),
			'status' => AccountStatus::of($staffUser),
			'roles' => RoleManager::APP_ROLES,
			'extraCapabilities' => RoleManager::EXTRA_CAPABILITIES,
			'grantedExtra' => $this->users->extraCapabilities($staffUser),
			'isSelf' => $staffUser->ID === get_current_user_id(),
			'isWpAdministrator' => RoleManager::roleOf($staffUser) === RoleManager::WP_ADMINISTRATOR,
			'meta' => [
				'registered' => $staffUser->user_registered,
				'lastLogin' => get_user_meta($staffUser->ID, AccountStatus::META_LAST_LOGIN_AT, true),
				'invitationSent' => get_user_meta($staffUser->ID, AccountStatus::META_INVITATION_SENT_AT, true),
				'deactivatedAt' => get_user_meta($staffUser->ID, AccountStatus::META_DEACTIVATED_AT, true),
			],
			'history' => Activity::query()
				->where('subject_type', ActivityLogger::SUBJECT_USER)
				->where('subject_id', $staffUser->ID)
				->latest('created_at')
				->latest('id')
				->limit(15)
				->get(),
		]);
	}

	public function update(UpdateUserRequest $request, WP_User $staffUser): RedirectResponse
	{
		$data = $request->validated();
		$data['capabilities'] = $data['capabilities'] ?? [];

		try {
			$this->users->update($staffUser, $data, Staff::current());
		} catch (UserManagementException $e) {
			return back()->withInput()->withErrors([$e->field ?? 'role' => $e->getMessage()]);
		}

		return redirect()->route('fmi.users.edit', $staffUser->ID)->with('status', 'Zmiany zostały zapisane.');
	}

	public function deactivate(WP_User $staffUser): RedirectResponse
	{
		return $this->statusAction(fn () => $this->users->deactivate($staffUser, Staff::current()), $staffUser, 'Konto zostało dezaktywowane. Użytkownik został wylogowany.');
	}

	public function reactivate(WP_User $staffUser): RedirectResponse
	{
		return $this->statusAction(fn () => $this->users->reactivate($staffUser, Staff::current()), $staffUser, 'Konto zostało ponownie aktywowane.');
	}

	public function resendInvitation(WP_User $staffUser): RedirectResponse
	{
		try {
			$invitation = $this->users->resendInvitation($staffUser, Staff::current());
		} catch (UserManagementException $e) {
			return back()->with('error', $e->getMessage());
		}

		return $this->invitationRedirect($staffUser, $invitation, null);
	}

	private function statusAction(callable $action, WP_User $user, string $message): RedirectResponse
	{
		try {
			$action();
		} catch (UserManagementException $e) {
			return back()->with('error', $e->getMessage());
		}

		return back()->with('status', $message);
	}

	private function invitationRedirect(WP_User $user, InvitationResult $invitation, ?string $prefix): RedirectResponse
	{
		$redirect = redirect()->route('fmi.users.edit', $user->ID);

		if ($invitation->sent) {
			return $redirect->with('status', trim(($prefix ?? '') . ' Zaproszenie zostało wysłane na adres ' . $user->user_email . '.'));
		}

		return $redirect
			->with('status', $prefix)
			->with('error', 'Nie udało się wysłać zaproszenia e-mail (' . $invitation->error . '). Sprawdź konfigurację poczty i użyj przycisku „Wyślij zaproszenie ponownie”.');
	}
}
