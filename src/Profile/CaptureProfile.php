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
    /** A garage-sale or auction lot. The question is "how much". */
    case Auction = 'auction';

    /**
     * A piece of medical equipment entering the Lions loan closet. There is no price: the
     * question is which item this is and who has it.
     */
    case MedicalEquipment = 'medical';

    public function label(): string
    {
        return match ($this) {
            self::Auction => 'Auction lot',
            self::MedicalEquipment => 'Medical equipment',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Auction => 'Priced for a garage sale. The label is mostly the price.',
            self::MedicalEquipment => 'Tracked in the loan closet. The label is a QR code and an asset number.',
        };
    }

    /** Whether the model is asked for a price, and whether the label prints one. */
    public function wantsPrice(): bool
    {
        return self::Auction === $this;
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
     * Whether this item can be listed on eBay.
     *
     * Loan-closet equipment cannot: it is lent out free, and the profile already
     * tells the model not to price it.
     *
     * NOTE the tension for auction lots. promptGuidance() asks for a folding-table
     * price -- "cheap and someone wants them today" -- which is the wrong number to
     * put on eBay, where the comparable is what a thing actually fetches online.
     * Listing is therefore a deliberate act on an item whose price a person has
     * already confirmed, never an automatic consequence of the AI suggestion.
     */
    public function listsOnEbay(): bool
    {
        return self::Auction === $this;
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
            self::Auction => "Anything the photo can't show — size, condition, what it's worth",
            self::MedicalEquipment => "Anything the photo can't show — size, weight limit, missing parts",
        };
    }

    /** Steers what the model is asked to produce from the photos. */
    public function promptGuidance(): string
    {
        return match ($this) {
            self::Auction => <<<'TXT'
                These photos are of an item going into a garage sale. Identify it, describe it
                with a little charm, and suggest what it should be priced at on a folding table
                on a Saturday morning.
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
