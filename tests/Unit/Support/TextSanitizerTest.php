<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Tests\Unit\Support;

use GeekCo\FilamentMaxChat\Support\TextSanitizer;
use PHPUnit\Framework\TestCase;

class TextSanitizerTest extends TestCase
{
    private TextSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new TextSanitizer();
    }

    public function test_keeps_max_supported_formatting_tags(): void
    {
        $html = '<h2>Акция</h2><p><b>Жирный</b>, <i>курсив</i>, <u>подчёркнутый</u>, '
            .'<s>зачёркнутый</s>, <mark>выделенный</mark></p><blockquote>Цитата</blockquote>'
            .'<pre><code>code</code></pre>';

        $result = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<h2>Акция</h2>', $result);
        $this->assertStringContainsString('<b>Жирный</b>', $result);
        $this->assertStringContainsString('<i>курсив</i>', $result);
        $this->assertStringContainsString('<u>подчёркнутый</u>', $result);
        $this->assertStringContainsString('<s>зачёркнутый</s>', $result);
        $this->assertStringContainsString('<mark>выделенный</mark>', $result);
        $this->assertStringContainsString('<blockquote>Цитата</blockquote>', $result);
        $this->assertStringContainsString('code', $result);
    }

    public function test_removes_script_and_style_with_content(): void
    {
        $result = $this->sanitizer->sanitize('<p>Текст</p><script>alert(1)</script><style>.x{}</style>');

        $this->assertStringNotContainsString('script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('style', $result);
        $this->assertStringContainsString('Текст', $result);
    }

    public function test_unwraps_unknown_tags_but_keeps_text(): void
    {
        $result = $this->sanitizer->sanitize('<div><span>Привет</span> мир</div>');

        $this->assertStringNotContainsString('<div', $result);
        $this->assertStringNotContainsString('<span', $result);
        $this->assertStringContainsString('Привет', $result);
        $this->assertStringContainsString('мир', $result);
    }

    public function test_strips_event_handlers_and_attributes(): void
    {
        $result = $this->sanitizer->sanitize('<b onclick="alert(1)" data-x="y">Жирный</b>');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('data-x', $result);
        $this->assertStringContainsString('<b>Жирный</b>', $result);
    }

    public function test_keeps_safe_links_and_drops_dangerous_schemes(): void
    {
        $result = $this->sanitizer->sanitize(
            '<a href="https://chisto-service.ru">Сайт</a>'
            .'<a href="https://max.ru/bot?startapp=booking">Бот</a>'
            .'<a href="javascript:alert(1)">Плохо</a>',
        );

        $this->assertStringContainsString('href="https://chisto-service.ru"', $result);
        $this->assertStringContainsString('href="https://max.ru/bot?startapp=booking"', $result);
        $this->assertStringNotContainsString('javascript:', $result);
        $this->assertStringContainsString('Плохо', $result);
    }

    public function test_removes_comments(): void
    {
        $result = $this->sanitizer->sanitize('<p>Текст<!-- секрет --></p>');

        $this->assertStringNotContainsString('секрет', $result);
        $this->assertStringContainsString('Текст', $result);
    }

    public function test_empty_string_returns_empty(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function test_to_max_html_unwraps_paragraphs_into_newlines(): void
    {
        $this->assertSame(
            "Первый абзац\nВторой абзац",
            $this->sanitizer->toMaxHtml('<p>Первый абзац</p><p>Второй абзац</p>'),
        );
    }

    public function test_to_max_html_keeps_heading_and_separates_following_text(): void
    {
        $this->assertSame(
            "<h1>Заголовок</h1>\nТекст после заголовка",
            $this->sanitizer->toMaxHtml('<h1>Заголовок</h1><p>Текст после заголовка</p>'),
        );
    }

    public function test_to_max_html_replaces_br_with_newline(): void
    {
        $this->assertSame(
            "строка один\nстрока два",
            $this->sanitizer->toMaxHtml('<p>строка один<br>строка два</p>'),
        );
    }

    public function test_to_max_html_keeps_inline_formatting_untouched(): void
    {
        $this->assertSame(
            '<b>Жирный</b> и <i>курсив</i>',
            $this->sanitizer->toMaxHtml('<b>Жирный</b> и <i>курсив</i>'),
        );
    }

    public function test_to_max_html_still_sanitizes_dangerous_content(): void
    {
        $this->assertSame(
            'Текст',
            $this->sanitizer->toMaxHtml('<p><script>alert(1)</script>Текст</p>'),
        );
    }

    public function test_sanitize_returns_escaped_when_html_is_unparseable(): void
    {
        $result = $this->sanitizer->sanitize('not < valid > html <<<');

        $this->assertStringContainsString('not &lt; valid &gt; html &lt;&lt;&lt;', $result);
    }

    public function test_to_max_html_with_empty_input_returns_empty(): void
    {
        $this->assertSame('', $this->sanitizer->toMaxHtml(''));
        $this->assertSame('', $this->sanitizer->toMaxHtml('   '));
    }

    public function test_to_max_html_unwraps_div_like_paragraph(): void
    {
        $this->assertSame(
            "Текст div",
            $this->sanitizer->toMaxHtml('<div>Текст div</div>'),
        );
    }

    public function test_to_max_html_keeps_blockquote(): void
    {
        $result = $this->sanitizer->toMaxHtml('<blockquote>Цитата</blockquote><p>После</p>');

        $this->assertStringContainsString('<blockquote>Цитата</blockquote>', $result);
        $this->assertStringContainsString("\n", $result);
    }

    public function test_to_max_html_collapses_multiple_newlines(): void
    {
        $result = $this->sanitizer->toMaxHtml('<p>A</p><p></p><p></p><p>B</p>');

        $this->assertStringNotContainsString("\n\n\n", $result);
    }

    public function test_sanitize_keeps_max_scheme_link(): void
    {
        $result = $this->sanitizer->sanitize('<a href="max://bot?startapp=123">Бот</a>');

        $this->assertStringContainsString('href="max://bot?startapp=123"', $result);
    }

    public function test_sanitize_drops_mailto_link(): void
    {
        $result = $this->sanitizer->sanitize('<a href="mailto:test@example.com">Почта</a>');

        $this->assertStringNotContainsString('href=', $result);
        $this->assertStringContainsString('Почта', $result);
    }

    public function test_sanitize_strips_nested_script_tags(): void
    {
        $result = $this->sanitizer->sanitize('<div><b>OK</b><script>nested</script></div>');

        $this->assertStringContainsString('<b>OK</b>', $result);
        $this->assertStringNotContainsString('nested', $result);
    }

    public function test_sanitize_removes_all_attributes_from_allowed_tags(): void
    {
        $result = $this->sanitizer->sanitize('<p class="x" id="y" style="color:red">Текст</p>');

        $this->assertStringContainsString('<p>Текст</p>', $result);
        $this->assertStringNotContainsString('class=', $result);
    }

    public function test_to_max_html_with_h2_h3_h4_tags(): void
    {
        $result = $this->sanitizer->toMaxHtml('<h2>Title</h2><p>Text</p>');

        $this->assertStringContainsString('<h2>Title</h2>', $result);
        $this->assertStringContainsString("\n", $result);
    }

    public function test_to_max_html_preserves_br_variants(): void
    {
        $result = $this->sanitizer->toMaxHtml('A<br/>B<br />C');

        $this->assertSame("A\nB\nC", $result);
    }
}
