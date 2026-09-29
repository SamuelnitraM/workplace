<?php

namespace App\Security;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Password rules of every form (registration, change, reset): at least 8 characters with an uppercase letter,
 * a lowercase letter, a digit and a symbol. With the login throttling (5 attempts a minute), this follows the
 * CNIL recommendation for passwords of 8 characters mixing four kinds of characters.
 * RULES also feed the live checklist of the registration form (pattern rules of assets/controllers/field_rules_controller.js).
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 4096;

    /** Pattern (JavaScript and PCRE compatible) => label of the rule. */
    public const RULES = [
        '[A-Z]' => 'Une lettre majuscule',
        '[a-z]' => 'Une lettre minuscule',
        '[0-9]' => 'Un chiffre',
        '[^A-Za-z0-9]' => 'Un symbole (par exemple ! ? # @ %)',
    ];

    /** Rules in one sentence, shown under the new password fields. */
    public static function summary(): string
    {
        return sprintf('Au moins %d caractères, avec une majuscule, une minuscule, un chiffre et un symbole.', self::MIN_LENGTH);
    }

    /** @return list<Constraint> */
    public static function constraints(string $blankMessage): array
    {
        $constraints = [
            new NotBlank(message: $blankMessage),
            new Length(
                min: self::MIN_LENGTH,
                minMessage: 'Ton mot de passe doit contenir au moins {{ limit }} caractères',
                max: self::MAX_LENGTH,
            ),
        ];
        foreach (self::RULES as $pattern => $label) {
            $constraints[] = new Regex(
                pattern: '/' . $pattern . '/u',
                message: sprintf('Ton mot de passe doit contenir : %s.', mb_strtolower($label)),
            );
        }
        return $constraints;
    }
}
