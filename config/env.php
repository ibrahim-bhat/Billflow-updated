<?php
// Simple .env loader: parses key=value lines and populates getenv/$_ENV
// Usage: require_once __DIR__ . '/../config/env.php';

$rootDir = dirname(__DIR__);
$envFile = $rootDir . DIRECTORY_SEPARATOR . '.env';

if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);
                // Remove optional quotes
                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }
                if (!array_key_exists($key, $_ENV)) {
                    $_ENV[$key] = $val;
                }
                if (!getenv($key)) {
                    putenv($key . '=' . $val);
                }
            }
        }
    }
}
?>


