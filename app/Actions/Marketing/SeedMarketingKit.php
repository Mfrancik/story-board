<?php

namespace App\Actions\Marketing;

use App\Exceptions\MarketingKitException;
use App\Exceptions\MarketingLogoException;
use App\Models\MarketingAsset;
use App\Models\MarketingCampaign;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Seeds a project's marketing from the StackerMint Facebook kit (MK-2, `marketing:seed-kit`): the brand, its copy
 * rules, the kit's idea menu and the prototype's five starter campaigns, plus optionally the logo from the repo.
 *
 * Idempotent by "create what is missing, never overwrite": a brand that exists keeps its values and rules (the owner
 * may have edited them), and a campaign whose slug exists is left as it is. Only the idea menu is always re-read,
 * because the kit owns it and Settings cannot edit it.
 *
 * MK-18: the kit's post and cover images (`posts/`, `covers/`) become inspiration — reference ads, never posts —
 * with their kit captions as the notes, so no image is sent to Claude. An image is added once, keyed by its path in
 * the kit (`ai_notes.kit_file`).
 */
class SeedMarketingKit
{
    /** The kit's text file: bios, captions per post, and the idea menu. */
    public const CAPTIONS_FILE = 'captions-and-bios.txt';

    /** The kit folders whose images become inspiration (MK-18): the 15 posts and 3 covers of the original kit. */
    public const INSPIRATION_DIRS = ['posts', 'covers'];

    /** StackerMint's look, from the brief and the coins app (tan, ink, Instrument Sans, 5 px corners, soft tan halo). */
    public const BRAND = [
        'name' => 'StackerMint', 'accent' => '#B79F82', 'ink' => '#222222', 'surface' => '#FAFAFA',
        'font' => 'Instrument Sans', 'radius' => 5, 'shadow' => 'halo',
    ];

    /** The brief's voice (stories/marketing/README.md §Copy rules), for MK-8's prompt. */
    public const VOICE = "Short and human, one or two lines.\nSets are the centrepiece.\n"
        .'A comparison pairs coins of genuinely similar value and says where the price came from.';

    /** Phrases MK-8's lint warns on: filler the owner never wants, and claims the app cannot back yet. */
    public const COPY_RULES = [
        'filler' => ['tell us below', 'we read every one'],
        'banned' => ['cert lookup', 'certificate lookup', 'look up your cert'],
    ];

    /**
     * The prototype's starter campaigns (docs/mockups/MK-prototype seed()), days as ISO weekdays. `ideas` are
     * "Category|Title" picks from the kit's idea menu; picks the kit's menu lacks are left out.
     *
     * @var list<array{name: string, days: list<int>, time: string, on: bool, photo: string, brief: string, ideas: list<string>}>
     */
    public const CAMPAIGNS = [
        ['name' => 'Coin of the week', 'days' => [2], 'time' => '10:00', 'on' => true, 'photo' => MarketingCampaign::PHOTO_FAVOURITES,
            'brief' => 'One coin, one fact worth knowing. Ask what people notice first.',
            'ideas' => ['Show & tell|Coin of the week', 'Did you know|Key dates', 'Did you know|Mint marks']],
        ['name' => 'TrueView Tuesday', 'days' => [2], 'time' => '19:00', 'on' => true, 'photo' => MarketingCampaign::PHOTO_PER_POST,
            'brief' => 'Show off the toning. One line, then ask people to share their best toner.',
            'ideas' => ['Toned & TrueView|TrueView Tuesday', 'Toned & TrueView|Toning 101', 'Toned & TrueView|Album toning', 'Toned & TrueView|Rate the rainbow']],
        ['name' => 'Set spotlight', 'days' => [5], 'time' => '18:00', 'on' => true, 'photo' => MarketingCampaign::PHOTO_FAVOURITES,
            'brief' => 'Sets are the centrepiece. Name the set and how far along it is.',
            'ideas' => ['Sets|Slot filled', 'Sets|One slot left', 'Sets|Set complete', 'Sets|Key date spotlight', 'Sets|Type set 101']],
        ['name' => 'Ask the community', 'days' => [7], 'time' => '12:00', 'on' => true, 'photo' => MarketingCampaign::PHOTO_AI_DECIDES,
            'brief' => 'One short question people can answer in a word.',
            'ideas' => ['Ask the community|Rarity or condition?', 'Ask the community|Grade it', 'Ask the community|Dream coin', 'Ask the community|Storage setup', 'Ask the community|The one that got away']],
        ['name' => 'Did you know', 'days' => [4], 'time' => '12:00', 'on' => false, 'photo' => MarketingCampaign::PHOTO_TEXT_ONLY,
            'brief' => 'One plain fact, no jargon.',
            'ideas' => ['Did you know|What "BN, RB, RD" means', 'Did you know|Mint marks', 'Did you know|Proof vs. business strike', 'Did you know|What CAC means']],
    ];

    /**
     * Create the action.
     */
    public function __construct(private readonly SaveBrand $save, private readonly ReadLogoFromRepo $readLogo, private readonly StoreMarketingAsset $store) {}

