<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * ClaimantIdentity
 *
 * The canonical form of a claimant's contact channel, and the answer to one
 * question: are these two typed contacts the same person? (RF-REF-11)
 *
 * ## Why this exists at all
 * The duplicate rule compares contacts to stop the same consumer from opening
 * two live cases on one incident. The first version of that comparison stripped
 * spaces, hyphens and dots from the typed text, which sounds thorough and is
 * not: "+34 600111222", a non-breaking space and an underscore all sailed
 * straight through and opened a second payable case for the same human. A
 * defence that a one-line change defeats is not a defence.
 *
 * ## The two canonical forms
 * - An **email** keeps its dots and signs, because they are part of the address.
 *   Only the case and the surrounding whitespace go.
 * - A **phone** collapses to its digits, with the Spanish country prefix
 *   removed, because the prefix is not part of the subscriber's identity. Only
 *   when exactly nine digits remain is a leading `34` treated as a prefix, so a
 *   different number that happens to start with those digits keeps its own key.
 *
 * ## What is NOT canonicalised
 * The contact as STORED stays exactly as the person typed it: the reception
 * desk has to be able to read it and use it to reach them. Canonicalisation
 * happens only in the comparison key, never in the database.
 */
final readonly class ClaimantIdentity
{
    /** Spanish country prefix in its three usual spellings, longest first. */
    private const COUNTRY_PREFIXES = ['0034', '+34', '34'];

    /** Length of a Spanish subscriber number once the prefix is gone. */
    private const NATIONAL_LENGTH = 9;

    private function __construct(
        private string $typedContact,
        private string $key
    ) {
    }

    /**
     * Folds a free-text contact channel into a comparable identity.
     *
     * @throws InvalidArgumentException When the channel is blank.
     */
    public static function from(string $contact): self
    {
        $trimmed = trim($contact);

        if ($trimmed === '') {
            throw new InvalidArgumentException('El medio de contacto no puede estar vacío.');
        }

        return new self($trimmed, self::canonicalKey($trimmed));
    }

    /**
     * Whether both contacts belong to the same claimant.
     */
    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }

    /**
     * The comparison key. Deliberately not a hash: it never leaves the process,
     * and a readable key is what makes a failed comparison debuggable.
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * The contact as the person typed it, which is what gets stored and shown.
     */
    public function typedContact(): string
    {
        return $this->typedContact;
    }

    /**
     * Canonicalisation rules, in the order the specification states them.
     */
    private static function canonicalKey(string $trimmed): string
    {
        $lowered = mb_strtolower($trimmed);

        if (str_contains($lowered, '@')) {
            return $lowered;
        }

        // `\D` under the unicode flag removes every non-digit, which covers the
        // separator families the naive version missed: ASCII spaces, tabs, the
        // non-breaking space (U+00A0) and the thin spaces, plus hyphens,
        // underscores and dots in any of their unicode spellings.
        $digits = (string)preg_replace('/\D+/u', '', $lowered);

        if ($digits === '') {
            // A contact with no digits and no `@` (a nickname, a typo) must not
            // collapse to the empty key, or every such contact would look like
            // every other one.
            return $lowered;
        }

        foreach (self::COUNTRY_PREFIXES as $prefix) {
            $candidate = $digits;

            if (str_starts_with($candidate, $prefix)) {
                $candidate = substr($candidate, strlen($prefix));
            }

            // Only strip the prefix when what remains is a full national number.
            // A bare `34 123 456` is seven digits and keeps its digits.
            if ($candidate !== '' && strlen($candidate) === self::NATIONAL_LENGTH) {
                return $candidate;
            }
        }

        return $digits;
    }
}