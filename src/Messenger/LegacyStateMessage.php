<?php

declare(strict_types=1);

namespace App\Messenger;

/** Inspects old PHP envelopes without autoloading or executing their message classes. */
final class LegacyStateMessage
{
    public static function inspect(string $body, int $depth = 0): ?array
    {
        if ($depth > 5 || strlen($body) > 2_000_000) {
            return null;
        }
        if (!str_ends_with($body, '}')) {
            $body = base64_decode($body, true) ?: '';
        }
        try {
            $envelope = (array) @unserialize(stripslashes($body), ['allowed_classes' => false, 'max_depth' => 64]);
        } catch (\Throwable) {
            return null;
        }
        $message = (array) ($envelope["\0Symfony\Component\Messenger\Envelope\0message"] ?? null);
        $class = $message['__PHP_Incomplete_Class_Name'] ?? null;
        if ($class === 'Symfony\Component\Messenger\Exception\MessageDecodingFailedException') {
            $nested = $message['encodedEnvelope']['body'] ?? null;
            return is_string($nested) ? self::inspect($nested, $depth + 1) : null;
        }
        if ($class !== 'Survos\StateBundle\Message\TransitionMessage'
            || ($message['className'] ?? null) !== 'App\Entity\Item'
            || ($message['workflow'] ?? null) !== 'item'
            || ($message['transitionName'] ?? null) !== 'suggest'
            || !is_scalar($message['id'] ?? null)
            || !ctype_digit((string) $message['id'])) {
            return null;
        }

        return ['itemId' => (string) $message['id'], 'transition' => 'suggest'];
    }
}
