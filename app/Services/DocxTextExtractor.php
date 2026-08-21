<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class DocxTextExtractor
{
    public function extract(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new RuntimeException('DOCX-файл не найден.');
        }

        $zip = new ZipArchive();

        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Не удалось открыть DOCX-файл.');
        }

        $xml = $zip->getFromName('word/document.xml');

        $zip->close();

        if ($xml === false) {
            throw new RuntimeException(
                'В DOCX не найден word/document.xml.'
            );
        }

        $dom = new DOMDocument();

        libxml_use_internal_errors(true);

        if (!$dom->loadXML($xml)) {
            libxml_clear_errors();

            throw new RuntimeException(
                'Не удалось разобрать XML документа Word.'
            );
        }

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $xpath->registerNamespace(
            'w',
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
        );

        $paragraphs = [];

        foreach ($xpath->query('//w:body/w:p') as $paragraph) {
            $parts = [];

            foreach ($xpath->query('.//w:t | .//w:tab | .//w:br', $paragraph) as $node) {
                if ($node->localName === 't') {
                    $parts[] = $node->textContent;
                } elseif ($node->localName === 'tab') {
                    $parts[] = "\t";
                } elseif ($node->localName === 'br') {
                    $parts[] = "\n";
                }
            }

            $paragraphText = trim(implode('', $parts));

            if ($paragraphText !== '') {
                $paragraphs[] = $paragraphText;
            }
        }

        $text = implode("\n\n", $paragraphs);

        $text = str_replace("\xC2\xA0", ' ', $text);

        $text = preg_replace(
            '/[ \t]+\n/u',
            "\n",
            $text
        );

        $text = preg_replace(
            '/\n{3,}/u',
            "\n\n",
            $text
        );

        return trim($text);
    }
}
