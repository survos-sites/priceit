<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;

/**
 * Builds ZPL for a garage-sale price tag.
 *
 * Sized for the Zebra GK420d at 203dpi on 2.25×1.25in stock — the same label
 * size ssai prints, so the same printer profile handles both. That's ~457×254
 * dots; everything below is positioned in dots, not inches.
 *
 * The price is the largest thing on the tag on purpose: at a garage sale the
 * only question anyone asks is "how much", and it gets asked across a table.
 */
final class PriceLabelService
{
    public function buildZpl(Item $item, ?string $qrValue = null): string
    {
        $price = $item->getPrice();
        $priceText = $price !== null ? '$'.rtrim(rtrim(number_format((float) $price, 2, '.', ''), '0'), '.') : '--';

        $lines = [
            '^XA',
            '^MMT',          // tear-off
            '^LH0,0',
            '^CI27',         // UTF-8
            // Title, wrapped to two lines, left column.
            '^FO14,14^A0N,26,24^FB300,2,2,L,0^FD'.$this->escape($this->titleFor($item)).'^FS',
            // The price, deliberately oversized.
            '^FO14,84^A0N,86,80^FD'.$this->escape($priceText).'^FS',
        ];

        $description = trim((string) $item->getDescription());
        if ($description !== '') {
            $lines[] = '^FO14,180^A0N,18,18^FB300,2,2,L,0^FD'.$this->escape($description).'^FS';
        }

        if ($qrValue !== null && $qrValue !== '') {
            $lines[] = '^FO330,20^BQN,2,5^FDQA,'.$this->escape($qrValue).'^FS';
        }

        $lines[] = '^FO330,200^A0N,18,18^FD'.$this->escape('#'.$item->getId()).'^FS';
        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    private function titleFor(Item $item): string
    {
        $title = trim((string) $item->getTitle());

        return $title !== '' ? $title : 'Untitled item';
    }

    /**
     * ZPL has two in-band control characters. A caret in a donated item's title
     * would otherwise start a new field command mid-word and mangle the label.
     */
    private function escape(string $value): string
    {
        $value = trim($value);
        $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return str_replace(['^', '~'], [' ', '-'], $value);
    }
}
