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

    /**
     * First code point of every decimal-digit block the canonicaliser knows.
     *
     * Every one of them is a run of ten consecutive code points whose last nine
     * are the digits `0` to `9`, so a single base plus its offset is enough to
     * transliterate the block: `base + 7` is a seven wherever it is written.
     */
    private const DIGIT_BLOCKS = [
        0x0660, // Arabic-Indic          ٠١٢٣
        0x06F0, // Extended Arabic-Indic ۰۱۲
        0x07C0, // NKo                   ߀߁
        0x0966, // Devanagari            ०१२
        0x09E6, // Bengali              ০১২
        0x0A66, // Gurmukhi             ੦੧
        0x0AE6, // Gujarati             ૦૧
        0x0B66, // Oriya                 ୦୧
        0x0BE6, // Tamil                 ௦௧
        0x0C66, // Telugu                ౦౧
        0x0CE6, // Kannada               ೦೧
        0x0D66, // Malayalam           ൦൧
        0x0E50, // Thai                   ๐๑
        0x0ED0, // Lao                    ໐໑
        0x0F20, // Tibetan                 ༠༡
        0x1040, // Myanmar                ႐႑
        0x17E0, // Khmer                   ០១
        0x1810, // Mongolian               ᠐᠑
        0xFF10, // Fullwidth              ０１
    ];

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
    /**
     * Rewrites every unicode decimal digit as its ASCII counterpart.
     *
     * A digit from a block this class does not know is left untouched instead
     * of being guessed at, and that is deliberate: canonicalisation that merges
     * two different people is a worse bug than the one it fixes, so an
     * unrecognised script keeps its own key rather than borrowing another one.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function toAsciiDigits(string $value): string
    {
        return (string)preg_replace_callback('/\p{Nd}/u', static function (array $matches): string {
            $codepoint = self::codepointOf($matches[0]);

            foreach (self::DIGIT_BLOCKS as $base) {
                if ($codepoint >= $base && $codepoint <= $base + 9) {
                    return (string)($codepoint - $base);
                }
            }

            return $matches[0];
        }, $value);
    }

    /** Code point of a single UTF-8 character, via UCS-4BE. */
    private static function codepointOf(string $character): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('N', str_pad(mb_convert_encoding($character, 'UCS-4BE', 'UTF-8'), 4, "\0", STR_PAD_LEFT));

        return $unpacked[1];
    }

    private static function canonicalKey(string $trimmed): string
    {
        $lowered = mb_strtolower($trimmed);

        if (str_contains($lowered, '@')) {
            return $lowered;
        }

        // Only ASCII `0`-`9` survive, and that is the whole point of the class
        // name: the canonical form of a phone number is ASCII digits and
        // nothing else.
        //
        // The obvious `\D+` under the unicode flag is NOT equivalent, and it
        // failed in production-shaped input. PHP's `u` modifier turns on
        // `PCRE_UCP`, where `\d` matches ANY unicode decimal digit, so `\D+`
        // KEEPS the fullwidth digits (U+FF10..U+FF19), the arabic-indic ones
        // (U+0660..U+0669) and the devanagari ones. A contact typed with those
        // glyphs then canonicalised to itself instead of to its ASCII twin, and
        // the duplicate rule of RF-REF-11 opened a second live case for the very
        // same person: two envelopes, two payments, one complaint.
        //
        // They have to be TRANSLITERATED rather than simply dropped: a contact
        // written entirely in fullwidth digits has no ASCII digit to keep, so
        // dropping them leaves nothing and the identity collapses to the raw
        // text, which is the same hole with a different symptom. Digits are
        // therefore folded to ASCII first, and only the separators go after.
        //
        // An explicit ASCII class says what it means and cannot be widened by a
        // regex flag. It still strips every separator family the naive version
        // missed: ASCII spaces, tabs, the non-breaking space (U+00A0) and the
        // thin spaces, plus hyphens, underscores and dots in any spelling.
        $digits = (string)preg_replace('/[^0-9]/', '', self::toAsciiDigits($lowered));

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