<?php
session_start();
require_once 'config.php';

// Temporarily disabled - only using Grok
// $geminiApiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
$grokApiKey = defined('GROK_API_KEY') ? GROK_API_KEY : getenv('GROK_API_KEY');
// $openaiApiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : getenv('OPENAI_API_KEY');
$geminiApiKey = '';
$openaiApiKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
    // Ensure config is loaded
    if (!defined('GROK_API_KEY')) {
        require_once __DIR__ . '/config.php';
    }

    // Reload API key variable after ensuring config is loaded
    $grokApiKey = defined('GROK_API_KEY') ? GROK_API_KEY : getenv('GROK_API_KEY');

    // Disable output buffering for real-time updates
    @ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');

    // Start HTML output for processing page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Processing CSV - CAPEX Analyzer</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <script>
            // Debug: Output API key status
            console.log("=== API Key Check at Start ===");
            console.log("config.php file exists: <?php echo file_exists(__DIR__ . '/config.php') ? 'YES' : 'NO'; ?>");
            console.log("GROK_API_KEY defined: <?php echo (defined('GROK_API_KEY') ? 'YES' : 'NO'); ?>");
            console.log("GROK_API_KEY value: <?php echo (defined('GROK_API_KEY') ? GROK_API_KEY : 'NOT DEFINED'); ?>");
            console.log("$grokApiKey variable: <?php echo ($grokApiKey ? $grokApiKey : 'EMPTY/FALSE'); ?>");
            console.log("GROK_API_KEYS defined: <?php echo (defined('GROK_API_KEYS') ? 'YES' : 'NO'); ?>");
        </script>
        <style>
            .processing-container {
                padding: 2rem;
                max-width: 1400px;
                margin: 0 auto;
            }
            .progress-info {
                background: #f8f9fa;
                padding: 1rem;
                border-radius: 5px;
                margin-bottom: 1rem;
            }
            .row-result {
                padding: 1.25rem;
                margin: 0.75rem 0;
                border-left: 5px solid #dee2e6;
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            .row-result.success { border-left-color: #28a745; }
            .row-result.error { border-left-color: #dc3545; }
            .row-result.pending { border-left-color: #ffc107; }
            .row-result.mixed { border-left-color: #17a2b8; }
            .results-container {
                max-height: 600px;
                overflow-y: auto;
                border: 1px solid #dee2e6;
                border-radius: 5px;
                padding: 1rem;
                background: #ffffff;
            }
            .cost-breakdown {
                display: inline-block;
                margin-left: 1rem;
                font-size: 0.9rem;
            }
            .cost-breakdown span {
                margin: 0 0.5rem;
            }
        </style>
    </head>
    <body>
        <div class="processing-container">
            <h2>Analyzing Work Orders</h2>
            <div class="results-container" id="results">
    <?php
    flush();

    $uploadedFile = $_FILES['csvFile'];

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        echo '<div class="alert alert-danger">Upload failed with error code: ' . $uploadedFile['error'] . '</div>';
        echo '</div></div></body></html>';
        exit;
    }

    if (pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) !== 'csv') {
        echo '<div class="alert alert-danger">Please upload a CSV file</div>';
        echo '</div></div></body></html>';
        exit;
    }

    $tempFile = $uploadedFile['tmp_name'];
    $originalFilename = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);

    // Create a persistent directory for incomplete files in the same directory as this script
    $outputDir = __DIR__ . '/unfinished';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    // Generate a random 4-digit number for uniqueness
    $randomNumber = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);

    // Create filename: originalname_incomplete_XXXX.csv
    $outputFilename = $originalFilename . '_incomplete_' . $randomNumber . '.csv';
    $outputFile = $outputDir . '/' . $outputFilename;

    // Process CSV with real-time updates
    $processingResult = processCSVRealtime($tempFile, $outputFile, $geminiApiKey, $grokApiKey, $openaiApiKey);

    // Check if processing was complete and file was moved
    $completedFilename = str_replace('_incomplete_', '_complete_', basename($outputFile));
    $completedFile = __DIR__ . '/' . $completedFilename;

    if (file_exists($completedFile)) {
        $_SESSION['results_file'] = $completedFile;
    } else {
        $_SESSION['results_file'] = $outputFile;
    }
    $_SESSION['original_filename'] = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
    $_SESSION['processing_complete'] = true;

    // Display completion message
    echo '<div class="alert alert-success mt-3">';
    echo '<strong>✅ The file has been analyzed.</strong>';
    echo '</div>';

    ?>
            </div>
            <div class="mt-4">
                <a href="?download=1" class="btn btn-success">📥 Download Results CSV</a>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">🔄 Analyze Another File</a>
                <?php
                ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['download']) && isset($_SESSION['results_file'])) {
    $file = $_SESSION['results_file'];
    if (file_exists($file)) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $_SESSION['original_filename'] . '_capex_analysis.csv"');
        readfile($file);
        exit;
    }
}