    /**
     * Seed `$projectName` from the kit folder at `$kitPath`; with `$logoPath`, also read the logo from the project's
     * repo at `$logoRef`. A logo that cannot be read does not undo the rest: it is reported in `logo`.
     *
     * Side effects: writes `marketing_brands` and `marketing_campaigns` in one transaction, then the kit's images as
     * inspiration (files and `marketing_assets` rows), then the logo; logs `marketing.kit_seed.succeeded` with the
     * counts, or `marketing.kit_seed.failed` with the reason.
     *
     * @return array{brand_created: bool, campaigns_created: int, campaigns_kept: int, inspiration_created: int, inspiration_kept: int, categories: int, ideas: int, logo: string}
     *
     * @throws MarketingKitException for an unknown project, a missing kit folder or captions file; nothing is written.
     */
    public function handle(string $projectName, string $kitPath, ?string $logoPath = null, string $logoRef = 'origin/main'): array
    {
        $project = Project::where('name', $projectName)->first();
        if ($project === null) {
            $this->fail($projectName, 'unknown_project', "No project named \"{$projectName}\". Add it on /projects first.");
        }
        $kitPath = rtrim($kitPath, '/');
        if (! is_dir($kitPath)) {
            $this->fail($projectName, 'missing_kit', "No marketing kit at {$kitPath}: that folder does not exist.");
        }
        $captions = $kitPath.'/'.self::CAPTIONS_FILE;
        if (! is_file($captions) || ! is_readable($captions)) {
            $this->fail($projectName, 'missing_captions', "The kit at {$kitPath} has no ".self::CAPTIONS_FILE.'.');
        }

        $captionsText = (string) file_get_contents($captions);
        $menu = $this->ideaMenu($captionsText);

        $result = DB::transaction(function () use ($project, $menu) {
            $brandCreated = ! $project->marketingBrand()->exists();
            $this->save->handle($project, $brandCreated
                ? ['values' => self::BRAND, 'voice' => self::VOICE, 'copy_rules' => self::COPY_RULES, 'idea_menu' => $menu]
                : ['idea_menu' => $menu]);

            $created = 0;
            foreach (self::CAMPAIGNS as $campaign) {
                $created += $this->campaign($project, $campaign, $menu) ? 1 : 0;
            }

            return ['brand_created' => $brandCreated, 'campaigns_created' => $created, 'campaigns_kept' => count(self::CAMPAIGNS) - $created];
        });

        $result += $this->inspiration($project, $kitPath, $this->kitCaptions($captionsText));

        $logo = 'skipped';
        if ($logoPath !== null && $logoPath !== '') {
            try {
                $this->readLogo->handle($project, $logoPath, $logoRef);
                $logo = 'stored';
            } catch (MarketingLogoException $e) {
                // ReadLogoFromRepo has logged why; the brand and campaigns stand without it.
                $logo = 'failed: '.$e->getMessage();
            }
        }

        $result += ['categories' => count($menu), 'ideas' => array_sum(array_map(fn (array $c) => count($c['ideas']), $menu)), 'logo' => $logo];
        Log::info('marketing.kit_seed.succeeded', ['project' => $project->name, ...$result, 'logo' => strtok($logo, ':')]);

        return $result;
    }

    /**
     * The kit's idea menu, from the captions file's "== IDEA MENU ==" section: a heading line per category, then
     * "- Title: text" lines. A list in the kit's order, not a category-keyed map, because MySQL's JSON type sorts an
     * object's keys and the owner's menu order would be lost. An empty list when the file has no menu.
     *
     * @return list<array{category: string, ideas: list<array{title: string, text: string}>}>
     */
    public function ideaMenu(string $captions): array
    {
        $menu = [];
        $inMenu = false;
        $current = null;
        foreach (preg_split('/\R/u', $captions) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^==\s*(.+?)\s*==$/u', $line, $m)) {
                // The menu runs from its own heading to the next "== … ==" heading or the end of the file.
                $inMenu = strtoupper($m[1]) === 'IDEA MENU';

                continue;
            }
            if (! $inMenu || $line === '') {
                continue;
            }
            if (! str_starts_with($line, '- ')) {
                if ($current !== null) {
                    $menu[] = $current;
                }
                $current = ['category' => $line, 'ideas' => []];

                continue;
            }
            if ($current === null) {
                continue;
            }
            // "Coin of the week: Coin of the week: [coin]…": the title ends at the first ": ".
            $idea = substr($line, 2);
            [$title, $text] = str_contains($idea, ': ') ? explode(': ', $idea, 2) : [$idea, $idea];
            $current['ideas'][] = ['title' => trim($title), 'text' => trim($text)];
        }
        if ($current !== null) {
            $menu[] = $current;
        }

