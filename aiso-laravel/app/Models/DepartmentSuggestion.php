<?php

namespace App\Models;

use App\Enums\SuggestionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A department's suggestion to the manager, shown on the manager dashboard until the manager accepts or rejects it.
 *
 * @property int $id
 * @property int $department_id
 * @property string $author_id
 * @property ?string $resource_id
 * @property string $title
 * @property string $body
 * @property SuggestionStatus $status
 */
class DepartmentSuggestion extends Model
{
    protected $table = 'department_suggestion';
    public $timestamps = false;
    protected $fillable = [
        'department_id', 'author_id', 'resource_id', 'title', 'body', 'status', 'decided_by', 'decided_at',
        'manager_note', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SuggestionStatus::class,
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(AppUser::class, 'author_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(WorkResource::class, 'resource_id');
    }
}
