<?php

/*
|--------------------------------------------------------------------------
| Fill Me In — trasy aplikacji
|--------------------------------------------------------------------------
|
| Trasy obsługuje Acorn (hook parse_request). Wszystko, co nie pasuje do
| poniższych wzorców, trafia do standardowego routingu WordPressa.
|
| Strefy z rozdzielonym dostępem:
| - /app/login, /app/password/* — goście (logowanie, ustawienie hasła z zaproszenia),
| - /app/*     — pracownicy (zalogowani użytkownicy WP z capability fmi_access),
| - /p/{token} — klienci, dostęp wyłącznie przez token (bez konta).
|
| Wszystkie zapisy to POST (CSRF w grupie `web`). Metody PUT/DELETE nie są używane,
| bo Acorn dopasowuje trasę przed obsłużeniem nadpisania metody (`_method`).
|
*/

use App\Http\Controllers\App\ActivityController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\App\ClientAccessController;
use App\Http\Controllers\App\ContentController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DesignReviewController;
use App\Http\Controllers\App\DesignSystemController;
use App\Http\Controllers\App\ExportController;
use App\Http\Controllers\App\FieldController;
use App\Http\Controllers\App\FigmaController;
use App\Http\Controllers\App\FigmaImportController;
use App\Http\Controllers\App\FigmaSettingsController;
use App\Http\Controllers\App\MappingController;
use App\Http\Controllers\App\PageController;
use App\Http\Controllers\App\ProfileController;
use App\Http\Controllers\App\ProjectController;
use App\Http\Controllers\App\ReviewController;
use App\Http\Controllers\App\SectionController;
use App\Http\Controllers\App\SiteMappingController;
use App\Http\Controllers\App\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordSetupController;
use App\Http\Controllers\Client\EditorController;
use App\Http\Controllers\Client\ReviewGuestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'fmi.app:guest'])
	->prefix('app')
	->name('fmi.')
	->group(function () {
		Route::get('login', [LoginController::class, 'create'])->name('login');
		Route::post('login', [LoginController::class, 'store'])->middleware('throttle:fmi-login')->name('login.store');
		Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

		Route::get('password/set', [PasswordSetupController::class, 'create'])->name('password.set');
		Route::post('password/set', [PasswordSetupController::class, 'store'])->middleware('throttle:fmi-password-setup')->name('password.store');
	});