function processCSVRealtime($inputFile, $outputFile, $geminiApiKey, $grokApiKey, $openaiApiKey) {
    $input = fopen($inputFile, 'r');
    $output = fopen($outputFile, 'w');

    if (!$input || !$output) {
        echo '<div class="alert alert-danger">Failed to process files</div>';
        return false;
    }

    // Create a progress file to track processed work orders
    $progressFile = $outputFile . '.progress';
    $processedWorkOrders = [];
    if (file_exists($progressFile)) {
        $progressData = json_decode(file_get_contents($progressFile), true);
        if (is_array($progressData)) {
            $processedWorkOrders = isset($progressData['processed']) ? $progressData['processed'] : $progressData;
        }
    }

    // Read and process headers
    $headers = fgetcsv($input);
    if (!$headers) {
        echo '<div class="alert alert-danger">Empty CSV file</div>';
        return false;
    }

    // Save headers globally for debugging
    $GLOBALS['headers'] = $headers;

    // Find important column indices
    $columnMap = [];
    foreach ($headers as $index => $header) {
        $headerLower = strtolower(trim($header));

        // Work order is always first column
        if ($index === 0) {
            $columnMap['work_order'] = $index;
        }

        // Look for request code column
        if (strpos($headerLower, 'request') !== false && strpos($headerLower, 'code') !== false) {
            $columnMap['request_code'] = $index;
        }

        // Look for description column
        if (strpos($headerLower, 'ifm invoice description') !== false ||
            (strpos($headerLower, 'description') !== false && strpos($headerLower, 'pepboys') === false)) {
            $columnMap['description'] = $index;
        }

        // Look for individual line item costs
        if ((strpos($headerLower, 'unit') !== false && strpos($headerLower, 'cost') !== false) ||
            (strpos($headerLower, 'unit') !== false && strpos($headerLower, 'price') !== false)) {
            $columnMap['unit_cost'] = $index;
        }

        if (strpos($headerLower, 'quantity') !== false || strpos($headerLower, 'qty') !== false) {
            $columnMap['quantity'] = $index;
        }

        if ((strpos($headerLower, 'line') !== false && strpos($headerLower, 'cost') !== false) ||
            (strpos($headerLower, 'extended') !== false && strpos($headerLower, 'pepboys') === false)) {
            $columnMap['line_cost'] = $index;
        }

        // PepBoys specific totals (these are work order totals, not line items)
        if (strpos($headerLower, 'pepboys') !== false && strpos($headerLower, 'tax') !== false) {
            $columnMap['pepboys_tax'] = $index;
        }
        if (strpos($headerLower, 'pepboys') !== false && strpos($headerLower, 'subtotal') !== false) {
            $columnMap['pepboys_subtotal'] = $index;
        }
        if (strpos($headerLower, 'pepboys') !== false && strpos($headerLower, 'client') !== false && strpos($headerLower, 'invoice') !== false) {
            $columnMap['pepboys_invoice'] = $index;
        }
    }
    flush();

    // Add new columns to headers for line-by-line analysis
    $newHeaders = array_merge($headers, [
        'Line Item CAPEX %',
        'Line Item OPEX %',
        'Line Item CAPEX Amount',
        'Line Item OPEX Amount',
        'Line Item Justification',
        'Work Order Overall Determination',
        'Work Order Total CAPEX',
        'Work Order Total OPEX',
        'Work Order Overall Justification',
        'Work Order Category'
    ]);
    fputcsv($output, $newHeaders);

    // Read all data and group by work order
    $workOrders = [];
    while (($row = fgetcsv($input)) !== false) {
        if (count($row) < count($headers)) {
            continue;
        }

        $workOrderNum = isset($columnMap['work_order']) ? trim($row[$columnMap['work_order']]) : trim($row[0]);
        if (empty($workOrderNum)) {
            continue;
        }

        if (!isset($workOrders[$workOrderNum])) {
            $workOrders[$workOrderNum] = [];
        }
        $workOrders[$workOrderNum][] = $row;
    }

    $_SESSION['processed_rows'] = [];
    $processedCount = 0;
    $totalWorkOrders = count($workOrders);

    // Process work orders one at a time
    $workOrdersToProcess = [];

    // Filter out already processed work orders
    foreach ($workOrders as $workOrderNum => $rows) {
        if (!in_array($workOrderNum, $processedWorkOrders)) {
            $workOrdersToProcess[$workOrderNum] = $rows;
        } else {
        }
    }

    // Process work orders in batches of 5
    $batchSize = 5;
    $workOrderArray = array_keys($workOrdersToProcess);
    $totalBatches = ceil(count($workOrderArray) / $batchSize);

    for ($batchNum = 0; $batchNum < $totalBatches; $batchNum++) {
        $batchStart = $batchNum * $batchSize;
        $batchWorkOrders = array_slice($workOrderArray, $batchStart, $batchSize, true);

        // Prepare batch data
        $batch = [];
        foreach ($batchWorkOrders as $workOrderNum) {
            $batch[$workOrderNum] = $workOrdersToProcess[$workOrderNum];
        }

        // Display batch processing message
        echo '<div class="alert alert-primary" style="margin: 15px 0; padding: 15px; border-left: 4px solid #0d6efd;">';
        echo '<strong>⚡ Processing Batch ' . ($batchNum + 1) . ' of ' . $totalBatches . '</strong>';
        echo '<span style="float: right;">Work Orders: ' . implode(', ', array_keys($batch)) . '</span>';
        echo '</div>';
        flush();

        // Process batch in parallel - but handle each WO individually for output
        foreach ($batch as $workOrderNum => $rows) {
            $processedCount++;
            $progress = round(($processedCount / $totalWorkOrders) * 100);

            // Display work order being processed
            echo '<div class="alert alert-info" style="margin: 10px 0; padding: 15px; border-left: 4px solid #0dcaf0;">';
            echo '<strong>📋 Processing Work Order: ' . htmlspecialchars($workOrderNum) . '</strong>';
            echo '<span style="float: right; color: #6c757d;">(' . $processedCount . ' of ' . $totalWorkOrders . ')</span>';
            echo '</div>';
            flush();

            // Check if all rows are maintenance/preventive based on request code
        $allMaintenance = true;
        $maintenanceTotal = 0;
        $maintenanceTax = 0;

        if (isset($columnMap['request_code'])) {
            foreach ($rows as $row) {
                $requestCode = isset($row[$columnMap['request_code']]) ? strtoupper(trim($row[$columnMap['request_code']])) : '';
                if (strpos($requestCode, 'MAINTENANCE') === false && strpos($requestCode, 'PREVENTIVE') === false) {
                    $allMaintenance = false;
                    break;
                }
                // Calculate totals for maintenance items
                if (isset($columnMap['line_cost'])) {
                    $maintenanceTotal += floatval(str_replace(['$', ','], '', $row[$columnMap['line_cost']]));
                }
            }

            // Get tax for maintenance items
            if ($allMaintenance && isset($columnMap['pepboys_tax']) && count($rows) > 0) {
                $maintenanceTax = floatval(str_replace(['$', ','], '', $rows[0][$columnMap['pepboys_tax']]));
            }
        }

        // If all lines are maintenance, skip API call and create OPEX result directly
        if ($allMaintenance) {
            flush();

            $analysis = [
                'determination' => 'OPEX',
                'capex_amount' => 0,
                'opex_amount' => $maintenanceTotal,
                'capex_tax' => 0,
                'opex_tax' => $maintenanceTax,
                'total_capex' => 0,
                'total_opex' => $maintenanceTotal + $maintenanceTax,
                'justification' => 'All line items are routine maintenance or preventive maintenance based on Request Code. Under ASC 360, routine and preventive maintenance that maintains existing condition without extending asset life is classified as OPEX.',
                'category' => 'N/A',
                'line_items' => []
            ];

            // Create line item details for each maintenance row
            foreach ($rows as $rowIndex => $row) {
                $lineCost = isset($columnMap['line_cost']) ? floatval(str_replace(['$', ','], '', $row[$columnMap['line_cost']])) : 0;
                $analysis['line_items'][$rowIndex] = [
                    'capex_percent' => '0',
                    'opex_percent' => '100',
                    'capex_amount' => '0',
                    'opex_amount' => number_format($lineCost, 2, '.', ''),
                    'justification' => 'Routine/Preventive Maintenance - 100% OPEX'
                ];
            }

            flush();
        } else {
            // Analyze the work order with error handling
            try {
                $analysis = analyzeWorkOrder($rows, $columnMap, $geminiApiKey, $grokApiKey, $openaiApiKey);

                flush();
            } catch (Exception $e) {
                // Log error but continue processing
                error_log("Error processing work order $workOrderNum: " . $e->getMessage());

                // Create error result
                $analysis = [
                    'determination' => 'ERROR',
                    'capex_amount' => 0,
                    'opex_amount' => 0,
                    'capex_tax' => 0,
                    'opex_tax' => 0,
                    'total_capex' => 0,
                    'total_opex' => 0,
                    'justification' => 'Processing error: ' . $e->getMessage(),
                    'category' => '',
                    'line_items' => []
                ];
            }
        }

        // Display results after analysis
        $badgeClass = 'bg-secondary';
        $badgeText = $analysis['determination'];
        if ($analysis['determination'] === 'CAPEX') {
            $badgeClass = 'bg-success';
        } elseif ($analysis['determination'] === 'OPEX') {
            $badgeClass = 'bg-danger';
        } elseif ($analysis['determination'] === 'MIXED') {
            $badgeClass = 'bg-warning text-dark';
        } elseif ($analysis['determination'] === 'ERROR') {
            $badgeClass = 'bg-secondary';
        }

        echo '<div class="alert alert-light" style="margin: 10px 0; padding: 15px; border-left: 4px solid #28a745; background-color: #f8f9fa;">';
        echo '<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">';
        echo '<strong>✅ Work Order: ' . htmlspecialchars($workOrderNum) . '</strong>';
        echo '<span class="badge ' . $badgeClass . '" style="font-size: 1em; padding: 8px 12px;">' . htmlspecialchars($badgeText) . '</span>';
        echo '</div>';
        echo '<div style="margin: 10px 0;">';
        echo '<strong style="color: #28a745;">CAPEX Total (with tax): $' . number_format($analysis['total_capex'], 2) . '</strong>';
        echo ' | ';
        echo '<strong style="color: #dc3545;">OPEX Total (with tax): $' . number_format($analysis['total_opex'], 2) . '</strong>';
        echo '</div>';
        if (!empty($analysis['category']) && $analysis['category'] !== 'N/A') {
            echo '<div style="margin: 5px 0; color: #6c757d;"><strong>Category:</strong> ' . htmlspecialchars($analysis['category']) . '</div>';
        }
        echo '<div style="margin-top: 10px; padding: 10px; background-color: white; border-radius: 4px; font-size: 0.9em;">';
        echo '<strong>Justification:</strong> ' . htmlspecialchars(substr($analysis['justification'], 0, 300));
        if (strlen($analysis['justification']) > 300) {
            echo '...';
        }
        echo '</div>';
        echo '</div>';
        flush();

        // Write each row with its individual analysis
        foreach ($rows as $rowIndex => $row) {
            $outputRow = $row;

            // Pad to ensure we have all original columns
            $outputRow = array_pad($outputRow, count($headers), '');

            // Add line item analysis
            if (isset($analysis['line_items'][$rowIndex])) {
                $lineAnalysis = $analysis['line_items'][$rowIndex];
                $outputRow[] = number_format($lineAnalysis['capex_percent'], 1) . '%';
                $outputRow[] = number_format($lineAnalysis['opex_percent'], 1) . '%';
                $outputRow[] = '$' . number_format($lineAnalysis['capex_amount'], 2);
                $outputRow[] = '$' . number_format($lineAnalysis['opex_amount'], 2);
                $outputRow[] = $lineAnalysis['justification'];
            } else {
                // Empty line item analysis columns
                $outputRow[] = '';
                $outputRow[] = '';
                $outputRow[] = '';
                $outputRow[] = '';
                $outputRow[] = '';
            }

            // Add overall work order analysis (same for all rows in the work order)
            $outputRow[] = $analysis['determination'];
            $outputRow[] = '$' . number_format($analysis['total_capex'], 2);
            $outputRow[] = '$' . number_format($analysis['total_opex'], 2);
            $outputRow[] = $analysis['justification'];
            $outputRow[] = $analysis['category'] ?? '';

            fputcsv($output, $outputRow);
        }

        // Flush the output buffer to save to disk immediately
        fflush($output);

        // Save progress
        $processedWorkOrders[] = $workOrderNum;
        file_put_contents($progressFile, json_encode([
            'processed' => $processedWorkOrders,
            'total' => $totalWorkOrders,
            'filename' => $outputFilename,
            'timestamp' => time()
        ]));

        // Update display
        $badgeClass = 'bg-secondary';
        $borderClass = 'error';
        if ($analysis['determination'] === 'CAPEX') {
            $badgeClass = 'bg-success';
            $borderClass = 'success';
        } elseif ($analysis['determination'] === 'OPEX') {
            $badgeClass = 'bg-danger';
            $borderClass = 'error';
        } elseif ($analysis['determination'] === 'MIXED') {
            $badgeClass = 'bg-info';
            $borderClass = 'mixed';
        }


        $_SESSION['processed_rows'][] = [
            'work_order' => $workOrderNum,
            'line_items' => count($rows),
            'determination' => $analysis['determination'],
            'capex_total' => $analysis['total_capex'],
            'opex_total' => $analysis['total_opex'],
            'justification' => $analysis['justification']
        ];

        // Track processed work orders
        $processedWorkOrders[] = $workOrderNum;

        // Update progress file after each work order
        file_put_contents($progressFile, json_encode($processedWorkOrders));
        fflush($output);

        // Add small delay to prevent API rate limiting
        usleep(500000); // 0.5 seconds between requests
        }
    }

    fclose($input);
    fclose($output);

    // On successful completion, move file to completed location
    if (file_exists($progressFile)) {
        unlink($progressFile);

        // Move the file from unfinished to the parent directory with 'complete' in name
        $completedFilename = str_replace('_incomplete_', '_complete_', basename($outputFile));
        $completedFile = dirname($outputDir) . '/' . $completedFilename;

        // Copy to completed location
        if (copy($outputFile, $completedFile)) {
            // Update session with new location
            $_SESSION['results_file'] = $completedFile;

            // Optionally delete the incomplete file
            // unlink($outputFile);
        }
    }

    return true;
}


