<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-08: unit progress (FR-23).
// Contract: TaskCreate, TaskUpdate, Task.progress_unit, and
// Task.unit_name_suggestion in docs/tcb_openapi.yaml.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/*
| Criterion: unit_total, unit_done, and unit_name are optional when creating
| and updating a task.
*/

it('creates a task with unit progress', function () {
    $this->postJson('/api/v1/tasks', [
        'title' => 'Koreksi UTS kelas A',
        'label' => 'Koreksi ujian',
        'unit_total' => 40,
        'unit_done' => 22,
        'unit_name' => 'lembar',
    ])->assertCreated()
        ->assertJsonPath('progress_unit', ['done' => 22, 'total' => 40, 'name' => 'lembar']);

    $task = Task::firstOrFail();
    expect($task->unit_total)->toBe(40.0)
        ->and($task->unit_done)->toBe(22.0)
        ->and($task->unit_name)->toBe('lembar');
});

it('creates a task without unit progress', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Susun soal UAS'])
        ->assertCreated()
        ->assertJsonPath('progress_unit', null);

    $task = Task::firstOrFail();
    expect($task->unit_total)->toBeNull()
        ->and($task->unit_done)->toBeNull()
        ->and($task->unit_name)->toBeNull();
});

it('starts a new unit total at zero done', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Koreksi', 'unit_total' => 40, 'unit_name' => 'lembar'])
        ->assertCreated()
        ->assertJsonPath('progress_unit', ['done' => 0, 'total' => 40, 'name' => 'lembar']);
});

it('accepts fractional units', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Revisi', 'unit_total' => 12.5, 'unit_done' => 2.5])
        ->assertCreated()
        ->assertJsonPath('progress_unit.done', 2.5)
        ->assertJsonPath('progress_unit.total', 12.5);
});

it('updates unit progress with PATCH, by id or task key', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 10, 'unit_name' => 'lembar']);

    $this->patchJson("/api/v1/tasks/{$task->task_key}", ['unit_done' => 23])
        ->assertOk()
        ->assertJsonPath('progress_unit', ['done' => 23, 'total' => 40, 'name' => 'lembar']);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_total' => 45, 'unit_name' => 'berkas'])
        ->assertOk()
        ->assertJsonPath('progress_unit', ['done' => 23, 'total' => 45, 'name' => 'berkas']);
});

it('adds unit progress to an existing task with PATCH', function () {
    $task = makeTask($this->user);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_total' => 8, 'unit_done' => 3])
        ->assertOk()
        ->assertJsonPath('progress_unit', ['done' => 3, 'total' => 8, 'name' => 'unit']);
});

it('leaves unit progress alone when PATCH omits the unit fields', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 22, 'unit_name' => 'lembar']);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Baru'])
        ->assertOk()
        ->assertJsonPath('progress_unit', ['done' => 22, 'total' => 40, 'name' => 'lembar']);
});

it('removes unit progress when PATCH clears unit_total', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 22, 'unit_name' => 'lembar']);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_total' => null])
        ->assertOk()
        ->assertJsonPath('progress_unit', null);

    $task->refresh();
    expect($task->unit_total)->toBeNull()
        ->and($task->unit_done)->toBeNull()
        ->and($task->unit_name)->toBeNull();
});

it('rejects invalid unit values', function (array $body, string $field, string $message) {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', ...$body])
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$field}.0", $message);

    expect(Task::count())->toBe(0);
})->with([
    'total zero' => [['unit_total' => 0], 'unit_total', 'Total satuan harus lebih dari 0.'],
    'total negative' => [['unit_total' => -5], 'unit_total', 'Total satuan harus lebih dari 0.'],
    'total text' => [['unit_total' => 'empat puluh'], 'unit_total', 'Total satuan harus berupa angka.'],
    'done negative' => [['unit_total' => 40, 'unit_done' => -1], 'unit_done', 'Jumlah selesai tidak boleh negatif.'],
    'done text' => [['unit_total' => 40, 'unit_done' => 'banyak'], 'unit_done', 'Jumlah selesai harus berupa angka.'],
    'name not text' => [['unit_total' => 40, 'unit_name' => ['lembar']], 'unit_name', 'Nama satuan harus berupa teks.'],
]);

it('rejects unit_done without a total', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'unit_done' => 3])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unit_done.0', 'Isi total satuan dulu sebelum mencatat jumlah selesai.');

    $task = makeTask($this->user);
    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_done' => 3])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unit_done.0', 'Isi total satuan dulu sebelum mencatat jumlah selesai.');

    $withUnits = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 5]);
    $this->patchJson("/api/v1/tasks/{$withUnits->id}", ['unit_total' => null, 'unit_done' => 5])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unit_done.0', 'Isi total satuan dulu sebelum mencatat jumlah selesai.');

    expect($withUnits->fresh()->unit_total)->toBe(40.0);
});

/*
| Criterion: an empty unit_name is stored as `unit`.
*/

it('stores an empty unit name as unit', function (array $name) {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'unit_total' => 10, ...$name])
        ->assertCreated()
        ->assertJsonPath('progress_unit.name', 'unit');

    expect(Task::firstOrFail()->unit_name)->toBe('unit');
})->with([
    'missing' => [[]],
    'null' => [['unit_name' => null]],
    'empty' => [['unit_name' => '']],
    'blank' => [['unit_name' => '   ']],
]);

