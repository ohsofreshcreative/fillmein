<?php

namespace App\Providers;

use App\Console\Commands\CheckCommand;
use App\Console\Commands\ImportCleanupCommand;
use App\Console\Commands\ImportCommand;
use App\Console\Commands\SyncRolesCommand;
use App\Enums\MappableType;
use App\Models\Asset;
use App\Models\ClientAccess;
use App\Models\Export;
use App\Models\Field;
use App\Models\Page;
use App\Models\Project;
use App\Models\RepeaterRow;
use App\Models\Section;
use App\Policies\ProjectPolicy;
use App\Services\AccountGuard;
use App\Services\LoginThrottle;
use App\Services\ProjectAssignmentService;
use App\Services\RoleManager;
use App\Support\Staff;
use Illuminate\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate as GateFacade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use WP_User;

/**
 * Fill Me In — rejestracja aplikacji w motywie.
 *
 * Trasy: routes/web.php (functions.php → withRouting). Middleware: functions.php → withMiddleware.
 */
class FillMeInServiceProvider extends ServiceProvider
{
	public function register(): void
	{
		// Acorn bierze charset z DB_CHARSET (często 'utf8' = utf8mb3), a collation z $wpdb (utf8mb4_*).
		// Ta para jest niezgodna w MySQL/MariaDB — używamy charsetu wynegocjowanego przez $wpdb.
		if (! empty($GLOBALS['wpdb']->charset)) {
			config()->set('database.connections.wordpress.charset', $GLOBALS['wpdb']->charset);
		}

		// Klucz aplikacji (CSRF, sesje) bez osobnego sekretu w .env: wyprowadzany z soli WordPressa
		// (wp-config.php). Jawny APP_KEY w .env ma pierwszeństwo. Rotacja soli unieważnia jedynie
		// sesje aplikacji — trwałych danych tym kluczem nie szyfrujemy.
		if (! config('app.key') && function_exists('wp_salt')) {
			config()->set('app.key', 'base64:' . base64_encode(hash_hmac('sha256', 'fillmein:app-key', wp_salt('auth'), true)));
		}

		// Prywatny dysk plików projektów (zdjęcia klientów). Brak `url` — pliki serwują wyłącznie
		// trasy aplikacji z kontrolą dostępu, nigdy bezpośredni adres.
		config()->set('filesystems.disks.fmi', [
			'driver' => 'local',
			'root' => config('fillmein.assets.path'),
			'visibility' => 'private',
			'throw' => true,
		]);

		// Gate Laravela pracuje na natywnych użytkownikach WordPressa (WP_User) zamiast na guardzie Laravel Auth.
		$this->app->singleton(GateContract::class, fn ($app) => new Gate($app, fn () => Staff::current()));
	}

