<?php
session_start();
require_once 'config.php';

$apiKey = defined('GROK_API_KEY') ? GROK_API_KEY : getenv('GROK_API_KEY');

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
                max-width: 1200px;
                margin: 0 auto;
            }
            .progress-info {
                background: #f8f9fa;
                padding: 1rem;
                border-radius: 5px;
                margin-bottom: 1rem;
            }
            .row-result {
                padding: 0.5rem;
                margin: 0.25rem 0;
                border-left: 3px solid #dee2e6;
                background: white;
            }
            .row-result.success { border-left-color: #28a745; }
            .row-result.error { border-left-color: #dc3545; }
            .row-result.pending { border-left-color: #ffc107; }
            .results-container {
                max-height: 500px;
                overflow-y: auto;
                border: 1px solid #dee2e6;
                border-radius: 5px;
                padding: 1rem;
                background: #ffffff;
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
    processCSVRealtime($tempFile, $outputFile, $apiKey);

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

function processCSVRealtime($inputFile, $outputFile, $apiKey) {
    $input = fopen($inputFile, 'r');
    $output = fopen($outputFile, 'w');

    if (!$input || !$output) {
        echo '<div class="alert alert-danger">Failed to process files</div>';
        return false;
    }

    $headers = fgetcsv($input);
    if (!$headers) {
        echo '<div class="alert alert-danger">Empty CSV file</div>';
        return false;
    }

    $newHeaders = array_merge($headers, ['CAPEX Determination', 'Justification']);
    fputcsv($output, $newHeaders);

    // Count total rows for progress
    $totalRows = 0;
    while (fgetcsv($input) !== false) {
        $totalRows++;
    }
    rewind($input);
    fgetcsv($input); // Skip headers again

    $_SESSION['processed_rows'] = [];
    $rowNumber = 0;
    $processedCount = 0;

    echo '<script>document.getElementById("statusText").innerHTML = "Processing ' . $totalRows . ' rows...";</script>';
    flush();

    while (($row = fgetcsv($input)) !== false) {
        $rowNumber++;
        $processedCount++;
        $progress = round(($processedCount / $totalRows) * 100);

        // Get work order number from first column (index 0)
        $workOrderNumber = isset($row[0]) ? trim($row[0]) : 'Row ' . $rowNumber;

        echo '<script>
            document.getElementById("progressBar").style.width = "' . $progress . '%";
            document.getElementById("statusText").innerHTML = "Processing work order ' . $processedCount . ' of ' . $totalRows . '...";
        </script>';
        flush();

        if (count($row) < 4) {
            $row[] = 'N/A';
            $row[] = 'Insufficient data - row has less than 4 columns';
            fputcsv($output, $row);

            echo '<div class="row-result error">
                    <strong>WO# ' . htmlspecialchars($workOrderNumber) . ':</strong>
                    <span class="badge bg-warning text-dark">SKIPPED</span> - Insufficient columns
                  </div>';
            flush();
            continue;
        }

        $description = $row[3];

        if (empty(trim($description))) {
            $row[] = 'N/A';
            $row[] = 'No description provided';
            fputcsv($output, $row);

            echo '<div class="row-result error">
                    <strong>WO# ' . htmlspecialchars($workOrderNumber) . ':</strong>
                    <span class="badge bg-warning text-dark">SKIPPED</span> - No description
                  </div>';
            flush();
            continue;
        }

        // Show what we're analyzing
        echo '<div class="row-result pending" id="row-' . $rowNumber . '">
                <strong>WO# ' . htmlspecialchars($workOrderNumber) . ':</strong>
                <span class="text-muted">Analyzing: ' . htmlspecialchars(substr($description, 0, 100)) . '...</span>
              </div>';
        flush();

        $analysis = analyzeWithGrok($description, $apiKey);

        $row[] = $analysis['determination'];
        $row[] = $analysis['justification'];

        fputcsv($output, $row);

        // Update the row with results
        $badgeClass = 'bg-secondary';
        $borderClass = 'error';
        if ($analysis['determination'] === 'YES') {
            $badgeClass = 'bg-success';
            $borderClass = 'success';
        } elseif ($analysis['determination'] === 'NO') {
            $badgeClass = 'bg-danger';
            $borderClass = 'success';
        } elseif ($analysis['determination'] === 'ERROR') {
            $badgeClass = 'bg-warning text-dark';
            $borderClass = 'error';
        }

        echo '<script>
            document.getElementById("row-' . $rowNumber . '").className = "row-result ' . $borderClass . '";
            document.getElementById("row-' . $rowNumber . '").innerHTML =
                "<strong>WO# ' . htmlspecialchars(addslashes($workOrderNumber)) . ':</strong> " +
                "<span class=\"badge ' . $badgeClass . '\">CAPEX: ' . $analysis['determination'] . '</span> - " +
                "<small>' . htmlspecialchars(addslashes($analysis['justification'])) . '</small>";
        </script>';
        flush();

        $_SESSION['processed_rows'][] = [
            'work_order' => $workOrderNumber,
            'description' => substr($description, 0, 100),
            'determination' => $analysis['determination'],
            'justification' => $analysis['justification']
        ];

        // Small delay to prevent API rate limiting
        usleep(300000); // 0.3 seconds
    }

    fclose($input);
    fclose($output);

    return true;
}

function processCSV($inputFile, $outputFile, $apiKey) {
    $input = fopen($inputFile, 'r');
    $output = fopen($outputFile, 'w');

    if (!$input || !$output) {
        $_SESSION['error'] = 'Failed to process files';
        return false;
    }

    $headers = fgetcsv($input);
    if (!$headers) {
        $_SESSION['error'] = 'Empty CSV file';
        return false;
    }

    $newHeaders = array_merge($headers, ['CAPEX Determination', 'Justification']);
    fputcsv($output, $newHeaders);

    $_SESSION['processed_rows'] = [];
    $rowNumber = 0;

    while (($row = fgetcsv($input)) !== false) {
        $rowNumber++;

        if (count($row) < 4) {
            $row[] = 'N/A';
            $row[] = 'Insufficient data - row has less than 4 columns';
            fputcsv($output, $row);
            continue;
        }

        $description = $row[3];

        if (empty(trim($description))) {
            $row[] = 'N/A';
            $row[] = 'No description provided';
            fputcsv($output, $row);
            continue;
        }

        $analysis = analyzeWithGrok($description, $apiKey);

        $row[] = $analysis['determination'];
        $row[] = $analysis['justification'];

        fputcsv($output, $row);

        $_SESSION['processed_rows'][] = [
            'row' => $rowNumber,
            'description' => substr($description, 0, 100),
            'determination' => $analysis['determination'],
            'justification' => $analysis['justification']
        ];

        usleep(500000);
    }

    fclose($input);
    fclose($output);

    return true;
}

function analyzeWithGrok($description, $apiKey) {
    // Clean and sanitize the description
    $description = str_replace(["\r", "\n", "\t"], ' ', $description); // Remove line breaks and tabs
    $description = preg_replace('/\s+/', ' ', $description); // Replace multiple spaces with single space
    $description = trim($description);

    // Remove non-ASCII characters and special quotes
    $description = preg_replace('/[^\x20-\x7E]/', '', $description); // Remove non-printable characters

    // Escape for JSON after cleaning
    $description = addslashes($description); // Properly escape for JSON

    $prompt = "You are an expert accountant familiar with ASC 360 (Property, Plant, and Equipment) rules. And this analysis should be quick and not in depth.

Analyze the following expense description and determine if it qualifies as CAPEX (Capital Expenditure) under ASC 360 guidelines but use them understanding that nearly any repair will extend the life of the asset significantly.

ASC 360 Key Criteria for CAPEX:
1. The cost must provide future economic benefits beyond the current period (typically > 1 year) which includes changing compressors, electrical wiring and boards, anything that isn't recharging or cleaning.
2. The amount must be material/significant over $500 but this should not be mentioned in the justification.
3. It must either:
   - Be a new asset acquisition
   - Significantly extend the useful life of an existing asset
   - Increase the capacity or efficiency of an existing asset
   - Improve the quality of output from an existing asset including safety and environmental upgrades

Expenses that are typically OPEX (not CAPEX):
- Routine maintenance. All repairs are CAPEX unless they are routine maintenance.
- Costs that merely maintain an asset's existing condition which are typically below $1000.
- Costs that restore an asset to its original operating efficiency including cleaning, repainting, or charing refrigerant.

Description to analyze: " . $description . "

Please respond in the following format:
DETERMINATION: [YES/NO]
JUSTIFICATION: [Provide a clear, concise explanation based on ASC 360 criteria in 1-2 sentences]

Speed of analysis is important, analysis per request should not exceed 10 seconds.";

    $data = [
        'model' => 'grok-4-fast-non-reasoning',
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7
    ];

    // Debug: Log the request data
    error_log("Grok API Request: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Ensure proper JSON encoding with all necessary flags
    $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Additional check for JSON encoding errors
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
            // Output to browser console for debugging
            echo "<script>console.error('API Error Details:', " . json_encode($errorData) . ");</script>";
            error_log("Grok API Error: " . json_encode($errorData));
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

    $determination = 'UNKNOWN';
    $justification = $content;

    if (preg_match('/DETERMINATION:\s*(YES|NO)/i', $content, $matches)) {
        $determination = strtoupper($matches[1]);
    }

    if (preg_match('/JUSTIFICATION:\s*(.+)/is', $content, $matches)) {
        $justification = trim($matches[1]);
    }

    return [
        'determination' => $determination,
        'justification' => $justification
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
        .badge-yes {
            background-color: #28a745;
        }
        .badge-no {
            background-color: #dc3545;
        }
        .badge-error {
            background-color: #ffc107;
            color: #000;
        }
    </style>
</head>
<body>
    <div class="hero-section">
        <div class="container">
            <h1 class="display-4 fw-bold">CAPEX Analyzer</h1>
            <p class="lead">ASC 360 Compliance Analysis using AI</p>
            <p>Upload your CSV file and get instant CAPEX/OPEX determinations based on ASC 360 accounting rules</p>
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
                    <p class="card-text">Select a CSV file with expense descriptions in the 4th column for analysis.</p>

                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="upload-area" onclick="document.getElementById('csvFile').click();">
                            <div class="upload-icon">📁</div>
                            <h4>Choose CSV File</h4>
                            <p class="text-muted">or drag and drop here</p>
                            <input type="file" name="csvFile" id="csvFile" accept=".csv" style="display: none;" required>
                            <p id="fileName" class="mt-2 text-primary"></p>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg mt-3 w-100" id="analyzeBtn">
                            Analyze
                        </button>
                    </form>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <h5 class="card-title">How it Works</h5>
                    <ol>
                        <li>Upload a CSV file containing expense descriptions in the 4th column</li>
                        <li>Each description is analyzed against ASC 360 criteria</li>
                        <li>Get instant CAPEX/OPEX determinations with justifications</li>
                        <li>Download the enhanced CSV with analysis results</li>
                    </ol>
                    <div class="alert alert-info mt-3">
                        <strong>ASC 360 Criteria:</strong> Costs that provide future economic benefits, extend asset life,
                        or improve asset capacity/efficiency are typically classified as CAPEX.
                    </div>
                </div>
            </div>

        <?php else: ?>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Analysis Complete!</h5>
                    <p class="card-text">Your CSV file has been processed. Below are the results:</p>

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
                        <h6>Processed Rows Summary:</h6>
                        <div class="results-table">
                            <table class="table table-striped table-hover">
                                <thead class="sticky-top bg-white">
                                    <tr>
                                        <th>Work Order</th>
                                        <th>Description</th>
                                        <th>CAPEX?</th>
                                        <th>Justification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_SESSION['processed_rows'] as $result): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($result['work_order'] ?? $result['row'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($result['description']); ?>...</td>
                                            <td>
                                                <?php
                                                $class = 'badge-error';
                                                if ($result['determination'] === 'YES') $class = 'badge-yes';
                                                elseif ($result['determination'] === 'NO') $class = 'badge-no';
                                                ?>
                                                <span class="badge <?php echo $class; ?>">
                                                    <?php echo htmlspecialchars($result['determination']); ?>
                                                </span>
                                            </td>
                                            <td><small><?php echo htmlspecialchars($result['justification']); ?></small></td>
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
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';
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