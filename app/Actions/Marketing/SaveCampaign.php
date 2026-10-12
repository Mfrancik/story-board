<?php

namespace App\Actions\Marketing;

use App\Models\MarketingCampaign;
use App\Models\Project;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Creates or updates a project's campaign criteria (MK-7): name, schedule (days, time, timezone), topics, brief and
 * photo choice. The campaign page's one form saves through here in both modes, so create and edit are one path.
 * Input is already validated by the caller; this only normalises it.
 *
 * Saving never touches posts. A post belongs to a date by its local day (ADR-062), so moving a campaign from Tuesday
 * to Wednesday leaves an approved Tue 20 on Tue 20, and the open dates simply follow the new schedule.
 */
class SaveCampaign
{
    /** Slugs a campaign can never take: `new` is the create page's own address. */
    public const RESERVED_SLUGS = ['new'];

    /**
     * Save `$data` as a new campaign of `$project`, or onto `$campaign` when one is given.
     *
     * @param  array{name: string, days: list<int|string>, time: string, timezone: string, topics: array{ideas: list<string>, own: list<array{text: string, detail?: string|null}>}, brief: string|null, photo_mode: string}  $data
     *
     * Side effects: writes `marketing_campaigns`; logs `marketing.campaign.created` (project, campaign_id, slug) or
     * `marketing.campaign.updated` (project, campaign_id, changed: the columns that changed, possibly none).
     */
    public function handle(Project $project, ?MarketingCampaign $campaign, array $data): MarketingCampaign
    {
        $values = $this->normalise($data);

        if ($campaign === null) {
            // A new campaign starts on: the owner created it to post. Its slug is fixed now and kept through renames,
            // so a link to the campaign never breaks.
            $campaign = MarketingCampaign::create($values + [
                'project_id' => $project->id,
                'slug' => $this->freeSlug($project, $values['name']),
                'is_enabled' => true,
            ]);
            Log::info('marketing.campaign.created', ['project' => $project->name, 'campaign_id' => $campaign->id, 'slug' => $campaign->slug]);

            return $campaign;
        }

        // MySQL stores JSON objects with its own key order ("own" before "ideas"), and Eloquent compares JSON casts
        // strictly, so an untouched topics column would read as changed. Compare those columns key-order-blind.
        $unchanged = array_filter(['days', 'topics'], fn (string $json) => $this->canonical($values[$json]) === $this->canonical($campaign->{$json}));
        $campaign->fill(array_diff_key($values, array_flip($unchanged)));
        $changed = array_keys($campaign->getDirty());
        $campaign->save();
        Log::info('marketing.campaign.updated', ['project' => $project->name, 'campaign_id' => $campaign->id, 'changed' => $changed]);

        return $campaign;
    }

    /**
     * The stored form of the criteria: weekdays as sorted unique integers, time as HH:MM:SS, blank text as null and
     * own topics trimmed, blank ones dropped, in the owner's order.
     *
     * @param  array<string, mixed>  $data
     * @return array{name: string, days: list<int>, time: string, timezone: string, topics: array{ideas: list<string>, own: list<array{text: string, detail: string|null}>}, brief: string|null, photo_mode: string}
     */
    private function normalise(array $data): array
    {
        $days = array_values(array_unique(array_map('intval', $data['days'])));
        sort($days);
        $own = [];
        foreach ($data['topics']['own'] as $topic) {
            $text = trim((string) $topic['text']);
            if ($text !== '') {
                $detail = trim((string) ($topic['detail'] ?? ''));
                $own[] = ['text' => $text, 'detail' => $detail === '' ? null : $detail];
            }
        }
        $brief = trim((string) $data['brief']);

        return [
            'name' => trim($data['name']),
            'days' => $days,
            'time' => substr($data['time'], 0, 5).':00',
            'timezone' => $data['timezone'],
            'topics' => ['ideas' => array_values(array_unique($data['topics']['ideas'])), 'own' => $own],
            'brief' => $brief === '' ? null : $brief,
            'photo_mode' => $data['photo_mode'],
        ];
    }

    /**
     * `$value` with every string-keyed array's keys sorted, recursively, so two decodings of one JSON compare equal.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => $this->canonical($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The name's slug, or the first of `slug-2`, `slug-3`… the project has not used and is not reserved. Two names
     * can share a slug ("TrueView Tuesday!"), so a unique name alone does not make a unique slug.
     */
    private function freeSlug(Project $project, string $name): string
    {
        $base = Str::slug($name) ?: 'campaign';
        $taken = $project->marketingCampaigns()->where('slug', 'like', $base.'%')->pluck('slug')->merge(self::RESERVED_SLUGS)->all();
        $slug = $base;
        for ($n = 2; in_array($slug, $taken, true); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
