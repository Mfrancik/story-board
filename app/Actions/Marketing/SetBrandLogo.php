<?php

namespace App\Actions\Marketing;

use App\Exceptions\MarketingLogoException;
use App\Models\MarketingBrand;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Sanitises SVG text and stores it as a project's brand logo with where it came from (MK-2). The one way a logo
 * reaches `marketing_brands.logo_svg`: from the repo (ReadLogoFromRepo) or an uploaded file, both pass through here.
 */
class SetBrandLogo
{
    /**
     * Create the action.
     */
    public function __construct(private readonly SanitiseSvg $sanitise, private readonly SaveBrand $save) {}

    /**
     * Store `$raw`, sanitised, as `$project`'s logo; `$source` is the read-only line Settings shows under it.
     *
     * Side effects: writes the brand (via SaveBrand, which logs `marketing.brand.saved`); logs
     * `marketing.brand.logo_sanitised` when anything was stripped, never the SVG itself.
     *
     * @throws MarketingLogoException when there is no usable SVG; logged as `marketing.brand.logo_refused`.
     */
    public function handle(Project $project, string $raw, string $source): MarketingBrand
    {
        try {
            $clean = $this->sanitise->handle($raw);
        } catch (MarketingLogoException $e) {
            Log::warning('marketing.brand.logo_refused', ['project' => $project->name, 'reason' => $e->reason, 'source' => $source]);

            throw $e;
        }

        if ($clean['removed'] > 0) {
            Log::info('marketing.brand.logo_sanitised', [
                'project' => $project->name, 'removed' => $clean['removed'], 'xmlns_added' => $clean['xmlns_added'], 'source' => $source,
            ]);
        }

        return $this->save->handle($project, ['logo_svg' => $clean['svg'], 'logo_source' => $source]);
    }
}
