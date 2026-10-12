<?php
/**
 * Static regression guard for WordPress dbDelta() index declaration layout.
 *
 * dbDelta splits table definitions by newline. Multiple indexes on one
 * line are interpreted as a malformed composite primary key. This test
 * never connects to WordPress or a database and never alters any data.
 */
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../includes/class-staycore-db.php');
if (!is_string($source)) {
    fwrite(STDERR, "Cannot load StayCore schema source.\n");
    exit(1);
}

preg_match_all('/dbDelta\\("CREATE TABLE.+?\\) \\$charset;"\\);/s', $source, $queries);
if (count($queries[0]) !== 8) {
    fwrite(STDERR, "Expected 8 dbDelta table definitions.\n");
    exit(1);
}

$primaryCount = 0;
$secondaryCount = 0;
foreach ($queries[0] as $tableIndex => $query) {
    $foundPrimary = 0;
    $foundSecondary = 0;
    foreach (explode("\n", $query) as $line) {
        $line = trim($line);
        if (strpos($line, 'PRIMARY KEY') === false &&
            !preg_match('/^(?:UNIQUE )?KEY /', $line)) {
            continue;
        }

        $validPrimary = preg_match('/^PRIMARY KEY  \\(id\\),?$/', $line) === 1;
        $validSecondary = preg_match(
            '/^(?:UNIQUE )?KEY [a-z_]+ \\([a-z_, ]+\\),?$/',
            $line
        ) === 1;
        if (!$validPrimary && !$validSecondary) {
            fwrite(STDERR, "Malformed dbDelta index in table " . ($tableIndex + 1) . ": $line\n");
            exit(1);
        }
        $foundPrimary += (int) $validPrimary;
        $foundSecondary += (int) $validSecondary;
    }
    if ($foundPrimary !== 1 || $foundSecondary < 1) {
        fwrite(STDERR, "Missing or duplicate index declaration in table " . ($tableIndex + 1) . "\n");
        exit(1);
    }
    $primaryCount += $foundPrimary;
    $secondaryCount += $foundSecondary;
}

if ($primaryCount !== 8 || $secondaryCount !== 21) {
    fwrite(STDERR, "Unexpected index count: primary=$primaryCount secondary=$secondaryCount\n");
    exit(1);
}

echo "PASS: 8 dbDelta schemas, 8 primary indexes and 21 separated secondary indexes.\n";
