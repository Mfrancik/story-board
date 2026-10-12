<?php

namespace App\Actions\Marketing;

use App\Models\MarketingBrand;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Creates or updates a project's one marketing brand (MK-2): Settings' brand form and copy-rules form both save
 * through here, so create and edit are the same path. Input is already validated by the caller; colours are
 * stored in one form (#RRGGBB, upper case).
 */
class SaveBrand
{
    /**
     * Save the given parts of `$project`'s brand, creating the brand with defaults first if it has none.
     *
     * @param  array{values?: array<string, mixed>, voice?: string|null, copy_rules?: array{filler: list<string>, banned: list<string>}, idea_menu?: list<array{category: string, ideas: list<array{title: string, text: string}>}>}  $changes
     *
     * Side effects: writes `marketing_brands`; logs `marketing.brand.saved` (project, brand id, created, fields).
     */
    public function handle(Project $project, array $changes): MarketingBrand
    {
        $brand = $this->brandOf($project);
        $created = ! $brand->exists;

        if (isset($changes['values'])) {
            $values = $changes['values'] + $brand->values;
            foreach (['accent', 'ink', 'surface'] as $colour) {
                $values[$colour] = MarketingBrand::normaliseHex((string) $values[$colour]);
            }
            $values['radius'] = (int) $values['radius'];
            $changes['values'] = $values;
        }

        $brand->fill($changes)->save();

        Log::info('marketing.brand.saved', [
            'project' => $project->name, 'brand_id' => $brand->id, 'created' => $created, 'fields' => array_keys($changes),
        ]);

        return $brand;
    }

    /**
     * The project's brand, or a new unsaved one holding the defaults with the project's name as the brand name.
     */
    public function brandOf(Project $project): MarketingBrand
    {
        return $project->marketingBrand()->first()
            ?? new MarketingBrand(['project_id' => $project->id, 'values' => ['name' => $project->name] + MarketingBrand::DEFAULTS]);
    }
}
