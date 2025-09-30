<?php
session_start();
require_once 'config.php';

$geminiApiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : getenv('GEMINI_API_KEY');
$grokApiKey = defined('GROK_API_KEY') ? GROK_API_KEY : getenv('GROK_API_KEY');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
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
                padding: 0.75rem;
                margin: 0.5rem 0;
                border-left: 4px solid #dee2e6;
                background: white;
                border-radius: 5px;
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
            <h2>Processing CSV File...</h2>
            <div class="progress mb-3">
                <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%"></div>
            </div>
            <div class="progress-info">
                <p id="statusText">Initializing...</p>
            </div>
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
    $outputFile = sys_get_temp_dir() . '/capex_analysis_' . uniqid() . '.csv';

    // Process CSV with real-time updates
    processCSVRealtime($tempFile, $outputFile, $geminiApiKey, $grokApiKey);

    $_SESSION['results_file'] = $outputFile;
    $_SESSION['original_filename'] = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
    $_SESSION['processing_complete'] = true;

    ?>
            </div>
            <div class="mt-4">
                <a href="?download=1" class="btn btn-success">📥 Download Results CSV</a>
                <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="btn btn-secondary">🔄 Analyze Another File</a>
            </div>
        </div>
        <script>
            setTimeout(function() {
                document.getElementById('statusText').innerHTML = '<strong>✅ Processing Complete!</strong>';
                document.getElementById('progressBar').style.width = '100%';
                document.getElementById('progressBar').classList.remove('progress-bar-animated');
            }, 500);
        </script>
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

