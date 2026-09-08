<?php

declare(strict_types=1);

namespace App\Profile;

/**
 * What kind of thing is being captured, and therefore what happens to it.
 *
 * The profile is chosen once, on the capture screen, and from then on it decides three things:
 * what the model is asked for, what comes out of the printer, and what happens when a person
 * confirms. Everything that differs between an auction lot and a loaned wheelchair is declared
 * here rather than as branches scattered through the services.
 */
enum CaptureProfile: string
{
    /**
     * A garage-sale lot. Cheap, local, sold off a folding table today. The question
     * is "what will someone hand over for this right now".
     */
    case GarageSale = 'garage_sale';

    /**
     * Something worth listing online rather than putting on a table. Priced against
     * what it actually fetches, not against what clears a driveway by Sunday.
     */
    case Resale = 'resale';

    /**
     * A piece of medical equipment entering the Lions loan closet. There is no price: the
     * question is which item this is and who has it.
     */
    case MedicalEquipment = 'medical';

    public function label(): string
    {
        return match ($this) {
            self::GarageSale => 'Garage sale',
            self::Resale => 'Resale (online)',
            self::MedicalEquipment => 'Medical equipment',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GarageSale => 'Priced to move today. The label is mostly the price.',
            self::Resale => 'Listed on eBay or Mercado Libre. Priced against what it sells for online.',
            self::MedicalEquipment => 'Tracked in the loan closet. The label is a QR code and an asset number.',
        };
    }

    /** Whether the model is asked for a price, and whether the label prints one. */
    public function wantsPrice(): bool
    {
        return self::MedicalEquipment !== $this;
    }

    /** Loaned equipment is scanned in and out, so its label carries a QR code. */
    public function wantsQrCode(): bool
    {
        return self::MedicalEquipment === $this;
    }

    /**
     * Whether the item gets a durable, human-readable asset number.
     *
     * Minted here rather than by Quickbase because the printer cannot wait for a network
     * round-trip: the label has to come out while the volunteer is still holding the item.
     */
    public function assignsAssetNumber(): bool
    {
        return self::MedicalEquipment === $this;
    }

    /** Whether confirming the item pushes it to the Quickbase loan closet. */
    public function publishesToQuickbase(): bool
    {
        return self::MedicalEquipment === $this;
    }

    /**
     * Whether this item can be listed on a marketplace.
     *
     * Only Resale. This is why the profile split exists: a garage-sale price is
     * deliberately low -- "cheap and someone wants them today" -- and putting that
     * number on eBay systematically undersells. The two answers are different
     * enough that they need different prompts, so they are different profiles
     * rather than one profile with a caveat.
     *
     * Loan-closet equipment is lent out free; the profile already tells the model
     * not to price it, so offering to sell it would be incoherent.
     */
    public function listsOnMarketplace(): bool
    {
        return self::Resale === $this;
    }

    /**
     * Prompt for the spoken or typed note.
     *
     * Asking a volunteer what a loaned wheelchair is "worth" would contradict the profile that
     * just told the model not to price it, so the question changes with the profile.
     */
    public function notePlaceholder(): string
    {
        return match ($this) {
            self::GarageSale => "Anything the photo can't show — size, condition, what it's worth",
            self::Resale => "Anything the photo can't show — maker, age, condition, flaws a buyer would want disclosed",
            self::MedicalEquipment => "Anything the photo can't show — size, weight limit, missing parts",
        };
    }

    /** Steers what the model is asked to produce from the photos. */
    public function promptGuidance(): string
    {
        return match ($this) {
            self::GarageSale => <<<'TXT'
                These photos are of an item going into a garage sale. Identify it, describe it
                with a little charm, and suggest what it should be priced at on a folding table
                on a Saturday morning.
                TXT,
            self::Resale => <<<'TXT'
                These photos are of an item being listed for sale online, on eBay or Mercado
                Libre, where buyers search for a specific thing and compare it against other
                listings of the same thing.

                Price it against what it actually sells for online, NOT what would clear a
                driveway on a Saturday. A collectible, a brand-name tool or a piece of vintage
                glassware can be worth many times its garage-sale price, and underpricing here
                costs the seller real money.

                Write the title the way a buyer would search for it -- maker, model, era,
                material, size -- rather than the way a price sticker would read. Say plainly
                what condition you can see, including flaws: an undisclosed chip becomes a
                return, which costs more than the honest description would have.
                TXT,
            self::MedicalEquipment => <<<'TXT'
                These photos are of a piece of medical or mobility equipment entering a Lions
                Club loan closet, which lends it out at no charge. Do not estimate a value or a
                price; nothing here is for sale.

                Identify the item precisely enough that a volunteer can pick the right one off a
                shelf: what it is, the manufacturer and model if either is legible, and the size
                or capacity if it is marked. Note any damage or missing parts you can actually
                see. Say plainly when something is not visible rather than guessing at it --
                someone will rely on this to decide whether it suits a person's needs.
                TXT,
        };
    }
}
