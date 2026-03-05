<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Tests;

use DevToolbox\Auditor\Contracts\Auditable;
use DevToolbox\Auditor\Traits\HasAuditOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Minimal User model for use in feature tests.
 */
class UserModel extends Model implements Auditable
{
    use HasAuditOptions;

    protected $table = 'test_users';

    protected $guarded = [];

    public function getAuditExcluded(): array
    {
        return ['password'];
    }

    public function getAuditTags(): array
    {
        return ['users'];
    }
}

/**
 * Minimal Post model with SoftDeletes for testing soft/hard delete distinction.
 */
class PostModel extends Model
{
    use SoftDeletes;

    protected $table = 'test_posts';

    protected $guarded = [];
}

/**
 * Minimal model with no special traits — plain auto-audit subject.
 */
class PlainModel extends Model
{
    protected $table = 'test_plain';

    protected $guarded = [];
}
