<?php
if (!defined('ABSPATH')) exit;

/**
 * Deterministic extension pricing. All timestamps are in the WordPress site's timezone.
 * This class has no database side effects and can be tested separately.
 */
final class StayCore_Booking_Extend {
    public static function quote(string $current_checkout, string $new_date, $nightly_rate) {
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D', $new_date)) {
            return new WP_Error('bad_extension_date', 'Select a valid new checkout date.', ['status'=>400]);
        }

        try {
            $old = new DateTimeImmutable($current_checkout, wp_timezone());
            $old_date = $old->format('Y-m-d');
            $new = new DateTimeImmutable($new_date . ' ' . $old->format('H:i:s'), wp_timezone());
            if ($new->format('Y-m-d') !== $new_date) throw new Exception('Invalid date');
        } catch (Exception $e) {
            return new WP_Error('bad_extension_date', 'Select a valid new checkout date.', ['status'=>400]);
        }

        if ($new_date <= $old_date) {
            return new WP_Error('not_extension', 'New checkout must be at least one day after the current checkout date.', ['status'=>400]);
        }
        $nights = (int)(new DateTimeImmutable($old_date, wp_timezone()))->diff(
            new DateTimeImmutable($new_date, wp_timezone())
        )->days;
        if ($nights < 1 || $nights > 90) {
            return new WP_Error('extension_limit', 'Extend by 1–90 nights per change.', ['status'=>400]);
        }

        $raw = is_scalar($nightly_rate) ? trim((string)$nightly_rate) : '';
        if (!preg_match('/^\\d+(?:\\.\\d{1,2})?$/D', $raw)) {
            return new WP_Error('bad_extension_rate', 'Enter the agreed nightly rate in rupees (maximum two decimals).', ['status'=>400]);
        }
        $rate = (float)$raw;
        if (!is_finite($rate) || $rate < 0 || $rate > 1000000) {
            return new WP_Error('bad_extension_rate', 'The nightly rate is outside the allowed range.', ['status'=>400]);
        }
        $charge = round($rate * $nights, 2);
        return [
            'new_checkout' => $new->format('Y-m-d H:i:s'),
            'old_checkout' => $old->format('Y-m-d H:i:s'),
            'nights' => $nights,
            'nightly_rate' => $rate,
            'additional_charge' => $charge,
        ];
    }
}
