<?php

class RateLimitService
{
    public static function allow(string $bucket, int $limit, int $windowSeconds): bool
    {
        $path = __DIR__ . '/../../storage/rate_limits';
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            return false;
        }

        $file = $path . '/' . hash('sha256', $bucket) . '.json';
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $raw = stream_get_contents($handle);
            $record = json_decode($raw ?: '', true);
            $now = time();
            if (!is_array($record) || (int)($record['reset_at'] ?? 0) <= $now) {
                $record = ['count' => 0, 'reset_at' => $now + $windowSeconds];
            }

            if ((int)$record['count'] >= $limit) {
                return false;
            }

            $record['count'] = (int)$record['count'] + 1;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($record, JSON_UNESCAPED_SLASHES));
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
