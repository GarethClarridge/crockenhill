<?php

declare(strict_types=1);

namespace App\Services\Email;

use DOMDocument;
use DOMElement;
use DOMNode;

class InboundEmailHtmlSanitizer
{
    /**
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'a',
        'b',
        'blockquote',
        'br',
        'code',
        'div',
        'em',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'hr',
        'i',
        'li',
        'ol',
        'p',
        'pre',
        's',
        'span',
        'strong',
        'table',
        'tbody',
        'td',
        'th',
        'thead',
        'tr',
        'u',
        'ul',
    ];

    /**
     * @var list<string>
     */
    private const REMOVE_WITH_CONTENT_TAGS = [
        'base',
        'button',
        'form',
        'iframe',
        'input',
        'link',
        'meta',
        'object',
        'option',
        'script',
        'select',
        'style',
        'svg',
        'textarea',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
    ];

    public function sanitize(?string $html): ?string
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrors = libxml_use_internal_errors(true);

        $loaded = $document->loadHTML(
            $this->encodeHtmlFragment('<div>'.$html.'</div>'),
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            return null;
        }

        $this->sanitizeNode($document->documentElement);

        return $this->innerHtml($document->documentElement);
    }

    private function encodeHtmlFragment(string $html): string
    {
        return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    private function sanitizeNode(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);

            if (in_array($tag, self::REMOVE_WITH_CONTENT_TAGS, true)) {
                $node->parentNode?->removeChild($node);

                return;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                $this->sanitizeChildren($node);
                $this->unwrapElement($node);

                return;
            }

            $this->sanitizeAttributes($node, $tag);
        } elseif (! $node instanceof \DOMText) {
            $node->parentNode?->removeChild($node);

            return;
        }

        $this->sanitizeChildren($node);
    }

    private function sanitizeChildren(DOMNode $node): void
    {
        /** @var list<DOMNode> $children */
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            $this->sanitizeNode($child);
        }
    }

    private function unwrapElement(DOMElement $element): void
    {
        $parent = $element->parentNode;
        if (! $parent instanceof DOMNode) {
            return;
        }

        while ($element->firstChild instanceof DOMNode) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        if (! $element->hasAttributes()) {
            return;
        }

        $allowedAttributes = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        $attributesToRemove = [];

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $attributeName = strtolower($attribute->name);

            if (str_starts_with($attributeName, 'on') || ! in_array($attributeName, $allowedAttributes, true)) {
                $attributesToRemove[] = $attribute->name;

                continue;
            }

            if ($tag === 'a' && $attributeName === 'href' && ! $this->isSafeHref($attribute->value)) {
                $attributesToRemove[] = $attribute->name;
            }
        }

        foreach ($attributesToRemove as $attributeName) {
            $element->removeAttribute($attributeName);
        }

        if ($tag === 'a' && $element->hasAttribute('href')) {
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
            $element->setAttribute('target', '_blank');
        }
    }

    private function isSafeHref(string $href): bool
    {
        $href = trim($href);
        if ($href === '') {
            return false;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }

    private function innerHtml(DOMNode $node): ?string
    {
        $html = '';

        foreach (iterator_to_array($node->childNodes) as $child) {
            $rendered = $node->ownerDocument?->saveHTML($child);

            if (is_string($rendered)) {
                $html .= $rendered;
            }
        }

        $html = trim($html);

        return $html !== '' ? $html : null;
    }
}
