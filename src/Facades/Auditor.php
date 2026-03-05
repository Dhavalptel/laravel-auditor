<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Facades;

use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the AuditService.
 *
 * Provides a static interface to the audit service for manual event recording
 * and service-level operations.
 *
 * @method static void   record(\Illuminate\Database\Eloquent\Model $model, \DevToolbox\Auditor\Enums\AuditEvent $event)
 * @method static \DevToolbox\Auditor\Models\Audit writeSync(\DevToolbox\Auditor\DTOs\AuditEventDTO $dto)
 * @method static bool   shouldAudit(\Illuminate\Database\Eloquent\Model $model, \DevToolbox\Auditor\Enums\AuditEvent $event)
 *
 * @see AuditService
 */
class Auditor extends Facade
{
    /**
     * Returns the facade accessor string bound in the service container.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return AuditService::class;
    }
}
