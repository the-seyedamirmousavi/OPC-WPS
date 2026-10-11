<?php

namespace Tests\Feature;

use App\Models\AppUser;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The documented workbooks of the original project (test-data/, see TEST-SCENARIOS.md): valid files import, invalid files
 * are rejected with the documented message, and the scheduling scenarios give the documented makespan.
 */
class WorkbookFixturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
    }

    private function fixture(string $name): UploadedFile
    {
        return new UploadedFile(__DIR__ . '/../Fixtures/test-data/' . $name, $name, null, null, true);
    }

    private function upload(string $name, bool $apply = false): \Illuminate\Testing\TestResponse
    {
        $this->actingAs(AppUser::query()->find('owner'));

        return $this->post('/api/import', ['file' => $this->fixture($name), 'apply' => $apply ? '1' : '0'],
            ['Accept' => 'application/json']);
    }

    public function test_valid_workbook_is_a_clean_dry_run_then_imports(): void
    {
        $this->upload('I01-valid.xlsx')->assertOk()->assertJsonPath('status', 'VALID');
        $this->getJson('/api/operations')->assertOk()->assertJsonCount(0);

        $applied = $this->upload('I01-valid.xlsx', true)->assertOk()->assertJsonPath('status', 'APPLIED')->json();
        $counts = collect($applied['counts'])->keyBy('entity');
        $this->assertSame(3, $counts['resources']['created']);
        $this->assertSame(3, $counts['operations']['created']);
        $this->assertSame(2, $counts['predecessors']['created']);

        // OP-1 is ready, OP-2 waits for OP-1
        $this->getJson('/api/operations/OP-1')->assertJsonPath('operation.status', 'READY');
        $this->getJson('/api/operations/OP-2')->assertJsonPath('operation.status', 'NOT_READY');
    }

    public function test_missing_column_is_rejected_with_sheet_row_and_column(): void
    {
        $this->upload('I02-missing-column.xlsx')
            ->assertOk()
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonFragment(['sheet' => 'OPC', 'row' => 1, 'column' => 'Resource_ID'])
            ->assertJsonFragment(['message' => "Required column 'Resource_ID' is missing"]);
    }

    public function test_bad_references_are_all_reported_together(): void
    {
        $messages = collect($this->upload('I05-bad-references.xlsx')->assertOk()->json('errors'))->pluck('message')->all();
        $this->assertContains("Resource 'R-NOPE' does not exist", $messages);
        $this->assertContains("Predecessor 'GHOST' does not exist", $messages);
        $this->assertContains('An operation cannot be its own predecessor', $messages);
        $this->assertContains('Duplicate predecessor A for B', $messages);
    }

    public function test_bad_dependency_type_is_named_with_the_value_found(): void
    {
        $this->upload('I05b-bad-dependency-type.xlsx')
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonFragment(['message' => 'Dependency_Type must be FINISH_TO_START or START_TO_START (got SIDEWAYS)']);
    }

    public function test_dependency_cycle_is_reported_as_a_path_and_nothing_is_removed(): void
    {
        $this->upload('I06-cycle.xlsx')
            ->assertJsonPath('status', 'REJECTED')
            ->assertJsonFragment(['sheet' => 'Predecessors', 'row' => 2]);
        $message = collect($this->upload('I06-cycle.xlsx')->json('errors'))->pluck('message')
            ->first(fn (string $m) => str_starts_with($m, 'Dependency cycle'));
        $this->assertStringStartsWith('Dependency cycle: A -> C -> B -> A.', (string) $message);
    }

    public function test_empty_file_is_a_bad_request(): void
    {
        $this->actingAs(AppUser::query()->find('owner'));
        $this->post('/api/import', ['file' => $this->fixture('I11-empty.xlsx')], ['Accept' => 'application/json'])
            ->assertStatus(400)
            ->assertJsonPath('message', 'The file is empty');
    }

    public function test_text_file_is_not_a_workbook(): void
    {
        $this->upload('I13-not-excel.txt')->assertOk()->assertJsonPath('status', 'REJECTED');
    }

    public function test_managers_cannot_import(): void
    {
        $this->actingAs(AppUser::query()->find('user1'));
        $this->post('/api/import', ['file' => $this->fixture('I01-valid.xlsx')], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function scheduleScenarios(): array
    {
        return [
            'sequential chain (A 4h, B 3h, C 2h)' => ['S01-sequential.xlsx', 9.0],
            'two parallel tasks on capacity 2' => ['S02a-parallel-capacity2.xlsx', 5.0],
            'two parallel tasks on capacity 1' => ['S02b-parallel-capacity1.xlsx', 10.0],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scheduleScenarios')]
    public function test_scheduling_scenarios_give_the_documented_makespan(string $file, float $makespan): void
    {
        $this->upload($file, true)->assertOk()->assertJsonPath('status', 'APPLIED');
        $this->actingAs(AppUser::query()->find('manager'));
        $overview = $this->getJson('/api/scheduling/overview')->assertOk()->json();
        $this->assertEquals($makespan, $overview['optimized']['makespanHours']);
    }
}
