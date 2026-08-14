<?php
/**
 * Quick Order — delivery geography, as the customer actually stated it.
 *
 * ┌────────────────────────────────────────────────────────────────────────┐
 * │  CAPTURE ONLY. THIS CLASS DECIDES WHETHER AN INPUT IS USABLE. IT NEVER │
 * │  DECIDES WHAT PLACE THE INPUT MEANS.                                   │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * WHAT WENT WRONG BEFORE
 *
 * The form had no city field and no postcode field. The JavaScript posted
 * `city = ''`, the controller turned that into `'-'`, and the address was
 * created with `postcode = '00000'`. Both look like geography and neither is:
 * 643 of this shop's 853 Moroccan addresses carry `-`, and 706 carry `00000`.
 *
 * A fabricated value is worse than a missing one. Missing data announces
 * itself; `00000` passes every structural test a postcode could be given, so
 * anything downstream that maps postcodes would map four fifths of the shop to
 * one place and call it coverage.
 *
 * THE RULES, AND WHY THEY ARE THIS LOOSE
 *
 * A Moroccan city is written `Salé`, `سلا`, `M'Diq`, `Sidi Slimane` or
 * `Aït Melloul`. Over-validating real names is the failure mode that makes a
 * customer abandon a checkout, so the city rule refuses only what cannot be a
 * place name: the empty string, the placeholders software writes, and
 * characters PrestaShop's own `Validate::isCityName()` would reject anyway.
 *
 * Spelling is NOT corrected. `Casa`, `casablanca` and `الدار البيضاء` are all
 * stored exactly as typed. Deciding that they name one place is a separate
 * job, done later, by something that can be argued with.
 *
 * NO TWIZA, NO RESOLVER, NO NETWORK
 *
 * This class names no other module and performs no lookup. Quick Order captures,
 * PrestaShop stores, and whatever interprets does so afterwards from the stored
 * address.
 *
 * @license Proprietary
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class QuickOrderAddressInput
{
    /** `ps_address`.`city` is VARCHAR(64). */
    const CITY_MAX = 64;

    /** `ps_address`.`postcode` is VARCHAR(12); Morocco uses five digits. */
    const POSTCODE_MAX = 12;

    /** Reason codes. The caller maps them to translated messages. */
    const OK = '';
    const CITY_EMPTY = 'city_empty';
    const CITY_PLACEHOLDER = 'city_placeholder';
    const CITY_TOO_SHORT = 'city_too_short';
    const CITY_INVALID_CHARACTERS = 'city_invalid_characters';
    const POSTCODE_FABRICATED = 'postcode_fabricated';
    const POSTCODE_MALFORMED = 'postcode_malformed';

    /**
     * Values that look like geography and are not.
     *
     * Every entry is either something this module used to write itself, or a
     * word customers type to mean "I did not answer". A real city name is
     * never added here on suspicion: an unrecognised name is still the
     * customer's answer, and this class has no dictionary to check it against.
     *
     * Compared after `foldForComparison()`, so `  -  ` and `-` agree.
     */
    private static $placeholderCities = [
        '-', '--', '---', '.', '..', '...', '_', '/', '\\',
        'city', 'ville', 'مدينة',
        'a definir', 'à définir', 'a preciser', 'à préciser',
        'na', 'n a', 'nc', 'none', 'null', 'undefined', 'unknown', 'inconnu',
        'maroc', 'morocco', 'المغرب', 'ma',
        'test', 'aaa', 'xxx', 'zzz',
    ];

    /**
     * Postcodes that are well-formed and still not answers.
     *
     * `00000` is the one this module wrote for every address it created. It is
     * five digits and would satisfy any format check, which is exactly why it
     * has to be named rather than filtered.
     */
    private static $fabricatedPostcodes = ['00000'];

    /* ═══════════════════════════ City ═══════════════════════════ */

    /**
     * The customer's own text, tidied but not rewritten.
     *
     * Only whitespace is touched: outer padding removed, runs collapsed,
     * invisible direction and zero-width marks dropped — all of which travel
     * in through copy-paste and none of which the customer meant to type.
     * Letters, accents, script and capitalisation are left exactly as given.
     */
    public static function normaliseCity($raw)
    {
        $value = (string) $raw;

        $value = str_replace([
            "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D",
            "\xE2\x80\x8E", "\xE2\x80\x8F", "\xEF\xBB\xBF",
        ], '', $value);

        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * A comparison form, used ONLY to recognise placeholders.
     *
     * Never stored. Lower-cased and stripped of punctuation so that `N/A`,
     * `n.a.` and `n a` are one thing; accents are folded so `à définir`
     * matches `a definir`.
     */
    public static function foldForComparison($raw)
    {
        $value = self::normaliseCity($raw);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);

        $value = strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    public static function isPlaceholderCity($raw)
    {
        $folded = self::foldForComparison($raw);

        if ($folded === '') {
            return true;
        }

        return in_array($folded, self::$placeholderCities, true);
    }

    /**
     * Is this usable as a delivery city?
     *
     * @return array{ok: bool, value: string, error: string}
     */
    public static function validateCity($raw)
    {
        $value = self::normaliseCity($raw);

        if ($value === '') {
            return ['ok' => false, 'value' => '', 'error' => self::CITY_EMPTY];
        }

        if (self::isPlaceholderCity($value)) {
            return ['ok' => false, 'value' => '', 'error' => self::CITY_PLACEHOLDER];
        }

        /* Counted on letters and digits, so `A.` is short and `سلا` is not.
           Two characters is the shortest real Moroccan locality name. */
        $meaningful = preg_replace('/[^\p{L}\p{N}]+/u', '', $value);
        $length = function_exists('mb_strlen')
            ? mb_strlen((string) $meaningful, 'UTF-8') : strlen((string) $meaningful);

        if ($length < 2) {
            return ['ok' => false, 'value' => '', 'error' => self::CITY_TOO_SHORT];
        }

        /* PrestaShop's own rule, applied here so the customer sees a field
           error instead of the address silently failing to save later. It
           forbids `!<>;?=+@#"°{}_$%` and nothing else — every letter of every
           script is allowed, which is the point. */
        if (!preg_match('/^[^!<>;?=+@#"°{}_$%]*$/u', $value)) {
            return ['ok' => false, 'value' => '', 'error' => self::CITY_INVALID_CHARACTERS];
        }

        if ($length > self::CITY_MAX) {
            $value = function_exists('mb_substr')
                ? mb_substr($value, 0, self::CITY_MAX, 'UTF-8') : substr($value, 0, self::CITY_MAX);
        }

        return ['ok' => true, 'value' => $value, 'error' => self::OK];
    }

    /* ═══════════════════════════ Postcode ═══════════════════════════ */

    /**
     * Digits only, with Arabic-Indic numerals folded to ASCII.
     *
     * Folding the numerals is notation, not interpretation: `٢٠٠٠٠` and
     * `20000` are one number typed on two keyboards. Spaces and dashes are
     * removed because people write `20 000`.
     */
    public static function normalisePostcode($raw)
    {
        $value = trim((string) $raw);

        $value = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩',
                '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value
        );

        $value = preg_replace('/[\s\-–—]/u', '', $value);

        return (string) $value;
    }

    public static function isFabricatedPostcode($raw)
    {
        return in_array(self::normalisePostcode($raw), self::$fabricatedPostcodes, true);
    }

    /**
     * Is this usable as a delivery postcode?
     *
     * ┌──────────────────────────────────────────────────────────────────┐
     * │  AN EMPTY POSTCODE IS A VALID ANSWER, AND `00000` IS NOT.         │
     * └──────────────────────────────────────────────────────────────────┘
     *
     * PrestaShop agrees: `Address` does not mark `postcode` required, and
     * `Validate::isPostCode()` opens with `empty($postcode) || …`. So the
     * honest representation of "the customer did not give one" is the empty
     * string, which is what a customer who does not know their postcode
     * deserves — not a number invented on their behalf.
     *
     * @param string      $raw
     * @param string|null $format the country's own `zip_code_format`, e.g. `NNNNN`
     *
     * @return array{ok: bool, value: string, error: string}
     */
    public static function validatePostcode($raw, $format = null)
    {
        $value = self::normalisePostcode($raw);

        if ($value === '') {
            /* Absent, truthfully. */
            return ['ok' => true, 'value' => '', 'error' => self::OK];
        }

        if (self::isFabricatedPostcode($value)) {
            return ['ok' => false, 'value' => '', 'error' => self::POSTCODE_FABRICATED];
        }

        if (strlen($value) > self::POSTCODE_MAX) {
            return ['ok' => false, 'value' => '', 'error' => self::POSTCODE_MALFORMED];
        }

        /* The country's declared format, never a constant. Morocco says
           `NNNNN` on this installation; a shop that later enables another
           country gets that country's rule without an edit here. */
        if (!self::matchesFormat($value, $format)) {
            return ['ok' => false, 'value' => '', 'error' => self::POSTCODE_MALFORMED];
        }

        return ['ok' => true, 'value' => $value, 'error' => self::OK];
    }

    /**
     * PrestaShop's own `Country::checkZipCode()` rule, restated without needing
     * a loaded Country — so it can be tested and so a missing format means
     * "no constraint" rather than "reject everything".
     */
    public static function matchesFormat($value, $format)
    {
        $format = trim((string) $format);

        if ($format === '') {
            return (bool) preg_match('/^[a-zA-Z 0-9-]+$/', (string) $value);
        }

        $pattern = '/^' . $format . '$/ui';
        $pattern = str_replace('N', '[0-9]', $pattern);
        $pattern = str_replace('L', '[a-zA-Z]', $pattern);

        /* `C` means the ISO code in PrestaShop's format language. Quick Order
           never sees one in Moroccan format, and substituting a wrong ISO
           would silently reject valid input — so a format using it falls back
           to the permissive rule rather than guessing. */
        if (strpos($pattern, 'C') !== false) {
            return (bool) preg_match('/^[a-zA-Z 0-9-]+$/', (string) $value);
        }

        return (bool) preg_match($pattern, (string) $value);
    }

    /* ═══════════════════════════ Together ═══════════════════════════ */

    /**
     * Both fields, with the first failure reported.
     *
     * @return array{ok: bool, city: string, postcode: string, field: string, error: string}
     */
    public static function validate($rawCity, $rawPostcode, $format = null)
    {
        $city = self::validateCity($rawCity);

        if (!$city['ok']) {
            return ['ok' => false, 'city' => '', 'postcode' => '',
                'field' => 'city', 'error' => $city['error']];
        }

        $postcode = self::validatePostcode($rawPostcode, $format);

        if (!$postcode['ok']) {
            return ['ok' => false, 'city' => $city['value'], 'postcode' => '',
                'field' => 'postcode', 'error' => $postcode['error']];
        }

        return ['ok' => true, 'city' => $city['value'], 'postcode' => $postcode['value'],
            'field' => '', 'error' => self::OK];
    }
}
