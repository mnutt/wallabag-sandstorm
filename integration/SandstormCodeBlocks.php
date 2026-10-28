<?php
/** Preserve Prism React code lines before readability removes empty token spans. */
final class SandstormCodeBlocks
{
    public static function normalize(string $html): string
    {
        if (strpos($html, 'token-line') === false) return $html;
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            if (!$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT)) return $html;
            $xpath = new DOMXPath($doc);
            $changed = false;
            foreach ($xpath->query('//pre') as $pre) {
                $lines = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " token-line ")]', $pre);
                if (!$lines->length) continue;
                $text = [];
                foreach ($lines as $line) {
                    // These nodes are presentation-only gutters, not source code.
                    $numbers = $xpath->query('.//*[@data-testid="number-line" or contains(concat(" ", normalize-space(@class), " "), " line-number ")]', $line);
                    foreach ($numbers as $number) $number->parentNode->removeChild($number);
                    // Some renderers put a newline inside an otherwise empty line.
                    $text[] = rtrim($line->textContent, "\r\n");
                }
                while ($pre->firstChild) $pre->removeChild($pre->firstChild);
                $code = $doc->createElement('code');
                $code->appendChild($doc->createTextNode(implode("\n", $text)));
                $pre->appendChild($code);
                $changed = true;
            }
            if (!$changed) return $html;
            foreach (iterator_to_array($doc->childNodes) as $node) {
                if ($node->nodeType === XML_PI_NODE) $doc->removeChild($node);
            }
            return $doc->saveHTML();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
