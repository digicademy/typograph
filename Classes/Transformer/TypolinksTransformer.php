<?php

declare(strict_types=1);

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>, Academy of Sciences and Literature | Mainz
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace Digicademy\TypoGraph\Transformer;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Typolink\LinkFactory;

/**
 * Expands TYPO3 link-handling URIs (t3://, mailto:, tel:) inside HTML
 * `href` attributes into real URLs.
 *
 * TYPO3 stores RTE-managed content (e.g. `tt_content.bodytext`) with link
 * targets in the `t3://...` notation. During a normal Fluid render these
 * are resolved by `lib.parseFunc_RTE` as part of the TSFE pipeline.
 * TypoGraph responds from a PSR-15 middleware that runs *before* TSFE is
 * bootstrapped, so without this transformer the raw `t3://...` values
 * would leave the database unchanged.
 *
 * The transformer scans the input HTML for `<a>` elements whose `href`
 * uses a TYPO3 link scheme and rewrites each via
 * {@see LinkFactory::createUri()}, which internally delegates to the
 * appropriate builder (`PageLinkBuilder`, `FileOrFolderLinkBuilder`,
 * `EmailLinkBuilder`, `TelephoneLinkBuilder`, `ExternalUrlLinkBuilder`,
 * `DatabaseRecordLinkBuilder`). Any other attribute on the `<a>` element
 * is preserved verbatim.
 *
 * Resolved URLs are cached per instance. Because the TYPO3 service
 * container shares instances across requests, the cache is bounded only
 * by the number of distinct hrefs encountered over the process lifetime;
 * in practice this is negligible for CMS content but worth keeping in
 * mind if the set of links is effectively unbounded.
 */
final class TypolinksTransformer implements TransformerInterface
{
    /**
     * URI schemes that should be rewritten. Anything else (plain http(s),
     * protocol-relative URLs, in-page anchors) is left untouched so that
     * user-pasted external links remain valid.
     *
     * @var list<string>
     */
    private const RESOLVABLE_SCHEMES = ['t3://', 'mailto:', 'tel:'];

    /**
     * Per-instance cache keyed by the original href. Values are:
     * - string: the resolved URL
     * - null: resolution failed and the original should be kept
     *
     * @var array<string, string|null>
     */
    private array $resolvedCache = [];

    public function __construct(
        private readonly LinkFactory $linkFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Returns the input unchanged when it is not a non-empty string or
     * when a fast substring probe can already rule out any rewritable
     * href. Otherwise the input is parsed into a DOM, hrefs are rewritten
     * in place, and the body content is serialized back out.
     */
    public function transform(mixed $value, ServerRequestInterface $request): mixed
    {
        if (!is_string($value) || $value === '' || !$this->containsResolvableHref($value)) {
            return $value;
        }

        $dom = $this->loadHtml($value);
        if ($dom === null) {
            return $value;
        }

        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//a[@href]');
        if (!$links instanceof \DOMNodeList || $links->length === 0) {
            return $value;
        }

        // ContentObjectRenderer is passed through to each LinkBuilder.
        // Its attached request carries the `site`, `language`, and
        // `routing` attributes that AbstractTypolinkBuilder uses to
        // lazily construct a minimal TSFE when the middleware runs
        // before the regular frontend pipeline.
        $cObj = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $cObj->setRequest($request);

        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $href = $link->getAttribute('href');
            if (!$this->isResolvableScheme($href)) {
                continue;
            }
            $resolved = $this->resolve($href, $cObj);
            if ($resolved !== null) {
                $link->setAttribute('href', $resolved);
            }
        }

        return $this->serializeBody($dom, $value);
    }

    /**
     * Parse an HTML fragment into a DOMDocument while preserving UTF-8.
     *
     * The fragment is wrapped in a `<body>` element and preceded by an
     * XML encoding hint so that DOMDocument does not fall back to
     * ISO-8859-1 for non-ASCII characters.
     */
    private function loadHtml(string $html): ?\DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadHTML(
                '<?xml encoding="UTF-8"?><body>' . $html . '</body>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return $loaded === false ? null : $dom;
    }

    /**
     * Serialize the children of the wrapping `<body>` element back to
     * HTML, leaving the surrounding wrapper out of the output. Falls
     * back to the original input if the wrapper is unexpectedly missing.
     */
    private function serializeBody(\DOMDocument $dom, string $fallback): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return $fallback;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $html = $dom->saveHTML($child);
            if (is_string($html)) {
                $result .= $html;
            }
        }
        return $result;
    }

    /**
     * Cheap substring probe that lets us skip the DOM parse entirely for
     * any value that obviously has no rewritable href.
     */
    private function containsResolvableHref(string $value): bool
    {
        foreach (self::RESOLVABLE_SCHEMES as $scheme) {
            if (
                str_contains($value, 'href="' . $scheme)
                || str_contains($value, "href='" . $scheme)
            ) {
                return true;
            }
        }
        return false;
    }

    private function isResolvableScheme(string $href): bool
    {
        foreach (self::RESOLVABLE_SCHEMES as $scheme) {
            if (str_starts_with($href, $scheme)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a single href via {@see LinkFactory::createUri()}, using an
     * in-memory cache keyed by the original href. Returns `null` when
     * resolution fails so the caller can keep the original attribute.
     */
    private function resolve(string $href, ContentObjectRenderer $cObj): ?string
    {
        if (array_key_exists($href, $this->resolvedCache)) {
            return $this->resolvedCache[$href];
        }

        try {
            $url = $this->linkFactory->createUri($href, $cObj)->getUrl();
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf('Failed to resolve typolink "%s": %s', $href, $e->getMessage())
            );
            $this->resolvedCache[$href] = null;
            return null;
        }

        $this->resolvedCache[$href] = $url;
        return $url;
    }
}