function getDetailedAnalysisPromptForBatch() {
    return "You are a professional accountant analyzing HVAC work orders for CAPEX vs OPEX determination under ASC 360.

ASC 360 CAPEX Criteria:
- Replacement of major components (fan motors, compressors, switches, control boards, entire units)
- Significant repairs that extend asset life beyond one year
- Upgrades that increase capacity, efficiency, or quality of output
- Safety and environmental improvements

OPEX Criteria:
- ANY work order containing the word 'temporary' is automatically OPEX
- Routine maintenance (tape, filters, belts, cleaning)
- Minor repairs (leak repairs, recharging refrigerant)
- Inspections and evaluations
- Items that merely maintain existing condition
- Consumable supplies

Specific Guidelines:
- TEMPORARY WORK RULE: If the work order description contains 'temporary' anywhere, ALL items are 100% OPEX
- Tape, recharge, filters, inspection, leaks, evaluations, belts, leak repairs = 100% OPEX
- Fan motors, switches, replacement units, major repairs, hardware items = 100% CAPEX (unless temporary)
- CRITICAL: Every material/part/equipment must be classified as ENTIRELY (100%) CAPEX or ENTIRELY (100%) OPEX
- NO material, part, or piece of equipment can be split between CAPEX and OPEX
- Labor allocation: Proportional to the ratio of CAPEX materials to OPEX materials
- Shipping/Freight: If ANY materials are CAPEX, then 100% of shipping/freight charges go to CAPEX
- Trip charges: Allocated proportionally like labor based on material ratio
- Tax should be allocated proportionally between CAPEX and OPEX

CRITICAL ALLOCATION RULES:
1. TEMPORARY WORK OVERRIDE: Any work order with 'temporary' in the description is automatically 100% OPEX
2. ALL hardware items, materials, parts, and equipment must be classified as either 100% CAPEX or 100% OPEX - NO SPLITTING OF MATERIALS
3. Each physical item is ENTIRELY CAPEX or ENTIRELY OPEX based on ASC 360 criteria
4. ONLY labor, shipping, freight, and trip charges can be allocated proportionally

ALLOCATION PROCESS:
1. Classify each material/part as 100% CAPEX or 100% OPEX (no splitting)
2. Calculate total value of CAPEX materials vs OPEX materials
3. Determine the percentage (e.g., $700 CAPEX parts + $300 OPEX parts = 70% CAPEX/30% OPEX)
4. SHIPPING/FREIGHT RULE: If ANY materials are CAPEX, then 100% of shipping/freight is CAPEX
5. LABOR RULE: Apply the material percentage to labor (70% CAPEX materials = 70% of labor is CAPEX)
6. TRIP CHARGES: Apply the same percentage as labor

IMPORTANT: First check if the work order contains the word 'temporary' - if it does, everything is OPEX.

Please analyze EACH line item individually, then provide overall analysis. Respond in this EXACT format:

LINE 1: CAPEX:XX% OPEX:XX% [Brief explanation - if material/part, must be 100% one category]
LINE 2: CAPEX:XX% OPEX:XX% [Brief explanation - if material/part, must be 100% one category]
[Continue for all lines]

OVERALL:
DETERMINATION: [CAPEX/OPEX/MIXED]
CATEGORY: [If CAPEX or MIXED: Controls/Automation OR Infrastructure/Utility OR Heater Replacement. If pure OPEX: N/A]
CAPEX_AMOUNT: [dollar amount without tax]
OPEX_AMOUNT: [dollar amount without tax]
JUSTIFICATION: [Professional explanation citing specific items and ASC 360 criteria, including how labor and service charges were allocated]";
}

// Process multiple work orders in parallel using different API keys
function processBatchParallel($batch, $columnMap, $geminiApiKey, $grokApiKey, $openaiApiKey) {
    $apiKeys = defined('GROK_API_KEYS') ? GROK_API_KEYS : [$grokApiKey, $grokApiKey, $grokApiKey, $grokApiKey, $grokApiKey];

    $multiHandle = curl_multi_init();
    $curlHandles = [];
    $workOrderMap = [];

    $keyIndex = 0;
    foreach ($batch as $workOrderNum => $rows) {
        $apiKey = $apiKeys[$keyIndex % count($apiKeys)];
        $keyIndex++;

        // Prepare work order data
        $workOrderData = prepareWorkOrderData($rows, $columnMap);

        if (!$workOrderData) {
            continue;
        }

        // Create curl handle
        $ch = createGrokCurlHandle($workOrderData, $apiKey);

        if ($ch) {
            curl_multi_add_handle($multiHandle, $ch);
            $curlHandles[$workOrderNum] = $ch;
            $workOrderMap[$workOrderNum] = ['rows' => $rows, 'data' => $workOrderData];
        }
    }

    // Execute all requests simultaneously
    $running = null;
    do {
        curl_multi_exec($multiHandle, $running);
        if ($running > 0) {
            curl_multi_select($multiHandle, 0.1);
        }
    } while ($running > 0);

    // Collect results
    $results = [];
    foreach ($curlHandles as $workOrderNum => $ch) {
        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 200 && $response) {
            $responseData = json_decode($response, true);
            if (isset($responseData['choices'][0]['message']['content'])) {
                $content = $responseData['choices'][0]['message']['content'];
                $results[$workOrderNum] = [
                    'determination' => 'UNKNOWN',
                    'justification' => $content
                ];
            } else {
                $results[$workOrderNum] = createErrorResult('Invalid API response');
            }
        } else {
            $results[$workOrderNum] = createErrorResult('API request failed (HTTP ' . $httpCode . ')');
        }

        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }

    curl_multi_close($multiHandle);

    return $results;
}

// Helper function to prepare work order data
function prepareWorkOrderData($rows, $columnMap) {
    $lineItems = [];
    $totalCost = 0;
    $totalTax = 0;

    foreach ($rows as $row) {
        $description = isset($columnMap['description']) ? trim($row[$columnMap['description']] ?? '') : '';
        $lineCost = isset($columnMap['line_cost']) ? floatval(str_replace(['$', ','], '', $row[$columnMap['line_cost']])) : 0;

        if (!empty($description)) {
            $lineItems[] = [
                'description' => $description,
                'cost' => $lineCost
            ];
            $totalCost += $lineCost;
        }
    }

    if (isset($columnMap['pepboys_tax']) && count($rows) > 0) {
        $totalTax = floatval(str_replace(['$', ','], '', $rows[0][$columnMap['pepboys_tax']]));
    }

    if (empty($lineItems)) {
        return null;
    }

    return json_encode([
        'line_items' => $lineItems,
        'total_cost' => $totalCost,
        'total_tax' => $totalTax
    ]);
}

