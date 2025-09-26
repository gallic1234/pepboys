<?php
session_start();
require_once 'config.php';

$apiKey = defined('GROK_API_KEY') ? GROK_API_KEY : getenv('GROK_API_KEY');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvFile'])) {
    $uploadedFile = $_FILES['csvFile'];

    if ($uploadedFile['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = 'Upload failed with error code: ' . $uploadedFile['error'];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (pathinfo($uploadedFile['name'], PATHINFO_EXTENSION) !== 'csv') {
        $_SESSION['error'] = 'Please upload a CSV file';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $tempFile = $uploadedFile['tmp_name'];
    $outputFile = sys_get_temp_dir() . '/capex_analysis_' . uniqid() . '.csv';

    processCSV($tempFile, $outputFile, $apiKey);

    $_SESSION['results_file'] = $outputFile;
    $_SESSION['original_filename'] = pathinfo($uploadedFile['name'], PATHINFO_FILENAME);
    $_SESSION['processing_complete'] = true;

    header('Location: ' . $_SERVER['PHP_SELF']);
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
    $prompt = "You are an expert accountant familiar with ASC 360 (Property, Plant, and Equipment) rules.

Analyze the following expense description and determine if it qualifies as CAPEX (Capital Expenditure) under ASC 360 guidelines.

ASC 360 Key Criteria for CAPEX:
1. The cost must provide future economic benefits beyond the current period (typically > 1 year)
2. The amount must be material/significant
3. It must either:
   - Be a new asset acquisition
   - Significantly extend the useful life of an existing asset
   - Increase the capacity or efficiency of an existing asset
   - Improve the quality of output from an existing asset

Expenses that are typically OPEX (not CAPEX):
- Routine maintenance and repairs
- Costs that merely maintain an asset's existing condition
- Costs that restore an asset to its original operating efficiency

Description to analyze: \"$description\"

Please respond in the following format:
DETERMINATION: [YES/NO]
JUSTIFICATION: [Provide a clear, concise explanation based on ASC 360 criteria in 1-2 sentences]";

    $data = [
        'model' => 'grok-2',
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'temperature' => 0.7
    ];

    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
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
                            Analyze with Grok AI
                        </button>
                    </form>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <h5 class="card-title">How it Works</h5>
                    <ol>
                        <li>Upload a CSV file containing expense descriptions in the 4th column</li>
                        <li>Each description is analyzed using Grok AI against ASC 360 criteria</li>
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
                                        <th>Row</th>
                                        <th>Description</th>
                                        <th>CAPEX?</th>
                                        <th>Justification</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($_SESSION['processed_rows'] as $result): ?>
                                        <tr>
                                            <td><?php echo $result['row']; ?></td>
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