<?php

namespace App\Models;

use Database\Factories\MarketingBrandFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A project's marketing brand (MK-2): the fixed look and voice every draft uses. The AI is given these values and
 * can never vary them; likes and feedback (MK-8) shape content, never the brand. One per project.
 *
 * @property int $id
 * @property int $project_id
 * @property array{name: string|null, accent: string, ink: string, surface: string, font: string, radius: int, shadow: string} $values
 * @property string|null $logo_svg sanitised by SanitiseSvg before it is stored
 * @property string|null $logo_source
 * @property string|null $voice
 * @property array{filler: list<string>, banned: list<string>} $copy_rules
 * @property list<array{category: string, ideas: list<array{title: string, text: string}>}> $idea_menu the kit's menu, in its order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MarketingBrand extends Model
{
    /** @use HasFactory<MarketingBrandFactory> */
    use HasFactory;

    /**
     * What Settings shows, and the first save stores, for a project with no brand yet. Neutral on purpose: a
     * project's own look comes from the owner or its kit (StackerMint's from marketing:seed-kit). A null name
     * means "the project's name".
     */
    public const DEFAULTS = [
        'name' => null,
        'accent' => '#2563EB',
        'ink' => '#222222',
        'surface' => '#FAFAFA',
        'font' => 'Instrument Sans',
        'radius' => 8,
        'shadow' => 'soft',
    ];

    /** The shadows a brand may use on its cards, lightest first. */
    public const SHADOWS = ['none', 'soft', 'halo'];

    /** A colour as the brand stores it: #RGB or #RRGGBB (stored as upper-case #RRGGBB). */
    public const HEX_PATTERN = '/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/D';

    /** @var list<string> */
    protected $fillable = ['project_id', 'values', 'logo_svg', 'logo_source', 'voice', 'copy_rules', 'idea_menu'];

    /**
     * A new brand has empty copy rules and no idea menu, so both JSON columns always decode to arrays.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'copy_rules' => '{"filler":[],"banned":[]}',
        'idea_menu' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'values' => 'array',
            'copy_rules' => 'array',
            'idea_menu' => 'array',
        ];
    }

    /**
     * `#abc` or `#aabbcc` as `#AABBCC`, the one form the brand stores, so the same colour never reads as two.
     */
    public static function normaliseHex(string $hex): string
    {
        $digits = ltrim(trim($hex), '#');
        if (strlen($digits) === 3) {
            $digits = $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        return '#'.strtoupper($digits);
    }

    /**
     * The project this brand belongs to.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