	public function boot(): void
	{
		Relation::morphMap([
			MappableType::Project->value => Project::class,
			MappableType::Page->value => Page::class,
			MappableType::Section->value => Section::class,
			MappableType::Field->value => Field::class,
			MappableType::RepeaterRow->value => RepeaterRow::class,
			MappableType::Asset->value => Asset::class,
			MappableType::ClientAccess->value => ClientAccess::class,
			MappableType::Export->value => Export::class,
			MappableType::ReviewRevision->value => \App\Models\ReviewRevision::class,
			MappableType::ReviewThread->value => \App\Models\ReviewThread::class,
			MappableType::ReviewScreen->value => \App\Models\ReviewScreen::class,
			MappableType::ReviewComment->value => \App\Models\ReviewComment::class,
		]);

		GateFacade::policy(Project::class, ProjectPolicy::class);

		// Uprawnienia globalne (poza projektami) — zawsze capability, nigdy nazwa roli.
		GateFacade::define('manage-users', fn (WP_User $user) => $user->has_cap('fmi_manage_users'));
		GateFacade::define('view-all-activity', fn (WP_User $user) => $user->has_cap('fmi_view_all_activity'));
		GateFacade::define('manage-settings', fn (WP_User $user) => $user->has_cap('fmi_manage_settings'));
		GateFacade::define('manage-integrations', fn (WP_User $user) => $user->has_cap('fmi_manage_integrations'));

		// {staffUser} w trasach = wyłącznie konto Fill Me In; inne konta WP → 404.
		// Bez uprawnienia do zarządzania użytkownikami nie rozwiązujemy ID (middleware `can` zwróci 403),
		// żeby odpowiedź nie zdradzała, które ID należą do kont.
		Route::bind('staffUser', function (string $value) {
			if (GateFacade::denies('manage-users')) {
				return null;
			}

			$user = ctype_digit($value) ? get_userdata((int) $value) : false;

			abort_unless($user instanceof WP_User && Staff::isAppUser($user), 404);

			return $user;
		});

		// Nazwane limity żądań — każda trasa ma własny licznik (domyślny `throttle:N,M` współdzieli klucz IP).
		RateLimiter::for('fmi-login', fn (Request $request) => Limit::perMinute(30)->by('login|' . $request->ip()));
		RateLimiter::for('fmi-password-setup', fn (Request $request) => Limit::perMinute(10)->by('password-setup|' . $request->ip()));
		RateLimiter::for('fmi-password-change', fn (Request $request) => Limit::perMinute(10)->by('password-change|' . get_current_user_id() . '|' . $request->ip()));
		RateLimiter::for('fmi-uploads', fn (Request $request) => Limit::perMinute((int) config('fillmein.assets.uploads_per_minute', 60))->by('uploads|' . $request->ip()));
		RateLimiter::for('fmi-review-comments', fn (Request $request) => Limit::perMinute((int) config('fillmein.review.comments_per_minute', 20))->by('review-comments|' . get_current_user_id() . '|' . $request->ip()));
		RateLimiter::for('fmi-review-status', fn (Request $request) => Limit::perMinute((int) config('fillmein.review.status_per_minute', 60))->by('review-status|' . get_current_user_id() . '|' . $request->ip()));
		// Linki recenzji: wejście przez token i kod dostępu — na adres IP; zapisy gościa — na link i adres IP.
		RateLimiter::for('fmi-review-link', fn (Request $request) => Limit::perMinute((int) config('fillmein.review.link_attempts_per_minute', 20))->by('review-link|' . $request->ip()));
		RateLimiter::for('fmi-review-guest', fn (Request $request) => Limit::perMinute((int) config('fillmein.review.comments_per_minute', 20))->by('review-guest|' . (int) ($request->session()->get('fmi_review_guest')['access'] ?? 0) . '|' . $request->ip()));
		RateLimiter::for('fmi-client', fn (Request $request) => Limit::perMinute(600)->by('client|' . $request->ip()));

		$this->app->make(AccountGuard::class)->register();
		$this->app->make(LoginThrottle::class)->register();

		add_action('init', fn () => $this->app->make(RoleManager::class)->syncIfOutdated());

		add_action('deleted_user', function (int $userId, ?int $reassign = null) {
			$this->app->make(ProjectAssignmentService::class)->detachUser($userId, $reassign ?: null);
		}, 10, 2);

		// Pracownicy bez dostępu do panelu WP (Project Manager, Pracownik) trafiają po zalogowaniu do /app.
		add_filter('login_redirect', function ($redirectTo, $requested, $user) {
			$default = ! $requested || in_array($requested, [admin_url(), 'wp-admin/'], true);

			if ($user instanceof WP_User && $user->has_cap(Staff::ACCESS_CAPABILITY) && ! $user->has_cap('edit_posts') && $default) {
				return home_url('/app');
			}

			return $redirectTo;
		}, 10, 3);

		if ($this->app->runningInConsole()) {
			$this->commands([
				CheckCommand::class,
				ImportCleanupCommand::class,
				ImportCommand::class,
				SyncRolesCommand::class,
			]);
		}
	}
}
