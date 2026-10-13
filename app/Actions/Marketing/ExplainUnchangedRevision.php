<?php

namespace App\Actions\Marketing;

use App\Models\MarketingAsset;
use App\Models\MarketingCampaign;
use App\Models\MarketingPost;

/**
 * Why a "What should change?" rebuild (ReviseDraft, MK-8) came back the same as the draft it rewrote, in words the
 * studio (MK-9) shows under the box. ReviseDraft only lets the model pick a photo when "Different photo" is chosen in
 * a campaign whose photos come from favourites (MarketingPrompts::revise), so a request for a photo that could not be
 * met is knowable from the campaign's photo mode, the chip and the project's favourites; anything else is the model
 * keeping the words. Reads only.
 */
class ExplainUnchangedRevision
{
    /** The asked-for photo cannot come from favourites: the project has none. */
    public const NO_FAVOURITES = 'no_favourites';

    /** The campaign posts the brand text card, so no photo is added. */
    public const TEXT_ONLY = 'text_only';

    /** The campaign's photos are the owner's to add; the model never picks one. */
    public const PER_POST = 'per_post';

    /** A photo was asked for in words, but only the "Different photo" chip lets the model pick one. */
    public const PHOTO_CHIP = 'photo_chip';

    /** The model was free to pick a photo and kept both the words and the photo. */
    public const KEPT_PHOTO = 'kept_photo';

    /** No photo was asked for; the model kept the words. */
    public const SAME_CAPTION = 'same_caption';

    /** Words in the owner's note that ask for a photo. */
    private const PHOTO_WORDS = '/\b(photo|image|picture|pic|pics)s?\b/i';

    /**
     * The reason and the studio's sentence for draft `$post`'s unchanged rebuild from `$feedback` and `$chips`.
     *
     * @param  list<string>  $chips
     * @return array{reason: string, message: string}
     */
    public function handle(MarketingPost $post, string $feedback, array $chips): array
    {
        $chip = in_array('Different photo', $chips, true);
        $mode = $post->campaign()->value('photo_mode');

        if (! $chip && preg_match(self::PHOTO_WORDS, $feedback) !== 1) {
            return $this->say(self::SAME_CAPTION, 'The AI kept the same words — try saying what to change in the caption.');
        }
        if ($mode === MarketingCampaign::PHOTO_TEXT_ONLY) {
            return $this->say(self::TEXT_ONLY, 'This campaign posts text only, so the AI won’t add a photo — upload one below to give this post one.');
        }
        // Checked before the per-post mode: with no favourites, uploading is the only way to a photo either way.
        if (! MarketingAsset::query()->where('project_id', $post->project_id)->favouritePhotos()->exists()) {
            return $this->say(self::NO_FAVOURITES, 'No favourite photos to pick from — upload one below or add favourites in Settings.');
        }
        if ($mode === MarketingCampaign::PHOTO_PER_POST || $mode === null) {
            return $this->say(self::PER_POST, 'The AI doesn’t pick photos for this campaign — use a favourite or upload one below.');
        }
        if (! $chip) {
            return $this->say(self::PHOTO_CHIP, 'The AI only picks a new photo when you choose “Different photo”.');
        }

        return $this->say(self::KEPT_PHOTO, 'The AI kept the same words and photo — try again, or pick a favourite below.');
    }

    /**
     * @return array{reason: string, message: string}
     */
    private function say(string $reason, string $message): array
    {
        return ['reason' => $reason, 'message' => $message];
    }
}