Route::middleware(['web', 'fmi.app:staff', 'fmi.staff'])
	->prefix('app')
	->name('fmi.')
	->group(function () {
		Route::get('/', DashboardController::class)->name('dashboard');

		// Menu „Projekty” = Design Review pod nowym adresem; /app/projects/* zostaje modułem Treści (stare linki).
		Route::get('design-review', [DesignReviewController::class, 'index'])->name('design-review.index');
		Route::prefix('design-review/{project}')
			->whereNumber('project')
			->middleware('fmi.module:review')
			->name('design-review.')
			->group(function () {
				Route::get('/', [DesignReviewController::class, 'show'])->name('show');
				Route::post('prototype', [DesignReviewController::class, 'savePrototype'])->name('prototype');
				Route::post('screens', [DesignReviewController::class, 'storeScreen'])->name('screens.store');
				Route::get('screens/{screen}/image', [DesignReviewController::class, 'screenImage'])->whereNumber('screen')->name('screens.image');
				Route::post('comments', [DesignReviewController::class, 'storeComment'])->middleware('throttle:fmi-review-comments')->name('comments.store');
				Route::post('decision', [DesignReviewController::class, 'storeDecision'])->middleware('throttle:fmi-review-status')->name('decision.store');
				Route::post('revisions', [DesignReviewController::class, 'createRevision'])->name('revisions.store');
				Route::post('decisions/{decision}/check', [DesignReviewController::class, 'checkDecision'])->whereNumber('decision')->middleware('throttle:fmi-review-status')->name('decisions.check');
				Route::post('links', [DesignReviewController::class, 'storeLink'])->name('links.store');
				Route::post('links/{link}/revoke', [DesignReviewController::class, 'revokeLink'])->whereNumber('link')->name('links.revoke');
				Route::post('threads/{thread}/replies', [DesignReviewController::class, 'storeReply'])->whereNumber('thread')->middleware('throttle:fmi-review-comments')->name('threads.replies.store');
				Route::post('threads/{thread}/status', [DesignReviewController::class, 'changeStatus'])->whereNumber('thread')->middleware('throttle:fmi-review-status')->name('threads.status');
				Route::post('threads/{thread}/comments/{comment}', [DesignReviewController::class, 'updateComment'])->whereNumber(['thread', 'comment'])->middleware('throttle:fmi-review-comments')->name('threads.comments.update');
			});

		Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
		Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
		Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
		Route::post('projects/delete-selected', [ProjectController::class, 'destroySelected'])->name('projects.destroy-selected');

		// Podstrona jest wiązana w zakresie projektu (scopeBindings) — obce ID daje 404, nie 403.
		Route::prefix('projects/{project}')
			->whereNumber('project')
			->scopeBindings()
			->group(function () {
				Route::get('/', [ProjectController::class, 'show'])->name('projects.show');
				Route::get('edit', [ProjectController::class, 'edit'])->name('projects.edit');
				Route::post('/', [ProjectController::class, 'update'])->name('projects.update');
				Route::post('status', [ProjectController::class, 'status'])->name('projects.status');
				Route::post('members', [ProjectController::class, 'members'])->name('projects.members');
				Route::post('delete', [ProjectController::class, 'destroy'])->name('projects.destroy');

				Route::get('activity', [ActivityController::class, 'index'])->name('projects.activity');

				Route::post('client-access', [ClientAccessController::class, 'store'])->name('projects.client-access.store');
				Route::post('client-access/regenerate', [ClientAccessController::class, 'regenerate'])->name('projects.client-access.regenerate');
				Route::post('client-access/{clientAccess}/revoke', [ClientAccessController::class, 'revoke'])
					->whereNumber('clientAccess')
					->name('projects.client-access.revoke');

				// Trasy modułu Treści: projekt bez tego modułu daje 404 (EnsureProjectModule), także dla Administratora.
				Route::middleware('fmi.module:content')->group(function () {
					Route::get('mapping', [MappingController::class, 'edit'])->name('projects.mapping');
					Route::post('mapping/{page}', [MappingController::class, 'update'])->whereNumber('page')->name('projects.mapping.update');
					Route::post('site-schema', [SiteMappingController::class, 'upload'])->name('projects.site-schema.upload');
					Route::post('site-mapping/auto', [SiteMappingController::class, 'auto'])->name('projects.site-mapping.auto');
					Route::post('site-mapping/{page}', [SiteMappingController::class, 'update'])->whereNumber('page')->name('projects.site-mapping.update');
					Route::post('site-mapping/{page}/restore', [SiteMappingController::class, 'restore'])->whereNumber('page')->name('projects.site-mapping.restore');
					Route::get('exports', [ExportController::class, 'index'])->name('projects.exports');
					Route::post('exports', [ExportController::class, 'store'])->name('projects.exports.store');
					Route::post('exports/{export}/regenerate', [ExportController::class, 'regenerate'])->whereUuid('export')->name('projects.exports.regenerate');
					Route::get('exports/{export}/{format}', [ExportController::class, 'download'])->whereUuid('export')->name('projects.exports.download');

					Route::get('review', [ReviewController::class, 'index'])->name('projects.review');
					Route::post('review/{value}', [ReviewController::class, 'mark'])->whereNumber('value')->name('projects.review.mark');
					Route::post('review/{value}/clear', [ReviewController::class, 'clear'])->whereNumber('value')->name('projects.review.clear');

					Route::get('assets/{asset}/{variant}', [ContentController::class, 'asset'])
						->whereUuid('asset')
						->name('projects.assets.show');

					Route::prefix('pages')
						->name('projects.pages.')
						->group(function () {
							Route::post('/', [PageController::class, 'store'])->name('store');
							Route::post('reorder', [PageController::class, 'reorder'])->name('reorder');

							Route::prefix('{page}')
								->whereNumber('page')
								->group(function () {
									Route::get('edit', [PageController::class, 'edit'])->name('edit');
									Route::get('preview', [ContentController::class, 'preview'])->name('preview');
									Route::post('/', [PageController::class, 'update'])->name('update');
									Route::post('duplicate', [PageController::class, 'duplicate'])->name('duplicate');
									Route::post('archive', [PageController::class, 'archive'])->name('archive');
									Route::post('restore', [PageController::class, 'restore'])->name('restore');

									Route::post('figma', [FigmaController::class, 'fetch'])->name('figma.fetch');
									Route::get('figma/preview/{node}', [FigmaController::class, 'preview'])
										->where('node', '[0-9]{1,10}-[0-9]{1,10}')
										->name('figma.preview');

									Route::get('figma/import', [FigmaImportController::class, 'show'])->name('figma.import');
									Route::post('figma/import', [FigmaImportController::class, 'apply'])->name('figma.import.apply');
									Route::post('figma/rules', [FigmaImportController::class, 'rule'])->name('figma.rules');

									Route::prefix('content')
										->name('content.')
										->group(function () {
											Route::get('/', [ContentController::class, 'edit'])->name('edit');
											Route::get('fields/{field}/history', [ContentController::class, 'history'])->whereNumber('field')->name('history');
											Route::post('fields/{field}', [ContentController::class, 'save'])->whereNumber('field')->name('save');
											Route::post('fields/{field}/revisions/{revision}/restore', [ContentController::class, 'restore'])
												->whereNumber(['field', 'revision'])
												->name('restore');
											Route::post('fields/{field}/assets', [ContentController::class, 'uploadAsset'])
												->whereNumber('field')
												->middleware('throttle:fmi-uploads')
												->name('assets.store');
											Route::post('fields/{field}/assets/reorder', [ContentController::class, 'reorderAssets'])->whereNumber('field')->name('assets.reorder');
											Route::post('fields/{field}/link', [ContentController::class, 'saveMediaLink'])->whereNumber('field')->name('media.link');
											Route::post('sections/{section}/note', [ContentController::class, 'saveSectionNote'])->whereNumber('section')->name('section-note');
											Route::post('fields/{field}/assets/{asset}/detach', [ContentController::class, 'detachAsset'])
												->whereNumber('field')
												->whereUuid('asset')
												->name('assets.detach');
											Route::post('fields/{field}/assets/{asset}/alt', [ContentController::class, 'updateAlt'])
												->whereNumber('field')
												->whereUuid('asset')
												->name('assets.alt');
											Route::post('fields/{field}/rows', [ContentController::class, 'addRow'])->whereNumber('field')->name('rows.store');
											Route::post('fields/{field}/rows/reorder', [ContentController::class, 'reorderRows'])->whereNumber('field')->name('rows.reorder');
											Route::post('fields/{field}/rows/{row}/delete', [ContentController::class, 'removeRow'])
												->whereNumber('field')
												->whereUuid('row')
												->name('rows.destroy');
										});

									Route::get('structure', [SectionController::class, 'index'])->name('structure');
									Route::post('sections', [SectionController::class, 'store'])->name('sections.store');
									Route::post('sections/reorder', [SectionController::class, 'reorder'])->name('sections.reorder');

									Route::prefix('sections/{section}')
										->whereNumber('section')
										->name('sections.')
										->group(function () {
											Route::get('edit', [SectionController::class, 'edit'])->name('edit');
											Route::post('/', [SectionController::class, 'update'])->name('update');
											Route::post('archive', [SectionController::class, 'archive'])->name('archive');
											Route::post('restore', [SectionController::class, 'restore'])->name('restore');

											Route::get('fields/create', [FieldController::class, 'create'])->name('fields.create');
											Route::post('fields', [FieldController::class, 'store'])->name('fields.store');
											Route::post('fields/reorder', [FieldController::class, 'reorder'])->name('fields.reorder');
										});

									Route::prefix('fields/{field}')
										->whereNumber('field')
										->name('fields.')
										->group(function () {
											Route::get('edit', [FieldController::class, 'edit'])->name('edit');
											Route::post('/', [FieldController::class, 'update'])->name('update');
											Route::post('archive', [FieldController::class, 'archive'])->name('archive');
											Route::post('restore', [FieldController::class, 'restore'])->name('restore');
										});
								});
						});
				});
			});

		Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
		Route::get('notifications/{notification}', [NotificationController::class, 'open'])->whereNumber('notification')->name('notifications.open');
		Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
		Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification')->name('notifications.read');
		Route::get('profile', [ProfileController::class, 'show'])->name('profile');
		Route::post('profile', [ProfileController::class, 'update'])->name('profile.update');
		Route::post('profile/password', [ProfileController::class, 'updatePassword'])
			->middleware('throttle:fmi-password-change')
			->name('profile.password');

		Route::middleware('can:manage-users')
			->prefix('users')
			->name('users.')
			->group(function () {
				Route::get('/', [UserController::class, 'index'])->name('index');
				Route::get('create', [UserController::class, 'create'])->name('create');
				Route::post('/', [UserController::class, 'store'])->name('store');
				Route::get('{staffUser}/edit', [UserController::class, 'edit'])->whereNumber('staffUser')->name('edit');
				Route::post('{staffUser}', [UserController::class, 'update'])->whereNumber('staffUser')->name('update');
				Route::post('{staffUser}/deactivate', [UserController::class, 'deactivate'])->whereNumber('staffUser')->name('deactivate');
				Route::post('{staffUser}/reactivate', [UserController::class, 'reactivate'])->whereNumber('staffUser')->name('reactivate');
				Route::post('{staffUser}/invitation', [UserController::class, 'resendInvitation'])->whereNumber('staffUser')->name('invitation');
			});

		Route::middleware('can:manage-integrations')
			->prefix('settings/figma')
			->name('settings.figma')
			->group(function () {
				Route::get('/', [FigmaSettingsController::class, 'show']);
				Route::post('/', [FigmaSettingsController::class, 'save'])->name('.save');
				Route::post('connect', [FigmaSettingsController::class, 'connect'])->name('.connect');
				Route::get('callback', [FigmaSettingsController::class, 'callback'])->name('.callback');
				Route::post('disconnect', [FigmaSettingsController::class, 'disconnect'])->name('.disconnect');
			});

		Route::get('design-system', DesignSystemController::class)->name('design-system');
	});

