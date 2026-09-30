<?php

use App\Actions\Board\ReadJourneyShots;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\Support\GitFixture;
use Tests\Support\JourneyShots;

/**
 * SB-22: `journeyStep()` saves a desktop and a 375 px PNG per journey step
 * plus a manifest the board's ReadJourneyShots reads. Walks two throwaway
 * routes registered here (never in routes/), into a throwaway git repo that
 * plays the project, so the real `storage/app/journey-shots/` is untouched
 * except by the gitignore check, which cleans up after itself.
 */
beforeEach(function () {
    Queue::fake();
    $this->was = getenv('JOURNEY_SHOTS');
    putenv('JOURNEY_SHOTS=1');

    $this->repo = new GitFixture;
    $this->repo->write('docs/journeys/sign-up.md', "# Journey — Sign up\n\n## Flow (plain steps)\n1. Verify your email at `/verify`. (FX-7)\n2. Land on the welcome page. (FX-8)\n")
        ->commitAndPush();
    JourneyShots::$project = $this->repo->author;
    JourneyShots::newRun();
    $this->shots = $this->repo->author.'/storage/app/journey-shots';

    Route::get('/verify', fn () => '<html><body><h1>Verify your email</h1></body></html>');
    Route::get('/welcome', fn () => '<html><body><h1>Welcome aboard</h1></body></html>');
});

afterEach(function () {
    $this->was === false ? putenv('JOURNEY_SHOTS') : putenv('JOURNEY_SHOTS='.$this->was);
    JourneyShots::$project = null;
    JourneyShots::newRun();
    File::deleteDirectory(base_path(JourneyShots::FOLDER.'/sb22-gitignore-probe'));
    $this->repo->destroy();
});

/** The manifest a capture wrote for `$journey`, decoded. */
function journeyManifest(string $shots, string $journey): array
{
    return json_decode((string) file_get_contents("{$shots}/{$journey}/manifest.json"), true);
}

it('Given JOURNEY_SHOTS=1, when a journey test calls journeyStep(sign-up, verify, /verify), then a PNG exists at journey-shots/sign-up/NN-verify.png and the manifest lists it with route, commit and time', function () {
    journeyStep('sign-up', 'verify', '/verify')->assertSee('Verify your email');

    expect("{$this->shots}/sign-up/01-verify.png")->toBeFile()
        ->and(getimagesize("{$this->shots}/sign-up/01-verify.png")['mime'])->toBe('image/png');

    $head = new Process(['git', 'rev-parse', '--short', 'HEAD'], $this->repo->author);
    $head->run();
    $entry = collect(journeyManifest($this->shots, 'sign-up'))->firstWhere('file', '01-verify.png');
    expect($entry)->toMatchArray(['journey' => 'sign-up', 'step' => 'verify', 'route' => '/verify', 'story' => 'FX-7', 'commit' => trim($head->getOutput())])
        ->and(now()->diffInSeconds(Carbon\Carbon::parse($entry['captured_at']), true))->toBeLessThan(120);
});

it('Given a captured step, then the manifest has two entries for it — desktop and 375 px — each with file set to the PNG bare name and width set to its capture width', function () {
    journeyStep('sign-up', 'verify', '/verify');

    $entries = journeyManifest($this->shots, 'sign-up');
    expect($entries)->toHaveCount(2)
        ->and(array_column($entries, 'file'))->toBe(['01-verify.png', '01-verify-375.png'])
        ->and(array_column($entries, 'width'))->toBe([1280, 375]);
    foreach ($entries as $entry) {
        // The PNG really is as wide as the manifest says (scale "css": one image px per CSS px).
        expect(getimagesize("{$this->shots}/sign-up/{$entry['file']}")[0])->toBe($entry['width']);
    }
});

