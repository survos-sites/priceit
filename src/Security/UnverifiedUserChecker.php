<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuses a login until the address has been confirmed.
 *
 * Without this, "email confirmation" is decorative: the scaffold mails a link, and nothing
 * anywhere requires it to have been clicked. The flag gets written and never read.
 *
 * checkPreAuth, not checkPostAuth: a wrong password and an unconfirmed address should not be
 * distinguishable from outside, and checking before the credentials are verified means the
 * response says nothing about whether the account exists.
 */
final class UnverifiedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isVerified()) {
            // A message the operator can act on. The generic "invalid credentials" would send
            // someone to reset a password that was never wrong.
            throw new CustomUserMessageAccountStatusException(
                'Confirm your email address before signing in — check your inbox for the link.',
            );
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