// Helper function to create Grok curl handle
function createGrokCurlHandle($workOrderData, $apiKey) {
    $workOrder = json_decode($workOrderData, true);

    $prompt = "You are a professional accountant with extensive experience in ASC 360 (Property, Plant, and Equipment) compliance and public entity financial reporting.

Analyze this work order with multiple line items. For EACH line item, determine its individual CAPEX vs OPEX allocation, then provide an overall determination.

Line Items:
" . implode("\n", array_map(function($item, $idx) {
    return ($idx + 1) . ". " . $item['description'] . " - Cost: $" . number_format($item['cost'], 2);
}, $workOrder['line_items'], array_keys($workOrder['line_items']))) . "

Total Cost: $" . number_format($workOrder['total_cost'], 2) . "
Total Tax: $" . number_format($workOrder['total_tax'], 2) . "

Respond with CAPEX/OPEX determination and justification.";

    $data = [
        'model' => 'grok-4-fast',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.3
    ];

    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    return $ch;
}

// Helper function to create error result
function createErrorResult($message) {
    return [
        'determination' => 'ERROR',
        'capex_amount' => 0,
        'opex_amount' => 0,
        'capex_tax' => 0,
        'opex_tax' => 0,
        'total_capex' => 0,
        'total_opex' => 0,
        'justification' => $message,
        'category' => '',
        'line_items' => []
    ];
}

function retrySingleRequest($workOrderNum, $rows, $columnMap, $grokApiKey) {
    // Build prompt for this work order
    $descriptions = [];
    foreach ($rows as $row) {
        $description = isset($columnMap['description']) ? trim($row[$columnMap['description']] ?? '') : '';
        $unitCost = isset($columnMap['unit_cost']) ?
                   floatval(str_replace(['$', ','], '', $row[$columnMap['unit_cost']])) : 0;
        $quantity = isset($columnMap['quantity']) ?
                   floatval($row[$columnMap['quantity']]) : 1;
        $lineCost = isset($columnMap['line_cost']) ?
                   floatval(str_replace(['$', ','], '', $row[$columnMap['line_cost']])) : 0;

        if ($lineCost == 0 && $unitCost > 0) {
            $lineCost = $unitCost * $quantity;
        }

        $descriptions[] = "- Item: $description, Unit Cost: \$$unitCost, Quantity: $quantity, Line Cost: \$$lineCost";
    }

    $prompt = getDetailedAnalysisPromptForBatch();
    $prompt .= "\n\nWork Order Details:\n" . implode("\n", $descriptions);

    // Make a single synchronous request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.x.ai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $grokApiKey,
        'Expect: '
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'model' => 'grok-4-fast',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.1,
        'max_tokens' => 2000
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 90); // Even longer timeout for retry
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['choices'][0]['message']['content'])) {
            return parseDetailedAIResponse($data['choices'][0]['message']['content'], $rows, $columnMap);
        }
    }

    return false;
}

function createErrorAnalysis($rows, $columnMap) {
    $subtotal = 0;
    foreach ($rows as $row) {
        if (isset($columnMap['line_cost'])) {
            $subtotal += floatval(str_replace(['$', ','], '', $row[$columnMap['line_cost']]));
        }
    }

    return [
        'determination' => 'ERROR',
        'capex_amount' => 0,
        'opex_amount' => $subtotal,
        'capex_tax' => 0,
        'opex_tax' => 0,
        'total_capex' => 0,
        'total_opex' => $subtotal,
        'justification' => 'Error processing work order - API request failed',
        'category' => 'N/A',
        'line_items' => []
    ];
}

function analyzeWorkOrder($rows, $columnMap, $geminiApiKey, $grokApiKey, $openaiApiKey) {
    // Collect all descriptions and costs
    $descriptions = [];
    $totalCost = 0;
    $totalTax = 0;
    $lineItems = [];
    $pepboysSubtotal = 0;
    $pepboysTax = 0;
    $pepboysInvoice = 0;

    // Debug first row to see what data we have
    if (count($rows) > 0) {

        // Get PepBoys totals from first row (they're the same for all rows in a work order)
        if (isset($columnMap['pepboys_subtotal'])) {
            $pepboysSubtotal = floatval(str_replace(['$', ','], '', $rows[0][$columnMap['pepboys_subtotal']]));
        }
        if (isset($columnMap['pepboys_tax'])) {
            $pepboysTax = floatval(str_replace(['$', ','], '', $rows[0][$columnMap['pepboys_tax']]));
        }
        if (isset($columnMap['pepboys_invoice'])) {
            $pepboysInvoice = floatval(str_replace(['$', ','], '', $rows[0][$columnMap['pepboys_invoice']]));
        }
    }

    foreach ($rows as $rowIndex => $row) {
        // Get description from the correct column
        $description = '';
        if (isset($columnMap['description'])) {
            $description = trim($row[$columnMap['description']] ?? '');
        }

        // If no description column found, look for descriptive text in other columns
        if (empty($description)) {
            // Skip first column (work order) and PepBoys columns
            for ($idx = 1; $idx < count($row); $idx++) {
                $cell = trim($row[$idx]);
                // Look for text that's likely a description (not a number or short code)
                if (strlen($cell) > 15 &&
                    !is_numeric(str_replace(['$', ',', '.', ' '], '', $cell)) &&
                    strpos(strtolower($headers[$idx]), 'pepboys') === false) {
                    $description = $cell;
                    if ($rowIndex == 0) {
                    }
                    break;
                }
            }
        }

        // Get individual line item costs
        $unitCost = 0;
        $quantity = 1;
        $lineCost = 0;

        if (isset($columnMap['unit_cost'])) {
            $unitCost = floatval(str_replace(['$', ','], '', $row[$columnMap['unit_cost']]));
        }
        if (isset($columnMap['quantity']) && !empty($row[$columnMap['quantity']])) {
            $quantity = floatval($row[$columnMap['quantity']]);
        }
        if (isset($columnMap['line_cost'])) {
            $lineCost = floatval(str_replace(['$', ','], '', $row[$columnMap['line_cost']]));
        } else if ($unitCost > 0) {
            $lineCost = $unitCost * $quantity;
        }

        // Add to line items if we have a description
        if (!empty($description)) {
            $descriptions[] = $description;
            $lineItems[] = [
                'description' => $description,
                'cost' => $lineCost
            ];

            if ($rowIndex == 0) {
            }
        }
    }

    // Use PepBoys totals if available, otherwise sum line items
    if ($pepboysSubtotal > 0) {
        $totalCost = $pepboysSubtotal;
        $totalTax = $pepboysTax;
    } else {
        // Calculate from line items
        foreach ($lineItems as $item) {
            $totalCost += $item['cost'];
        }
    }

    if (empty($descriptions)) {

        // If we have totals but no descriptions, create a generic entry
        if ($pepboysSubtotal > 0 || $totalCost > 0) {
            $descriptions[] = 'Work order items (descriptions not found in CSV)';
            $lineItems[] = [
                'description' => 'Work order total',
                'cost' => $pepboysSubtotal > 0 ? $pepboysSubtotal : $totalCost
            ];
        } else {
            return [
                'determination' => 'ERROR',
                'capex_amount' => 0,
                'opex_amount' => 0,
                'capex_tax' => 0,
                'opex_tax' => 0,
                'total_capex' => 0,
                'total_opex' => 0,
                'justification' => 'No descriptions or costs found - please check CSV format'
            ];
        }
    }

    // Prepare data for AI analysis
    $workOrderData = json_encode([
        'descriptions' => $descriptions,
        'line_items' => $lineItems,
        'total_cost' => $totalCost,
        'total_tax' => $totalTax
    ]);

    flush();

    // Get AI analysis from Grok only (temporarily disabled OpenAI and Gemini)
    $analysis = analyzeWithGrokDetailed($workOrderData, $grokApiKey);

    // Parse the AI response to extract amounts and line item details
    return parseDetailedAIResponse($analysis, $totalCost, $totalTax, $lineItems);
}

