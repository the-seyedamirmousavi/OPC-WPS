<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\Department;
use App\Models\WorkResource;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Who sees which department in the menu and in its panel, and who keeps the user management. */
class DepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
        $this->asUser('owner');
    }

    private function asUser(string $id): void
    {
        $this->actingAs(AppUser::query()->find($id));
    }

    private function hrId(): int
    {
        return (int) Department::query()->where('code', Department::HR)->value('id');
    }

    private function department(string $name): int
    {
        $this->asUser('owner');

        return (int) $this->postJson('/api/departments', ['name' => $name])->assertOk()->json('id');
    }

    /** @param array<int, array{0: string, 1: string}> $members user id and department role pairs */
    private function staff(int $dept, array $members): void
    {
        $this->asUser('owner');
        $this->putJson("/api/departments/{$dept}/members", [
            'members' => array_map(static fn (array $m) => ['userId' => $m[0], 'role' => $m[1]], $members),
        ])->assertOk();
    }

    /** @return string[] the names of the departments in the person's menu */
    private function menuNames(string $who): array
    {
        $this->asUser($who);

        return array_column($this->getJson('/api/departments/menu')->assertOk()->json(), 'name');
    }

    public function test_the_human_resources_department_exists_from_the_start_with_its_default_permissions(): void
    {
        $hr = $this->hrId();
        $panel = $this->getJson("/api/departments/{$hr}")->assertOk()->json();

        $this->assertTrue($panel['isHr']);
        $this->assertContains('PANEL_VIEW', $panel['permissions']['HEAD']);
        $this->assertContains('PANEL_VIEW', $panel['permissions']['MEMBER']);
    }

    public function test_the_owner_sees_every_department_in_the_menu(): void
    {
        $this->department('Production');
        $this->department('Finance');

        $this->assertSame(['منابع انسانی', 'Production', 'Finance'], $this->menuNames('owner'));
    }

    public function test_a_person_sees_only_the_departments_where_their_role_may_view(): void
    {
        $production = $this->department('Production');
        $finance = $this->department('Finance');
        $this->staff($production, [['user1', 'HEAD']]);
        $this->staff($finance, [['user2', 'MEMBER']]);
        $this->staff($this->hrId(), [['user2', 'MEMBER']]);

        $this->assertSame(['Production'], $this->menuNames('user1'));
        $this->assertSame(['منابع انسانی', 'Finance'], $this->menuNames('user2'));
        $this->assertSame([], $this->menuNames('user3'));
        $this->assertSame([], $this->menuNames('manager'));
    }

    public function test_removing_panel_view_from_a_role_closes_its_panel_without_removing_the_member(): void
    {
        $production = $this->department('Production');
        $this->staff($production, [['user1', 'HEAD'], ['user2', 'MEMBER']]);

        $this->asUser('owner');
        $this->putJson("/api/departments/{$production}/permissions", [
            'permissions' => ['HEAD' => ['PANEL_VIEW'], 'MEMBER' => ['SUGGESTIONS_SUBMIT']],
        ])->assertOk();

        $this->assertSame([], $this->menuNames('user2'));
        $this->asUser('user2');
        $this->getJson("/api/departments/{$production}")->assertForbidden();

        // the head still has the panel, and the member is still a member
        $this->assertSame(['Production'], $this->menuNames('user1'));
        $this->asUser('owner');
        $this->assertSame(2, $this->getJson("/api/departments/{$production}")->json('memberCount'));
    }

    public function test_a_member_sees_the_panel_but_not_the_owners_controls(): void
    {
        $production = $this->department('Production');
        $this->resource('R-1');
        $this->putJson("/api/departments/{$production}/resources", ['resourceIds' => ['R-1']])->assertOk();
        $this->staff($production, [['user1', 'HEAD'], ['user2', 'MEMBER']]);

        $this->asUser('user2');
        $panel = $this->getJson("/api/departments/{$production}")->assertOk()->json();
        $this->assertArrayNotHasKey('permissions', $panel);
        $this->assertArrayNotHasKey('allResources', $panel);
        $this->assertSame('MEMBER', $panel['myRole']);
        $this->assertSame([
            'manageDepartment' => false,
            'manageMembers' => false,
            'managePermissions' => false,
            'assignResources' => false,
            'viewUsers' => false,
            'viewAudit' => false,
            'viewAgents' => false,
            'viewImports' => false,
            'viewManagerDashboard' => false,
            'viewPlanning' => false,
            'manageResources' => false,
            'submitSuggestions' => true,
        ], $panel['abilities']);

        $this->asUser('user1');
        $this->assertTrue($this->getJson("/api/departments/{$production}")->json('abilities.manageResources'));

        $this->asUser('owner');
        $owner = $this->getJson("/api/departments/{$production}")->json();
        $this->assertArrayHasKey('permissions', $owner);
        $this->assertTrue($owner['abilities']['manageMembers']);
        $this->assertFalse($owner['abilities']['viewUsers']); // not the human resources department
    }

    public function test_only_the_owner_sees_the_user_management_in_the_human_resources_panel(): void
    {
        $hr = $this->hrId();
        $this->staff($hr, [['user1', 'HEAD']]);

        $this->asUser('user1');
        $this->assertFalse($this->getJson("/api/departments/{$hr}")->json('abilities.viewUsers'));

        $this->asUser('owner');
        $this->assertTrue($this->getJson("/api/departments/{$hr}")->json('abilities.viewUsers'));
    }

    public function test_members_cannot_list_or_change_departments_or_their_people(): void
    {
        $production = $this->department('Production');
        $this->staff($production, [['user1', 'HEAD']]);

        $this->asUser('user1');
        $this->getJson('/api/departments')->assertForbidden();
        $this->postJson('/api/departments', ['name' => 'Sneaky'])->assertForbidden();
        $this->patchJson("/api/departments/{$production}", ['name' => 'Renamed'])->assertForbidden();
        $this->putJson("/api/departments/{$production}/members", ['members' => []])->assertForbidden();
        $this->putJson("/api/departments/{$production}/permissions", ['permissions' => ['HEAD' => [], 'MEMBER' => []]])
            ->assertForbidden();
        $this->putJson("/api/departments/{$production}/resources", ['resourceIds' => []])->assertForbidden();
    }

    public function test_user_management_stays_with_the_owner_wherever_it_is_shown(): void
    {
        $this->staff($this->hrId(), [['user1', 'HEAD']]);

        $this->asUser('user1');
        $this->getJson('/api/users')->assertForbidden();
        $this->postJson('/api/users', [
            'id' => 'newhire', 'fullName' => 'New Hire', 'role' => 'USER_1', 'password' => 'Passw0rd!long',
        ])->assertForbidden();
        $this->patchJson('/api/users/user3', ['active' => false])->assertForbidden();

        $this->asUser('owner');
        $this->getJson('/api/users')->assertOk();
    }

    public function test_someone_outside_the_department_cannot_open_its_panel(): void
    {
        $production = $this->department('Production');

        $this->asUser('user3');
        $this->getJson("/api/departments/{$production}")->assertForbidden();
    }

    public function test_the_menu_takes_the_same_number_of_queries_whatever_the_number_of_departments(): void
    {
        $this->staff($this->department('Production'), [['user1', 'HEAD']]);
        $this->asUser('user1');
        $this->getJson('/api/departments/menu')->assertOk();

        DB::enableQueryLog();
        $this->getJson('/api/departments/menu')->assertOk();
        $fewer = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $id = $this->department("Extra {$i}");
            $this->staff($id, [['user1', 'MEMBER']]);
        }
        $this->asUser('user1');
        DB::flushQueryLog();
        $this->getJson('/api/departments/menu')->assertOk()->assertJsonCount(7);
        $more = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($fewer, $more);
    }

    public function test_an_old_user_bookmark_lands_in_the_human_resources_panel(): void
    {
        $this->get('/owner/users')->assertRedirect('/departments/' . $this->hrId() . '?tab=users');
    }

    public function test_the_panel_has_a_named_route(): void
    {
        $this->assertSame(url('/departments/7'), route('departments.show', ['id' => 7]));
        $this->assertSame(url('/owner/users'), route('users.index'));
    }

    public function test_the_audit_log_is_in_the_human_resources_panel_and_only_the_owner_reads_it(): void
    {
        $hr = $this->hrId();
        $production = $this->department('Production');
        $this->staff($hr, [['user1', 'HEAD']]);

        $this->asUser('owner');
        $this->getJson("/api/departments/{$hr}")->assertOk()->assertJsonPath('abilities.viewAudit', true)
            ->assertJsonPath('abilities.viewAgents', true);
        $this->getJson("/api/departments/{$hr}/audit?limit=5")->assertOk()
            ->assertJsonFragment(['action' => 'DEPARTMENT_CREATED']);
        // the audit log is shown in the human resources panel only
        $this->getJson("/api/departments/{$production}/audit")->assertForbidden();

        $this->asUser('user1');
        $this->assertFalse($this->getJson("/api/departments/{$hr}")->json('abilities.viewAudit'));
        $this->getJson("/api/departments/{$hr}/audit")->assertForbidden();

        $this->asUser('manager');
        $this->getJson("/api/departments/{$hr}/audit")->assertForbidden();
    }

    public function test_the_import_history_is_in_the_production_panel_for_the_owner_and_its_members(): void
    {
        $this->asUser('owner');
        $production = $this->department('Production');
        $this->patchJson("/api/departments/{$production}", ['code' => 'PRODUCTION'])
            ->assertOk()->assertJsonPath('code', 'PRODUCTION');
        DB::table('import_log')->insert([
            'imported_at' => now(), 'actor_id' => 'owner', 'file_name' => 'plan.xlsx', 'file_format' => 'XLSX',
            'status' => 'APPLIED', 'error_count' => 0, 'summary' => 'ok', 'details' => null,
        ]);
        $this->staff($production, [['user1', 'HEAD']]);
        $this->staff($this->hrId(), [['user2', 'MEMBER']]);

        $this->getJson("/api/departments/{$production}/imports")->assertOk()
            ->assertJsonPath('0.fileName', 'plan.xlsx');

        $this->asUser('user1');
        $this->assertTrue($this->getJson("/api/departments/{$production}")->json('abilities.viewImports'));
        $this->getJson("/api/departments/{$production}/imports")->assertOk();

        // a member of another department does not see it
        $this->asUser('user2');
        $this->getJson("/api/departments/{$production}/imports")->assertForbidden();

        // the import history belongs to the production department only
        $this->asUser('owner');
        $this->getJson("/api/departments/{$this->hrId()}/imports")->assertNotFound();
    }

    public function test_a_special_role_is_held_by_one_department_at_a_time(): void
    {
        $this->asUser('owner');
        $a = $this->department('A');
        $b = $this->department('B');

        $this->patchJson("/api/departments/{$a}", ['code' => 'PRODUCTION'])->assertOk();
        $this->patchJson("/api/departments/{$b}", ['code' => 'PRODUCTION'])->assertConflict();
        $this->patchJson("/api/departments/{$this->hrId()}", ['code' => 'PRODUCTION'])->assertConflict();

        $this->patchJson("/api/departments/{$a}", ['code' => ''])->assertOk()->assertJsonPath('code', null);
        $this->patchJson("/api/departments/{$b}", ['code' => 'PRODUCTION'])->assertOk();
        $this->patchJson("/api/departments/{$b}", ['code' => 'UNKNOWN'])->assertStatus(400);
    }

    public function test_the_old_audit_and_import_addresses_land_in_their_panel_tabs(): void
    {
        $this->asUser('owner');
        $production = $this->department('Production');
        $this->patchJson("/api/departments/{$production}", ['code' => 'PRODUCTION'])->assertOk();

        $this->get('/audit')->assertRedirect('/departments/' . $this->hrId() . '?tab=audit');
        $this->get('/import')->assertRedirect('/departments/' . $production . '?tab=imports');
    }

    public function test_the_manager_dashboard_is_in_the_panel_of_the_department_the_manager_heads(): void
    {
        $production = $this->department('Production');
        $finance = $this->department('Finance');
        $this->staff($production, [['manager', 'HEAD']]);
        $this->staff($finance, [['user1', 'HEAD'], ['manager', 'MEMBER']]);

        $this->asUser('manager');
        $this->assertTrue($this->getJson("/api/departments/{$production}")->json('abilities.viewManagerDashboard'));
        // a member, not the head, does not get it
        $this->assertFalse($this->getJson("/api/departments/{$finance}")->json('abilities.viewManagerDashboard'));

        $menu = collect($this->getJson('/api/departments/menu')->assertOk()->json());
        $this->assertTrue($menu->firstWhere('id', $production)['headOf']);
        $this->assertFalse($menu->firstWhere('id', $finance)['headOf']);

        // the owner sees it where a manager heads the department, and nowhere else
        $this->asUser('owner');
        $this->assertTrue($this->getJson("/api/departments/{$production}")->json('abilities.viewManagerDashboard'));
        $this->assertFalse($this->getJson("/api/departments/{$finance}")->json('abilities.viewManagerDashboard'));

        // a head who is not a manager does not get the manager dashboard
        $this->asUser('user1');
        $this->assertFalse($this->getJson("/api/departments/{$finance}")->json('abilities.viewManagerDashboard'));
    }

    public function test_the_planning_pages_are_in_the_production_workspace_for_the_owner_and_its_managers(): void
    {
        $this->asUser('owner');
        $production = $this->department('Production');
        $this->patchJson("/api/departments/{$production}", ['code' => 'PRODUCTION'])->assertOk();
        $other = $this->department('Finance');
        $this->staff($production, [['manager', 'HEAD'], ['user1', 'MEMBER']]);
        $this->staff($other, [['manager', 'HEAD']]);

        $this->asUser('owner');
        $this->assertTrue($this->getJson("/api/departments/{$production}")->json('abilities.viewPlanning'));
        // only the production department has them
        $this->assertFalse($this->getJson("/api/departments/{$other}")->json('abilities.viewPlanning'));

        $this->asUser('manager');
        $this->assertTrue($this->getJson("/api/departments/{$production}")->json('abilities.viewPlanning'));
        $this->assertFalse($this->getJson("/api/departments/{$other}")->json('abilities.viewPlanning'));

        // an executor in the department has the panel but not the planning pages (their APIs are owner and manager only)
        $this->asUser('user1');
        $this->assertFalse($this->getJson("/api/departments/{$production}")->json('abilities.viewPlanning'));
        $this->getJson('/api/operations')->assertForbidden();
        $this->getJson('/api/projects')->assertForbidden();
    }

    public function test_the_directory_gives_each_department_its_heads_and_a_small_summary(): void
    {
        $this->asUser('owner');
        $production = $this->department('Production');
        $this->patchJson("/api/departments/{$production}", ['code' => 'PRODUCTION'])->assertOk();
        $this->staff($production, [['manager', 'HEAD'], ['user1', 'MEMBER']]);

        $list = collect($this->getJson('/api/departments')->assertOk()->json());
        $prod = $list->firstWhere('id', $production);
        $this->assertSame([['userId' => 'manager', 'fullName' => 'Project Manager']], $prod['heads']);
        $this->assertSame(['ready' => 0, 'inProgress' => 0, 'blocked' => 0], $prod['metrics']);

        $hr = $list->firstWhere('id', $this->hrId());
        $this->assertSame([], $hr['heads']);
        $this->assertSame(5, $hr['metrics']['activeUsers']);

        // a department without a summary of its own has none
        $finance = $this->department('Finance');
        $this->assertNull(collect($this->getJson('/api/departments')->json())->firstWhere('id', $finance)['metrics']);
    }

    public function test_the_directory_takes_the_same_number_of_queries_whatever_the_number_of_departments(): void
    {
        $this->asUser('owner');
        DB::enableQueryLog();
        $this->getJson('/api/departments')->assertOk();
        DB::flushQueryLog();
        $this->getJson('/api/departments')->assertOk();
        $few = count(DB::getQueryLog());

        for ($i = 0; $i < 5; $i++) {
            $this->department("Extra {$i}");
        }
        DB::flushQueryLog();
        $this->getJson('/api/departments')->assertOk();
        $many = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($few, $many);
    }

    private function resource(string $id): void
    {
        WorkResource::query()->create([
            'id' => $id, 'name' => "Machine {$id}", 'resource_type' => 'Machine', 'capacity' => 1,
            'status' => 'ACTIVE', 'sort_order' => 0,
        ]);
    }
}