        return $menu;
    }

    /**
     * The kit's captions per image, from the "--- posts/01-post-welcome.png (post, sets) ---" sections: the caption text
     * (to the next section or "== … ==" heading) and the tags in brackets, keyed by the image's path in the kit.
     *
     * @return array<string, array{caption: string, tags: list<string>}>
     */
    public function kitCaptions(string $captions): array
    {
        $out = [];
        $current = null;
        foreach (preg_split('/\R/u', $captions) ?: [] as $line) {
            if (preg_match('/^---\s*(\S+?)\s*(?:\(([^)]*)\))?\s*---$/u', trim($line), $m)) {
                $current = $m[1];
                $out[$current] = ['caption' => '', 'tags' => array_values(array_filter(array_map('trim', explode(',', $m[2] ?? ''))))];

                continue;
            }
            if (preg_match('/^==.*==$/u', trim($line))) {
                $current = null;

                continue;
            }
            if ($current !== null) {
                $out[$current]['caption'] .= $line."\n";
            }
        }

        return array_map(fn (array $c) => ['caption' => trim($c['caption']), 'tags' => $c['tags']], $out);
    }

    /**
     * Add each image in the kit's INSPIRATION_DIRS that the project does not have yet as inspiration, its kit caption
     * as the notes: headline (the caption's first line, or a title made from the file name), caption, and the topic
     * from its tags ("toned", "sets"). Already added images are kept as they are.
     *
     * @param  array<string, array{caption: string, tags: list<string>}>  $captions
     * @return array{inspiration_created: int, inspiration_kept: int}
     */
    private function inspiration(Project $project, string $kitPath, array $captions): array
    {
        $have = $project->marketingAssets()->where('purpose', MarketingAsset::PURPOSE_INSPIRATION)->pluck('ai_notes')
            ->map(fn ($notes) => is_array($notes) ? ($notes['kit_file'] ?? null) : null)->filter()->flip();

        $created = 0;
        $kept = 0;
        foreach (self::INSPIRATION_DIRS as $dir) {
            foreach (glob("{$kitPath}/{$dir}/*") ?: [] as $file) {
                $mime = (string) mime_content_type($file);
                if (! in_array($mime, MarketingAsset::PHOTO_MIMES, true)) {
                    continue;
                }
                $key = $dir.'/'.basename($file);
                if ($have->has($key)) {
                    $kept++;

                    continue;
                }

                $kit = $captions[$key] ?? ['caption' => '', 'tags' => []];
                $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $kit['caption']) ?: []), fn (string $l) => $l !== ''));
                // "cover-1-welcome-home.png" → "Welcome home"; "14-ad-invite.png" → "Invite".
                $title = Str::ucfirst(str_replace('-', ' ', (string) preg_replace('/^(cover-\d+-|\d+[a-z]?-(post|ad)-)/', '', pathinfo($file, PATHINFO_FILENAME))));
                $topic = array_values(array_diff($kit['tags'], ['post', 'ad']))[0] ?? null;

                $asset = $this->store->handle($project, new UploadedFile($file, basename($file), $mime, test: true), MarketingAsset::PURPOSE_INSPIRATION, favourite: false);
                $asset->update(['ai_notes' => [
                    'status' => MarketingAsset::READ_DONE, 'source' => 'kit', 'kit_file' => $key,
                    'headline' => $lines[0] ?? $title, 'caption' => $kit['caption'], 'style' => [], 'topic' => $topic,
                ]]);
                $created++;
            }
        }

        return ['inspiration_created' => $created, 'inspiration_kept' => $kept];
    }

    /**
     * Create one starter campaign unless the project already has its slug. Returns whether it was created.
     *
     * @param  array{name: string, days: list<int>, time: string, on: bool, photo: string, brief: string, ideas: list<string>}  $c
     * @param  list<array{category: string, ideas: list<array{title: string, text: string}>}>  $menu
     */
    private function campaign(Project $project, array $c, array $menu): bool
    {
        $slug = Str::slug($c['name']);
        if ($project->marketingCampaigns()->where('slug', $slug)->exists()) {
            return false;
        }

        $available = collect($menu)->flatMap(fn (array $c) => array_map(fn (array $i) => $c['category'].'|'.$i['title'], $c['ideas']))->all();
        $project->marketingCampaigns()->create([
            'name' => $c['name'], 'slug' => $slug, 'days' => $c['days'], 'time' => $c['time'].':00',
            // The owner's own timezone, so "Tue 19:00" is 19:00 where the owner posts from.
            'timezone' => config('board.timezone'),
            'is_enabled' => $c['on'], 'brief' => $c['brief'], 'photo_mode' => $c['photo'],
            'topics' => ['ideas' => array_values(array_intersect($c['ideas'], $available)), 'own' => []],
        ]);

        return true;
    }

    /**
     * Log why the seed was refused and throw the owner's message. Nothing has been written yet.
     *
     * @throws MarketingKitException always.
     */
    private function fail(string $project, string $reason, string $message): never
    {
        Log::warning('marketing.kit_seed.failed', ['project' => $project, 'reason' => $reason]);

        throw new MarketingKitException($message, $reason);
    }
}
