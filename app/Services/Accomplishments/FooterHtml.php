<?php

namespace App\Services\Accomplishments;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class FooterHtml
{
    public static function sanitize(?string $value): string
    {
        if (! $value) {
            return '';
        }

        if (! preg_match('/<\/?[a-z][^>]*>/i', $value)) {
            return nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$value.'</body></html>', LIBXML_NONET);
            $body = $document->getElementsByTagName('body')->item(0);

            return $body ? self::children($body) : '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function text(?string $value): string
    {
        $html = self::sanitize($value);
        $html = preg_replace('/<br\s*\/?>\r?\n?|<\/(?:p|div)>/i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function children(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $html .= htmlspecialchars($child->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } elseif ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'svg', 'math', 'template'], true)) {
                    continue;
                }
                if (! in_array($tag, ['p', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'br', 'font'], true)) {
                    $html .= self::children($child);

                    continue;
                }
                $styles = self::styles($child->getAttribute('style'));
                if ($tag === 'font') {
                    $sizes = ['1' => '8pt', '2' => '10pt', '3' => '12pt', '4' => '14pt', '5' => '18pt', '6' => '24pt', '7' => '36pt'];
                    $styles .= isset($sizes[$child->getAttribute('size')]) ? 'font-size:'.$sizes[$child->getAttribute('size')].';' : '';
                    $styles .= self::styles('font-family:'.$child->getAttribute('face'));
                    $tag = 'span';
                }
                $html .= '<'.$tag.($styles ? ' style="'.htmlspecialchars($styles, ENT_QUOTES, 'UTF-8').'"' : '').'>';
                if ($tag !== 'br') {
                    $html .= self::children($child).'</'.$tag.'>';
                }
            }
        }

        return $html;
    }

    private static function styles(string $style): string
    {
        $allowed = [
            'text-align' => '/^(left|center|right|justify)$/i',
            'font-family' => '/^(Arial|Times New Roman|DejaVu Sans|Courier New|Ovo|sans-serif|serif)$/i',
            'font-size' => '/^(?:[8-9]|[12][0-9]|3[0-6])(?:pt|px)$/i',
            'font-weight' => '/^(normal|bold|[1-9]00)$/i',
            'font-style' => '/^(normal|italic)$/i',
            'text-decoration' => '/^(none|underline|line-through)$/i',
        ];
        $result = '';
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            $property = strtolower(trim($parts[0]));
            $value = trim($parts[1] ?? '', " \t\n\r\0\x0B\"'");
            if (isset($allowed[$property]) && preg_match($allowed[$property], $value)) {
                $result .= $property.':'.$value.';';
            }
        }

        return $result;
    }
}
