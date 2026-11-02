<?php

return [
    'enabled' => (bool) env('TRACKING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Server-side quality thresholds
    |--------------------------------------------------------------------------
    |
    | These are defence-in-depth filters. The mobile application applies the
    | same rules before batching, but the server never trusts client filtering.
    |
    */

    'max_accepted_accuracy_meters' => (int) env('LOCATION_MAX_ACCEPTED_ACCURACY_METERS', 50),
    'min_segment_meters' => (int) env('DISTANCE_MIN_SEGMENT_METERS', 8),
    'max_speed_kmh' => (int) env('LOCATION_MAX_SPEED_KMH', 180),
    'max_gap_minutes' => (int) env('LOCATION_GAP_WARNING_MINUTES', 10),
    'history_max_days' => (int) env('LOCATION_HISTORY_MAX_DAYS', 30),
    'batch_max_points' => (int) env('TRACKING_BATCH_MAX_POINTS', 500),
    'stale_after_minutes' => (int) env('TRACKING_STALE_AFTER_MINUTES', 10),

    'gps_retention_days' => (int) env('GPS_RETENTION_DAYS', 180),
    'photo_retention_days' => (int) env('PHOTO_RETENTION_DAYS', 365),
    'enable_gps_archival' => (bool) env('ENABLE_GPS_ARCHIVAL', true),

    'photo' => [
        'max_upload_mb' => (int) env('PHOTO_MAX_UPLOAD_MB', 8),
        'max_width' => (int) env('PHOTO_MAX_WIDTH', 1600),
        'jpeg_quality' => (int) env('PHOTO_JPEG_QUALITY', 82),
        'thumbnail_width' => (int) env('PHOTO_THUMBNAIL_WIDTH', 320),
        'max_pixels' => (int) env('PHOTO_MAX_PIXELS', 24000000),
    ],
];
