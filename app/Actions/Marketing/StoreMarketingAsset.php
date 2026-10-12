<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores an uploaded image as one of a project's marketing assets (MK-2): the bytes on the `local` disk under
 * `marketing/{project}/{purpose}s/<uuid>.<ext>`, the row with its size and original name. The caller has already
 * validated type and size (Settings' upload rules); the stored name is generated, never the uploaded one.
 */
class StoreMarketingAsset
{
    /** The extension each accepted image type is stored under. */
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Store `$file` for `$project`.
     *
     * Side effects: writes a file to the local disk and a `marketing_assets` row (the file is removed again if the
     * row cannot be written); logs `marketing.asset.stored` (project, asset id, purpose, mime, bytes).
     */
    public function handle(Project $project, UploadedFile $file, string $purpose = MarketingAsset::PURPOSE_PHOTO, bool $favourite = true): MarketingAsset
    {
        $mime = (string) $file->getMimeType();
        $size = @getimagesize((string) $file->getRealPath()) ?: [null, null];
        $path = $file->storeAs("marketing/{$project->name}/{$purpose}s", Str::uuid().'.'.(self::EXTENSIONS[$mime] ?? 'bin'), 'local');

        try {
            $asset = $project->marketingAssets()->create([
                'purpose' => $purpose,
                'is_favourite' => $favourite,
                'path' => $path,
                // Kept only as a label; a name from the owner's machine is never used to build a path.
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime' => $mime,
                'width' => $size[0],
                'height' => $size[1],
            ]);
        } catch (Throwable $e) {
            // No row means nothing can reach the file again: do not leave it on disk.
            Storage::disk('local')->delete((string) $path);

            throw $e;
        }

        Log::info('marketing.asset.stored', [
            'project' => $project->name, 'asset_id' => $asset->id, 'purpose' => $purpose, 'mime' => $mime, 'bytes' => $file->getSize(),
        ]);

        return $asset;
    }
}
