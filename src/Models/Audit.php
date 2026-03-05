<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Models;

use DevToolbox\Auditor\Enums\AuditEvent;
use DevToolbox\Auditor\Scopes\AuditQueryScopes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * Represents a single audit record in the database.
 *
 * Each record captures one lifecycle event for one model at one point in time.
 * The model is intentionally read-optimised: no timestamps trait, manual
 * `created_at` only, and ULID primary keys for insert-friendly ordering.
 *
 * @property string                   $id
 * @property AuditEvent               $event
 * @property string                   $auditable_type
 * @property string                   $auditable_id
 * @property string|null              $user_type
 * @property string|null              $user_id
 * @property array<string,mixed>|null $old_values
 * @property array<string,mixed>|null $new_values
 * @property string|null              $ip_address
 * @property string|null              $user_agent
 * @property string|null              $url
 * @property array<string>|null       $tags
 * @property \Carbon\Carbon           $created_at
 */
class Audit extends Model
{
    use AuditQueryScopes;

    /**
     * Disable auto-managed timestamps; we only need `created_at`.
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     */
    protected $table = 'audits';

    /**
     * The primary key type.
     */
    protected $keyType = 'string';

    /**
     * Disable auto-incrementing since we use ULIDs.
     */
    public $incrementing = false;

    /**
     * Mass-assignable attributes.
     *
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'event',
        'auditable_type',
        'auditable_id',
        'user_type',
        'user_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
        'tags',
        'created_at',
    ];

    /**
     * Attribute casts for automatic type conversion.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'event'      => AuditEvent::class,
        'old_values' => 'array',
        'new_values' => 'array',
        'tags'       => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Boots the model and auto-assigns a ULID before creation.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::ulid();
            }
        });
    }

    /**
     * Returns the table name from config, allowing projects to customise it.
     */
    public function getTable(): string
    {
        return config('auditor.table', 'audits');
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * Polymorphic relation back to the model that was audited.
     *
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Polymorphic relation to the user who performed the action.
     *
     * @return MorphTo<Model, $this>
     */
    public function user(): MorphTo
    {
        return $this->morphTo();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Returns only the changed attributes (keys present in both old and new values).
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];

        $keys = array_unique(array_merge(array_keys($old), array_keys($new)));

        $result = [];

        foreach ($keys as $key) {
            $result[$key] = [
                'old' => $old[$key] ?? null,
                'new' => $new[$key] ?? null,
            ];
        }

        return $result;
    }
}
