<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsAgentWorld;
use Tests\TestCase;

/** The owner's roles page: the company's roles with their headcount, and every department's matrix. */
class RolesPageTest extends TestCase
{
    use BuildsAgentWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWorld();
    }

    private function roleRow(array $overview, string $key): array
    {
        return collect($overview['roles'])->firstWhere('key', $key);
    }

    public function test_only_the_owner_sees_the_roles_overview(): void
    {
        $this->as('manager');
        $this->getJson('/api/roles')->assertForbidden();

        $this->as('owner');
        $this->getJson('/api/roles')->assertOk();
    }

    public function test_it_lists_every_role_with_its_headcount_and_locks_the_owner_and_the_agent(): void
    {
        $this->as('owner');
        $overview = $this->getJson('/api/roles')->assertOk()->json();

        $this->assertSame(1, $this->roleRow($overview, 'OWNER')['total']);
        $this->assertTrue($this->roleRow($overview, 'OWNER')['locked']);
        $this->assertSame(1, $this->roleRow($overview, 'MANAGER')['total']);
        $this->assertFalse($this->roleRow($overview, 'MANAGER')['locked']);
        $this->assertSame(1, $this->roleRow($overview, 'USER_1')['total']);
        $this->assertTrue($this->roleRow($overview, 'AGENT')['locked']);
        $this->assertSame(['HEAD', 'MEMBER'], $overview['departmentRoles']);
        $this->assertSame(['PANEL_VIEW', 'RESOURCES_MANAGE', 'SUGGESTIONS_SUBMIT'], $overview['catalog']);
        $this->assertSame(['production.view', 'production.manage', 'users.view', 'users.manage', 'departments.manage'], $overview['capabilities']);
    }

    public function test_a_change_saved_for_a_department_is_what_the_overview_shows(): void
    {
        $hr = $this->hr();
        $this->as('owner');

        $this->putJson("/api/departments/{$hr->id}/permissions", ['permissions' => [
            'HEAD' => ['PANEL_VIEW'], 'MEMBER' => ['PANEL_VIEW', 'SUGGESTIONS_SUBMIT'],
        ]])->assertOk();

        $row = collect($this->getJson('/api/roles')->json('departments'))->firstWhere('id', $hr->id);
        $this->assertSame(['PANEL_VIEW'], $row['permissions']['HEAD']);
        $this->assertSame(['PANEL_VIEW', 'SUGGESTIONS_SUBMIT'], $row['permissions']['MEMBER']);
    }
}
