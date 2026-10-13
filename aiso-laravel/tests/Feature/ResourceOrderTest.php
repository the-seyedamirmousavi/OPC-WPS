<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Resources keep the order they were entered in, so the timeline lists them the way the manager wrote them. */
class ResourceOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
        $this->actingAs(AppUser::query()->find('manager'));
    }

    private static function res(string $id): array
    {
        return ['id' => $id, 'name' => "Machine {$id}", 'capacity' => 1, 'status' => 'ACTIVE'];
    }

    /** @return string[] */
    private function dashboardOrder(): array
    {
        return array_column($this->getJson('/api/dashboard/manager')->assertOk()->json('resources'), 'id');
    }

    /** @return string[] */
    private function plannerOrder(): array
    {
        return array_column($this->getJson('/api/planning/plan')->assertOk()->json('resources'), 'id');
    }

    public function test_resources_follow_the_order_they_were_entered_not_their_ids(): void
    {
        $project = $this->postJson('/api/planning/submit', [
            'apply' => true, 'newProjectName' => 'Order',
            'resources' => [self::res('R-ZED'), self::res('R-ALPHA'), self::res('R-MID')],
            'operations' => [
                ['id' => 'OP-1', 'name' => 'a', 'resourceId' => 'R-MID', 'direct' => 1],
                ['id' => 'OP-2', 'name' => 'b', 'resourceId' => 'R-ALPHA', 'direct' => 1],
                ['id' => 'OP-3', 'name' => 'c', 'resourceId' => 'R-ZED', 'direct' => 1],
            ],
        ])->assertOk()->assertJsonPath('status', 'APPLIED')->json('projectId');

        // by id this would be ALPHA, MID, ZED
        $this->assertSame(['R-ZED', 'R-ALPHA', 'R-MID'], $this->dashboardOrder());
        $this->assertSame(['R-ZED', 'R-ALPHA', 'R-MID'], $this->plannerOrder());

        // writing them in another order, with a new one at the end, changes the order
        $this->postJson('/api/planning/submit', [
            'apply' => true, 'projectId' => $project,
            'resources' => [self::res('R-MID'), self::res('R-ZED'), self::res('R-ALPHA'), self::res('R-NEW')],
        ])->assertOk()->assertJsonPath('status', 'APPLIED');
        $this->assertSame(['R-MID', 'R-ZED', 'R-ALPHA', 'R-NEW'], $this->dashboardOrder());

        // a file that names only some resources puts them first; the others keep their order after them
        $this->postJson('/api/planning/submit', [
            'apply' => true, 'projectId' => $project, 'resources' => [self::res('R-NEW'), self::res('R-ZED')],
        ])->assertOk()->assertJsonPath('status', 'APPLIED');
        $this->assertSame(['R-NEW', 'R-ZED', 'R-MID', 'R-ALPHA'], $this->plannerOrder());
    }
}
