<?php

use App\Actions\SyncTeamCatalogue;
use App\Enums\AttachmentExtraction;
use App\Enums\TaskRunStatus;
use App\Models\Skill;
use App\Models\TaskRun;
use App\Models\TaskRunAttachment;
use App\Models\User;
use App\Services\TaskPromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A lot of this catalogue's work needs the organization's own papers — last
 * year's 990, the budget, an export from the CRM. What matters most here is
 * not that a file uploads, but that a file the model could NOT read says so
 * loudly: a reviewer who believes the 990 was used will check the draft
 * against a document it never saw.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->team = $this->user->personalTeam();
    $this->skill = Skill::factory()->create(['slug' => 'nonprofit-test-task']);
    app(SyncTeamCatalogue::class)->handle($this->team);

    $this->url = '/'.$this->team->slug.'/tasks';
});

function fixtureFile(string $name): UploadedFile
{
    return new UploadedFile(base_path('tests/fixtures/'.$name), $name, null, null, true);
}

it('reads a csv and gives it to the model', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'notes' => 'Use our actual numbers.',
        'files' => [UploadedFile::fake()->createWithContent(
            'budget.csv',
            "Program,Budget\nHousing,412880\nOutreach,96000\n",
        )],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();

    expect($attachment->extraction)->toBe(AttachmentExtraction::Extracted)
        ->and($attachment->text)->toContain('Housing,412880')
        ->and($attachment->original_name)->toBe('budget.csv');

    // The words have to reach the prompt, after the cache breakpoint.
    $content = app(TaskPromptBuilder::class)->userContent($attachment->taskRun);

    expect($content)->toContain('## Documents provided')
        ->toContain('budget.csv')
        ->toContain('Housing,412880');
});

it('reads the text out of a pdf', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [fixtureFile('form990.pdf')],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();

    expect($attachment->extraction)->toBe(AttachmentExtraction::Extracted)
        ->and($attachment->text)->toContain('Return of Organization Exempt From Income Tax');
})->skip(
    fn () => blank(shell_exec('command -v pdftotext')),
    'pdftotext is not installed on this host.',
);

it('reads the text out of a word document', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [fixtureFile('budget.docx')],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();

    expect($attachment->extraction)->toBe(AttachmentExtraction::Extracted)
        ->and($attachment->text)->toContain('Statement of Financial Position')
        ->and($attachment->text)->toContain('Total net assets: $412,880');
});

it('says so when a file has no text in it', function () {
    // What a scan or a photograph of a 990 amounts to.
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [UploadedFile::fake()->createWithContent('scan.txt', '   ')],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();

    expect($attachment->extraction)->toBe(AttachmentExtraction::Empty)
        ->and($attachment->extraction->reachedTheModel())->toBeFalse()
        ->and($attachment->extraction->explanation())->toContain('was not used');
});

it('keeps an unread file out of the prompt entirely', function () {
    $run = TaskRun::factory()->for($this->team)->for($this->skill)->create();

    TaskRunAttachment::create([
        'task_run_id' => $run->id,
        'team_id' => $this->team->id,
        'original_name' => 'scanned-990.pdf',
        'path' => 'attachments/x/y/scanned.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1000,
        'extraction' => AttachmentExtraction::Empty,
        'text' => null,
        'text_chars' => 0,
    ]);

    $content = app(TaskPromptBuilder::class)->userContent($run->refresh());

    expect($content)->not->toContain('Documents provided')
        ->and($content)->not->toContain('scanned-990.pdf');
});

it('caps how much of one document goes into a task', function () {
    config(['signoff.attachments.max_characters' => 500]);

    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [UploadedFile::fake()->createWithContent(
            'long.txt',
            str_repeat('Every line of the general ledger. ', 400),
        )],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();

    expect($attachment->truncated)->toBeTrue()
        ->and($attachment->text_chars)->toBe(500);

    expect(app(TaskPromptBuilder::class)->userContent($attachment->taskRun))
        ->toContain('it stops here');
});

it('turns away a kind of file it cannot read', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [UploadedFile::fake()->create('payload.php', 10)],
    ])->assertSessionHasErrors('files.0');

    expect(TaskRunAttachment::count())->toBe(0)
        ->and(TaskRun::count())->toBe(0);
});

it('turns away more files than a task may have', function () {
    config(['signoff.attachments.max_files' => 2]);

    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [
            UploadedFile::fake()->createWithContent('a.csv', 'a'),
            UploadedFile::fake()->createWithContent('b.csv', 'b'),
            UploadedFile::fake()->createWithContent('c.csv', 'c'),
        ],
    ])->assertSessionHasErrors('files');

    expect(TaskRun::count())->toBe(0);
});

it('stores the file under its organization, not under its name', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [UploadedFile::fake()->createWithContent('../../etc/passwd.csv', 'x,y')],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();

    expect($attachment->path)->toStartWith('attachments/'.$this->team->id.'/')
        ->and($attachment->path)->not->toContain('..')
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue();
});

it('hands a file back only to the organization that uploaded it', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [UploadedFile::fake()->createWithContent('budget.csv', 'Program,Budget')],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();
    $path = $this->url.'/'.$attachment->task_run_id.'/files/'.$attachment->id;

    $this->actingAs($this->user)->get($path)->assertOk()
        ->assertDownload('budget.csv');

    // Someone else's account, asking for the same file by the same URL. The
    // team middleware turns them away before the controller is reached.
    $stranger = User::factory()->create();
    $this->actingAs($stranger)->get($path)->assertForbidden();
});

it('will not hand over a file through another run of the same team', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [UploadedFile::fake()->createWithContent('budget.csv', 'Program,Budget')],
    ])->assertRedirect();

    $attachment = TaskRunAttachment::sole();
    $other = TaskRun::factory()->for($this->team)->for($this->skill)->create();

    // The file exists and the team is right, but it does not belong to this
    // run — an id swapped in the URL must not walk between runs.
    $this->actingAs($this->user)
        ->get($this->url.'/'.$other->id.'/files/'.$attachment->id)
        ->assertNotFound();
});

it('gives a second attempt the same papers as the first', function () {
    $this->actingAs($this->user)->post($this->url, [
        'skill' => $this->skill->slug,
        'files' => [UploadedFile::fake()->createWithContent('budget.csv', 'Program,Budget')],
    ])->assertRedirect();

    $first = TaskRun::sole();
    $first->forceFill(['status' => TaskRunStatus::Rejected])->save();

    $this->actingAs($this->user)
        ->post($this->url.'/'.$first->id.'/revise', ['notes' => 'Try again.'])
        ->assertRedirect();

    $second = TaskRun::where('revised_from_id', $first->id)->sole();

    expect($second->attachments)->toHaveCount(1)
        ->and($second->attachments->first()->text)->toContain('Program,Budget')
        ->and($second->attachments->first()->path)->not->toBe($first->attachments->first()->path);
});
