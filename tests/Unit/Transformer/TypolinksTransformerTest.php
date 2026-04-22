<?php

declare(strict_types=1);

namespace Tests\Unit\Transformer;

use Codeception\Test\Unit;
use Digicademy\TypoGraph\Transformer\TypolinksTransformer;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Typolink\LinkFactory;
use TYPO3\CMS\Frontend\Typolink\LinkResultInterface;

class TypolinksTransformerTest extends Unit
{
    private LoggerInterface $logger;
    private ServerRequestInterface $request;

    protected function _before(): void
    {
        $this->logger = $this->makeEmpty(LoggerInterface::class);
        $this->request = $this->makeEmpty(ServerRequestInterface::class);
    }

    protected function _after(): void
    {
        // GeneralUtility::addInstance() queues instances for subsequent
        // makeInstance() calls. Tests may leave unused entries behind on
        // failure; purge so later tests see a clean slate.
        GeneralUtility::purgeInstances();
    }

    // =========================================================================
    // Fast-path / pass-through cases (never invokes DOM or LinkFactory)
    // =========================================================================

    public function testNullInputReturnsUnchanged(): void
    {
        $transformer = $this->makeTransformerWithUnusedLinkFactory();

        verify($transformer->transform(null, $this->request))->null();
    }

    public function testIntegerInputReturnsUnchanged(): void
    {
        $transformer = $this->makeTransformerWithUnusedLinkFactory();

        verify($transformer->transform(42, $this->request))->equals(42);
    }

    public function testArrayInputReturnsUnchanged(): void
    {
        $transformer = $this->makeTransformerWithUnusedLinkFactory();

        verify($transformer->transform(['a', 'b'], $this->request))->equals(['a', 'b']);
    }

    public function testBooleanInputReturnsUnchanged(): void
    {
        $transformer = $this->makeTransformerWithUnusedLinkFactory();

        verify($transformer->transform(true, $this->request))->true();
        verify($transformer->transform(false, $this->request))->false();
    }

    public function testEmptyStringReturnsUnchanged(): void
    {
        $transformer = $this->makeTransformerWithUnusedLinkFactory();

        verify($transformer->transform('', $this->request))->equals('');
    }

    public function testStringWithoutResolvableSchemeReturnsUnchanged(): void
    {
        $transformer = $this->makeTransformerWithUnusedLinkFactory();
        $html = '<p>Plain paragraph with an <a href="https://example.com">external link</a>.</p>';

        verify($transformer->transform($html, $this->request))->equals($html);
    }

    public function testStringWithOnlyAnchorHrefReturnsUnchanged(): void
    {
        $transformer = $this->makeTransformerWithUnusedLinkFactory();
        $html = '<p>See <a href="#section">below</a>.</p>';

        verify($transformer->transform($html, $this->request))->equals($html);
    }

    // =========================================================================
    // Rewrite behaviour
    // =========================================================================

