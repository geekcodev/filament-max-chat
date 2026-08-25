<?php

declare(strict_types=1);

namespace GeekCo\FilamentMaxChat\Support;

use Dom\Comment;
use Dom\Element;
use Dom\HTMLDocument;

use function in_array;
use function is_string;
use function strtolower;
use function trim;

/**
 * Sanitizes HTML text to a whitelist of tags supported by MAX (TextFormat: html).
 */
class TextSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ins', 's', 'del',
        'code', 'pre', 'mark', 'h1', 'h2', 'h3', 'h4', 'blockquote', 'a',
    ];

    /**
     * Tags removed with their content (do not unwrap — otherwise script text becomes visible).
     */
    private const DROP_WITH_CONTENT_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'noscript'];

    private const SAFE_HREF_SCHEMES = ['http', 'https', 'max'];

    public function sanitize(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            return htmlspecialchars($html, ENT_QUOTES, 'UTF-8');
        }

        $body = $document->body;

        if ($body === null) {
            return '';
        }

        foreach ([...$body->childNodes] as $node) {
            if ($node instanceof Element) {
                $this->cleanElement($node);
            } elseif ($node instanceof Comment) {
                $node->remove();
            }
        }

        $output = '';

        foreach ($body->childNodes as $node) {
            $output .= $document->saveHTML($node);
        }

        return trim($output);
    }

    /**
     * Converts HTML to a subset of tags that MAX actually renders
     * (TextFormat: html): <p>/<div> are unwrapped into text with "\n",
     * <br> is replaced with "\n". Without this paragraphs merge into one line
     * and text after <h1>..</h1> inherits the heading (MAX has no block-level paragraphs).
     */
    public function toMaxHtml(string $html): string
    {
        $sanitized = $this->sanitize($html);

        if ($sanitized === '') {
            return '';
        }

        try {
            $document = HTMLDocument::createFromString($sanitized, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            return $sanitized;
        }

        $body = $document->body;

        if ($body === null) {
            return '';
        }

        $output = '';

        foreach ($body->childNodes as $node) {
            if ($node instanceof Element && in_array(strtolower($node->tagName), ['p', 'div'], true)) {
                $output .= $this->inlineHtml($document, $node)."\n";

                continue;
            }

            if ($node instanceof Element && in_array(strtolower($node->tagName), ['h1', 'h2', 'h3', 'h4', 'blockquote'], true)) {
                $output .= trim((string) $document->saveHTML($node))."\n";

                continue;
            }

            $output .= (string) $document->saveHTML($node);
        }

        $output = str_replace(['<br>', '<br/>', '<br />'], "\n", $output);

        $normalized = preg_replace('/\n{3,}/', "\n\n", $output);

        return trim(is_string($normalized) ? $normalized : $output);
    }

    private function inlineHtml(HTMLDocument $document, Element $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= (string) $document->saveHTML($child);
        }

        return trim($html);
    }

    private function cleanElement(Element $element): void
    {
        foreach ([...$element->childNodes] as $child) {
            if ($child instanceof Element) {
                $this->cleanElement($child);
            } elseif ($child instanceof Comment) {
                $child->remove();
            }
        }

        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP_WITH_CONTENT_TAGS, true)) {
            $element->remove();

            return;
        }

        if (! in_array($tag, self::ALLOWED_TAGS, true)) {
            $this->unwrap($element);

            return;
        }

        if ($tag === 'a') {
            $href = $element->getAttribute('href') ?? '';

            $this->dropAttributes($element);

            if ($this->isSafeHref($href)) {
                $element->setAttribute('href', $href);
            }

            return;
        }

        $this->dropAttributes($element);
    }

    private function unwrap(Element $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        foreach ([...$element->childNodes] as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private function dropAttributes(Element $element): void
    {
        foreach ([...$element->attributes] as $attribute) {
            $element->removeAttributeNode($attribute);
        }
    }

    private function isSafeHref(string $href): bool
    {
        $scheme = parse_url($href, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), self::SAFE_HREF_SCHEMES, true);
    }
}
