<?php
// Wabbs WhatsApp Template Sender
// Upload this file to your PHP hosting and open it in a browser.

const WABBS_ENDPOINT = 'https://wabbs.in/api/wa-send';
const WABBS_TOKEN = 'odzk_live_mSErnbpHGA79Lga4ZmAedVhp';

const RECIPIENTS = [
    '919655086164',
    '917200314099',
];

function buildTemplatePayload(string $templateName, string $to): array
{
    $templates = [
        'reminder' => [
            'name' => 'reminder',
            'language' => 'en',
            'variables' => [
                'Ari',
                '15 Sep 2026',
                "1. Review today's client tasks\n2. Follow up pending payments\n3. Check development updates",
                "11:00 AM - Project Review\n4:00 PM - Client Follow-up",
            ],
        ],
        'project_confirmation' => [
            'name' => 'project_confirmation',
            'language' => 'en',
            'variables' => [
                'Priya',
                'Website Development',
                '25000',
                '10000',
                '15000',
            ],
        ],
        'paymen_received' => [
            'name' => 'paymen_received',
            'language' => 'en',
            'variables' => [
                'Priya',
                '5000',
                'Website Development',
                '10000',
            ],
        ],
        'otp' => [
            'name' => 'otp',
            'language' => 'en_US',
            'variables' => [
                '482731',
            ],
        ],
    ];

    if (!isset($templates[$templateName])) {
        throw new InvalidArgumentException('Unknown template: ' . $templateName);
    }

    $config = $templates[$templateName];
    $template = [
        'name' => $config['name'],
        'language' => $config['language'],
    ];

    foreach ($config['variables'] as $index => $value) {
        $template['variables_' . ($index + 1)] = $value;
    }

    return [
        'to' => $to,
        'type' => 'template',
        'template' => $template,
    ];
}

function sendTemplateRequest(array $payload): array
{
    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'http_code' => 0,
            'response' => 'PHP cURL extension is not enabled on this server.',
        ];
    }

    $ch = curl_init(WABBS_ENDPOINT);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WABBS_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return [
            'ok' => false,
            'http_code' => $httpCode,
            'response' => $curlError ?: 'Unknown cURL error',
        ];
    }

    $decoded = json_decode($response, true);

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response' => $decoded ?? $response,
    ];
}

function maskPhone(string $phone): string
{
    if (strlen($phone) <= 6) {
        return $phone;
    }

    return substr($phone, 0, 4) . str_repeat('*', strlen($phone) - 8) . substr($phone, -4);
}

if (PHP_SAPI === 'cli') {
    return;
}

$allowedTemplates = ['reminder', 'project_confirmation', 'paymen_received', 'otp'];
$results = [];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $templateName = $_POST['template'] ?? '';

    if (!in_array($templateName, $allowedTemplates, true)) {
        $error = 'Invalid template selected.';
    } else {
        foreach (RECIPIENTS as $recipient) {
            try {
                $payload = buildTemplatePayload($templateName, $recipient);
                $result = sendTemplateRequest($payload);
                $result['recipient'] = $recipient;
                $results[] = $result;
            } catch (Throwable $e) {
                $results[] = [
                    'ok' => false,
                    'http_code' => 0,
                    'response' => $e->getMessage(),
                    'recipient' => $recipient,
                ];
            }
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Template Sender</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f6;
            color: #17221d;
        }
        .wrap {
            max-width: 920px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .header {
            margin-bottom: 22px;
        }
        .header h1 {
            margin: 0 0 8px;
            font-size: 28px;
        }
        .header p {
            margin: 0;
            color: #65716b;
        }
        .notice {
            background: #fff8dc;
            border: 1px solid #eadb96;
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        .card {
            background: #fff;
            border: 1px solid #e3e8e5;
            border-radius: 14px;
            padding: 18px;
            box-shadow: 0 4px 14px rgba(0,0,0,.04);
        }
        .card h3 {
            margin: 0 0 8px;
            font-size: 18px;
        }
        .card p {
            margin: 0 0 16px;
            color: #6d7772;
            font-size: 14px;
            min-height: 38px;
        }
        button {
            width: 100%;
            border: 0;
            background: #128c7e;
            color: white;
            padding: 12px 14px;
            border-radius: 9px;
            font-weight: 700;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover { background: #0e7368; }
        .results {
            margin-top: 24px;
        }
        .result {
            background: #fff;
            border-radius: 10px;
            padding: 14px;
            margin-bottom: 10px;
            border-left: 5px solid #d64040;
        }
        .result.ok { border-left-color: #1d9c5a; }
        .result strong { display: block; margin-bottom: 6px; }
        pre {
            margin: 8px 0 0;
            white-space: pre-wrap;
            word-break: break-word;
            background: #f7f8f8;
            padding: 10px;
            border-radius: 7px;
            font-size: 12px;
        }
        .error {
            background: #ffe9e9;
            color: #a62929;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .recipients {
            margin-top: 8px;
            font-size: 13px;
            color: #65716b;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="header">
        <h1>WhatsApp Template Sender</h1>
        <p>Send approved Meta templates through Wabbs API in one tap.</p>
        <div class="recipients">
            Recipients: <?= e(maskPhone(RECIPIENTS[0])) ?> and <?= e(maskPhone(RECIPIENTS[1])) ?>
        </div>
    </div>

    <div class="notice">
        Clicking a button sends that template immediately to both configured WhatsApp numbers.
    </div>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="card">
            <h3>Daily Reminder</h3>
            <p>Template: reminder · Language: en</p>
            <form method="post">
                <input type="hidden" name="template" value="reminder">
                <button type="submit">Send Reminder</button>
            </form>
        </div>

        <div class="card">
            <h3>Project Confirmation</h3>
            <p>Template: project_confirmation · Language: en</p>
            <form method="post">
                <input type="hidden" name="template" value="project_confirmation">
                <button type="submit">Send Project Confirmation</button>
            </form>
        </div>

        <div class="card">
            <h3>Payment Received</h3>
            <p>Template: paymen_received · Language: en</p>
            <form method="post">
                <input type="hidden" name="template" value="paymen_received">
                <button type="submit">Send Payment Received</button>
            </form>
        </div>

        <div class="card">
            <h3>OTP</h3>
            <p>Template: otp · Language: en_US</p>
            <form method="post">
                <input type="hidden" name="template" value="otp">
                <button type="submit">Send OTP</button>
            </form>
        </div>
    </div>

    <?php if ($results): ?>
        <div class="results">
            <h2>API Response</h2>
            <?php foreach ($results as $result): ?>
                <div class="result <?= $result['ok'] ? 'ok' : '' ?>">
                    <strong>
                        <?= $result['ok'] ? 'Sent' : 'Failed' ?> to <?= e(maskPhone($result['recipient'])) ?>
                        · HTTP <?= (int) $result['http_code'] ?>
                    </strong>
                    <pre><?= e(json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
