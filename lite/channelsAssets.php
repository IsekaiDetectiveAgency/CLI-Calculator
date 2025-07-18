<?php

declare(strict_types=1);

/**
 * Stage 1: Asset & HTML/Subscription Fetcher (Fully Parallel Version)
 * - Fetches all channel HTML pages and subscription links in a single, large parallel batch.
 * - Caches raw HTML and processes assets.
 * - Supports `subscription_url` in channelsAssets.json for channels without a public page.
 * - Integrates an external JSON file of pre-fetched configs.
 */

// --- Setup ---
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/functions.php';

// --- Configuration Constants ---
const INPUT_FILE = __DIR__ . '/channelsData/channelsAssets.json';
const FINAL_ASSETS_DIR = __DIR__ . '/channelsData';
const TEMP_BUILD_DIR = __DIR__ . '/temp_build';
const HTML_CACHE_DIR = TEMP_BUILD_DIR . '/html_cache';
const LOGOS_DIR_NAME = 'logos';
const GITHUB_LOGO_BASE_URL = 'https://raw.githubusercontent.com/itsyebekhe/PSG/main/channelsData/logos';
// --- NEW: URL for the private configs generated by the other script ---
const PRIVATE_CONFIGS_URL = 'https://raw.githubusercontent.com/itsyebekhe/PSGP/main/private_configs.json';


// --- 1. Initial Checks and Setup ---
echo "--- STAGE 1: ASSET & CONTENT FETCHER (FULLY PARALLEL) ---" . PHP_EOL;
echo "1. Initializing and loading source data..." . PHP_EOL;

if (!file_exists(INPUT_FILE)) {
    die('Error: channelsAssets.json not found.' . PHP_EOL);
}
$sourcesData = json_decode(file_get_contents(INPUT_FILE), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die('Error: Failed to decode channelsAssets.json.' . PHP_EOL);
}
$sourcesToProcess = array_keys($sourcesData);
$totalSources = count($sourcesToProcess);

if (is_dir(TEMP_BUILD_DIR)) {
    deleteFolder(TEMP_BUILD_DIR);
}
mkdir(HTML_CACHE_DIR, 0775, true);
mkdir(TEMP_BUILD_DIR . '/' . LOGOS_DIR_NAME, 0775, true);


// --- 2. Build URL List and Fetch All Content in a Single Parallel Batch ---
echo "\n2. Building content list and fetching all {$totalSources} sources in parallel..." . PHP_EOL;

$urls_to_fetch = [];
foreach ($sourcesToProcess as $source) {
    if (isset($sourcesData[$source]['subscription_url']) && !empty($sourcesData[$source]['subscription_url'])) {
        $urls_to_fetch[$source] = $sourcesData[$source]['subscription_url'];
        echo "  - {$source} (Subscription URL)" . PHP_EOL;
    } else {
        $urls_to_fetch[$source] = "https://t.me/s/" . $source;
    }
}
$fetched_content_data = fetch_multiple_urls_parallel($urls_to_fetch);
echo "\nFinished fetching content. Total successful fetches: " . count($fetched_content_data) . " of {$totalSources}" . PHP_EOL;


// --- 3. Save HTML to Cache and Process Assets ---
echo "\n3. Caching content and processing assets from Telegram/Subscriptions..." . PHP_EOL;

$channelArray = [];
$logo_urls_to_fetch = [];
$processedCount = 0;

foreach ($sourcesToProcess as $source) {
    print_progress(++$processedCount, $totalSources, 'Processing:');

    if (!isset($fetched_content_data[$source]) || empty($fetched_content_data[$source])) {
        $channelArray[$source] = $sourcesData[$source] ?? ['types' => [], 'title' => 'Unknown (Fetch Failed)', 'logo' => ''];
        unset($channelArray[$source]['subscription_url']);
        continue;
    }

    $content = $fetched_content_data[$source];
    $foundTypes = [];

    if (isset($sourcesData[$source]['subscription_url']) && !empty($sourcesData[$source]['subscription_url'])) {
        $decoded_content = base64_decode($content, true);
        if ($decoded_content === false) {
            echo "Warning: Failed to base64-decode subscription content for '{$source}'. Skipping type detection." . PHP_EOL;
            $channelArray[$source]['types'] = [];
        } else {
            $links = preg_split('/\r\n|\r|\n/', $decoded_content);
            foreach ($links as $link) {
                $type = detect_type(trim($link));
                if ($type) {
                    $foundTypes[$type] = true;
                }
            }
            $channelArray[$source]['types'] = array_keys($foundTypes);
        }
        $channelArray[$source]['title'] = $sourcesData[$source]['title'] ?? $source;
        $channelArray[$source]['logo'] = $sourcesData[$source]['logo'] ?? '';
    } else {
        $html = $content;
        file_put_contents(HTML_CACHE_DIR . '/' . $source . '.html', $html);
        $links = extractLinksByType($html);
        foreach ($links as $link) {
            $type = detect_type($link);
            if ($type) {
                $foundTypes[$type] = true;
            }
        }
        $channelArray[$source]['types'] = array_keys($foundTypes);
        preg_match('#<meta property="twitter:title" content="(.*?)">#', $html, $title_match);
        preg_match('#<meta property="twitter:image" content="(.*?)">#', $html, $image_match);
        $channelArray[$source]['title'] = $title_match[1] ?? 'Unknown Title';
        if (isset($image_match[1]) && !empty($image_match[1])) {
            $logo_urls_to_fetch[$source] = $image_match[1];
            $channelArray[$source]['logo'] = GITHUB_LOGO_BASE_URL . '/' . $source . ".jpg";
        } else {
            $channelArray[$source]['logo'] = '';
        }
    }
}
echo PHP_EOL;

