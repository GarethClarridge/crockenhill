<?php

declare(strict_types=1);

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Allowlist-based HTML sanitizer for api.bible scripture content.
 *
 * Preserves verse markup (sup, span with class) while stripping all scripts,
 * inline event handlers, unsafe URLs, and disallowed tags.
 */
class ScriptureHtmlSanitizer
{
    /**
     * Tags whose content is preserved when the tag itself is removed (unwrapped).
     *
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'b',
        'br',
        'div',
        'em',
        'i',
        'p',
        'span',
        'strong',
        'sup',
    ];

    /**
     * Tags removed along with all their children.
     *
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
        'script',
        'select',
        'style',
        'svg',
        'textarea',
    ];

    /**
     * Attributes allowed per tag. Tags not listed here get no attributes at all.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'span' => ['class', 'data-number'],
        'sup' => ['class', 'data-verse'],
        'div' => ['class'],
        'p' => ['class'],
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
            }
        }

        foreach ($attributesToRemove as $attributeName) {
            $element->removeAttribute($attributeName);
        }
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
