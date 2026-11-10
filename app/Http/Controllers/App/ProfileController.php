<?php

namespace App\Http\Controllers\App;

use App\Enums\AccountStatus;
use App\Exceptions\UserManagementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\RoleManager;
use App\Services\UserService;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Profil zalogowanego pracownika. Zawsze działa na bieżącym użytkowniku (brak ID w adresie),
 * a formularze nie przyjmują roli ani statusu — samodzielna zmiana uprawnień jest niemożliwa.
 */
class ProfileController extends Controller
{
	public function __construct(private UserService $users)
	{
	}

	public function show(): View
	{
		$user = Staff::current();

		return view('fillmein.profile.show', [
			'person' => $user,
			'roleLabel' => RoleManager::label(RoleManager::roleOf($user)),
			'capabilities' => array_filter(RoleManager::ALL_CAPABILITIES, fn ($label, $capability) => $user->has_cap($capability), ARRAY_FILTER_USE_BOTH),
			'lastLogin' => get_user_meta($user->ID, AccountStatus::META_LAST_LOGIN_AT, true),
			'minLength' => (int) config('fillmein.auth.password_min_length', 12),
		]);
	}

	public function update(UpdateProfileRequest $request): RedirectResponse
	{
		$user = Staff::current();

		$this->users->updateName($user, $request->validated('first_name'), $request->validated('last_name'), $user);

		return redirect()->route('fmi.profile')->with('status', 'Dane zostały zapisane.');
	}

	public function updatePassword(ChangePasswordRequest $request): RedirectResponse
	{
		$user = Staff::current();
		$key = 'fmi-password-change:' . $user->ID;

		if (RateLimiter::tooManyAttempts($key, 5)) {
			return back()->withErrors(['current_password' => 'Zbyt wiele prób. Spróbuj ponownie za kilka minut.'], 'password');
		}

		try {
			$this->users->changeOwnPassword($user, (string) $request->input('current_password'), (string) $request->input('password'));
		} catch (UserManagementException $e) {
			RateLimiter::hit($key, 300);

			return back()->withErrors([$e->field ?? 'current_password' => $e->getMessage()], 'password');
		}

		RateLimiter::clear($key);

		return redirect()->route('fmi.profile')->with('status', 'Hasło zostało zmienione. Inne sesje zostały wylogowane.');
	}
}
