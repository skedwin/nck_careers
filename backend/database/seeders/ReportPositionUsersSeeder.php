<?php

namespace Database\Seeders;

use App\Models\Position;
use App\Models\User;
use App\Models\UserPositionScope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ReportPositionUsersSeeder extends Seeder
{
    /**
     * @return list<array{username: string, email: string, password: string, positions: list<string>}>
     */
    public function run(): array
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ([
            'reports.view',
            'applications.view',
            'applications.profile.update',
            'documents.view',
            'documents.download',
        ] as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('Report Viewer');
        $role->syncPermissions([
            'reports.view',
            'applications.view',
            'applications.profile.update',
            'documents.view',
            'documents.download',
        ]);

        $users = [
            [
                'username' => 'fsduser',
                'email' => 'fsduser@nckenya.go.ke',
                'name' => 'FSD Reports User',
                'positions' => ['NCK/REC1', 'NCK/REC2', 'NCK/REC3', 'NCK/REC4', 'NCK/REC5'],
            ],
            [
                'username' => 'commsuser',
                'email' => 'commsuser@nckenya.go.ke',
                'name' => 'Communications Reports User',
                'positions' => ['NCK/REC6', 'NCK/REC7'],
            ],
            [
                'username' => 'nusesuser',
                'email' => 'nusesuser@nckenya.go.ke',
                'name' => 'Nursing Services Reports User',
                'positions' => ['NCK/REC8', 'NCK/REC9', 'NCK/REC10'],
            ],
            [
                'username' => 'officeuser',
                'email' => 'officeuser@nckenya.go.ke',
                'name' => 'Office Reports User',
                'positions' => ['NCK/REC11', 'NCK/REC12', 'NCK/REC13'],
            ],
        ];

        $created = [];

        foreach ($users as $spec) {
            $existing = User::query()->where('email', $spec['email'])->first();
            $password = $existing ? null : Str::password(16, symbols: true);

            $attributes = [
                'name' => $spec['name'],
                'display_name' => $spec['username'],
                'is_active' => true,
                'email_verified_at' => now(),
            ];

            if ($password !== null) {
                $attributes['password'] = Hash::make($password);
            }

            $user = User::query()->updateOrCreate(
                ['email' => $spec['email']],
                $attributes
            );

            $user->syncRoles(['Report Viewer']);
            UserPositionScope::query()->where('user_id', $user->id)->delete();

            foreach ($spec['positions'] as $code) {
                $positionId = Position::query()->where('reference_code', $code)->value('id');
                if (! $positionId) {
                    $this->command?->warn("Position {$code} not found — run PositionSeeder first.");

                    continue;
                }

                UserPositionScope::query()->create([
                    'user_id' => $user->id,
                    'position_id' => $positionId,
                ]);
            }

            $created[] = [
                'username' => $spec['username'],
                'email' => $spec['email'],
                'password' => $password ?? '(unchanged)',
                'positions' => $spec['positions'],
            ];
        }

        return $created;
    }
}
