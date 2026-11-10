<?php

namespace App\Models;

use App\Enums\ActorType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Wpis dziennika aktywności (tylko do zapisu — bez updated_at).
 */
class Activity extends Model
{
	public const UPDATED_AT = null;

	public const ACTOR_CLIENT = 'klient';

	/** Obszary dziennika: filtr => etykieta i przedrostki zdarzeń. */
	public const AREAS = [
		'tresc' => ['label' => 'Treść', 'prefixes' => ['content.']],
		'struktura' => ['label' => 'Struktura', 'prefixes' => ['page.', 'section.', 'field.']],
		'figma' => ['label' => 'Figma', 'prefixes' => ['figma.']],
		'eksport' => ['label' => 'Eksport', 'prefixes' => ['export.', 'mapping.']],
		'projekt' => ['label' => 'Projekt', 'prefixes' => ['project.']],
		'dostep' => ['label' => 'Dostęp klienta', 'prefixes' => ['client_access.']],
		'recenzja' => ['label' => 'Design Review', 'prefixes' => ['review.']],
	];

	protected $table = 'fmi_activity_log';

	protected $fillable = [
		'project_id',
		'actor_type',
		'user_id',
		'client_access_id',
		'event',
		'subject_type',
		'subject_id',
		'properties',
	];

	protected function casts(): array
	{
		return [
			'actor_type' => ActorType::class,
			'user_id' => 'integer',
			'client_access_id' => 'integer',
			'subject_id' => 'integer',
			'properties' => 'array',
			'created_at' => 'datetime',
		];
	}

	/**
	 * @param array{typ: string, aktor: string, od: string, do: string, strona: ?int} $filters
	 */
	public function scopeFiltered(Builder $query, array $filters): Builder
	{
		return $query
			->when($filters['typ'] !== '', fn (Builder $q) => $q->where(function (Builder $events) use ($filters) {
				foreach (self::AREAS[$filters['typ']]['prefixes'] as $prefix) {
					$events->orWhere('event', 'like', $prefix . '%');
				}
			}))
			->when($filters['aktor'] === self::ACTOR_CLIENT, fn (Builder $q) => $q->where('actor_type', ActorType::Client->value))
			->when($filters['aktor'] !== '' && $filters['aktor'] !== self::ACTOR_CLIENT, fn (Builder $q) => $q->where('user_id', (int) $filters['aktor']))
			->when($filters['od'] !== '', fn (Builder $q) => $q->where('created_at', '>=', $filters['od'] . ' 00:00:00'))
			->when($filters['do'] !== '', fn (Builder $q) => $q->where('created_at', '<=', $filters['do'] . ' 23:59:59'))
			->when($filters['strona'], fn (Builder $q) => $q->where(function (Builder $subject) use ($filters) {
				$page = (int) $filters['strona'];
				$sections = Section::query()->where('page_id', $page)->select('id');

				$subject
					->where(fn (Builder $q) => $q->where('subject_type', (new Page)->getMorphClass())->where('subject_id', $page))
					->orWhere(fn (Builder $q) => $q->where('subject_type', (new Section)->getMorphClass())->whereIn('subject_id', $sections))
					->orWhere(fn (Builder $q) => $q->where('subject_type', (new Field)->getMorphClass())
						->whereIn('subject_id', Field::query()->whereIn('section_id', $sections)->select('id')));
			}));
	}

	public function project(): BelongsTo
	{
		return $this->belongsTo(Project::class);
	}

	public function clientAccess(): BelongsTo
	{
		return $this->belongsTo(ClientAccess::class);
	}

	public function subject(): MorphTo
	{
		return $this->morphTo();
	}
}
