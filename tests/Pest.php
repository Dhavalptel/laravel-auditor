<?php

declare(strict_types=1);

use DevToolbox\Auditor\Tests\TestCase;

// Load test model definitions (PSR-4 can't autoload multiple classes from one file)
require_once __DIR__ . '/Models.php';

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Helpers available across all test files.
 */

/**
 * Creates a fake Eloquent model stub for testing.
 */
function makeModel(string $class = 'App\\Models\\User', int|string $id = 1): \Illuminate\Database\Eloquent\Model
{
    $model = Mockery::mock(\Illuminate\Database\Eloquent\Model::class)->makePartial();
    $model->shouldReceive('getMorphClass')->andReturn($class);
    $model->shouldReceive('getKey')->andReturn($id);
    $model->shouldReceive('getKeyName')->andReturn('id');
    $model->shouldReceive('getAttributes')->andReturn(['id' => $id, 'name' => 'Test', 'email' => 'test@test.com']);
    $model->shouldReceive('getDirty')->andReturn(['name' => 'New Name']);
    $model->shouldReceive('getOriginal')->andReturn(['name' => 'Old Name']);

    return $model;
}
