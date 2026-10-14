#!/usr/bin/env node
// MK-5: renders ad HTML files to PNG with Playwright's Chromium, for App\Services\Marketing\AdRenderer.
//
//   node bin/render-ad.mjs <in.html> <out.png> <width> <height> [<in.html> <out.png> <width> <height> ...]
//
// One browser start renders every page given (a post's two sizes). Pages are opened over file:// and every request
// that is not a file: or data: URL is aborted, so an ad renders offline and can never fetch anything. On success it
// prints one JSON line, {"overflow":[bool, ...]}: per page, whether any of the ad's words (the headline .fit, the
// subline .sub, the cta .cta / .pill) are cut off: overflowing their own box, or drawn past the edge of any ancestor
// that clips (the ad itself included). The CSS shrink-to-fit should never allow either. On failure it prints one line
// to stderr and exits 1.
import { chromium } from 'playwright';
import { pathToFileURL } from 'node:url';

const args = process.argv.slice(2);
if (args.length === 0 || args.length % 4 !== 0) {
    console.error('usage: render-ad.mjs <in.html> <out.png> <width> <height> [...]');
    process.exit(1);
}

const jobs = [];
for (let i = 0; i < args.length; i += 4) {
    const [input, output, width, height] = args.slice(i, i + 4);
    const w = Number.parseInt(width, 10);
    const h = Number.parseInt(height, 10);
    if (!(w > 0 && w <= 4096 && h > 0 && h <= 4096)) {
        console.error(`bad size ${width}x${height}`);
        process.exit(1);
    }
    jobs.push({ input, output, w, h });
}

let browser;
try {
    browser = await chromium.launch({ headless: true });
    const overflow = [];
    for (const job of jobs) {
        const page = await browser.newPage({ viewport: { width: job.w, height: job.h }, deviceScaleFactor: 1 });
        // Offline by construction: only the page itself and data: URLs (embedded fonts and photos) may load.
        await page.route('**/*', (route) => {
            const url = route.request().url();
            return url.startsWith('file:') || url.startsWith('data:') ? route.continue() : route.abort();
        });
        await page.goto(pathToFileURL(job.input).href, { waitUntil: 'load' });
        await page.evaluate(() => document.fonts.ready);
        overflow.push(await page.evaluate(() => [...document.querySelectorAll('.fit, .sub, .cta, .pill')].some((el) => {
            // 1px of slack throughout, for sub-pixel rounding.
            if (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth + 1) {
                return true;
            }
            // A box that fits its own text can still be cut by a clipping ancestor: a line pushed below the ad's edge,
            // or out of the poster's number block.
            const box = el.getBoundingClientRect();
            for (let up = el.parentElement; up !== null; up = up.parentElement) {
                const style = getComputedStyle(up);
                if (style.overflowX === 'visible' && style.overflowY === 'visible') {
                    continue;
                }
                const clip = up.getBoundingClientRect();
                if (box.top < clip.top - 1 || box.left < clip.left - 1 || box.bottom > clip.bottom + 1 || box.right > clip.right + 1) {
                    return true;
                }
            }
            return false;
        })));
        await page.screenshot({ path: job.output, clip: { x: 0, y: 0, width: job.w, height: job.h }, type: 'png' });
        await page.close();
    }
    process.stdout.write(JSON.stringify({ overflow }) + '\n');
} catch (error) {
    console.error(String(error?.message ?? error).split('\n')[0]);
    process.exitCode = 1;
} finally {
    await browser?.close();
}
