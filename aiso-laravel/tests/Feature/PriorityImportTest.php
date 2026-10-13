<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\ImportLog;
use App\Models\Project;
use Database\Seeders\AisoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * A new project that joins active ones: the first request answers PRIORITY_REQUIRED and writes nothing; the second
 * sends the chosen ranking through POST /api/import and is APPLIED.
 */
class PriorityImportTest extends TestCase
{
    use RefreshDatabase;

    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['aiso.seed.enabled' => true, 'aiso.seed.password' => 'Passw0rd!test', 'aiso.seed.language' => 'en']);
        $this->seed(AisoSeeder::class);
        $this->actingAs(AppUser::query()->find('manager'));
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** A master-layout workbook with its own ids, so two projects never clash. */
    private function workbook(string $prefix): UploadedFile
    {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $sheets = [
            'Resources' => [['Resource_ID', 'Resource_Name', 'Capacity', 'Status'], ["R-{$prefix}", "Station {$prefix}", 1, 'ACTIVE']],
            'BOM' => [['Item_ID', 'Item_Name', 'Quantity'], ["ITM-{$prefix}", 'Box', 1]],
            'OPC' => [
                ['Operation_ID', 'Operation_Name', 'Item_ID', 'Resource_ID', 'Preparation_Time', 'Transport_Time', 'Setup_Time', 'Direct_Time'],
                ["{$prefix}-1", 'Cut', "ITM-{$prefix}", "R-{$prefix}", 0, 0, 0, 3],
                ["{$prefix}-2", 'Press', "ITM-{$prefix}", "R-{$prefix}", 0, 0, 0, 2],
            ],
            'Predecessors' => [['Operation_ID', 'Predecessor_Operation_ID', 'Dependency_Type', 'Is_Mandatory'], ["{$prefix}-2", "{$prefix}-1", 'FINISH_TO_START', 'TRUE']],
            'Users' => [['User_ID', 'Full_Name', 'Role', 'Active_Status']],
            'Settings' => [['Project_ID', 'Project_Name', 'Owner_ID', 'Manager_ID', 'Messenger_Platform', 'System_Status', 'Data_Version', 'Configuration_Version'], ['', '', '', '', '', '', '', '']],
        ];
        foreach ($sheets as $name => $rows) {
            $ws = $book->createSheet();
            $ws->setTitle($name);
            foreach ($rows as $r => $row) {
                foreach ($row as $c => $v) {
                    $ws->getCell(Coordinate::stringFromColumnIndex($c + 1) . ($r + 1))->setValue($v);
                }
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'prio') . '.xlsx';
        $this->tmp[] = $path;
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, "{$prefix}.xlsx", null, null, true);
    }

    private function import(string $query, UploadedFile $file): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/import?' . $query, ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_priority_is_asked_then_applied_with_the_chosen_ranking(): void
    {
        // the first project is created on its own
        $this->import('apply=true&newProjectName=First&newProjectId=PRJ-A', $this->workbook('A'))
            ->assertOk()->assertJsonPath('status', 'APPLIED');
        $this->assertSame(1, Project::query()->count());

        // a second project joins an active one: the server asks for a priority and writes nothing
        $asked = $this->import('apply=true&newProjectName=Second&newProjectId=PRJ-B', $this->workbook('B'))
            ->assertOk()
            ->assertJsonPath('status', 'PRIORITY_REQUIRED')
            ->assertJsonPath('newProject', true)
            ->assertJsonPath('projectId', 'PRJ-B')
            ->assertJsonCount(1, 'activeProjects')
            ->assertJsonPath('activeProjects.0.id', 'PRJ-A');
        $this->assertNotEmpty($asked->json('activeProjects'));
        $this->assertSame(1, Project::query()->count(), 'nothing is created while the priority is pending');
        $this->assertFalse(Project::query()->whereKey('PRJ-B')->exists());

        // the confirmation: the ranking puts the new project first, the other second
        $this->import('apply=true&newProjectName=Second&newProjectId=PRJ-B&ranking=NEW,PRJ-A', $this->workbook('B'))
            ->assertOk()->assertJsonPath('status', 'APPLIED')->assertJsonPath('projectId', 'PRJ-B');

        $this->assertSame(1, (int) Project::query()->whereKey('PRJ-B')->value('priority'));
        $this->assertSame(2, (int) Project::query()->whereKey('PRJ-A')->value('priority'));
        $this->assertSame(['APPLIED', 'PRIORITY_REQUIRED', 'APPLIED'],
            ImportLog::query()->orderBy('id')->pluck('status')->all());
    }

    public function test_a_ranking_missing_a_project_is_refused_and_writes_nothing(): void
    {
        $this->import('apply=true&newProjectName=First&newProjectId=PRJ-A', $this->workbook('A'))->assertOk();
        $this->import('apply=true&newProjectName=Second&newProjectId=PRJ-B', $this->workbook('B'))
            ->assertJsonPath('status', 'PRIORITY_REQUIRED');

        $r = $this->import('apply=true&newProjectName=Second&newProjectId=PRJ-B&ranking=NEW', $this->workbook('B'))
            ->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertStringContainsString('every active project', collect($r->json('errors'))->pluck('message')->implode(' '));
        $this->assertFalse(Project::query()->whereKey('PRJ-B')->exists());
    }
}