function parseAIResponse($analysis, $totalCost, $totalTax, $lineItems) {
    // Default values
    $result = [
        'determination' => 'UNKNOWN',
        'capex_amount' => 0,
        'opex_amount' => 0,
        'capex_tax' => 0,
        'opex_tax' => 0,
        'total_capex' => 0,
        'total_opex' => 0,
        'justification' => $analysis['justification'],
        'category' => 'Uncategorized'
    ];

    // Log what we're parsing
    error_log("Parsing AI response: " . substr($analysis['justification'], 0, 200));

    // Extract determination
    if (preg_match('/DETERMINATION:\s*(CAPEX|OPEX|MIXED)/i', $analysis['justification'], $matches)) {
        $result['determination'] = strtoupper($matches[1]);
        error_log("Found determination: " . $result['determination']);
    }

    // Extract category
    if (preg_match('/CATEGORY:\s*([^\n]+)/i', $analysis['justification'], $matches)) {
        $category = trim($matches[1]);
        // Check if it's N/A (for OPEX-only work orders)
        if (stripos($category, 'n/a') !== false || stripos($category, 'none') !== false) {
            $result['category'] = '';
        }
        // Normalize category names for CAPEX work
        elseif (stripos($category, 'control') !== false || stripos($category, 'automat') !== false) {
            $result['category'] = 'Controls/Automation';
        } elseif (stripos($category, 'infrastructure') !== false || stripos($category, 'utility') !== false) {
            $result['category'] = 'Infrastructure/Utility';
        } elseif (stripos($category, 'heater') !== false || stripos($category, 'heating') !== false || stripos($category, 'hvac') !== false) {
            $result['category'] = 'Heater Replacement';
        } else {
            $result['category'] = $category;
        }
    } else {
        // Default to empty for OPEX, will be set based on determination
        $result['category'] = '';
    }

    // Extract amounts from AI response
    if (preg_match('/CAPEX_AMOUNT:\s*\$?([\d,]+\.?\d*)/i', $analysis['justification'], $matches)) {
        $result['capex_amount'] = floatval(str_replace(',', '', $matches[1]));
        error_log("Found CAPEX amount: " . $result['capex_amount']);
    }
    if (preg_match('/OPEX_AMOUNT:\s*\$?([\d,]+\.?\d*)/i', $analysis['justification'], $matches)) {
        $result['opex_amount'] = floatval(str_replace(',', '', $matches[1]));
        error_log("Found OPEX amount: " . $result['opex_amount']);
    }

    // If amounts weren't parsed, use simple allocation based on determination
    if ($result['capex_amount'] == 0 && $result['opex_amount'] == 0) {
        if ($result['determination'] === 'CAPEX') {
            $result['capex_amount'] = $totalCost;
            $result['opex_amount'] = 0;
        } elseif ($result['determination'] === 'OPEX') {
            $result['capex_amount'] = 0;
            $result['opex_amount'] = $totalCost;
        } else {
            // Mixed - default to 50/50 if not specified
            $result['capex_amount'] = $totalCost * 0.5;
            $result['opex_amount'] = $totalCost * 0.5;
        }
    }

    // Ensure amounts don't exceed total cost (in case AI made an error)
    $sumAmounts = $result['capex_amount'] + $result['opex_amount'];
    if ($sumAmounts > $totalCost && $sumAmounts > 0) {
        // Scale down proportionally
        $scale = $totalCost / $sumAmounts;
        $result['capex_amount'] *= $scale;
        $result['opex_amount'] *= $scale;
    }

    // Allocate tax proportionally based on the amounts
    if ($totalCost > 0 && ($result['capex_amount'] > 0 || $result['opex_amount'] > 0)) {
        // Use actual amounts for ratio calculation
        $actualTotal = $result['capex_amount'] + $result['opex_amount'];
        if ($actualTotal > 0) {
            $capexRatio = $result['capex_amount'] / $actualTotal;
            $opexRatio = $result['opex_amount'] / $actualTotal;
        } else {
            $capexRatio = 0;
            $opexRatio = 0;
        }

        $result['capex_tax'] = $totalTax * $capexRatio;
        $result['opex_tax'] = $totalTax * $opexRatio;

    }

    // Calculate totals INCLUDING TAX
    $result['total_capex'] = $result['capex_amount'] + $result['capex_tax'];
    $result['total_opex'] = $result['opex_amount'] + $result['opex_tax'];

    // Clear category if it's pure OPEX (no CAPEX content)
    if ($result['determination'] === 'OPEX' || $result['total_capex'] == 0) {
        $result['category'] = '';
    }


    return $result;
}



function analyzeWithAI($workOrderData, $geminiApiKey, $grokApiKey) {
    // Try Gemini first
    if (!empty($geminiApiKey)) {
        $result = analyzeWithGemini($workOrderData, $geminiApiKey);
        if ($result['determination'] !== 'ERROR') {
            return $result;
        }
        error_log("Gemini API failed, falling back to Grok: " . $result['justification']);
    } else {
    }

    // Fallback to Grok
    if (!empty($grokApiKey)) {
        $result = analyzeWithGrok($workOrderData, $grokApiKey);
        if ($result['determination'] !== 'ERROR') {
            return $result;
        }
        error_log("Grok API also failed: " . $result['justification']);
    } else {
    }

    // Both failed
    return [
        'determination' => 'ERROR',
        'justification' => 'All AI models failed to process the request. Please check API keys in config.php'
    ];
}

function analyzeWithGemini($workOrderData, $apiKey) {
    $workOrder = json_decode($workOrderData, true);

    $prompt = "You are a professional accountant with extensive experience in ASC 360 (Property, Plant, and Equipment) compliance and public entity financial reporting.

Analyze this work order with multiple line items and determine the CAPEX vs OPEX allocation:

Line Items:
" . implode("\n", array_map(function($item, $idx) {
    return ($idx + 1) . ". " . $item['description'] . " - Cost: $" . number_format($item['cost'], 2);
}, $workOrder['line_items'], array_keys($workOrder['line_items']))) . "

Total Cost: $" . number_format($workOrder['total_cost'], 2) . "
Total Tax: $" . number_format($workOrder['total_tax'], 2) . "

ASC 360 CAPEX Criteria:
- Replacement of major components (fan motors, compressors, switches, control boards, entire units)
- Significant repairs that extend asset life beyond one year
- Upgrades that increase capacity, efficiency, or quality of output
- Safety and environmental improvements

OPEX Criteria:
- ANY work order containing the word 'temporary' is automatically OPEX
- Routine maintenance (tape, filters, belts, cleaning)
- Minor repairs (leak repairs, recharging refrigerant)
- Inspections and evaluations
- Items that merely maintain existing condition
- Consumable supplies

Specific Guidelines:
- TEMPORARY WORK RULE: If the work order description contains 'temporary' anywhere, ALL items are 100% OPEX
- Tape, recharge, filters, inspection, leaks, evaluations, belts, leak repairs = 100% OPEX
- Fan motors, switches, replacement units, major repairs, hardware items = 100% CAPEX (unless temporary)
- CRITICAL: Every material/part/equipment must be classified as ENTIRELY (100%) CAPEX or ENTIRELY (100%) OPEX
- NO material, part, or piece of equipment can be split between CAPEX and OPEX
- Labor allocation: Proportional to the ratio of CAPEX materials to OPEX materials
- Shipping/Freight: If ANY materials are CAPEX, then 100% of shipping/freight charges go to CAPEX
- Trip charges: Allocated proportionally like labor based on material ratio
- Tax should be allocated proportionally between CAPEX and OPEX

CRITICAL ALLOCATION RULES:
1. TEMPORARY WORK OVERRIDE: Any work order with 'temporary' in the description is automatically 100% OPEX
2. ALL hardware items, materials, parts, and equipment must be classified as either 100% CAPEX or 100% OPEX - NO SPLITTING OF MATERIALS
3. Each physical item is ENTIRELY CAPEX or ENTIRELY OPEX based on ASC 360 criteria
4. ONLY labor, shipping, freight, and trip charges can be allocated proportionally

ALLOCATION PROCESS:
1. Classify each material/part as 100% CAPEX or 100% OPEX (no splitting)
2. Calculate total value of CAPEX materials vs OPEX materials
3. Determine the percentage (e.g., $700 CAPEX parts + $300 OPEX parts = 70% CAPEX/30% OPEX)
4. SHIPPING/FREIGHT RULE: If ANY materials are CAPEX, then 100% of shipping/freight is CAPEX
5. LABOR RULE: Apply the material percentage to labor (70% CAPEX materials = 70% of labor is CAPEX)
6. TRIP CHARGES: Apply the same percentage as labor

IMPORTANT: First check if the work order contains the word 'temporary' - if it does, everything is OPEX.

Please analyze and respond in this format:
DETERMINATION: [CAPEX/OPEX/MIXED]
CATEGORY: [If CAPEX or MIXED: Controls/Automation OR Infrastructure/Utility OR Heater Replacement. If pure OPEX: N/A]
CAPEX_AMOUNT: [dollar amount without tax]
OPEX_AMOUNT: [dollar amount without tax]
JUSTIFICATION: [Professional explanation citing specific items and ASC 360 criteria, including how labor and service charges were allocated]

