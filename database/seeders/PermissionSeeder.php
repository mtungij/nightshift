<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    public function run(bool $preserveExisting = false): void
    {
        $permissions = [];
        foreach (config('nightshift.permissions') as $name) {
            $permissions[$name] = Permission::firstOrCreate(['name' => $name])->id;
        } foreach (config('nightshift.roles') as $name => $grants) {
            $role = Role::firstOrCreate(['name' => $name]);
            if ($preserveExisting && ! $role->wasRecentlyCreated) {
                continue;
            }
            $role->permissions()->sync(array_values(array_intersect_key($permissions, array_flip(in_array('*', $grants, true) ? array_keys($permissions) : $grants))));
        }
    }
}