// Recenzja Design Review dla klienta (link zakresu `review`). `/r/{token}` wymienia token na sesję i przekierowuje
// na `/review` — dalsze adresy nie zawierają tokenu. Każdy nieważny token: ta sama odpowiedź 404.
Route::middleware(['web', 'fmi.app:client'])
	->name('fmi.review.')
	->group(function () {
		Route::get('r/{token}', [ReviewGuestController::class, 'enter'])->where('token', '[^/]{1,256}')->middleware('throttle:fmi-review-link')->name('enter');

		Route::prefix('review')->group(function () {
			// Próby kodu: limit na link i adres IP w kontrolerze (fillmein.review.code_attempts / 15 min).
			Route::get('unlock', [ReviewGuestController::class, 'unlockForm'])->name('unlock');
			Route::post('unlock', [ReviewGuestController::class, 'unlock'])->name('unlock.submit');
			Route::post('leave', [ReviewGuestController::class, 'leave'])->name('leave');

			Route::middleware(['throttle:fmi-client', 'fmi.review-guest'])->group(function () {
				Route::get('/', [ReviewGuestController::class, 'show'])->name('show');
				Route::post('name', [ReviewGuestController::class, 'saveName'])->name('name');
				Route::get('screens/{screen}/image', [ReviewGuestController::class, 'screenImage'])->whereNumber('screen')->name('screens.image');
			});

			Route::post('decision', [ReviewGuestController::class, 'storeDecision'])->middleware(['throttle:fmi-review-guest', 'fmi.review-guest:approve'])->name('decision.store');

			Route::middleware(['throttle:fmi-review-guest', 'fmi.review-guest:comment'])->group(function () {
				Route::post('comments', [ReviewGuestController::class, 'storeComment'])->name('comments.store');
				Route::post('threads/{thread}/replies', [ReviewGuestController::class, 'storeReply'])->whereNumber('thread')->name('threads.replies.store');
			});
		});
	});

