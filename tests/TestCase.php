<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ensure the given Spatie permission exists before assigning it.
     * Necessary in tests that use RefreshDatabase, since the DB is wiped between tests.
     */
    protected function createPermission(string $name): Permission
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        return Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
    }
}
