<?php

declare(strict_types=1);

namespace App\Entity;

enum ItemStatus: string
{
    case Captured = 'captured';   // photo(s) uploaded, AI enrichment not run yet
    case Suggested = 'suggested'; // AI has proposed title/description/price
    case Priced = 'priced';       // user confirmed the price, ready for the sale
}