CATEGORY RULES:
- ONLY categorize work orders that contain CAPEX (CAPEX or MIXED determinations)
- Pure OPEX work orders should have category N/A
- Category Definitions (for CAPEX work):
  * Controls/Automation: Control systems, automation, thermostats, sensors, BMS
  * Infrastructure/Utility: Electrical, plumbing, structural, utilities, power systems
  * Heater Replacement: HVAC, heating, cooling, compressors, air handlers, refrigeration";

    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 800
        ]
    ];

    $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON Encoding Error: " . json_last_error_msg());
        return [
            'determination' => 'ERROR',
            'justification' => 'Failed to encode request data'
        ];
    }

    $url = 'https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=' . $apiKey;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        $errorMsg = "API Error (HTTP $httpCode)";
        if ($curlError) {
            $errorMsg .= " - CURL: $curlError";
        }
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= " - " . $errorData['error']['message'];
            }
            error_log("Gemini API Error: " . json_encode($errorData));
        }
        return [
            'determination' => 'ERROR',
            'justification' => $errorMsg
        ];
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        return [
            'determination' => 'ERROR',
            'justification' => 'Invalid API response'
        ];
    }

    $content = $responseData['candidates'][0]['content']['parts'][0]['text'];

    return [
        'determination' => 'UNKNOWN',
        'justification' => $content
    ];
}

function parseDetailedAIResponse($analysis, $totalCost, $totalTax, $lineItems) {
    // Initialize result with overall analysis
    $result = [
        'determination' => 'UNKNOWN',
        'capex_amount' => 0,
        'opex_amount' => 0,
        'capex_tax' => 0,
        'opex_tax' => 0,
        'total_capex' => 0,
        'total_opex' => 0,
        'justification' => $analysis['justification'],
        'category' => '',
        'line_items' => []
    ];

    // Parse overall determination
    if (preg_match('/OVERALL DETERMINATION:\s*(CAPEX|OPEX|MIXED)/i', $analysis['justification'], $matches)) {
        $result['determination'] = strtoupper($matches[1]);
    }

    // Parse category
    if (preg_match('/CATEGORY:\s*([^\n]+)/i', $analysis['justification'], $matches)) {
        $category = trim($matches[1]);
        // Normalize category names
        if (stripos($category, 'control') !== false || stripos($category, 'automat') !== false) {
            $result['category'] = 'Controls/Automation';
        } elseif (stripos($category, 'infrastructure') !== false || stripos($category, 'utility') !== false) {
            $result['category'] = 'Infrastructure/Utility';
        } elseif (stripos($category, 'heater') !== false || stripos($category, 'heating') !== false || stripos($category, 'hvac') !== false) {
            $result['category'] = 'Heater Replacement';
        } else {
            $result['category'] = $category;
        }
    }

    // Parse line items
    if (preg_match_all('/LINE (\d+):\s*CAPEX:(\d+)%\s*OPEX:(\d+)%\s*(.+?)(?=LINE \d+:|OVERALL|$)/si', $analysis['justification'], $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $lineNum = intval($match[1]) - 1; // Convert to 0-based index
            $capexPercent = floatval($match[2]);
            $opexPercent = floatval($match[3]);
            $lineJustification = trim($match[4]);

            if (isset($lineItems[$lineNum])) {
                $lineCost = $lineItems[$lineNum]['cost'];
                $result['line_items'][$lineNum] = [
                    'capex_percent' => $capexPercent,
                    'opex_percent' => $opexPercent,
                    'capex_amount' => ($lineCost * $capexPercent / 100),
                    'opex_amount' => ($lineCost * $opexPercent / 100),
                    'justification' => $lineJustification
                ];

                // Accumulate totals
                $result['capex_amount'] += $result['line_items'][$lineNum]['capex_amount'];
                $result['opex_amount'] += $result['line_items'][$lineNum]['opex_amount'];
            }
        }
    }

    // If no line items were parsed, fall back to simple allocation
    if (empty($result['line_items'])) {
        // Parse simple CAPEX/OPEX amounts
        if (preg_match('/CAPEX_AMOUNT:\s*\$?([\d,]+\.?\d*)/i', $analysis['justification'], $matches)) {
            $result['capex_amount'] = floatval(str_replace(',', '', $matches[1]));
        }
        if (preg_match('/OPEX_AMOUNT:\s*\$?([\d,]+\.?\d*)/i', $analysis['justification'], $matches)) {
            $result['opex_amount'] = floatval(str_replace(',', '', $matches[1]));
        }

        // If amounts weren't parsed, use determination for allocation
        if ($result['capex_amount'] == 0 && $result['opex_amount'] == 0) {
            if ($result['determination'] === 'CAPEX') {
                $result['capex_amount'] = $totalCost;
            } elseif ($result['determination'] === 'OPEX') {
                $result['opex_amount'] = $totalCost;
            } else {
                $result['capex_amount'] = $totalCost * 0.5;
                $result['opex_amount'] = $totalCost * 0.5;
            }
        }

        // Create default line items based on overall allocation
        foreach ($lineItems as $idx => $item) {
            $lineCapexRatio = $totalCost > 0 ? $result['capex_amount'] / $totalCost : 0.5;
            $lineOpexRatio = 1 - $lineCapexRatio;

            $result['line_items'][$idx] = [
                'capex_percent' => $lineCapexRatio * 100,
                'opex_percent' => $lineOpexRatio * 100,
                'capex_amount' => $item['cost'] * $lineCapexRatio,
                'opex_amount' => $item['cost'] * $lineOpexRatio,
                'justification' => 'Proportional allocation based on overall determination'
            ];
        }
    }

    // Allocate tax proportionally
    if ($totalCost > 0 && ($result['capex_amount'] > 0 || $result['opex_amount'] > 0)) {
        $actualTotal = $result['capex_amount'] + $result['opex_amount'];
        if ($actualTotal > 0) {
            $capexRatio = $result['capex_amount'] / $actualTotal;
            $opexRatio = $result['opex_amount'] / $actualTotal;
        } else {
            $capexRatio = 0;
            $opexRatio = 0;
        }

        $result['capex_tax'] = $totalTax * $capexRatio;
        $result['opex_tax'] = $totalTax * $opexRatio;
    }

    // Calculate totals INCLUDING TAX
    $result['total_capex'] = $result['capex_amount'] + $result['capex_tax'];
    $result['total_opex'] = $result['opex_amount'] + $result['opex_tax'];

    // Clear category if it's pure OPEX (no CAPEX content)
    if ($result['determination'] === 'OPEX' || $result['total_capex'] == 0) {
        $result['category'] = '';
    }

    return $result;
}

function analyzeWithGrokDetailed($workOrderData, $apiKey) {
    $workOrder = json_decode($workOrderData, true);

    $prompt = "You are a professional accountant with extensive experience in ASC 360 (Property, Plant, and Equipment) compliance and public entity financial reporting.

Analyze this work order with multiple line items. For EACH line item, determine its individual CAPEX vs OPEX allocation, then provide an overall determination.

Line Items:
" . implode("\n", array_map(function($item, $idx) {
    return ($idx + 1) . ". " . $item['description'] . " - Cost: $" . number_format($item['cost'], 2);
}, $workOrder['line_items'], array_keys($workOrder['line_items']))) . "

Total Cost: $" . number_format($workOrder['total_cost'], 2) . "
Total Tax: $" . number_format($workOrder['total_tax'], 2) . "

ASC 360 CAPEX Criteria:
- Replacement of major components (fan motors, compressors, switches, control boards, entire units)
- Significant repairs that extend asset life beyond one year
- Upgrades that increase capacity, efficiency, or quality of output
- Safety and environmental improvements

OPEX Criteria:
- ANY work order containing the word 'temporary' is automatically OPEX
- Routine maintenance (tape, filters, belts, cleaning)
- Minor repairs (leak repairs, recharging refrigerant)
- Inspections and evaluations
- Items that merely maintain existing condition
- Consumable supplies

Specific Guidelines:
- TEMPORARY WORK RULE: If the work order description contains 'temporary' anywhere, ALL items are 100% OPEX
- Tape, recharge, filters, inspection, leaks, evaluations, belts, leak repairs = 100% OPEX
- Fan motors, switches, replacement units, major repairs, hardware items = 100% CAPEX (unless temporary)
- CRITICAL: Every material/part/equipment must be classified as ENTIRELY (100%) CAPEX or ENTIRELY (100%) OPEX
- NO material, part, or piece of equipment can be split between CAPEX and OPEX
- Labor allocation: Proportional to the ratio of CAPEX materials to OPEX materials
- Shipping/Freight: If ANY materials are CAPEX, then 100% of shipping/freight charges go to CAPEX
- Trip charges: Allocated proportionally like labor based on material ratio

CRITICAL ALLOCATION RULES:
1. TEMPORARY WORK OVERRIDE: If any work order description contains 'temporary', ALL items are 100% OPEX regardless of type
2. ALL materials, parts, equipment, and hardware must be classified as either 100% CAPEX or 100% OPEX - NO SPLITTING
3. Each physical item/material is ENTIRELY one or the other based on its nature
4. Only labor, shipping, freight, and trip charges can be split proportionally

