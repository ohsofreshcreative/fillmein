<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuwanie wiadomości w uwagach Design Review bez kasowania: `deleted_at` ukrywa wiadomość przed klientem,
 * zespół widzi ją w panelu jako usuniętą. Kto usunął — wpis `comment_deleted` w fmi_review_thread_events.
 */
return new class extends Migration
{
	public function up(): void
	{
		Schema::table('fmi_review_comments', function (Blueprint $table) {
			$table->timestamp('deleted_at')->nullable()->after('edited_at');
		});
	}

	public function down(): void
	{
		Schema::table('fmi_review_comments', function (Blueprint $table) {
			$table->dropColumn('deleted_at');
		});
	}
};