    public function testRewritesT3PageHref(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            't3://page?uid=76#64' => '/info/page/#c64',
        ]);

        $html = '<p>See <a href="t3://page?uid=76#64">this page</a>.</p>';
        $out = $transformer->transform($html, $this->request);

        verify($out)->stringContainsString('href="/info/page/#c64"');
        verify($out)->stringNotContainsString('t3://');
        verify($out)->stringContainsString('>this page</a>');
    }

    public function testRewritesT3FileHref(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            't3://file?uid=123' => '/fileadmin/docs/spec.pdf',
        ]);

        $out = $transformer->transform(
            '<a href="t3://file?uid=123">PDF</a>',
            $this->request
        );

        verify($out)->stringContainsString('href="/fileadmin/docs/spec.pdf"');
    }

    public function testRewritesMailtoHref(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            'mailto:alice@example.com' => 'mailto:alice@example.com',
        ]);

        $out = $transformer->transform(
            '<a href="mailto:alice@example.com">Alice</a>',
            $this->request
        );

        verify($out)->stringContainsString('href="mailto:alice@example.com"');
    }

    public function testRewritesTelHref(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            'tel:+4955555' => 'tel:+4955555',
        ]);

        $out = $transformer->transform(
            '<a href="tel:+4955555">Call</a>',
            $this->request
        );

        verify($out)->stringContainsString('href="tel:+4955555"');
    }

    public function testRewritesMultipleLinksInOneInput(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            't3://page?uid=1' => '/one',
            't3://page?uid=2' => '/two',
        ]);

        $html = '<p><a href="t3://page?uid=1">one</a> and <a href="t3://page?uid=2">two</a></p>';
        $out = $transformer->transform($html, $this->request);

        verify($out)->stringContainsString('href="/one"');
        verify($out)->stringContainsString('href="/two"');
        verify($out)->stringNotContainsString('t3://');
    }

    public function testPreservesOtherAttributesOnAnchor(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            't3://page?uid=9' => '/nine',
        ]);

        $out = $transformer->transform(
            '<a class="cta" target="_blank" href="t3://page?uid=9">nine</a>',
            $this->request
        );

        verify($out)->stringContainsString('class="cta"');
        verify($out)->stringContainsString('target="_blank"');
        verify($out)->stringContainsString('href="/nine"');
    }

    public function testDoesNotTouchExternalLinksMixedWithTypolinks(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            't3://page?uid=1' => '/internal',
        ]);

        $out = $transformer->transform(
            '<a href="t3://page?uid=1">in</a> <a href="https://example.com">out</a>',
            $this->request
        );

        verify($out)->stringContainsString('href="/internal"');
        verify($out)->stringContainsString('href="https://example.com"');
    }

    public function testPreservesUtf8CharactersInSurroundingText(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            't3://page?uid=1' => '/books',
        ]);

        $out = $transformer->transform(
            '<p>Über Bücher: <a href="t3://page?uid=1">mehr erfahren</a> – heute.</p>',
            $this->request
        );

        verify($out)->stringContainsString('Über Bücher');
        verify($out)->stringContainsString('– heute');
        verify($out)->stringContainsString('href="/books"');
    }

    public function testOutputDoesNotIncludeHtmlOrBodyWrapper(): void
    {
        $this->queueContentObjectRendererMock();
        $transformer = $this->makeTransformerResolvingTo([
            't3://page?uid=1' => '/x',
        ]);

        $out = $transformer->transform(
            '<p><a href="t3://page?uid=1">x</a></p>',
            $this->request
        );

        verify($out)->stringNotContainsString('<html');
        verify($out)->stringNotContainsString('<body');
        verify($out)->stringNotContainsString('<?xml');
    }

    // =========================================================================
    // Cache behaviour
    // =========================================================================

    public function testResolvesSameHrefOnlyOncePerInstance(): void
    {
        $this->queueContentObjectRendererMock();

        $callCount = 0;
        $linkFactory = $this->makeEmpty(LinkFactory::class, [
            'createUri' => function (string $href) use (&$callCount): LinkResultInterface {
                $callCount++;
                return $this->linkResultReturning('/resolved-' . $callCount);
            },
        ]);
        $transformer = new TypolinksTransformer($linkFactory, $this->logger);

        $html = '<a href="t3://page?uid=1">a</a>'
            . '<a href="t3://page?uid=1">b</a>'
            . '<a href="t3://page?uid=1">c</a>';
        $out = $transformer->transform($html, $this->request);

        verify($callCount)->equals(1);
        // All three hrefs point at the single cached resolution result.
        verify(substr_count($out, 'href="/resolved-1"'))->equals(3);
    }

    // =========================================================================
    // Failure behaviour
    // =========================================================================

    public function testLeavesHrefUnchangedWhenLinkFactoryThrows(): void
    {
        $this->queueContentObjectRendererMock();
        $linkFactory = $this->makeEmpty(LinkFactory::class, [
            'createUri' => function (): LinkResultInterface {
                throw new \RuntimeException('link could not be built');
            },
        ]);

        $warned = false;
        $logger = $this->makeEmpty(LoggerInterface::class, [
            'warning' => function () use (&$warned): void {
                $warned = true;
            },
        ]);

        $transformer = new TypolinksTransformer($linkFactory, $logger);
        $out = $transformer->transform(
            '<a href="t3://page?uid=1">x</a>',
            $this->request
        );

        verify($warned)->true();
        verify($out)->stringContainsString('t3://page?uid=1');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a transformer whose LinkFactory we expect to NEVER be called.
     * Any accidental call will surface via the PHPUnit expectation failing
     * because `createUri` is not listed on the stub.
     */
    private function makeTransformerWithUnusedLinkFactory(): TypolinksTransformer
    {
        return new TypolinksTransformer(
            $this->makeEmpty(LinkFactory::class),
            $this->logger
        );
    }

    /**
     * Build a transformer whose LinkFactory resolves hrefs according to
     * the given map. Unknown hrefs raise an assertion failure so a test
     * is never surprised by an unstubbed call.
     *
     * @param array<string, string> $map href → resolved URL
     */
    private function makeTransformerResolvingTo(array $map): TypolinksTransformer
    {
        $linkFactory = $this->makeEmpty(LinkFactory::class, [
            'createUri' => function (string $href) use ($map): LinkResultInterface {
                if (!array_key_exists($href, $map)) {
                    $this->fail(sprintf('Unexpected createUri("%s") call', $href));
                }
                return $this->linkResultReturning($map[$href]);
            },
        ]);
        return new TypolinksTransformer($linkFactory, $this->logger);
    }

    private function linkResultReturning(string $url): LinkResultInterface
    {
        return $this->makeEmpty(LinkResultInterface::class, [
            'getUrl' => $url,
        ]);
    }

    /**
     * Queue a ContentObjectRenderer mock for the next
     * `GeneralUtility::makeInstance(ContentObjectRenderer::class)` call
     * that the transformer performs. Call once per expected
     * `transform()` invocation that reaches the rewrite path.
     */
    private function queueContentObjectRendererMock(): void
    {
        GeneralUtility::addInstance(
            ContentObjectRenderer::class,
            $this->makeEmpty(ContentObjectRenderer::class)
        );
    }
}
