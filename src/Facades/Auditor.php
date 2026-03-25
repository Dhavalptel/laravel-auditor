<?php

declare(strict_types=1);

namespace DevToolbox\Auditor\Facades;

use DevToolbox\Auditor\Services\AuditService;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the AuditService.
 *
 * Provides a static interface to the audit service for manual event recording,
 * service-level operations, and the fluent activity builder API.
 *
 * Automatic auditing (Eloquent observer):
 * @method static void   record(\Illuminate\Database\Eloquent\Model $model, \DevToolbox\Auditor\Enums\AuditEvent $event)
 * @method static \DevToolbox\Auditor\Models\Audit writeSync(\DevToolbox\Auditor\DTOs\AuditEventDTO $dto)
 * @method static bool   shouldAudit(\Illuminate\Database\Eloquent\Model $model, \DevToolbox\Auditor\Enums\AuditEvent $event)
 *
 * Fluent activity builder API:
 * @method static \DevToolbox\Auditor\Builders\ActivityBuilder newActivity()
 * @method static \DevToolbox\Auditor\Builders\ActivityBuilder inLog(string $logName)
 * @method static \DevToolbox\Auditor\Builders\ActivityBuilder causedBy(?\Illuminate\Database\Eloquent\Model $causer)
 * @method static \DevToolbox\Auditor\Builders\ActivityBuilder performedOn(?\Illuminate\Database\Eloquent\Model $subject)
 * @method static \DevToolbox\Auditor\Builders\ActivityBuilder withProperties(array $properties)
 * @method static \DevToolbox\Auditor\Models\Audit|null writeActivity(\DevToolbox\Auditor\DTOs\ActivityEventDTO $dto)
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