function processCSVRealtime($inputFile, $outputFile, $geminiApiKey, $grokApiKey) {
    $input = fopen($inputFile, 'r');
    $output = fopen($outputFile, 'w');

    if (!$input || !$output) {
        echo '<div class="alert alert-danger">Failed to process files</div>';
        return false;
    }

    // Read and process headers
    $headers = fgetcsv($input);
    if (!$headers) {
        echo '<div class="alert alert-danger">Empty CSV file</div>';
        return false;
    }

    // Find important column indices
    $columnMap = [];
    foreach ($headers as $index => $header) {
        $headerLower = strtolower(trim($header));
        if (strpos($headerLower, 'work order') !== false || $index === 0) {
            $columnMap['work_order'] = $index;
        }
        if (strpos($headerLower, 'ifm invoice description') !== false || strpos($headerLower, 'description') !== false) {
            $columnMap['description'] = $index;
        }
        if (strpos($headerLower, 'unit cost') !== false || strpos($headerLower, 'unit price') !== false) {
            $columnMap['unit_cost'] = $index;
        }
        if (strpos($headerLower, 'quantity') !== false || strpos($headerLower, 'qty') !== false) {
            $columnMap['quantity'] = $index;
        }
        if (strpos($headerLower, 'line item cost') !== false || strpos($headerLower, 'line cost') !== false || strpos($headerLower, 'extended') !== false) {
            $columnMap['line_cost'] = $index;
        }
        if (strpos($headerLower, 'tax') !== false && strpos($headerLower, 'total') !== false) {
            $columnMap['total_tax'] = $index;
        }
    }

    // Add new columns to headers
    $newHeaders = array_merge($headers, [
        'CAPEX/OPEX Determination',
        'CAPEX Amount',
        'OPEX Amount',
        'CAPEX Tax Allocation',
        'OPEX Tax Allocation',
        'Total CAPEX',
        'Total OPEX',
        'Justification'
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

    echo '<script>document.getElementById("statusText").innerHTML = "Processing ' . $totalWorkOrders . ' work orders...";</script>';
    flush();

    foreach ($workOrders as $workOrderNum => $rows) {
        $processedCount++;
        $progress = round(($processedCount / $totalWorkOrders) * 100);

        echo '<script>
            document.getElementById("progressBar").style.width = "' . $progress . '%";
            document.getElementById("statusText").innerHTML = "Processing work order ' . $processedCount . ' of ' . $totalWorkOrders . '...";
        </script>';
        flush();

        echo '<div class="row-result pending" id="wo-' . htmlspecialchars($workOrderNum) . '">
                <strong>WO# ' . htmlspecialchars($workOrderNum) . ':</strong>
                <span class="text-muted">Analyzing ' . count($rows) . ' line items...</span>
              </div>';
        flush();

        // Analyze the work order
        $analysis = analyzeWorkOrder($rows, $columnMap, $geminiApiKey, $grokApiKey);

        // Write cumulative row for this work order
        $outputRow = $rows[0]; // Start with first row's data

        // Add analysis results
        $outputRow = array_pad($outputRow, count($headers), '');
        $outputRow[] = $analysis['determination'];
        $outputRow[] = number_format($analysis['capex_amount'], 2);
        $outputRow[] = number_format($analysis['opex_amount'], 2);
        $outputRow[] = number_format($analysis['capex_tax'], 2);
        $outputRow[] = number_format($analysis['opex_tax'], 2);
        $outputRow[] = number_format($analysis['total_capex'], 2);
        $outputRow[] = number_format($analysis['total_opex'], 2);
        $outputRow[] = $analysis['justification'];

        fputcsv($output, $outputRow);

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

        echo '<script>
            document.getElementById("wo-' . htmlspecialchars($workOrderNum) . '").className = "row-result ' . $borderClass . '";
            document.getElementById("wo-' . htmlspecialchars($workOrderNum) . '").innerHTML =
                "<strong>WO# ' . htmlspecialchars(addslashes($workOrderNum)) . ':</strong> " +
                "<span class=\"badge ' . $badgeClass . '\">' . $analysis['determination'] . '</span>" +
                "<div class=\"cost-breakdown\">" +
                "<span class=\"text-success\">CAPEX: $' . number_format($analysis['total_capex'], 2) . '</span>" +
                "<span class=\"text-danger\">OPEX: $' . number_format($analysis['total_opex'], 2) . '</span>" +
                "</div>" +
                "<br><small>' . htmlspecialchars(addslashes(substr($analysis['justification'], 0, 200))) . '...</small>";
        </script>';
        flush();

        $_SESSION['processed_rows'][] = [
            'work_order' => $workOrderNum,
            'line_items' => count($rows),
            'determination' => $analysis['determination'],
            'capex_total' => $analysis['total_capex'],
            'opex_total' => $analysis['total_opex'],
            'justification' => $analysis['justification']
        ];

        // Small delay to prevent API rate limiting
        usleep(300000); // 0.3 seconds
    }

    fclose($input);
    fclose($output);

    return true;
}

function analyzeWorkOrder($rows, $columnMap, $geminiApiKey, $grokApiKey) {
    // Collect all descriptions and costs
    $descriptions = [];
    $totalCost = 0;
    $totalTax = 0;
    $lineItems = [];

    foreach ($rows as $row) {
        $description = isset($columnMap['description']) ? $row[$columnMap['description']] : '';
        $unitCost = isset($columnMap['unit_cost']) ? floatval(str_replace(['$', ','], '', $row[$columnMap['unit_cost']])) : 0;
        $quantity = isset($columnMap['quantity']) ? floatval($row[$columnMap['quantity']]) : 1;
        $lineCost = isset($columnMap['line_cost']) ? floatval(str_replace(['$', ','], '', $row[$columnMap['line_cost']])) : ($unitCost * $quantity);

        if (!empty($description)) {
            $descriptions[] = trim($description);
            $lineItems[] = [
                'description' => $description,
                'cost' => $lineCost
            ];
            $totalCost += $lineCost;
        }

        // Get tax (usually same for all rows in a work order)
        if (isset($columnMap['total_tax']) && $totalTax == 0) {
            $totalTax = floatval(str_replace(['$', ','], '', $row[$columnMap['total_tax']]));
        }
    }

    if (empty($descriptions)) {
        return [
            'determination' => 'ERROR',
            'capex_amount' => 0,
            'opex_amount' => 0,
            'capex_tax' => 0,
            'opex_tax' => 0,
            'total_capex' => 0,
            'total_opex' => 0,
            'justification' => 'No descriptions found in work order'
        ];
    }

    // Prepare data for AI analysis
    $workOrderData = json_encode([
        'descriptions' => $descriptions,
        'line_items' => $lineItems,
        'total_cost' => $totalCost,
        'total_tax' => $totalTax
    ]);

    // Get AI analysis
    $analysis = analyzeWithAI($workOrderData, $geminiApiKey, $grokApiKey);

    // Parse the AI response to extract amounts
    return parseAIResponse($analysis, $totalCost, $totalTax, $lineItems);
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
        'justification' => $analysis['justification']
    ];

    // Extract determination
    if (preg_match('/DETERMINATION:\s*(CAPEX|OPEX|MIXED)/i', $analysis['justification'], $matches)) {
        $result['determination'] = strtoupper($matches[1]);
    }

    // Extract amounts from AI response
    if (preg_match('/CAPEX_AMOUNT:\s*\$?([\d,]+\.?\d*)/i', $analysis['justification'], $matches)) {
        $result['capex_amount'] = floatval(str_replace(',', '', $matches[1]));
    }
    if (preg_match('/OPEX_AMOUNT:\s*\$?([\d,]+\.?\d*)/i', $analysis['justification'], $matches)) {
        $result['opex_amount'] = floatval(str_replace(',', '', $matches[1]));
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

    // Allocate tax proportionally
    if ($totalCost > 0) {
        $capexRatio = $result['capex_amount'] / $totalCost;
        $opexRatio = $result['opex_amount'] / $totalCost;

        $result['capex_tax'] = $totalTax * $capexRatio;
        $result['opex_tax'] = $totalTax * $opexRatio;
    }

    // Calculate totals
    $result['total_capex'] = $result['capex_amount'] + $result['capex_tax'];
    $result['total_opex'] = $result['opex_amount'] + $result['opex_tax'];

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
        echo '<script>console.log("Gemini API failed: ' . addslashes($result['justification']) . '");</script>';
    } else {
        echo '<script>console.log("Gemini API key is empty, skipping to Grok");</script>';
    }

    // Fallback to Grok
    if (!empty($grokApiKey)) {
        echo '<script>console.log("Trying Grok API...");</script>';
        $result = analyzeWithGrok($workOrderData, $grokApiKey);
        if ($result['determination'] !== 'ERROR') {
            echo '<script>console.log("Grok API succeeded");</script>';
            return $result;
        }
        error_log("Grok API also failed: " . $result['justification']);
        echo '<script>console.error("Grok API failed: ' . addslashes($result['justification']) . '");</script>';
    } else {
        echo '<script>console.log("Grok API key is empty");</script>';
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
- Generally involves material costs over $500

OPEX Criteria:
- Routine maintenance (tape, filters, belts, cleaning)
- Minor repairs (leak repairs, recharging refrigerant)
- Inspections and evaluations
- Items that merely maintain existing condition
- Consumable supplies

Specific Guidelines:
- Tape, recharge, filters, inspection, leaks, evaluations, belts, leak repairs = OPEX
- Fan motors, switches, replacement units, major repairs = CAPEX
- Labor follows the same classification as the materials it's associated with
- Tax should be allocated proportionally between CAPEX and OPEX

Please analyze each line item and respond in this format:
DETERMINATION: [CAPEX/OPEX/MIXED]
CAPEX_AMOUNT: [dollar amount without tax]
OPEX_AMOUNT: [dollar amount without tax]
JUSTIFICATION: [Professional explanation citing specific items and ASC 360 criteria. For MIXED classifications, specify which items are CAPEX and which are OPEX]";

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
- Generally involves material costs over $500

OPEX Criteria:
- Routine maintenance (tape, filters, belts, cleaning)
- Minor repairs (leak repairs, recharging refrigerant)
- Inspections and evaluations
- Items that merely maintain existing condition
- Consumable supplies

Specific Guidelines:
- Tape, recharge, filters, inspection, leaks, evaluations, belts, leak repairs = OPEX
- Fan motors, switches, replacement units, major repairs = CAPEX
- Labor follows the same classification as the materials it's associated with
- Tax should be allocated proportionally between CAPEX and OPEX

Please analyze each line item and respond in this format:
DETERMINATION: [CAPEX/OPEX/MIXED]
CAPEX_AMOUNT: [dollar amount without tax]
OPEX_AMOUNT: [dollar amount without tax]
JUSTIFICATION: [Professional explanation citing specific items and ASC 360 criteria. For MIXED classifications, specify which items are CAPEX and which are OPEX]";

    $data = [
        'model' => 'grok-4-fast-non-reasoning',
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
            echo '<script>console.error("Grok API HTTP ' . $httpCode . ' Response:", ' . json_encode($response) . ');</script>';
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
                                    </div>
                                    <div class="cost-item">
                                        <h4>Total OPEX</h4>
                                        <div class="amount text-danger">$<?php echo number_format($totalOpex, 2); ?></div>
                                    </div>
                                    <div class="cost-item">
                                        <h4>Work Orders</h4>
                                        <div class="amount text-primary"><?php echo count($_SESSION['processed_rows']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h6>Work Order Details:</h6>
                        <div class="results-table">
                            <table class="table table-striped table-hover">
                                <thead class="sticky-top bg-white">
                                    <tr>
                                        <th>Work Order</th>
                                        <th>Line Items</th>
                                        <th>Classification</th>
                                        <th>CAPEX Total</th>
                                        <th>OPEX Total</th>
                                        <th>Justification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_SESSION['processed_rows'] as $result): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($result['work_order']); ?></strong></td>
                                            <td><?php echo $result['line_items']; ?></td>
                                            <td>
                                                <?php
                                                $class = 'badge-error';
                                                if ($result['determination'] === 'CAPEX') $class = 'badge-capex';
                                                elseif ($result['determination'] === 'OPEX') $class = 'badge-opex';
                                                elseif ($result['determination'] === 'MIXED') $class = 'badge-mixed';
                                                ?>
                                                <span class="badge <?php echo $class; ?>">
                                                    <?php echo htmlspecialchars($result['determination']); ?>
                                                </span>
                                            </td>
                                            <td class="text-success">$<?php echo number_format($result['capex_total'], 2); ?></td>
                                            <td class="text-danger">$<?php echo number_format($result['opex_total'], 2); ?></td>
                                            <td><small><?php echo htmlspecialchars(substr($result['justification'], 0, 150)); ?>...</small></td>
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