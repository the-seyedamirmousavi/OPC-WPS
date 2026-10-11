<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Imports\ImportService;
use App\Models\AppUser;
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

        $this->add('owner', 'System Owner', Role::OWNER, $password);
        $this->add('manager', 'Project Manager', Role::MANAGER, $password);
        $this->add('user1', 'Executive User 1', Role::USER_1, $password);
        $this->add('user2', 'Executive User 2', Role::USER_2, $password);
        $this->add('user3', 'Executive User 3', Role::USER_3, $password);

        $s = SystemSetting::current();
        $s->owner_id = 'owner';
        $s->manager_id = 'manager';
        $language = (string) config('aiso.seed.language');
        if (trim($language) !== '') {
            $s->language = $language;
        }
        $s->save();

        // Demo data: the sample workbook (16 operations, 1 project) so every screen has something to show.
        if (config('aiso.seed.demo_data')) {
            $result = app(ImportService::class)->processSample(true, AppUser::query()->find('owner'));
            $this->command?->info('Demo data loaded: ' . ($result['status'] ?? 'unknown') . ' (sample workbook).');
        }

        if ($password === self::DEFAULT_PASSWORD) {
            $this->command?->warn('Seeded users use the DEFAULT password. Set AISO_SEED_PASSWORD before the first start, or change the passwords now.');
        }
    }

    private function add(string $id, string $name, Role $role, string $password): void
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
