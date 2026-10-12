<?php

namespace App\Console\Commands;

use App\Actions\Marketing\SeedMarketingKit;
use App\Exceptions\MarketingKitException;
use Illuminate\Console\Command;

/**
 * `marketing:seed-kit {project} {kitPath}` — seed a project's Marketing settings and starter campaigns from a
 * StackerMint-style kit folder (MK-2). Safe to run again: it only adds what is missing (see SeedMarketingKit).
 * Example: `php artisan marketing:seed-kit coins ~/Downloads/StackerMint-Facebook-Kit
 * --logo-path=resources/views/components/app-mark.blade.php`.
 */
class MarketingSeedKit extends Command
{
    /** @var string */
    protected $signature = 'marketing:seed-kit
        {project : The board project to seed, e.g. coins}
        {kitPath : The kit folder holding captions-and-bios.txt}
        {--logo-ref=origin/main : The ref to read the logo at}
        {--logo-path= : The logo file inside the project repo, e.g. resources/views/components/app-mark.blade.php}';

    /** @var string */
    protected $description = "Seed a project's marketing brand, copy rules, idea menu and starter campaigns from a kit folder";

    /**
     * Run the seed and print what it did. Fails, writing nothing, for an unknown project or a missing kit.
     *
     * Side effects: see SeedMarketingKit (writes the brand and campaigns, reads the logo through GitReader, logs).
     */
    public function handle(SeedMarketingKit $seed): int
    {
        try {
            $r = $seed->handle((string) $this->argument('project'), (string) $this->argument('kitPath'),
                $this->option('logo-path') ?: null, (string) ($this->option('logo-ref') ?: 'origin/main'));
        } catch (MarketingKitException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Brand '.($r['brand_created'] ? 'created' : 'already there: kept as it is, idea menu refreshed').'.');
        $this->info("Idea menu: {$r['ideas']} ideas in {$r['categories']} categories.");
        $this->info("Campaigns: {$r['campaigns_created']} created, {$r['campaigns_kept']} already there.");
        if (str_starts_with($r['logo'], 'failed')) {
            $this->warn('Logo not read — '.substr($r['logo'], strlen('failed: ')));
        } else {
            $this->info('Logo: '.$r['logo'].'.');
        }

        return self::SUCCESS;
    }
}
