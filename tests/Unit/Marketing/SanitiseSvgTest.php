<?php

use App\Actions\Marketing\SanitiseSvg;
use App\Exceptions\MarketingLogoException;

/**
 * MK-2: SanitiseSvg keeps a logo's drawing and drops anything that could run, load from elsewhere or embed HTML,
 * and always returns standalone SVG with an xmlns.
 */
it('adds an xmlns and keeps the drawing of a clean SVG untouched', function () {
    $out = (new SanitiseSvg)->handle('<svg viewBox="0 0 10 10" fill="currentColor"><path d="M0 0 L 5 5"/></svg>');

    expect($out['svg'])->toBe('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" fill="currentColor"><path d="M0 0 L 5 5"/></svg>')
        ->and($out['removed'])->toBe(0)
        ->and($out['xmlns_added'])->toBeTrue();
});

it('lifts the SVG out of a Blade component and drops Blade echoes, giving bare attributes a value', function () {
    $out = (new SanitiseSvg)->handle("{{-- mark --}}\n<svg data-mark viewBox=\"0 0 1 1\" {{ \$attributes->class(['a']) }}><path d=\"M 1 1\"/></svg>\n");

    expect($out['svg'])->toBe('<svg xmlns="http://www.w3.org/2000/svg" data-mark="" viewBox="0 0 1 1"><path d="M 1 1"/></svg>');
});

it('strips scripts, handlers, external references, foreignObject and href animations', function () {
    $raw = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="x()">'
        .'<script>alert(1)</script><SCRIPT>alert(2)</SCRIPT>'
        .'<image xlink:href="https://evil.test/a.png"/><use href="#ok"/><a href="javascript:alert(3)"><path d="M0 0"/></a>'
        .'<foreignObject><iframe src="https://evil.test"/></foreignObject>'
        .'<set attributeName="href" to="javascript:alert(4)"/>'
        .'<rect style="fill:url(https://evil.test/x)" onclick="y()"/><rect style="fill:url(#g)"/>'
        .'<style>@import url(https://evil.test/a.css);</style></svg>';

    $out = (new SanitiseSvg)->handle($raw);

    expect($out['svg'])->not->toContain('script')->not->toContain('SCRIPT')->not->toContain('evil.test')
        ->not->toContain('javascript')->not->toContain('onload')->not->toContain('onclick')
        ->not->toContain('foreignObject')->not->toContain('<set')->not->toContain('@import')
        ->toContain('<use href="#ok"/>')->toContain('<path d="M0 0"/>')->toContain('style="fill:url(#g)"')
        ->and($out['removed'])->toBeGreaterThanOrEqual(10)
        ->and($out['xmlns_added'])->toBeFalse();
});

it('never expands entities from a doctype', function () {
    $raw = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg><text>&x;</text></svg>';

    expect(fn () => (new SanitiseSvg)->handle($raw))->toThrow(MarketingLogoException::class);
});

it('refuses text with no SVG in it, and malformed SVG', function (string $raw) {
    expect(fn () => (new SanitiseSvg)->handle($raw))->toThrow(MarketingLogoException::class);
})->with(['plain text' => 'just words', 'unclosed' => '<svg><path></svg>', 'empty' => '']);
