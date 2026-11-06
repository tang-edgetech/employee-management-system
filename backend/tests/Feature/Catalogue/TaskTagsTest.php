<?php

use App\Domain\Catalogue\TaskTag;
use Database\Seeders\DemoCompanySeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(fn () => $this->seed(DemoCompanySeeder::class));

function taskTag(string $code): TaskTag
{
    return TaskTag::query()->where('code', $code)->firstOrFail();
}

it('lists every tag with its levels', function () {
    Sanctum::actingAs(demoUser('sinyee'));

    $data = $this->getJson('/api/v1/task-tags')->assertOk()->json('data');

    expect(array_column($data, 'code'))->toBe(['F', 'S', 'E', 'D', 'C', 'B', 'M'])
        ->and($data[4]['levels'][2])->toBe(['level' => 3, 'name' => 'Involved', 'points' => 42]);
});

it('adds a tag with a single level', function () {
    Sanctum::actingAs(demoUser('teechienyap')); // team leads manage tags by default

    $this->postJson('/api/v1/task-tags', [
        'code' => 'q', 'name' => 'Quick fix', 'color' => '#0e7490',
        'levels' => [['name' => 'Fixed', 'points' => 3]],
    ])->assertCreated()->assertJsonPath('data.code', 'Q')->assertJsonPath('data.levels', [['level' => 1, 'name' => 'Fixed', 'points' => 3]]);
});

it('validates code, colour and levels', function () {
    Sanctum::actingAs(demoUser('teechienyap'));

    $this->postJson('/api/v1/task-tags', ['code' => 'C', 'name' => 'Dup', 'color' => '#000000', 'levels' => [['name' => 'x', 'points' => 1]]])
        ->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/task-tags', ['code' => '1A', 'name' => 'Bad', 'color' => 'red', 'levels' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['code', 'color', 'levels']);
});

it('replaces the levels as a whole, numbered from 1', function () {
    Sanctum::actingAs(demoUser('teechienyap'));
    $f = taskTag('F');

    $this->patchJson("/api/v1/task-tags/{$f->public_id}", ['levels' => [
        ['name' => 'Small', 'points' => 1], ['name' => 'Big', 'points' => 4],
    ]], ['If-Match' => '"1"'])->assertOk()->assertJsonPath('data.levels.1', ['level' => 2, 'name' => 'Big', 'points' => 4])
        ->assertHeader('ETag', '"2"');

    expect($f->levels()->count())->toBe(2);

    $this->patchJson("/api/v1/task-tags/{$f->public_id}", ['code' => 'G'], ['If-Match' => '"2"'])
        ->assertStatus(422)->assertJsonValidationErrors('code');
});

it('deletes a tag but keeps at least one', function () {
    Sanctum::actingAs(demoUser('teechienyap'));

    foreach (['F', 'S', 'E', 'D', 'C', 'B'] as $code) {
        $this->deleteJson('/api/v1/task-tags/'.taskTag($code)->public_id, [], ['If-Match' => '"1"'])->assertNoContent();
    }
    $this->deleteJson('/api/v1/task-tags/'.taskTag('M')->public_id, [], ['If-Match' => '"1"'])
        ->assertStatus(409)->assertJsonPath('code', 'LAST_TASK_TAG');
});

it('refuses tag changes without org.task_tags.manage', function () {
    Sanctum::actingAs(demoUser('sinyee'));

    $this->postJson('/api/v1/task-tags', ['code' => 'Q'])->assertForbidden();
});