// Dowolny token (także w złym formacie) trafia do `fmi.client`, więc każdy nieważny dostaje tę samą odpowiedź 404.
// ID pól i wierszy są rozwiązywane w serwisie dopiero po weryfikacji tokenu: bez wiązania modeli trasy.
Route::middleware(['web', 'fmi.app:client', 'throttle:fmi-client', 'fmi.client', 'fmi.module:content'])
	->prefix('p/{token}')
	->where(['token' => '[^/]{1,256}'])
	->name('fmi.client.')
	->group(function () {
		Route::get('/', [EditorController::class, 'show'])->name('editor');
		Route::post('fields/{field}', [EditorController::class, 'save'])->whereNumber('field')->name('fields.save');
		Route::post('fields/{field}/revisions/{revision}/restore', [EditorController::class, 'restore'])
			->whereNumber(['field', 'revision'])
			->name('fields.restore');
		Route::post('fields/{field}/assets', [EditorController::class, 'uploadAsset'])
			->whereNumber('field')
			->middleware('throttle:fmi-uploads')
			->name('assets.store');
		Route::post('fields/{field}/assets/reorder', [EditorController::class, 'reorderAssets'])->whereNumber('field')->name('assets.reorder');
		Route::post('fields/{field}/link', [EditorController::class, 'saveMediaLink'])->whereNumber('field')->name('media.link');
		Route::post('sections/{section}/note', [EditorController::class, 'saveSectionNote'])->whereNumber('section')->name('section-note');
		Route::post('fields/{field}/assets/{asset}/detach', [EditorController::class, 'detachAsset'])
			->whereNumber('field')
			->whereUuid('asset')
			->name('assets.detach');
		Route::post('fields/{field}/assets/{asset}/alt', [EditorController::class, 'updateAlt'])
			->whereNumber('field')
			->whereUuid('asset')
			->name('assets.alt');
		Route::post('fields/{field}/rows', [EditorController::class, 'addRow'])->whereNumber('field')->name('rows.store');
		Route::post('fields/{field}/rows/reorder', [EditorController::class, 'reorderRows'])->whereNumber('field')->name('rows.reorder');
		Route::post('fields/{field}/rows/{row}/delete', [EditorController::class, 'removeRow'])
			->whereNumber('field')
			->whereUuid('row')
			->name('rows.destroy');
	});