ALLOCATION PROCESS:
1. First classify each material/part/equipment as 100% CAPEX or 100% OPEX
2. Calculate the total dollar value of CAPEX materials vs OPEX materials
3. Determine the percentage split (e.g., if $800 CAPEX materials and $200 OPEX materials, then 80% CAPEX/20% OPEX)
4. SHIPPING/FREIGHT RULE: If ANY materials in the work order are CAPEX, then ALL shipping and freight charges are 100% CAPEX
5. LABOR RULE: Allocate labor proportionally based on the CAPEX/OPEX material ratio (e.g., 80% CAPEX materials = 80% of labor is CAPEX)
6. TRIP CHARGES: Follow the same proportional allocation as labor
7. TAXES: Follow the same proportional allocation as labor and trip charges based on the material ratio

REMEMBER:
- Materials/parts/equipment: Must be either CAPEX:100% OPEX:0% or CAPEX:0% OPEX:100%
- Shipping/Freight: If ANY materials are CAPEX, then CAPEX:100% OPEX:0%
- Labor: Split based on material ratio (e.g., CAPEX:70% OPEX:30% if materials are 70/30)
- Trip charges: Split same as labor
- Taxes: Split same as labor and trip charges based on material ratio

EXAMPLE: If work order has $800 fan motor (CAPEX), $200 filters (OPEX), $300 labor, $50 shipping, $85 tax:
- Fan motor: CAPEX:100% OPEX:0%
- Filters: CAPEX:0% OPEX:100%
- Labor: CAPEX:80% OPEX:20% (based on $800/$1000 material ratio = $240 CAPEX labor, $60 OPEX labor)
- Shipping: CAPEX:100% OPEX:0% (because CAPEX materials exist = $50 CAPEX)
- Tax: CAPEX:80% OPEX:20% (same as labor ratio = $68 CAPEX tax, $17 OPEX tax)

IMPORTANT: First check if the work order contains the word 'temporary' - if it does, ALL items are OPEX.

Please analyze EACH line item individually, then provide overall analysis. Respond in this EXACT format:

LINE 1: CAPEX:XX% OPEX:XX% [Brief explanation - if material/part, must be 100% one category]
LINE 2: CAPEX:XX% OPEX:XX% [Brief explanation - if labor, can be split based on material ratio]
[Continue for all line items]

OVERALL DETERMINATION: [CAPEX/OPEX/MIXED]
CATEGORY: [ONLY if determination is CAPEX or MIXED - Choose ONE: Controls/Automation OR Infrastructure/Utility OR Heater Replacement. If OPEX only, write N/A]
OVERALL JUSTIFICATION: [Professional explanation of the complete work order, citing ASC 360 criteria and explaining how the individual line items combine to form the overall determination]

CATEGORY RULES:
- ONLY categorize if the work order contains CAPEX items (CAPEX or MIXED determination)
- If the work order is purely OPEX, category should be N/A
- Category Definitions (for CAPEX work only):
  * Controls/Automation: Work related to control systems, automation equipment, thermostats, sensors, building management systems, electrical controls
  * Infrastructure/Utility: Work on building infrastructure, electrical systems, plumbing, structural components, utility connections, power distribution
  * Heater Replacement: HVAC systems, heating units, cooling systems, air handlers, compressors, refrigeration units, ventilation equipment";

    $data = [
        'model' => 'grok-4-fast',
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.3
    ];

    $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON Encoding Error: " . json_last_error_msg());
        return [
            'determination' => 'ERROR',
            'justification' => 'Failed to encode request data'
        ];
    }

    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    // Output API request details to console
    $apiInfo = [
        'endpoint' => 'https://api.x.ai/v1/chat/completions',
        'model' => $data['model'],
        'api_key' => $apiKey,
        'prompt' => $prompt,
        'temperature' => $data['temperature']
    ];
    echo '<script>console.log("=== API Request ===\\n", ' . json_encode($apiInfo, JSON_PRETTY_PRINT) . ');</script>';
    flush();

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        $errorMsg = "API Error (HTTP $httpCode)";
        if ($curlError) {
            $errorMsg .= " - CURL: $curlError";
        }
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= " - " . $errorData['error']['message'];
            }
            error_log("Grok API Error Response: " . $response);
        }
        return [
            'determination' => 'ERROR',
            'justification' => $errorMsg
        ];
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['choices'][0]['message']['content'])) {
        return [
            'determination' => 'ERROR',
            'justification' => 'Invalid API response'
        ];
    }

    $content = $responseData['choices'][0]['message']['content'];


    return [
        'determination' => 'UNKNOWN',
        'justification' => $content
    ];
}

function analyzeWithGrok($workOrderData, $apiKey) {
    $workOrder = json_decode($workOrderData, true);

    $prompt = "You are a professional accountant with extensive experience in ASC 360 (Property, Plant, and Equipment) compliance and public entity financial reporting.

Analyze this work order with multiple line items and determine the CAPEX vs OPEX allocation:

Line Items:
" . implode("\n", array_map(function($item, $idx) {
    return ($idx + 1) . ". " . $item['description'] . " - Cost: $" . number_format($item['cost'], 2);
}, $workOrder['line_items'], array_keys($workOrder['line_items']))) . "

Total Cost: $" . number_format($workOrder['total_cost'], 2) . "
Total Tax: $" . number_format($workOrder['total_tax'], 2) . "

ASC 360 CAPEX Criteria:
- Replacement of major components (fan motors, compressors, switches, control boards, entire units)
- Significant repairs that extend asset life beyond one year
- Upgrades that increase capacity, efficiency, or quality of output
- Safety and environmental improvements

OPEX Criteria:
- ANY work order containing the word 'temporary' is automatically OPEX
- Routine maintenance (tape, filters, belts, cleaning)
- Minor repairs (leak repairs, recharging refrigerant)
- Inspections and evaluations
- Items that merely maintain existing condition
- Consumable supplies

Specific Guidelines:
- TEMPORARY WORK RULE: If the work order description contains 'temporary' anywhere, ALL items are 100% OPEX
- Tape, recharge, filters, inspection, leaks, evaluations, belts, leak repairs = 100% OPEX
- Fan motors, switches, replacement units, major repairs, hardware items = 100% CAPEX (unless temporary)
- CRITICAL: Every material/part/equipment must be classified as ENTIRELY (100%) CAPEX or ENTIRELY (100%) OPEX
- NO material, part, or piece of equipment can be split between CAPEX and OPEX
- Labor allocation: Proportional to the ratio of CAPEX materials to OPEX materials
- Shipping/Freight: If ANY materials are CAPEX, then 100% of shipping/freight charges go to CAPEX
- Trip charges: Allocated proportionally like labor based on material ratio
- Tax should be allocated proportionally between CAPEX and OPEX

CRITICAL ALLOCATION RULES:
1. TEMPORARY WORK OVERRIDE: Any work order with 'temporary' in the description is automatically 100% OPEX
2. ALL hardware items, materials, parts, and equipment must be classified as either 100% CAPEX or 100% OPEX - NO SPLITTING OF MATERIALS
3. Each physical item is ENTIRELY CAPEX or ENTIRELY OPEX based on ASC 360 criteria
4. ONLY labor, shipping, freight, and trip charges can be allocated proportionally

ALLOCATION PROCESS:
1. Classify each material/part as 100% CAPEX or 100% OPEX (no splitting)
2. Calculate total value of CAPEX materials vs OPEX materials
3. Determine the percentage (e.g., $700 CAPEX parts + $300 OPEX parts = 70% CAPEX/30% OPEX)
4. SHIPPING/FREIGHT RULE: If ANY materials are CAPEX, then 100% of shipping/freight is CAPEX
5. LABOR RULE: Apply the material percentage to labor (70% CAPEX materials = 70% of labor is CAPEX)
6. TRIP CHARGES: Apply the same percentage as labor

IMPORTANT: First check if the work order contains the word 'temporary' - if it does, everything is OPEX.

Please analyze and respond in this format:
DETERMINATION: [CAPEX/OPEX/MIXED]
CATEGORY: [If CAPEX or MIXED: Controls/Automation OR Infrastructure/Utility OR Heater Replacement. If pure OPEX: N/A]
CAPEX_AMOUNT: [dollar amount without tax]
OPEX_AMOUNT: [dollar amount without tax]
JUSTIFICATION: [Professional explanation citing specific items and ASC 360 criteria, including how labor and service charges were allocated]

