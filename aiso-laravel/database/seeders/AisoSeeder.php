<?php

namespace Database\Seeders;

use App\Models\AppRole;
use App\Imports\ImportService;
use App\Models\AppUser;
use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * On an empty database creates the OWNER, a MANAGER and the three executive users (accounts are independent). Replaces
 * the Spring DataSeeder: runs with `php artisan migrate --seed` and is skipped once any user exists.
 */
class AisoSeeder extends Seeder
{
    private const DEFAULT_PASSWORD = 'ChangeMe!123';

    public function run(): void
    {
        if (!config('aiso.seed.enabled') || AppUser::query()->exists()) {
            return;
        }
        $password = (string) config('aiso.seed.password');

        $this->add('owner', 'System Owner', AppRole::OWNER, $password);
        $this->add('manager', 'Project Manager', AppRole::MANAGER, $password);
        $this->add('user1', 'Executive User 1', AppRole::USER_1, $password);
        $this->add('user2', 'Executive User 2', AppRole::USER_2, $password);
        $this->add('user3', 'Executive User 3', AppRole::USER_3, $password);

        $s = SystemSetting::current();
        $s->owner_id = 'owner';
        $s->manager_id = 'manager';
        $language = (string) config('aiso.seed.language');
        if (trim($language) !== '') {
            $s->language = $language;
        }
        $s->save();

        // The fixed departments: human resources (created by its migration) and production, which holds every planning
        // page. A fresh installation starts with both.
        if (config('aiso.seed.production_department') && !Department::query()->where('code', Department::PRODUCTION)->exists()) {
            $production = Department::query()->create([
                'name' => 'تولید', 'description' => null, 'status' => 'ACTIVE',
                'sort_order' => (int) Department::query()->max('sort_order') + 1, 'code' => Department::PRODUCTION,
            ]);
            foreach ([['HEAD', 'PANEL_VIEW'], ['HEAD', 'RESOURCES_MANAGE'], ['HEAD', 'SUGGESTIONS_SUBMIT'],
                ['MEMBER', 'PANEL_VIEW'], ['MEMBER', 'SUGGESTIONS_SUBMIT']] as [$role, $permission]) {
                DepartmentRolePermission::query()->create(['department_id' => $production->id, 'role' => $role, 'permission' => $permission]);
            }
        }

        // Demo data: the sample workbook (16 operations, 1 project) so every screen has something to show.
        if (config('aiso.seed.demo_data')) {
            $result = app(ImportService::class)->processSample(true, AppUser::query()->find('owner'));
            $this->command?->info('Demo data loaded: ' . ($result['status'] ?? 'unknown') . ' (sample workbook).');
        }

        if ($password === self::DEFAULT_PASSWORD) {
            $this->command?->warn('Seeded users use the DEFAULT password. Set AISO_SEED_PASSWORD before the first start, or change the passwords now.');
        }
    }

    private function add(string $id, string $name, string $role, string $password): void
    {
        AppUser::query()->create([
            'id' => $id,
            'full_name' => $name,
            'user_role' => $role,
            'active' => true,
            'password_hash' => Hash::make($password),
            'must_change_password' => false,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