it('stores unit when PATCH empties the unit name', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_name' => 'lembar']);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_name' => ''])
        ->assertOk()
        ->assertJsonPath('progress_unit.name', 'unit');

    expect($task->fresh()->unit_name)->toBe('unit');
});

it('stores unit when PATCH adds a total to a task without a unit name', function () {
    $task = makeTask($this->user);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_total' => 5]);

    expect($task->fresh()->unit_name)->toBe('unit');
});

it('trims the unit name', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'unit_total' => 10, 'unit_name' => '  lembar  '])
        ->assertCreated()
        ->assertJsonPath('progress_unit.name', 'lembar');
});

/*
| Criterion: the unit name is suggested from the label and returned as a
| suggestion, never stored automatically.
*/

it('suggests a unit name from the label', function (?string $label, string $suggestion) {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'label' => $label])
        ->assertCreated()
        ->assertJsonPath('unit_name_suggestion', $suggestion);
})->with([
    'Koreksi ujian' => ['Koreksi ujian', 'lembar'],
    'Menulis paper' => ['Menulis paper', 'halaman'],
    'Mengajar' => ['Mengajar', 'slide'],
    'other case' => ['koreksi UJIAN', 'lembar'],
    'other label' => ['Bimbingan', 'unit'],
    'no label' => [null, 'unit'],
]);

it('does not store the suggestion as the unit name', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Koreksi', 'label' => 'Koreksi ujian', 'unit_total' => 40])
        ->assertCreated()
        ->assertJsonPath('progress_unit.name', 'unit')
        ->assertJsonPath('unit_name_suggestion', 'lembar');

    $this->postJson('/api/v1/tasks', ['title' => 'Mengajar', 'label' => 'Mengajar'])
        ->assertCreated();

    expect(Task::where('title', 'Koreksi')->value('unit_name'))->toBe('unit')
        ->and(Task::where('title', 'Mengajar')->value('unit_name'))->toBeNull();
});

it('returns the suggestion in the list, detail, and update responses', function () {
    $task = makeTask($this->user, ['label' => 'Menulis paper']);

    $this->getJson('/api/v1/tasks')->assertJsonPath('data.0.unit_name_suggestion', 'halaman');
    $this->getJson("/api/v1/tasks/{$task->task_key}")->assertJsonPath('unit_name_suggestion', 'halaman');
    $this->patchJson("/api/v1/tasks/{$task->id}", ['label' => 'Mengajar'])
        ->assertJsonPath('unit_name_suggestion', 'slide');
});

/*
| Criterion: unit_done > unit_total is rejected with a friendly 422, not a 500.
*/

it('rejects unit_done above unit_total on create', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'unit_total' => 40, 'unit_done' => 41, 'unit_name' => 'lembar'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unit_done.0', 'Jumlah selesai (41) tidak boleh melebihi total (40 lembar).');

    expect(Task::count())->toBe(0);
});

it('accepts unit_done equal to unit_total', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'unit_total' => 40, 'unit_done' => 40])
        ->assertCreated()
        ->assertJsonPath('progress_unit.done', 40);
});

it('rejects a PATCH unit_done above the stored total', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 22, 'unit_name' => 'lembar']);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_done' => 40.5])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unit_done.0', 'Jumlah selesai (40,5) tidak boleh melebihi total (40 lembar).');

    expect($task->fresh()->unit_done)->toBe(22.0);
});

it('rejects a PATCH total below the units already done', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 22, 'unit_name' => 'lembar']);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_total' => 20])
        ->assertUnprocessable()
        ->assertJsonPath('errors.unit_total.0', 'Total (20 lembar) tidak boleh kurang dari jumlah yang sudah selesai (22).');

    expect($task->fresh()->unit_total)->toBe(40.0);
});

it('accepts a PATCH that lowers the total and the units done together', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 22]);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_total' => 20, 'unit_done' => 20])
        ->assertOk()
        ->assertJsonPath('progress_unit.total', 20)
        ->assertJsonPath('progress_unit.done', 20);
});

it('maps a unit check violation from the database to a friendly 422', function (array $attributes, string $constraint) {
    // The database is the last guard (CLAUDE.md rule 3): if a request ever
    // slips past validation, the CHECK error still becomes a 422, not a 500.
    try {
        DB::transaction(fn () => makeTask($this->user, $attributes));
        $this->fail('Expected a check violation');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain($constraint);
        $response = app(ExceptionHandler::class)->render(Request::create('/api/v1/tasks', 'POST'), $e);
    }

    expect($response->getStatusCode())->toBe(422);
    $body = json_decode($response->getContent(), true);
    expect($body['errors']['unit_done'][0])->toBe($body['message'])
        ->and($body['message'])->not->toContain($constraint);
})->with([
    'done above total' => [['unit_total' => 40, 'unit_done' => 41], 'chk_tasks_unit_le_total'],
    'done without total' => [['unit_done' => 3], 'chk_tasks_unit_needs_total'],
]);
