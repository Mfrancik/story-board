<?php

namespace App\Actions\Marketing;

use App\Exceptions\MarketingLogoException;
use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;

/**
 * Turns untrusted SVG text into a standalone logo that is safe to inline on a page (MK-2). It keeps the drawing and
 * drops anything that could run (scripts, `on*` handlers, `javascript:` values), load from elsewhere (non-fragment
 * `href`/`xlink:href`/`src`, external `url(…)`, `@import`) or embed other markup (`<foreignObject>`, iframes), and
 * always gives the root an `xmlns`. The SVG may sit inside a Blade component (coins' `app-mark.blade.php`): it is
 * lifted out and Blade echoes are dropped. Built on PHP's DOM; no dependency.
 */
class SanitiseSvg
{
    /** The SVG namespace every stored logo declares, so it renders as a standalone file too. */
    public const SVG_NS = 'http://www.w3.org/2000/svg';

    /** The XLink namespace, declared when a kept `xlink:href` fragment needs it. */
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /** A logo is a few KB; anything far larger is not a logo, and parsing it would only cost time. */
    public const MAX_BYTES = 512 * 1024;

    /** Elements that run code or embed non-SVG content: removed with everything inside them. */
    private const BANNED_ELEMENTS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'handler', 'listener', 'audio', 'video', 'canvas'];

    /** Animation elements: harmless unless they animate a link or a handler into existence. */
    private const ANIMATIONS = ['set', 'animate', 'animatetransform', 'animatemotion', 'animatecolor'];

    /**
     * Sanitise `$raw` and count what was removed.
     *
     * @return array{svg: string, removed: int, xmlns_added: bool} `removed` counts dropped elements, attributes and
     *                                                             processing instructions
     *
     * @throws MarketingLogoException when there is no well-formed `<svg>` root in `$raw`, or it is too large.
     */
    public function handle(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            throw new MarketingLogoException('That file is too large to be a logo (512 KB at most).', reason: 'too_big');
        }
        // Greedy to the last closing tag, so a nested <svg> stays inside the logo.
        if (! preg_match('/<svg\b.*<\/svg\s*>/is', $raw, $m)) {
            throw new MarketingLogoException('There is no SVG in that file.', reason: 'not_svg');
        }

        $doc = $this->parse($this->normalise($m[0]));
        $root = $doc->documentElement;
        if (! $root instanceof DOMElement || strtolower($root->localName ?? '') !== 'svg' || ! in_array($root->namespaceURI, [null, self::SVG_NS], true)) {
            throw new MarketingLogoException('There is no SVG in that file.', reason: 'not_svg');
        }

        $removed = $this->clean($root);

        // A Blade component's <svg> leans on the HTML page for its namespace; as a stored file it needs its own.
        $xmlnsAdded = $root->namespaceURI === null && ! $root->hasAttribute('xmlns');
        if ($xmlnsAdded) {
            $root->setAttribute('xmlns', self::SVG_NS);
        }
        $svg = (string) $doc->saveXML($root);
        if (str_contains($svg, 'xlink:') && ! str_contains($svg, 'xmlns:xlink')) {
            $root->setAttribute('xmlns:xlink', self::XLINK_NS);
            $svg = (string) $doc->saveXML($root);
        }

        return ['svg' => $svg, 'removed' => $removed, 'xmlns_added' => $xmlnsAdded];
    }

    /**
     * Make HTML-ish SVG parse as XML: drop Blade comments and echoes, and give a bare attribute (`data-brand-mark`)
     * an empty value. Quoted values are carried over as they are, so a path's spaces are never mistaken for names.
     */
    private function normalise(string $svg): string
    {
        $svg = (string) preg_replace('/\{\{--.*?--\}\}|\{!!.*?!!\}|\{\{.*?\}\}/s', '', $svg);

        return (string) preg_replace_callback('/<([A-Za-z][\w:.-]*)(\s[^<>]*?)?(\/?)>/s', function (array $tag): string {
            preg_match_all('/([^\s=\/>"\']+)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/', $tag[2], $attrs, PREG_SET_ORDER);
            $out = '<'.$tag[1];
            foreach ($attrs as $a) {
                $value = $a[2] ?? '';
                $quoted = $value === '' ? '""' : ($value[0] === '"' || $value[0] === "'" ? $value : '"'.str_replace('"', '&quot;', $value).'"');
                $out .= ' '.$a[1].'='.$quoted;
            }

            return $out.$tag[3].'>';
        }, $svg);
    }

    /**
     * Parse as XML without touching the network or expanding entities; a doctype is refused outright, since a logo
     * never needs one and it is how entity tricks get in.
     *
     * @throws MarketingLogoException when the text is not well-formed.
     */
    private function parse(string $svg): DOMDocument
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            // LIBXML_NONET: never fetch anything; no LIBXML_NOENT, so entities are never substituted.
            $ok = $doc->loadXML($svg, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $ok || $doc->doctype !== null) {
            throw new MarketingLogoException('That SVG could not be read: it is not well-formed.', reason: 'malformed');
        }

        return $doc;
    }

    /**
     * Strip `$el`'s unsafe attributes and descendants in place; returns how many things were removed.
     */
    private function clean(DOMElement $el): int
    {
        $removed = 0;
        /** @var list<DOMAttr> $attributes */
        $attributes = iterator_to_array($el->attributes ?? []);
        foreach ($attributes as $attr) {
            if ($this->unsafeAttribute($attr)) {
                $el->removeAttributeNode($attr);
                $removed++;
            }
        }

        // A snapshot: removing a child while walking the live list would skip its next sibling.
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($this->unsafeNode($child)) {
                $el->removeChild($child);
                $removed++;
            } elseif ($child instanceof DOMElement) {
                $removed += $this->clean($child);
            }
        }

        return $removed;
    }

    /**
     * Whether a child node goes: a banned element, an animation of a link or handler, a stylesheet that imports
     * or loads from elsewhere, or a processing instruction.
     */
    private function unsafeNode(DOMNode $node): bool
    {
        if ($node instanceof DOMProcessingInstruction) {
            return true;
        }
        if (! $node instanceof DOMElement) {
            return false;
        }

        $name = strtolower($node->localName ?? '');
        if (in_array($name, self::BANNED_ELEMENTS, true)) {
            return true;
        }
        // `<set attributeName="href" to="javascript:…">` writes a link the attribute pass never saw.
        if (in_array($name, self::ANIMATIONS, true)) {
            $target = strtolower($node->getAttribute('attributeName'));

            return str_starts_with($target, 'on') || $target === 'href' || $target === 'xlink:href';
        }

        return $name === 'style' && $this->unsafeCss((string) $node->textContent);
    }

    /**
     * Whether an attribute goes: an event handler, a link or source that is not a fragment of this SVG, a
     * `javascript:` value anywhere, or a style that loads from elsewhere.
     */
    private function unsafeAttribute(DOMAttr $attr): bool
    {
        $name = strtolower($attr->nodeName);
        $local = strtolower($attr->localName ?? $name);
        $value = trim((string) $attr->value);
        // Browsers ignore whitespace and control characters inside a scheme, so "java\nscript:" still runs.
        $squashed = strtolower((string) preg_replace('/[\s\x00-\x1f]+/', '', $value));

        if (str_starts_with($local, 'on') || str_contains($squashed, 'javascript:')) {
            return true;
        }
        if (in_array($local, ['href', 'src'], true) || str_ends_with($name, ':href')) {
            return ! str_starts_with($value, '#');
        }

        return $local === 'style' && $this->unsafeCss($value);
    }

    /**
     * Whether CSS loads from elsewhere: an `@import`, a `url()` that is not a `#fragment`, or IE's `expression()`.
     */
    private function unsafeCss(string $css): bool
    {
        $css = strtolower($css);
        if (str_contains($css, '@import') || str_contains($css, 'expression(')) {
            return true;
        }

        return (bool) preg_match('/url\(\s*(?![\'"]?#)/', $css);
    }
}
