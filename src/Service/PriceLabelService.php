<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Item;
use App\Profile\CaptureProfile;

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
    /**
     * Character budgets, measured against the rendered label rather than
     * calculated. ^FB takes a *maximum* line count and silently overprints
     * anything past it — the extra lines land on top of the last one — so
     * overflow has to be prevented here rather than absorbed by the printer.
     *
     * ^A0 is proportional, so these are approximate by nature: ~22 chars per
     * line at 26/24 for the title (2 lines) and ~38 at 18/18 for the
     * description (3 lines). The budgets sit well under raw capacity because
     * word wrap can orphan most of a line — "Whimsical skeleton-and-sailboats"
     * puts one word on line 1 and still needs a third line.
     */
    private const TITLE_CHARS = 32;
    private const DESC_CHARS = 100;

    public function buildZpl(Item $item, ?string $qrValue = null): string
    {
        if ($item->getProfile()->wantsQrCode()) {
            return $this->buildLoanClosetZpl($item, $qrValue);
        }

        $price = $item->getPrice();
        $priceText = $price !== null ? '$'.rtrim(rtrim(number_format((float) $price, 2, '.', ''), '0'), '.') : '--';

        $lines = [
            '^XA',
            '^MMT',          // tear-off
            '^LH0,0',
            '^CI27',         // UTF-8
            // Title, wrapped to two lines, left column.
            '^FO14,14^A0N,26,24^FB300,2,2,L,0^FD'.$this->escape($this->fit($this->titleFor($item), self::TITLE_CHARS)).'^FS',
            // The price, deliberately oversized.
            '^FO14,84^A0N,86,80^FD'.$this->escape($priceText).'^FS',
        ];

        $description = trim((string) $item->getDescription());
        if ($description !== '') {
            $lines[] = '^FO14,176^A0N,18,18^FB300,3,2,L,0^FD'.$this->escape($this->fit($description, self::DESC_CHARS)).'^FS';
        }

        // No QR code on an auction tag. A buyer across a table wants the price, and every
        // dot spent on a code nobody scans is a dot not spent on the number they came for.
        $lines[] = '^FO318,186^A0N,18,18^FD'.$this->escape('#'.$item->getId()).'^FS';
        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    /**
     * A loan-closet label: no price, because nothing here is for sale.
     *
     * The asset number takes the space and the weight the price has on an auction tag -- it is
     * the thing a volunteer reads across a room or says over the phone. The QR code carries the
     * same value, so scanning and reading aloud agree; a label whose code and digits disagreed
     * would be worse than one with no code at all.
     */
    private function buildLoanClosetZpl(Item $item, ?string $qrValue = null): string
    {
        $assetNumber = $item->getAssetNumber() ?? '';

        // The code encodes the asset number itself, not a URL: it is the natural key of the
        // Equipment record in Quickbase, so a scan and a person reading the label aloud produce
        // the same string. A code that resolved to something the printed digits did not match
        // would be worse than no code at all.
        $code = $assetNumber;

        $lines = [
            '^XA',
            '^MMT',
            '^LH0,0',
            '^CI27',
            '^FO14,14^A0N,26,24^FB300,2,2,L,0^FD'.$this->escape($this->fit($this->titleFor($item), self::TITLE_CHARS)).'^FS',
            // Smaller than an auction price: an asset number is read, not shouted across a
            // table, and it has to stay legible at ten characters rather than four.
            '^FO14,92^A0N,54,50^FD'.$this->escape($assetNumber !== '' ? $assetNumber : '--').'^FS',
        ];

        $description = trim((string) $item->getDescription());
        if ($description !== '') {
            $lines[] = '^FO14,158^A0N,18,18^FB300,3,2,L,0^FD'.$this->escape($this->fit($description, self::DESC_CHARS)).'^FS';
        }

        if ($code !== '') {
            $lines[] = '^FO318,22^BQN,2,5^FDQA,'.$this->escape($code).'^FS';
        }

        $lines[] = '^FO318,196^A0N,16,16^FD'.$this->escape('Lions loan closet').'^FS';
        $lines[] = '^XZ';

        return implode("\n", $lines);
    }

    /** Trim to a character budget on a word boundary, with an ellipsis. */
    private function fit(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $cut = mb_substr($value, 0, $max - 1);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > $max * 0.6) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " ,;:.-").'...';
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
