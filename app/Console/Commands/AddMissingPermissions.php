<?php

namespace App\Console\Commands;

use Database\Seeders\PermissionSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Brings permissions and system roles up to date after a deploy without
 * undoing changes made in admin or via the API: nothing is ever removed.
 *
 * - Permissions in PermissionSeeder::PERMISSIONS that are missing are created.
 * - A new permission is given to the roles that list it in ROLES.
 * - A missing system role is created with its full set from ROLES.
 *
 * Changing what an existing role has for an existing permission is not done
 * here — do it in admin, or with a migration if it must reach every install.
 */
class AddMissingPermissions extends Command
{
    protected $signature = 'permissions:add-missing';

    protected $description = 'Skapar behörigheter och systemroller som saknas, utan att ta bort något';

    public function handle(PermissionRegistrar $registrar): int
    {
        $registrar->forgetCachedPermissions();

        $existing = Permission::pluck('name')->all();
        $new = array_values(array_diff(array_keys(PermissionSeeder::PERMISSIONS), $existing));

        foreach (PermissionSeeder::PERMISSIONS as $name => $label) {
            PermissionSeeder::permission($name, $label);
        }

        $existingRoles = Role::pluck('name')->all();
        $changes = 0;

        foreach (PermissionSeeder::ROLES as $name => $definition) {
            $role = PermissionSeeder::role($name, $definition['label']);

            if (! in_array($name, $existingRoles, true)) {
                $role->givePermissionTo(PermissionSeeder::permissionsFor($name));
                $this->line("  Ny roll: {$name}");
                $changes++;

                continue;
            }

            $grant = array_values(array_intersect(PermissionSeeder::permissionsFor($name), $new));

            if ($grant !== []) {
                $role->givePermissionTo($grant);
                $this->line("  {$name} fick: ".implode(', ', $grant));
                $changes++;
            }
        }

        $registrar->forgetCachedPermissions();

        foreach ($new as $name) {
            $this->line("  Ny behörighet: {$name}");
        }

        $this->info($new === [] && $changes === 0
            ? 'Behörigheterna är redan aktuella.'
            : 'Behörigheterna är uppdaterade.');

        return self::SUCCESS;
    }
}
