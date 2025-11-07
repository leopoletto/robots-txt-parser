<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Leopoletto\RobotsTxtParser\RobotsTxtParser;

$parser = new RobotsTxtParser();

$responseRecords = $parser->parseFile('robots.txt')->records();

// Test without user agent (should be deduplicated)
$disallowed = $responseRecords->disallowed()->toArray();
$allowed = $responseRecords->allowed()->toArray();

echo "=== Without user agent filter ===\n";
echo "Disallowed count: " . count($disallowed) . "\n";
echo "Allowed count: " . count($allowed) . "\n\n";

// Test with user agent '*' (should return all directives)
$disallowedStar = $responseRecords->disallowed('*')->toArray();
$allowedStar = $responseRecords->allowed('*')->toArray();

echo "=== With user agent '*' ===\n";
echo "Disallowed count: " . count($disallowedStar) . "\n";
echo "Allowed count: " . count($allowedStar) . "\n\n";

// Test with user agent 'GPT-User' (should return all directives, same as '*')
$disallowedGPT = $responseRecords->disallowed('GPT-User')->toArray();
$allowedGPT = $responseRecords->allowed('GPT-User')->toArray();

echo "=== With user agent 'GPT-User' ===\n";
echo "Disallowed count: " . count($disallowedGPT) . "\n";
echo "Allowed count: " . count($allowedGPT) . "\n\n";

// Verify they're the same (since both user agents are in the same group)
echo "=== Verification ===\n";
echo "Disallowed '*' == 'GPT-User': " . (count($disallowedStar) === count($disallowedGPT) ? "YES" : "NO") . "\n";
echo "Allowed '*' == 'GPT-User': " . (count($allowedStar) === count($allowedGPT) ? "YES" : "NO") . "\n";

