<?php

namespace App\Actions\Board;

use App\Exceptions\StoryPickRefusedException;
use App\Models\Project;
use App\Models\Story;
use App\Services\StoryPickWriter;
use Illuminate\Support\Facades\Log;

/**
 * A pick from the board, from either page that offers one: the mockup viewer
 * (SB-21) and the story page (RD-3). It re-reads the set and its row on the
 * server — the browser can send any option for any story — and hands the write
 * to StoryPickWriter, whose guards decide; it only turns their refusal into a
 * sentence for the page.
 */
class PickMockup
{
    /**
     * @param  ReadMockupSets  $sets  the set as the board sees it now, and its local-pick cache
     * @param  StoryPickWriter  $writer  the one writer into a registered project (ADR-032)
     */
    public function __construct(private readonly ReadMockupSets $sets, private readonly StoryPickWriter $writer) {}

    /**
     * Record `$option` as `$storyId`'s pick with `$reason`.
     *
     * Side effects: through StoryPickWriter, one file write and one local commit
     * in the project (never pushed), or none when refused; logs
     * `board.mockup_pick_refused` when the story has no set, else the writer
     * logs. Always drops the project's local-pick cache, so a pick that landed
     * shows at once.
     *
     * @return array{branch: string|null, refusal: string|null} the branch committed on, or why nothing was written
     */
    public function handle(string $projectName, string $storyId, string $option, string $reason): array
    {
        $project = Project::where('name', $projectName)->first();
        $set = $project ? $this->sets->find($project, $storyId) : null;
        $row = $set ? Story::find($set['row']) : null;
        if ($project === null || $set === null || $row === null) {
            Log::warning('board.mockup_pick_refused', ['project' => $projectName, 'story' => $storyId, 'reason' => 'no mockup set']);

            return ['branch' => null, 'refusal' => 'This story has no mockup set on the board any more.'];
        }

        try {
            $picked = $this->writer->pick($project, $row, $set['options'], $option, $reason);
        } catch (StoryPickRefusedException $e) {
            return ['branch' => null, 'refusal' => 'Pick refused: '.$e->getMessage().'.'];
        } finally {
            // A refused pick changes nothing, but a commit that landed must show at once.
            $this->sets->forget($project);
        }

        return ['branch' => $picked['branch'], 'refusal' => null];
    }
}
