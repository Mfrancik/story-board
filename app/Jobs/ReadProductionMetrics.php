<?php

namespace App\Jobs;

use App\Actions\Board\ReadProductionProject;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads one project's production metrics on the queue (SB-18, the refresh
 * pattern of ADR-006): the Production page queues one per connected project, so
 * projects are read in parallel by the worker and one slow or unreachable
 * database never holds up the page or the others. Unique per project: a burst of
 * page loads or Refresh presses queues one read, not one each.
 */
class ReadProductionMetrics implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Long enough to cover a read — a 5 s connect plus up to 5 s per metric — so no
     * second copy queues while one could still be running, short enough that a lock
     * left by a crashed worker does not block Refresh for long.
     */
    public int $uniqueFor = 120;

    /** The request that asked for this read, so its log lines trace back to it. */
    public ?string $requestId;

    /**
     * @param  Project  $project  the project whose production database to read
     */
    public function __construct(public Project $project)
    {
        $this->requestId = Context::get('request_id');
    }

    /**
     * One queued read per project at a time.
     */
    public function uniqueId(): string
    {
        return (string) $this->project->id;
    }

    /**
     * Run the read. ReadProductionProject logs every expected outcome itself; an
     * unexpected error (the board's own database) is logged here and rethrown.
     * Logged in handle() rather than failed() so the line carries the restored
     * request_id, as RefreshProjectJob does.
     */
    public function handle(ReadProductionProject $read): void
    {
        // On a worker the originating request's Context is gone; restore its ID for this job's logs.
        if ($this->requestId !== null) {
            Context::add('request_id', $this->requestId);
        }

        try {
            $read->handle($this->project);
        } catch (Throwable $e) {
            // The class only: a PDO message from a production read could quote production data.
            Log::error('board.prod_read_crashed', ['project' => $this->project->name, 'exception' => $e::class]);

            throw $e;
        }
    }
}
