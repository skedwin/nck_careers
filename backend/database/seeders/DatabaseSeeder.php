<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'applications.view',
            'applications.create',
            'applications.update',
            'applications.profile.update',
            'applications.shortlist',
            'applications.reject',
            'documents.view',
            'documents.download',
            'screening.view',
            'screening.update',
            'positions.manage',
            'mailbox.sync',
            'reports.view',
            'users.manage',
            'audit.view',
            'settings.view',
            'settings.manage',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $roles = [
            'System Administrator' => $permissions,
            'Recruitment Administrator' => [
                'applications.view', 'applications.create', 'applications.update', 'applications.shortlist', 'applications.reject',
                'documents.view', 'documents.download', 'screening.view', 'screening.update', 'positions.manage',
                'mailbox.sync', 'reports.view', 'users.manage', 'settings.view',
            ],
            'Recruitment Officer' => [
                'applications.view', 'applications.update', 'applications.shortlist',
                'documents.view', 'documents.download', 'screening.view', 'screening.update', 'reports.view', 'settings.view',
            ],
            'Recruitment Panel Member' => [
                'applications.view', 'documents.view', 'documents.download', 'screening.view', 'reports.view',
            ],
            'Reviewer' => [
                'applications.view', 'documents.view', 'documents.download', 'screening.view', 'screening.update',
            ],
            'Read Only' => [
                'applications.view', 'documents.view', 'screening.view', 'reports.view', 'settings.view',
            ],
            'Report Viewer' => [
                'reports.view',
                'applications.view',
                'applications.profile.update',
                'documents.view',
                'documents.download',
            ],
            'Auditor' => [
                'applications.view', 'documents.view', 'audit.view', 'reports.view', 'settings.view',
            ],
        ];

        foreach ($roles as $roleName => $rolePermissions) {
            $role = Role::findOrCreate($roleName);
            $role->syncPermissions($rolePermissions);
        }

        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@nckenya.go.ke'],
            [
                'name' => 'System Administrator',
                'display_name' => 'System Administrator',
                'password' => Hash::make('ChangeMeNow!123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]
        );
        $admin->syncRoles(['System Administrator']);

        $defaults = [
            ['group' => 'general', 'key' => 'organization_name', 'value' => 'Nursing Council of Kenya', 'type' => 'string', 'is_public' => true],
            ['group' => 'general', 'key' => 'system_name', 'value' => 'NCK Careers', 'type' => 'string', 'is_public' => true],
            ['group' => 'mailbox', 'key' => 'mailbox_address', 'value' => 'careers@nckenya.go.ke', 'type' => 'string', 'is_public' => true],
            ['group' => 'ai', 'key' => 'ai_enabled', 'value' => 'false', 'type' => 'boolean', 'is_public' => true],
            ['group' => 'ai', 'key' => 'ai_confidence_threshold', 'value' => '0.7', 'type' => 'string', 'is_public' => false],
        ];

        foreach ($defaults as $setting) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $setting['key']],
                $setting + ['description' => null]
            );
        }

        $this->call(PositionSeeder::class);
    }
}
