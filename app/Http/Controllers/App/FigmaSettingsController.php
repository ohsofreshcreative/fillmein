<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveFigmaSettingsRequest;
use App\Integrations\Figma\FigmaClient;
use App\Integrations\Figma\FigmaConnection;
use App\Integrations\Figma\FigmaException;
use App\Services\ActivityLogger;
use App\Support\Actor;
use App\Support\Staff;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Wspólne połączenie z Figmą (/app/settings/figma). Dostęp: Gate `manage-integrations` (middleware `can`).
 */
class FigmaSettingsController extends Controller
{
	private const SESSION_KEY = 'fmi_figma_oauth';

	public function __construct(private FigmaConnection $connection, private ActivityLogger $activity)
	{
	}

	public function show(): View
	{
		$status = $this->connection->status();
		$by = $status['connected_by'] ? get_userdata($status['connected_by']) : null;

		return view('fillmein.settings.figma', [
			'status' => $status,
			'connectedBy' => $by ? Staff::fullName($by) : null,
			'redirectUri' => route('fmi.settings.figma.callback'),
			'tokenOverride' => FigmaClient::configuredToken() !== null,
		]);
	}

	public function save(SaveFigmaSettingsRequest $request): RedirectResponse
	{
		$data = $request->validated();
		$secret = $data['client_secret'] ?? null;

		if (($secret === null || $secret === '') && ! $this->connection->status()['has_secret']) {
			return back()->withInput()->withErrors(['client_secret' => 'Podaj Client Secret.']);
		}

		$this->connection->saveCredentials($data['client_id'], $secret);
		$this->log('figma.credentials_saved');

		return redirect()->route('fmi.settings.figma')->with('status', 'Dane aplikacji Figma zostały zapisane.');
	}

	public function connect(Request $request): RedirectResponse
	{
		if (! $this->connection->hasCredentials()) {
			return redirect()->route('fmi.settings.figma')->with('error', 'Najpierw zapisz Client ID i Client Secret.');
		}

		$state = Str::random(40);
		$verifier = Str::random(64);

		$request->session()->put(self::SESSION_KEY, ['state' => $state, 'verifier' => $verifier]);

		return redirect()->away($this->connection->authorizationUrl(route('fmi.settings.figma.callback'), $state, $verifier));
	}

	public function callback(Request $request): RedirectResponse
	{
		// `pull`: stan jest jednorazowy, także gdy logowanie się nie powiedzie.
		$expected = $request->session()->pull(self::SESSION_KEY);
		$state = $request->query('state');
		$code = $request->query('code');
		$settings = redirect()->route('fmi.settings.figma');

		if (! is_array($expected) || ! is_string($state) || ! hash_equals($expected['state'], $state)) {
			return $settings->with('error', 'Nie udało się potwierdzić logowania. Spróbuj połączyć ponownie.');
		}

		if (! is_string($code) || $code === '' || strlen($code) > 1024) {
			return $settings->with('error', 'Figma nie potwierdziła połączenia.');
		}

		try {
			$this->connection->connect($code, $expected['verifier'], route('fmi.settings.figma.callback'), Staff::current()->ID);
		} catch (FigmaException $e) {
			return $settings->with('error', $e->getMessage());
		}

		$this->log('figma.connected');

		return $settings->with('status', 'Połączono z Figmą.');
	}

	public function disconnect(): RedirectResponse
	{
		$this->connection->disconnect();
		$this->log('figma.disconnected');

		return redirect()->route('fmi.settings.figma')->with('status', 'Rozłączono z Figmą.');
	}

	private function log(string $event): void
	{
		$this->activity->log($event, Actor::user(Staff::current()));
	}
}