CATEGORY RULES:
- ONLY categorize work orders that contain CAPEX (CAPEX or MIXED determinations)
- Pure OPEX work orders should have category N/A
- Category Definitions (for CAPEX work):
  * Controls/Automation: Control systems, automation, thermostats, sensors, BMS
  * Infrastructure/Utility: Electrical, plumbing, structural, utilities, power systems
  * Heater Replacement: HVAC, heating, cooling, compressors, air handlers, refrigeration";

    $data = [
        'model' => 'grok-4-fast',
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.3
    ];

    $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("JSON Encoding Error: " . json_last_error_msg());
        return [
            'determination' => 'ERROR',
            'justification' => 'Failed to encode request data'
        ];
    }

    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        $errorMsg = "API Error (HTTP $httpCode)";
        if ($curlError) {
            $errorMsg .= " - CURL: $curlError";
        }
        if ($response) {
            $errorData = json_decode($response, true);
            if (isset($errorData['error']['message'])) {
                $errorMsg .= " - " . $errorData['error']['message'];
            }
            error_log("Grok API Error Response: " . $response);
        }
        return [
            'determination' => 'ERROR',
            'justification' => $errorMsg
        ];
    }

    $responseData = json_decode($response, true);

    if (!isset($responseData['choices'][0]['message']['content'])) {
        return [
            'determination' => 'ERROR',
            'justification' => 'Invalid API response'
        ];
    }

    $content = $responseData['choices'][0]['message']['content'];


    return [
        'determination' => 'UNKNOWN',
        'justification' => $content
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CAPEX Analyzer - ASC 360 Compliance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 3rem;
            text-align: center;
            transition: all 0.3s;
            background-color: #f8f9fa;
        }
        .upload-area:hover {
            border-color: #667eea;
            background-color: #f0f0ff;
        }
        .upload-icon {
            font-size: 3rem;
            color: #667eea;
            margin-bottom: 1rem;
        }
        .results-table {
            max-height: 500px;
            overflow-y: auto;
        }
        .badge-capex {
            background-color: #28a745;
        }
        .badge-opex {
            background-color: #dc3545;
        }
        .badge-mixed {
            background-color: #17a2b8;
        }
        .badge-error {
            background-color: #ffc107;
            color: #000;
        }
        .summary-card {
            border-left: 4px solid #667eea;
            margin-bottom: 1rem;
        }
        .summary-card .card-body {
            padding: 1rem;
        }
        .cost-summary {
            display: flex;
            justify-content: space-around;
            margin-top: 1rem;
        }
        .cost-item {
            text-align: center;
        }
        .cost-item h4 {
            margin-bottom: 0.5rem;
            color: #495057;
        }
        .cost-item .amount {
            font-size: 1.5rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="hero-section">
        <div class="container">
            <h1 class="display-4 fw-bold">CAPEX Analyzer</h1>
            <p class="lead">Professional ASC 360 Compliance Analysis</p>
            <p>Upload your CSV file for detailed CAPEX/OPEX determination with tax allocation</p>
        </div>
    </div>

    <div class="container">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (!isset($_SESSION['processing_complete']) || !$_SESSION['processing_complete']): ?>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Upload CSV File</h5>
                    <p class="card-text">Select a CSV file with work orders and invoice descriptions for analysis.</p>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="upload-area" onclick="document.getElementById('csvFile').click();">
                            <div class="upload-icon">📁</div>
                            <h4>Choose CSV File</h4>
                            <p class="text-muted">or drag and drop here</p>
                            <input type="file" name="csvFile" id="csvFile" accept=".csv" style="display: none;" required>
                            <p id="fileName" class="mt-2 text-primary"></p>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg mt-3 w-100" id="analyzeBtn">
                            Analyze Work Orders
                        </button>
                    </form>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <h5 class="card-title">How it Works</h5>
                    <ol>
                        <li>Upload a CSV file with work orders (first column) and IFM Invoice Descriptions</li>
                        <li>System groups all rows with the same work order number</li>
                        <li>AI analyzes each work order against ASC 360 criteria</li>
                        <li>Receive detailed CAPEX/OPEX allocations with tax distribution</li>
                        <li>Download enhanced CSV with cumulative results per work order</li>
                    </ol>
                    <div class="alert alert-info mt-3">
                        <strong>ASC 360 Professional Analysis:</strong>
                        <ul class="mb-0 mt-2">
                            <li><strong>CAPEX:</strong> Fan motors, switches, replacement units, major repairs extending life >1 year</li>
                            <li><strong>OPEX:</strong> Tape, filters, recharge, inspections, leaks, evaluations, belts, routine maintenance</li>
                            <li><strong>Tax Allocation:</strong> Proportionally distributed based on CAPEX/OPEX ratio</li>
                        </ul>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Analysis Complete!</h5>
                    <p class="card-text">Your work orders have been analyzed and consolidated.</p>

                    <div class="d-grid gap-2 d-md-flex justify-content-md-start mb-4">
                        <a href="?download=1" class="btn btn-success">
                            📥 Download Results CSV
                        </a>
                        <form method="POST" style="display: inline;">
                            <button type="submit" name="reset" class="btn btn-secondary">
                                🔄 Analyze Another File
                            </button>
                        </form>
                    </div>

                    <?php if (isset($_SESSION['processed_rows']) && count($_SESSION['processed_rows']) > 0): ?>
                        <?php
                        $totalCapex = array_sum(array_column($_SESSION['processed_rows'], 'capex_total'));
                        $totalOpex = array_sum(array_column($_SESSION['processed_rows'], 'opex_total'));
                        ?>
                        <div class="summary-card card">
                            <div class="card-body">
                                <h6>Summary Statistics</h6>
                                <div class="cost-summary">
                                    <div class="cost-item">
                                        <h4>Total CAPEX</h4>
                                        <div class="amount text-success">$<?php echo number_format($totalCapex, 2); ?></div>
                                        <small class="text-muted">(includes tax)</small>
                                    </div>
                                    <div class="cost-item">
                                        <h4>Total OPEX</h4>
                                        <div class="amount text-danger">$<?php echo number_format($totalOpex, 2); ?></div>
                                        <small class="text-muted">(includes tax)</small>
                                    </div>
                                    <div class="cost-item">
                                        <h4>Work Orders</h4>
                                        <div class="amount text-primary"><?php echo count($_SESSION['processed_rows']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h6>Work Order Analysis Details:</h6>
                        <div class="results-table">
                            <table class="table table-striped table-hover">
                                <thead class="sticky-top bg-white">
                                    <tr>
                                        <th style="width: 15%;">Work Order Number</th>
                                        <th style="width: 12%;">Determination</th>
                                        <th style="width: 15%;">CAPEX Total<br><small class="text-muted">(incl. tax)</small></th>
                                        <th style="width: 12%;">OPEX Total<br><small class="text-muted">(incl. tax)</small></th>
                                        <th style="width: 46%;">Justification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_SESSION['processed_rows'] as $result): ?>
                                        <tr>
                                            <td>
                                                <strong style="font-size: 1.1em;"><?php echo htmlspecialchars($result['work_order']); ?></strong>
                                                <br>
                                                <small class="text-muted"><?php echo $result['line_items']; ?> line items</small>
                                            </td>
                                            <td>
                                                <?php
                                                $class = 'badge-error';
                                                if ($result['determination'] === 'CAPEX') $class = 'badge-capex';
                                                elseif ($result['determination'] === 'OPEX') $class = 'badge-opex';
                                                elseif ($result['determination'] === 'MIXED') $class = 'badge-mixed';
                                                ?>
                                                <span class="badge <?php echo $class; ?>" style="font-size: 1em;">
                                                    <?php echo htmlspecialchars($result['determination']); ?>
                                                </span>
                                            </td>
                                            <td class="text-success" style="font-weight: bold; font-size: 1.1em;">$<?php echo number_format($result['capex_total'], 2); ?></td>
                                            <td class="text-danger">$<?php echo number_format($result['opex_total'], 2); ?></td>
                                            <td>
                                                <div style="max-height: 100px; overflow-y: auto;">
                                                    <?php echo htmlspecialchars($result['justification']); ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            unset($_SESSION['processing_complete']);
            unset($_SESSION['processed_rows']);
            ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('csvFile')?.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name || '';
            document.getElementById('fileName').textContent = fileName ? `Selected: ${fileName}` : '';
        });

        document.getElementById('uploadForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('analyzeBtn');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing Work Orders...';
            btn.disabled = true;
        });

        const uploadArea = document.querySelector('.upload-area');
        if (uploadArea) {
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.style.borderColor = '#667eea';
                uploadArea.style.backgroundColor = '#f0f0ff';
            });

            uploadArea.addEventListener('dragleave', (e) => {
                e.preventDefault();
                uploadArea.style.borderColor = '#dee2e6';
                uploadArea.style.backgroundColor = '#f8f9fa';
            });

            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    document.getElementById('csvFile').files = files;
                    document.getElementById('fileName').textContent = `Selected: ${files[0].name}`;
                }
                uploadArea.style.borderColor = '#dee2e6';
                uploadArea.style.backgroundColor = '#f8f9fa';
            });
        }
    </script>
</body>
</html>