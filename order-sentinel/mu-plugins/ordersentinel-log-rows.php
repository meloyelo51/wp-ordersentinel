<?php
/**
 * OrderSentinel – temp cap for live log rows
 */
add_filter('ordersentinel_rest_live_rows', function ($n) {
    return 100; // set your preferred row count
});
