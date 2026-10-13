<?php

namespace Tests\Feature\AI;

use App\AI\DepartmentRegistry;
use App\Exceptions\ApiException;
use App\Models\AppUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** What a user may reach through the agent is exactly what their role and department access allow. */
class HandlerScopeTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    /** @return string[] */
    private function offered(string $userId): array
    {
        return array_keys(app(DepartmentRegistry::class)->forUser($this->user($userId)));
    }

    public function test_the_owner_is_offered_every_department_and_a_stranger_none(): void
    {
        $this->production();

        $this->assertEqualsCanonicalizing(['hr', 'production'], $this->offered('owner'));
        $this->assertSame([], $this->offered('user3'));
    }

    public function test_a_manager_is_offered_production_but_not_human_resources(): void
    {
        $this->staff($this->production(), [['manager', 'HEAD']]);
        $this->staff($this->hr(), [['manager', 'MEMBER']]);

        $this->assertSame(['production'], $this->offered('manager'));
    }

    public function test_an_executor_in_production_is_not_offered_its_operations(): void
    {
        $this->staff($this->production(), [['user1', 'MEMBER']]);

        $this->assertSame([], $this->offered('user1'));
    }

    public function test_a_handler_refuses_a_role_the_registry_would_not_offer(): void
    {
        $d = $this->production();
        $this->staff($d, [['user1', 'MEMBER']]);

        $this->expectException(ApiException::class);
        app(DepartmentRegistry::class)->handler('production')->fetch('status_summary', [], $this->user('user1'), $d);
    }

    public function test_human_resources_returns_no_contact_details_and_respects_the_row_limit(): void
    {
        AppUser::query()->whereKey('user1')->update(['contact_info' => '09120000000', 'messenger_id' => '555111']);
        $hr = app(DepartmentRegistry::class)->handler('hr');

        $r = $hr->fetch('list_users', ['limit' => 2], $this->user('owner'), $this->hr());

        $this->assertCount(2, $r['data']);
        $this->assertTrue($r['truncated']);
        $json = json_encode($r);
        $this->assertStringNotContainsString('09120000000', $json);
        $this->assertStringNotContainsString('555111', $json);
        $this->assertStringNotContainsString('password', $json);
        $this->assertSame(['id', 'name', 'role', 'active'], array_keys($r['data'][0]));
    }

    public function test_the_row_limit_never_exceeds_the_configured_maximum(): void
    {
        config(['aiso.agent.max_rows' => 3]);
        for ($i = 1; $i <= 5; $i++) {
            $this->operation("OP-{$i}", 'READY');
        }
        $d = $this->production();

        $r = app(DepartmentRegistry::class)->handler('production')->fetch('list_by_status', ['status' => 'READY', 'limit' => 50], $this->user('owner'), $d);

        $this->assertCount(3, $r['data']);
        $this->assertTrue($r['truncated']);
    }

    public function test_the_general_handler_refuses_someone_who_may_not_open_the_department(): void
    {
        $other = \App\Models\Department::query()->create(['name' => 'Finance', 'status' => 'ACTIVE', 'sort_order' => 9, 'created_at' => now()]);

        $this->expectException(ApiException::class);
        app(DepartmentRegistry::class)->handler('general')->fetch('overview', [], $this->user('user3'), $other);
    }
}
