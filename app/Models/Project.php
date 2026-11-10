<?php

namespace App\Models;

use App\Enums\ProjectModule;
use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use WP_User;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property ?string $client_name
 * @property ProjectStatus $status
 * @property ?int $author_id
 * @property ?int $manager_id
 * @property bool $content_enabled
 * @property bool $review_enabled
 */
class Project extends Model
{
	use HasUuids;
	use SoftDeletes;

	protected $table = 'fmi_projects';

	protected $fillable = [
		'name',
		'client_name',
		'status',
		'content_enabled',
		'review_enabled',
		'author_id',
		'manager_id',
		'figma_file_key',
		'figma_file_url',
		'figma_file_name',
		'figma_synced_at',
		'settings',
	];

	protected $attributes = [
		'status' => 'draft',
		'content_enabled' => true,
		'review_enabled' => false,
	];

	protected function casts(): array
	{
		return [
			'status' => ProjectStatus::class,
			'content_enabled' => 'boolean',
			'review_enabled' => 'boolean',
			'author_id' => 'integer',
			'manager_id' => 'integer',
			'figma_synced_at' => 'datetime',
			'settings' => 'array',
		];
	}

	/**
	 * Klucz główny zostaje autoinkrementowany; UUID to publiczny, stabilny identyfikator.
	 */
	public function uniqueIds(): array
	{
		return ['uuid'];
	}

	public function members(): HasMany
	{
		return $this->hasMany(ProjectMember::class);
	}

	public function pages(): HasMany
	{
		return $this->hasMany(Page::class)->orderBy('position');
	}

	public function sections(): HasMany
	{
		return $this->hasMany(Section::class);
	}

	public function figmaFrames(): HasMany
	{
		return $this->hasMany(FigmaFrame::class);
	}

	public function fields(): HasMany
	{
		return $this->hasMany(Field::class);
	}

	public function assets(): HasMany
	{
		return $this->hasMany(Asset::class);
	}

	public function clientAccesses(): HasMany
	{
		return $this->hasMany(ClientAccess::class);
	}

	public function activities(): HasMany
	{
		return $this->hasMany(Activity::class)->latest('created_at');
	}

	public function exports(): HasMany
	{
		return $this->hasMany(Export::class)->latest();
	}

	public function reviewRevisions(): HasMany
	{
		return $this->hasMany(ReviewRevision::class)->orderByDesc('number');
	}

	public function reviewScreens(): HasMany
	{
		return $this->hasMany(ReviewScreen::class);
	}

	public function reviewThreads(): HasMany
	{
		return $this->hasMany(ReviewThread::class);
	}

	public function usesModule(ProjectModule|string $module): bool
	{
		$module = $module instanceof ProjectModule ? $module : ProjectModule::tryFrom($module);

		if ($module === null) {
			return false;
		}

		$enabled = $this->getAttribute($module->column());

		// Wiersz bez kolumny (kod przed migracją 2026_11_03_000100 albo po jej wycofaniu) zachowuje się jak dotąd: tylko Treści.
		return $enabled === null ? $module === ProjectModule::Content : (bool) $enabled;
	}

	/**
	 * @return ProjectModule[]
	 */
	public function modules(): array
	{
		return array_values(array_filter(ProjectModule::cases(), fn (ProjectModule $module) => $this->usesModule($module)));
	}

	public function scopeWithModule(Builder $query, ProjectModule $module): Builder
	{
		return $query->where($module->column(), true);
	}

	/**
	 * Projekty widoczne dla użytkownika: wszystkie dla Administratora,
	 * w pozostałych przypadkach tylko te, których jest autorem, opiekunem lub członkiem.
	 */
	public function scopeVisibleTo(Builder $query, WP_User $user): Builder
	{
		if ($user->has_cap('fmi_manage_all_projects')) {
			return $query;
		}

		return $query->where(function (Builder $query) use ($user) {
			$query->where('author_id', $user->ID)
				->orWhere('manager_id', $user->ID)
				->orWhereHas('members', fn (Builder $members) => $members->where('user_id', $user->ID));
		});
	}

	public function isAuthor(WP_User $user): bool
	{
		return $this->author_id === $user->ID;
	}

	public function isManagedBy(WP_User $user): bool
	{
		return $this->manager_id === $user->ID;
	}

	public function hasMember(WP_User $user): bool
	{
		return $this->members()->where('user_id', $user->ID)->exists();
	}

	public function isAssignedTo(WP_User $user): bool
	{
		return $this->isAuthor($user) || $this->isManagedBy($user) || $this->hasMember($user);
	}
}
