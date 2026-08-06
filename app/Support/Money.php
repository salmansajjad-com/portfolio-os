<?php

namespace App\Support;

/**
 * Integer money helpers — never float intermediate for persisted amounts.
 *
 * Amounts are stored as minor units of the configured currency (config/money.php).
 * "Minor units" means cents, pence, paisa, etc. — whatever `exponent` says.
 */
class Money
{
    public const FX_SCALE = 1_000_000;

    /**
     * Base-currency major units string → minor units.
     */
    public static function toMinor(string|float|int $major, ?int $exponent = null): int
    {
        return static::parseMinor($major, $exponent ?? Currency::exponent());
    }

    /**
     * Source-currency major units string → minor units.
     */
    public static function sourceToMinor(string|float|int $major): int
    {
        return static::parseMinor($major, Currency::sourceExponent());
    }

    /**
     * Minor units → plain major-unit string ("1234.56"), no grouping, no code.
     */
    public static function fromMinor(int $minor, ?int $exponent = null): string
    {
        $exponent ??= Currency::exponent();
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        if ($exponent === 0) {
            return $sign.$abs;
        }

        $subunits = 10 ** $exponent;

        return $sign.intdiv($abs, $subunits).'.'.str_pad((string) ($abs % $subunits), $exponent, '0', STR_PAD_LEFT);
    }

    public static function fromSourceMinor(int $minor): string
    {
        return static::fromMinor($minor, Currency::sourceExponent());
    }

    /**
     * Minor units → grouped, currency-suffixed display string ("1,234.56 USD").
     */
    public static function formatted(int $minor, ?string $currency = null, ?int $exponent = null): string
    {
        $exponent ??= Currency::exponent();
        $currency ??= Currency::code();

        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        $subunits = 10 ** $exponent;

        $amount = number_format(intdiv($abs, $subunits));
        if ($exponent > 0) {
            $amount .= '.'.str_pad((string) ($abs % $subunits), $exponent, '0', STR_PAD_LEFT);
        }

        return trim($sign.$amount.' '.$currency);
    }

    /**
     * Minor units → grouped whole major units ("1,235"), for compact stat tiles.
     */
    public static function rounded(int $minor): string
    {
        $subunits = Currency::subunits();
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign.number_format(intdiv($abs + intdiv($subunits, 2), $subunits));
    }

    /**
     * Human FX rate "278.50" → e6 integer (278_500_000).
     */
    public static function fxRateToE6(string|float|int $rate): int
    {
        $normalized = static::normalize($rate);

        if ($normalized === null) {
            return 0;
        }

        if (! str_contains($normalized, '.')) {
            return (int) $normalized * self::FX_SCALE;
        }

        [$whole, $frac] = explode('.', $normalized, 2);
        $frac = substr(str_pad(preg_replace('/\D/', '', $frac) ?? '', 6, '0'), 0, 6);
        $whole = ltrim($whole, '+-');

        return ((int) $whole * self::FX_SCALE) + (int) $frac;
    }

    public static function fxRateFromE6(int $e6): string
    {
        $whole = intdiv($e6, self::FX_SCALE);
        $frac = str_pad((string) ($e6 % self::FX_SCALE), 6, '0', STR_PAD_LEFT);
        $frac = rtrim($frac, '0');
        if ($frac === '') {
            return (string) $whole;
        }

        return $whole.'.'.$frac;
    }

    /**
     * Source-currency minor units × frozen FX rate → base-currency minor units.
     *
     * base = source_minor × (rate_e6 / FX_SCALE) × (base_subunits / source_subunits)
     *
     * Kept in integer arithmetic and rounded half-up. The subunit ratio is only
     * applied when the two currencies differ in exponent, so the common case
     * keeps its full integer headroom.
     */
    public static function sourceMinorToBaseMinor(int $sourceMinor, int $fxRateE6): int
    {
        if ($sourceMinor === 0 || $fxRateE6 === 0) {
            return 0;
        }

        $baseSubunits = Currency::subunits();
        $sourceSubunits = Currency::sourceSubunits();

        if ($baseSubunits === $sourceSubunits) {
            $baseSubunits = $sourceSubunits = 1;
        }

        $numerator = $sourceMinor * $fxRateE6 * $baseSubunits;
        $denominator = self::FX_SCALE * $sourceSubunits;

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    /**
     * Basis points of amount (10000 bps = 100%).
     */
    public static function applyBps(int $amount, int $bps): int
    {
        if ($amount === 0 || $bps === 0) {
            return 0;
        }

        $product = $amount * $bps;

        return intdiv($product + 5000, 10000);
    }

    /**
     * Split an amount across basis-point shares so the parts always sum back to it.
     *
     * Rounding each share independently with applyBps() can over- or under-pay:
     * two 50% owners of 1 minor unit would each round to 1, paying out double.
     * This floors every share and hands the leftover units to the largest
     * fractional remainders (Hamilton apportionment), so nothing is created or lost.
     *
     * @param  array<array-key, int>  $bpsByKey  basis points keyed however the caller needs
     * @return array<array-key, int> the same keys, mapped to minor units
     */
    public static function allocateBps(int $amount, array $bpsByKey): array
    {
        if ($bpsByKey === []) {
            return [];
        }

        $totalBps = array_sum($bpsByKey);

        if ($amount === 0 || $totalBps <= 0) {
            return array_map(fn () => 0, $bpsByKey);
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($bpsByKey as $key => $bps) {
            $bps = max(0, $bps);
            $product = $amount * $bps;
            $shares[$key] = intdiv($product, $totalBps);
            $remainders[$key] = $product % $totalBps;
            $allocated += $shares[$key];
        }

        // Hand out what flooring left behind, largest remainder first. Ties go to
        // the earlier key so the same inputs always produce the same split.
        $leftover = $amount - $allocated;

        if ($leftover > 0) {
            $order = array_keys($remainders);
            usort($order, function ($a, $b) use ($remainders, $bpsByKey) {
                return $remainders[$b] <=> $remainders[$a]
                    ?: $bpsByKey[$b] <=> $bpsByKey[$a]
                    ?: array_search($a, array_keys($bpsByKey), true) <=> array_search($b, array_keys($bpsByKey), true);
            });

            foreach ($order as $key) {
                if ($leftover <= 0) {
                    break;
                }

                $shares[$key]++;
                $leftover--;
            }
        }

        return $shares;
    }

    protected static function parseMinor(string|float|int $major, int $exponent): int
    {
        $normalized = static::normalize($major);

        if ($normalized === null) {
            return 0;
        }

        $subunits = 10 ** $exponent;

        if (! str_contains($normalized, '.')) {
            return (int) $normalized * $subunits;
        }

        [$whole, $frac] = explode('.', $normalized, 2);
        $sign = str_starts_with($whole, '-') ? -1 : 1;
        $whole = ltrim($whole, '+-');

        if ($exponent === 0) {
            return $sign * (int) $whole;
        }

        $frac = substr(str_pad(preg_replace('/\D/', '', $frac) ?? '', $exponent, '0'), 0, $exponent);

        return $sign * (((int) $whole * $subunits) + (int) $frac);
    }

    protected static function normalize(string|float|int $value): ?string
    {
        $normalized = is_string($value)
            ? str_replace([',', ' '], '', trim($value))
            : (string) $value;

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return $normalized;
    }
}