// --- 4. Fetch All Logo Images in a Single Parallel Batch ---
if (!empty($logo_urls_to_fetch)) {
    echo "\n4. Fetching " . count($logo_urls_to_fetch) . " new logo images in parallel..." . PHP_EOL;
    $fetched_logo_data = fetch_multiple_urls_parallel($logo_urls_to_fetch);

    foreach ($fetched_logo_data as $source => $imageData) {
        file_put_contents(TEMP_BUILD_DIR . '/' . LOGOS_DIR_NAME . '/' . $source . '.jpg', $imageData);
    }
    echo "\nLogo downloads complete." . PHP_EOL;
} else {
    echo "\n4. No new logos to fetch." . PHP_EOL;
}


// --- NEW: STAGE 4.5 ---
// Integrate data from the private config file generated by the other script.
echo "\n4.5. Integrating data from private_configs.json..." . PHP_EOL;

// Use @ to suppress warnings on failure, which we'll handle manually.
$privateConfigsJson = @file_get_contents(PRIVATE_CONFIGS_URL);

if ($privateConfigsJson === false) {
    echo "  - WARNING: Could not fetch private_configs.json from the URL. Skipping this step." . PHP_EOL;
} else {
    $privateConfigsData = json_decode($privateConfigsJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "  - WARNING: The fetched private_configs.json is not valid JSON. Skipping this step." . PHP_EOL;
    } else {
        echo "  - Successfully fetched and parsed private configs. Merging data..." . PHP_EOL;
        
        foreach ($privateConfigsData as $channelName => $configs) {
            if (!is_array($configs) || empty($configs)) {
                continue; // Skip if a channel has no configs.
            }

            // Detect all unique protocol types from the provided links.
            $privateTypes = [];
            foreach ($configs as $link) {
                $type = detect_type(trim($link));
                if ($type) {
                    $privateTypes[$type] = true;
                }
            }
            $privateTypes = array_keys($privateTypes);
            sort($privateTypes);

            if (empty($privateTypes)) {
                continue; // No valid types found.
            }
            
            // Check if this channel was already processed from scraping.
            if (isset($channelArray[$channelName])) {
                // Channel exists. Merge the types, ensuring no duplicates.
                $mergedTypes = array_unique(array_merge($channelArray[$channelName]['types'], $privateTypes));
                sort($mergedTypes);
                $channelArray[$channelName]['types'] = $mergedTypes;
                echo "    - Updated channel: '{$channelName}' with types: " . implode(', ', $mergedTypes) . PHP_EOL;
            } else {
                // This is a new channel found only in the private configs. Add it.
                $channelArray[$channelName] = [
                    'title' => $channelName, // Use the key as the title
                    'logo' => '',            // No logo is available
                    'types' => $privateTypes,
                ];
                echo "    - Added new channel: '{$channelName}' with types: " . implode(', ', $privateTypes) . PHP_EOL;
            }
        }
        echo "  - Finished merging private config data." . PHP_EOL;
    }
}


// --- 5. Finalize, Write JSON, and Perform Atomic Swap ---
echo "\n5. Writing new assets file and swapping directories..." . PHP_EOL;
// Sort the final array by channel name (keys) for consistent output
ksort($channelArray);
$jsonOutput = json_encode($channelArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents(TEMP_BUILD_DIR . '/channelsAssets.json', $jsonOutput);

if (is_dir(FINAL_ASSETS_DIR)) {
    deleteFolder(FINAL_ASSETS_DIR);
}
rename(TEMP_BUILD_DIR, FINAL_ASSETS_DIR);
echo "\nDone! Channel assets have been successfully updated with all sources." . PHP_EOL;
