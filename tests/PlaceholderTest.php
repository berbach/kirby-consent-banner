<?php

declare(strict_types=1);

namespace Bb\ConsentBanner\Tests;

use Bb\ConsentBanner\Placeholder;
use PHPUnit\Framework\TestCase;

final class PlaceholderTest extends TestCase
{
    private const YOUTUBE = [
        'id' => 'yt1',
        'title' => 'YouTube',
        'domains' => ['youtube.com', 'youtube-nocookie.com'],
        'placeholderTitle' => '',
        'placeholderText' => '',
    ];

    private const GENERIC = '<div class="generic">removed</div>';

    private function replace(string $html): string
    {
        return Placeholder::replaceIframes($html, 'www.example.com', [self::YOUTUBE], self::GENERIC);
    }

    public function testAComponentPlaceholderKeepsTheContentInATemplate(): void
    {
        $html = Placeholder::build('Video "1"', 'Text', 'yt1', '<iframe src="https://x.test/"></iframe>');

        self::assertStringContainsString('data-cookiebanner-component="yt1"', $html);
        self::assertStringContainsString('<input type="checkbox" name="yt1">', $html);
        self::assertStringContainsString('Video &quot;1&quot;', $html);
        self::assertStringContainsString(
            '<template class="bb-consentbanner-deferred"><iframe src="https://x.test/"></iframe></template>',
            $html,
        );
    }

    public function testAGenericPlaceholderHasNoToggle(): void
    {
        $html = Placeholder::build('Headline', 'Text');

        self::assertStringNotContainsString('<input', $html);
        self::assertStringNotContainsString('data-cookiebanner-component', $html);
        self::assertStringNotContainsString('<template', $html);
    }

    public function testAttributesAreEscapedAndBadNamesDropped(): void
    {
        $html = Placeholder::build('H', '', '', '', 'a"b', ['data-x' => '"><script>', 'on click' => 'x']);

        self::assertStringContainsString('class="bb-consentbanner-placeholder a&quot;b"', $html);
        self::assertStringContainsString('data-x="&quot;&gt;&lt;script&gt;"', $html);
        self::assertStringNotContainsString('on click', $html);
    }

    public function testAnIframeOfAComponentGetsItsPlaceholder(): void
    {
        $iframe = '<iframe width="560" src="https://www.youtube-nocookie.com/embed/abc?x=1&amp;y=2" allowfullscreen></iframe>';
        $html = $this->replace('<p>before</p>' . $iframe . '<p>after</p>');

        self::assertStringStartsWith('<p>before</p><div class="bb-consentbanner-placeholder', $html);
        self::assertStringEndsWith('<p>after</p>', $html);
        self::assertStringContainsString('data-cookiebanner-component="yt1"', $html);
        self::assertStringContainsString('<template class="bb-consentbanner-deferred">' . $iframe . '</template>', $html);
        // The only iframe left is the inert one in the template
        self::assertSame(1, substr_count($html, '<iframe'));
    }

    public function testAnIframeNoComponentStandsForIsRemoved(): void
    {
        $html = $this->replace('<iframe src="//maps.other.test/embed"></iframe>');

        self::assertSame(self::GENERIC, $html);
    }

    public function testOwnAndRelativeIframesStay(): void
    {
        $html = '<iframe src="https://example.com/a"></iframe><iframe src="/b"></iframe><iframe src="c.html"></iframe><iframe></iframe><iframe data-src="https://www.youtube.com/embed/x"></iframe>';

        self::assertSame($html, $this->replace($html));
    }

    public function testIframesInScriptsTemplatesAndCommentsStay(): void
    {
        $html = '<script>var a = \'<iframe src="https://www.youtube.com/embed/x"></iframe>\';</script>'
            . '<template><iframe src="https://www.youtube.com/embed/x"></iframe></template>'
            . '<!-- <iframe src="https://www.youtube.com/embed/x"></iframe> -->';

        self::assertSame($html, $this->replace($html));
    }

    public function testAPlaceholderIsNotReplacedTwice(): void
    {
        $once = $this->replace('<iframe src="https://youtube.com/embed/x"></iframe>');

        self::assertSame($once, $this->replace($once));
    }

    public function testSubdomainsMatchButOtherDomainsWithTheSameEndingDoNot(): void
    {
        self::assertNotNull(Placeholder::componentForHost('m.youtube.com', [self::YOUTUBE]));
        self::assertNotNull(Placeholder::componentForHost('youtube.com', [self::YOUTUBE]));
        self::assertNull(Placeholder::componentForHost('notyoutube.com', [self::YOUTUBE]));
        self::assertNull(Placeholder::componentForHost('youtube.com.evil.test', [self::YOUTUBE]));
    }

    public function testNormalizesHosts(): void
    {
        self::assertSame('youtube.com', Placeholder::normalizeHost(' .YouTube.com '));
        self::assertSame('youtube.com', Placeholder::normalizeHost('www.youtube.com'));
        self::assertSame('youtube.com', Placeholder::normalizeHost('https://www.youtube.com/embed/x'));
        self::assertSame('localhost', Placeholder::normalizeHost('localhost:8000'));
        self::assertSame('', Placeholder::normalizeHost(''));
    }

    public function testReadsTheHostOfAnIframe(): void
    {
        self::assertSame('player.vimeo.com', Placeholder::iframeHost("<iframe src='https://player.vimeo.com/video/1'></iframe>"));
        self::assertSame('a.test', Placeholder::iframeHost('<iframe class=x src=https://a.test/b></iframe>'));
        self::assertSame('', Placeholder::iframeHost('<iframe title="src=https://a.test"></iframe>'));
        self::assertSame('', Placeholder::iframeHost('<iframe src="javascript:alert(1)"></iframe>'));
    }

    public function testThePlaceholderTextIsPlainText(): void
    {
        self::assertSame("a &lt;b&gt;<br>\nc", Placeholder::text(" a <b>\nc "));
    }
}
