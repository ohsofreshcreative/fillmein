<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stały podgląd linku klienta Treści w panelu (decyzja właściciela z 2026-11-05: link generowany raz i widoczny
 * dla zespołu). Token zapisujemy zaszyfrowany przez SecretBox (jak połączenie OAuth z Figmą) — wyszukiwanie dalej
 * idzie po `token_hash`. Linki sprzed tej migracji nie mają kopii (jawnego tokenu nie znamy) — trzeba je wygenerować
 * od nowa, żeby adres był widoczny.
 */
return new class extends Migration
{
	public function up(): void
	{
		if (! Schema::hasColumn('fmi_client_accesses', 'token_sealed')) {
			Schema::table('fmi_client_accesses', function (Blueprint $table) {
				$table->text('token_sealed')->nullable()->after('token_hash');
			});
		}
	}

	public function down(): void
	{
		if (Schema::hasColumn('fmi_client_accesses', 'token_sealed')) {
			Schema::table('fmi_client_accesses', function (Blueprint $table) {
				$table->dropColumn('token_sealed');
			});
		}
	}
};
