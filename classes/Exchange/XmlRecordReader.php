<?php

declare(strict_types=1);

namespace App\Exchange;

use App\Service\ServiceException;
use DOMDocument;
use DOMElement;

/**
 * Odczyt XML do importu. Rekordy to elementy podrzędne elementu głównego (albo jednego
 * elementu-kontenera, np. <certificates>); pola to ich atrybuty i elementy liściowe.
 * Taki układ ma eksport z aplikacji, ale nazwy elementów mogą też być polskimi nagłówkami.
 *
 * Dokumenty z deklaracją DOCTYPE są odrzucane — to zamyka ataki przez encje zewnętrzne (XXE)
 * i rozwijanie encji („billion laughs”).
 */
final class XmlRecordReader
{
    public static function parse(string $content): ParsedTable
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (trim($content) === '') {
            throw ServiceException::validation(['file' => \__('exchange.error.empty_file')]);
        }
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            throw ServiceException::validation(['file' => \__('exchange.error.xml_doctype')]);
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;
        if (!$loaded || $root === null) {
            $error = $errors[0] ?? null;
            throw ServiceException::validation(['file' => \__('exchange.error.xml_invalid', [
                'line'    => (string) ($error !== null ? $error->line : 0),
                'message' => $error !== null ? trim($error->message) : '',
            ])]);
        }

        $records = self::children($root);
        if (count($records) === 1 && self::isContainer($records[0])) {
            $records = self::children($records[0]);
        }

        $headers = [];
        $rows = [];
        foreach ($records as $record) {
            $values = [];
            foreach ($record->attributes as $attribute) {
                $values[$attribute->nodeName] = trim((string) $attribute->nodeValue);
            }
            foreach (self::children($record) as $field) {
                if (self::children($field) === []) {
                    $values[$field->nodeName] = trim($field->textContent);
                }
            }
            if ($values === []) {
                continue;
            }

            foreach (array_keys($values) as $header) {
                if (!in_array($header, $headers, true)) {
                    $headers[] = $header;
                }
            }
            $rows[] = ['line' => $record->getLineNo(), 'values' => $values];

            if (count($rows) > CsvReader::MAX_ROWS) {
                throw ServiceException::validation(['file' => \__('exchange.error.too_many_rows', ['max' => (string) CsvReader::MAX_ROWS])]);
            }
        }

        if ($rows === []) {
            throw ServiceException::validation(['file' => \__('exchange.error.empty_file')]);
        }

        return new ParsedTable($headers, $rows, 'xml');
    }

    /**
     * @return list<DOMElement>
     */
    private static function children(DOMElement $element): array
    {
        $children = [];
        foreach ($element->childNodes as $node) {
            if ($node instanceof DOMElement) {
                $children[] = $node;
            }
        }

        return $children;
    }

    /**
     * Kontener to element, którego wszystkie elementy podrzędne same mają elementy podrzędne.
     */
    private static function isContainer(DOMElement $element): bool
    {
        $children = self::children($element);
        if ($children === []) {
            return false;
        }

        foreach ($children as $child) {
            if (self::children($child) === []) {
                return false;
            }
        }

        return true;
    }
}