it('Given the manifest a real capture wrote, when ReadJourneyShots reads it, then every shot is found and the 375 px one is classed as the phone shot', function () {
    journeyStep('sign-up', 'verify', '/verify');
    journeyStep('sign-up', 'welcome', '/welcome');

    // Put each step's phone entry first: the reader must still list desktop first, which only
    // happens when it classes the 375 px entry as the phone shot. The entries are the capture's own.
    $manifest = "{$this->shots}/sign-up/manifest.json";
    file_put_contents($manifest, json_encode(array_reverse(journeyManifest($this->shots, 'sign-up'))));

    $project = Project::factory()->create(['name' => 'fx', 'path' => $this->repo->author]);
    $reader = new ReadJourneyShots;
    $shots = $reader->handle($project);

    expect(array_keys($shots))->toBe(['sign-up'])
        ->and(array_column($shots['sign-up'], 'file'))->toEqualCanonicalizing(['01-verify.png', '01-verify-375.png', '02-welcome.png', '02-welcome-375.png'])
        ->and(array_column(array_slice($shots['sign-up'], 0, 2), 'file'))->toEqualCanonicalizing(['01-verify.png', '02-welcome.png'])
        ->and($reader->forStep($shots, 'sign-up', 'FX-7', null)['file'])->toBe('01-verify.png')
        ->and($reader->forStep($shots, 'sign-up', null, '/welcome')['file'])->toBe('02-welcome.png')
        ->and($reader->file($project, 'sign-up', '02-welcome-375.png'))->toBe(realpath("{$this->shots}/sign-up/02-welcome-375.png"));
});

it('Given JOURNEY_SHOTS unset, then no file is written and the test runs as before', function () {
    putenv('JOURNEY_SHOTS');

    journeyStep('sign-up', 'verify', '/verify')->assertSee('Verify your email');
    $page = visit('/welcome');
    journeyStep('sign-up', 'welcome', '/welcome', $page)->assertSee('Welcome aboard');

    expect($this->shots)->not->toBeDirectory()
        ->and(is_dir(base_path('tests/Browser/Screenshots')) ? glob(base_path('tests/Browser/Screenshots/journey-shot-*')) : [])->toBe([]);
});

it('Given a second run, then the journey shots and manifest entries are replaced, not appended twice', function () {
    journeyStep('sign-up', 'verify', '/verify');
    journeyStep('sign-up', 'welcome', '/welcome');

    JourneyShots::newRun();
    journeyStep('sign-up', 'verify', '/verify');

    expect(array_column(journeyManifest($this->shots, 'sign-up'), 'file'))->toBe(['01-verify.png', '01-verify-375.png'])
        ->and(array_map('basename', glob("{$this->shots}/sign-up/*")))->toEqualCanonicalizing(['01-verify.png', '01-verify-375.png', 'manifest.json']);
});

it('Given a step name with spaces or slashes, then the file name is slugged and stays inside the journey folder', function () {
    journeyStep('sign-up', 'Pick a / plan\\..\\x', '/verify');
    journeyStep('../../escape', '../verify', '/verify');

    expect("{$this->shots}/sign-up/01-pick-a-plan-x.png")->toBeFile()
        ->and(collect(journeyManifest($this->shots, 'sign-up'))->pluck('step')->unique()->all())->toBe(['Pick a / plan\\..\\x'])
        ->and("{$this->shots}/escape/01-verify.png")->toBeFile();

    // Nothing was written anywhere but inside journey-shots/.
    $outside = array_filter(File::allFiles($this->repo->author.'/storage'), fn ($f) => ! str_starts_with($f->getRealPath(), realpath($this->shots).'/'));
    expect($outside)->toBe([]);
});

it('refuses a journey or step name with no letters or digits, before writing anything', function () {
    expect(fn () => journeyStep('sign-up', ' / ', '/verify'))->toThrow(InvalidArgumentException::class, 'has no letters or digits')
        ->and(fn () => journeyStep('..', 'verify', '/verify'))->toThrow(InvalidArgumentException::class);

    expect($this->shots)->not->toBeDirectory();
});

it('Given the shots folder, then git status shows nothing new (gitignored)', function () {
    // The real project folder this time: that is the one the gitignore has to cover.
    JourneyShots::$project = null;
    journeyStep('sb22-gitignore-probe', 'verify', '/verify');
    expect(base_path(JourneyShots::FOLDER.'/sb22-gitignore-probe/01-verify.png'))->toBeFile();

    $status = new Process(['git', 'status', '--porcelain', '--untracked-files=all', '--', JourneyShots::FOLDER, 'tests/Browser/Screenshots'], base_path());
    $status->mustRun();
    $ignored = new Process(['git', 'check-ignore', '-q', JourneyShots::FOLDER.'/sb22-gitignore-probe/manifest.json'], base_path());
    $ignored->run();

    expect($status->getOutput())->toBe('')
        ->and($ignored->getExitCode())->toBe(0);
});
