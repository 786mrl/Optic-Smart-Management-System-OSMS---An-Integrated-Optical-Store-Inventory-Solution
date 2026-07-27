<?php
/**
 * analyze_customer_history.php
 * -------------------------------------------------------------
 * Trend-based sibling of analyze_prescription.php. Instead of analyzing a
 * single visit, this looks at a customer's FULL examination history to spot
 * patterns across visits (progression speed, stability, recurring symptoms,
 * presbyopia onset, etc). Same Gemini setup/config as analyze_prescription.php
 * — see that file for API key setup instructions.
 * -------------------------------------------------------------
 */

session_start();
header('Content-Type: application/json');

include 'db_config.php';
include 'config_helper.php';

// 1. AUTHENTICATION GUARD
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in again.']);
    exit();
}

// 2. METHOD CHECK
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// 3. API KEY CHECK
if (!defined('GEMINI_API_KEY') || empty(GEMINI_API_KEY)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Gemini API key not configured.',
        'hint'  => "Get a free key at https://aistudio.google.com/app/apikey then add define('GEMINI_API_KEY', 'AIzaSy...'); to config_helper.php"
    ]);
    exit();
}

// 4. PARSE INPUT
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input) || empty($input['visits']) || !is_array($input['visits'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload: no visit history provided.']);
    exit();
}
if (count($input['visits']) < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'At least 1 examination is required for analysis.']);
    exit();
}

// 5. BUILD VISIT-BY-VISIT HISTORY TEXT
function formatVisit($i, $v) {
    $needs = trim(
        (!empty($v['need_distance']) ? 'distance ' : '') .
        (!empty($v['need_intermediate']) ? 'intermediate ' : '') .
        (!empty($v['need_near']) ? 'near' : '')
    );
    $has_old = !empty($v['old_r_sph']) || !empty($v['old_r_cyl']) || !empty($v['old_l_sph']) || !empty($v['old_l_cyl']);
    $line = sprintf(
        "Visit %d — %s (age %s):\n",
        $i + 1, $v['date'] ?? '?', $v['age'] ?? '?'
    );
    if ($has_old) {
        $line .= sprintf(
            "  OLD Rx (what the customer had coming into this visit):\n" .
            "    RIGHT (OD): SPH %s | CYL %s\n" .
            "    LEFT  (OS): SPH %s | CYL %s\n",
            $v['old_r_sph'] ?? '0.00', $v['old_r_cyl'] ?? '0.00',
            $v['old_l_sph'] ?? '0.00', $v['old_l_cyl'] ?? '0.00'
        );
    }
    $line .= sprintf(
        "  NEW Rx (prescribed this visit):\n" .
        "    RIGHT (OD): SPH %s | CYL %s | AXIS %s | ADD %s\n" .
        "    LEFT  (OS): SPH %s | CYL %s | AXIS %s | ADD %s\n" .
        "  Lens needs: %s | Visual habit: %s | Digital usage: %s",
        $v['r_sph'] ?? '0.00', $v['r_cyl'] ?? '0.00', $v['r_ax'] ?? '0', $v['r_add'] ?? '0.00',
        $v['l_sph'] ?? '0.00', $v['l_cyl'] ?? '0.00', $v['l_ax'] ?? '0', $v['l_add'] ?? '0.00',
        $needs !== '' ? $needs : 'none recorded',
        $v['visual_habit'] ?? '—',
        !empty($v['digital_usage']) ? 'high' : 'normal'
    );
    if (!empty($v['symptoms']))   $line .= "\n  Reported symptoms: " . preg_replace('/[\x00-\x1F\x7F]/', '', $v['symptoms']);
    if (!empty($v['exam_notes'])) $line .= "\n  Examiner notes: " . preg_replace('/[\x00-\x1F\x7F]/', '', $v['exam_notes']);
    return $line;
}

$visit_lines = [];
foreach ($input['visits'] as $i => $v) {
    $visit_lines[] = formatVisit($i, $v);
}
$visit_history_text = implode("\n\n", $visit_lines);

// The rx_timeline (built server-side in customer_history.php) is the OLD+NEW
// Rx points already deduplicated — e.g. if visit 2's old Rx equals visit 1's
// new Rx, that duplicate point is collapsed, so this count is the TRUE number
// of distinct prescription data points, which may be more (or fewer) than
// simply "number of visits x 2".
$timeline_note = '';
if (!empty($input['rx_timeline']) && is_array($input['rx_timeline'])) {
    $points = [];
    foreach ($input['rx_timeline'] as $p) {
        $tag = ($p['label'] ?? 'new') === 'old' ? 'old' : 'new';
        $points[] = sprintf('[%s %s: OD %s/%s, OS %s/%s]', $p['date'] ?? '?', $tag, $p['r_sph'] ?? '0', $p['r_cyl'] ?? '0', $p['l_sph'] ?? '0', $p['l_cyl'] ?? '0');
    }
    $timeline_note = "\n\nDISTINCT PRESCRIPTION DATA POINTS (deduplicated old+new Rx across all visits, chronological — use THESE as the actual trend line, not just one point per visit):\n" . implode(' -> ', $points);
}

$customer_name = preg_replace('/[\x00-\x1F\x7F]/', '', $input['customer_name'] ?? 'Unknown');
$exam_count    = (int)($input['exam_count'] ?? count($input['visits']));
$avg_gap_days  = $input['avg_gap_days'] ?? null;
$avg_gap_text  = $avg_gap_days !== null ? "{$avg_gap_days} days" : 'not enough data';

// 6. BUILD SYSTEM PROMPT
$system_prompt = <<<SYS
You are an experienced optometry clinical assistant helping a licensed optometrist interpret a customer's refractive examination history ACROSS MULTIPLE VISITS. Your role is to identify trends and patterns over time — NOT to diagnose the patient. The optometrist is always the final decision-maker.

IMPORTANT GUIDELINES:
- Each visit may include an OLD Rx (what the customer had coming in) and a NEW Rx (what was prescribed that visit). USE BOTH — the old Rx of a visit is a real historical data point too, not just a reference number. A "DISTINCT PRESCRIPTION DATA POINTS" list is provided below with duplicates already removed (e.g. if visit 2's old Rx matches visit 1's new Rx exactly, it is not repeated) — treat that list as the ground truth trend line.
- Even with a single visit, if it has both an old and a new Rx, that alone shows a real change (2 data points) — analyze it as a trend, not just a baseline. Only treat it as a pure baseline (no trend) if there is truly just one single Rx value with nothing to compare it to.
- If MULTIPLE visits are provided, focus on CHANGE OVER TIME: is the prescription stable, progressing (getting more myopic/hyperopic/astigmatic), regressing, or showing presbyopia onset (ADD power appearing/increasing with age)? Compare the earliest point to the latest, and note any irregular jumps.
- Factor in visit frequency (average interval between visits) when there is more than one visit — very frequent visits may suggest unresolved complaints; very long gaps may suggest inconsistent follow-up.
- Factor in recurring symptoms or notes that appear across multiple visits.
- Use standard optometric severity categories (normal / mild / moderate / high / severe).
- Be concise but informative. This is a clinical reference, not a textbook.
- LANGUAGE: Write ALL descriptive/explanatory text (trend_summary, explanation, causes, management, recommendations, referral reason) in BAHASA INDONESIA, clear and easy for the optometrist to discuss with the patient.
- EXCEPTION: the "name" field of every entry in main_findings must stay in ENGLISH, in UPPERCASE (e.g. MYOPIA PROGRESSION, PRESBYOPIA ONSET, STABLE REFRACTION, ASTIGMATISM INCREASE), because it is used as a machine-readable tag elsewhere in the system.
- Never use markdown formatting like **bold** or *italics* in your output values.
- AVOID REPETITION: each field must add distinct information. Keep every field as short as possible while staying clear.

RESPONSE FORMAT:
Respond with a single valid JSON object using this exact structure:

{
  "trend_summary": "2-4 kalimat dalam Bahasa Indonesia merangkum tren refraksi pelanggan ini dari kunjungan pertama hingga terakhir",
  "referral": {
    "recommended": true or false,
    "specialist": "Jenis dokter spesialis yang disarankan, dalam Bahasa Indonesia. Kosongkan string jika recommended = false.",
    "reason": "1-2 kalimat dalam Bahasa Indonesia menjelaskan mengapa pasien perlu dirujuk (mis. progres miopi sangat cepat, gejala berulang yang tidak membaik, dugaan kondisi di luar kelainan refraksi biasa). Kosongkan string jika recommended = false."
  },
  "main_findings": [
    {
      "name": "PATTERN NAME IN UPPERCASE ENGLISH (e.g. MYOPIA PROGRESSION, PRESBYOPIA ONSET, STABLE REFRACTION, ASTIGMATISM INCREASE, RECURRING DIGITAL EYE STRAIN)",
      "severity": "one of: normal | mild | moderate | high | severe",
      "explanation": "2-4 kalimat dalam Bahasa Indonesia menjelaskan pola ini dan mengapa berlaku pada pelanggan ini, berdasarkan seluruh riwayat",
      "causes": ["Penyebab / faktor risiko 1 dalam Bahasa Indonesia", "Penyebab 2", "..."],
      "management": ["Langkah penanggulangan / tindakan yang disarankan 1 dalam Bahasa Indonesia", "Langkah 2", "..."]
    }
  ],
  "recommendations": [
    "Rekomendasi lensa / jadwal kontrol / perawatan 1, dalam Bahasa Indonesia, dipersonalisasi berdasarkan tren",
    "Rekomendasi 2"
  ]
}

RULES FOR main_findings:
- Usually ONE entry summarizing the dominant trend. Only add a second entry if there's a clearly distinct, unrelated pattern worth separating (e.g. myopia progression AND recurring digital eye strain symptoms).
- If the refraction has stayed essentially the same across visits, use name "STABLE REFRACTION", severity "normal".
- Base findings on the FULL history provided, not just the two endpoints — note any irregular visit or reversal too.

RULES FOR recommendations:
- Keep between 2-5 items, personalized to the trend (e.g. suggest earlier next check-up if progressing fast, suggest anti-fatigue/blue-light lens if digital usage is high and symptoms recur, suggest myopia control options if progression is fast in a young patient).
SYS;

// 7. BUILD USER MESSAGE
$user_message = "Please analyze the following customer's examination history for trends.\n\n" .
                "Customer: {$customer_name}\n" .
                "Total examinations: {$exam_count}\n" .
                "Average interval between visits: {$avg_gap_text}\n\n" .
                "VISIT-BY-VISIT HISTORY (chronological):\n{$visit_history_text}" .
                $timeline_note;

// 8. CALL GEMINI API (same model/config as analyze_prescription.php)
$model = 'gemini-3.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

$max_output_tokens = 4096;

$payload = [
    'system_instruction' => [
        'parts' => [
            ['text' => $system_prompt]
        ]
    ],
    'contents' => [
        [
            'role'  => 'user',
            'parts' => [
                ['text' => $user_message]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature'      => 0.4,
        'maxOutputTokens'  => $max_output_tokens,
        'responseMimeType' => 'application/json',
        'thinkingConfig'   => [
            'thinkingLevel' => 'low'
        ]
    ]
];

$ch = curl_init($url);
$curl_opts = [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_CONNECTTIMEOUT => 10,
];

$host = $_SERVER['HTTP_HOST'] ?? '';
$is_localhost = (
    strpos($host, 'localhost') !== false ||
    strpos($host, '127.0.0.1') !== false ||
    strpos($host, '.local')    !== false ||
    strpos($host, '.test')     !== false
);
if ($is_localhost) {
    $curl_opts[CURLOPT_SSL_VERIFYPEER] = false;
    $curl_opts[CURLOPT_SSL_VERIFYHOST] = false;
}

curl_setopt_array($ch, $curl_opts);

$response   = curl_exec($ch);
$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(502);
    echo json_encode(['error' => 'Network error reaching Gemini AI: ' . $curl_error]);
    exit();
}

if ($http_code !== 200) {
    $api_err = json_decode($response, true);
    $err_msg = $api_err['error']['message'] ?? $response;

    $friendly_hint = '';
    if ($http_code === 429) {
        $friendly_hint = ' (Free tier limit reached: 10 requests/minute or 500 requests/day. Wait a moment and try again.)';
    } elseif ($http_code === 400 && stripos($err_msg, 'api key') !== false) {
        $friendly_hint = ' (Please check that GEMINI_API_KEY in config_helper.php is valid.)';
    } elseif ($http_code === 403) {
        $friendly_hint = ' (API key may be invalid, restricted, or the Gemini API is not enabled for this key.)';
    }

    http_response_code($http_code);
    echo json_encode([
        'error'   => 'Gemini API returned an error.' . $friendly_hint,
        'status'  => $http_code,
        'details' => $err_msg
    ]);
    exit();
}

// 9. PARSE GEMINI'S RESPONSE
$api_response = json_decode($response, true);

$ai_text = '';
if (!empty($api_response['candidates'][0]['content']['parts'])) {
    foreach ($api_response['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) $ai_text .= $part['text'];
    }
}

if (empty($ai_text)) {
    http_response_code(500);
    echo json_encode(['error' => 'Gemini returned an empty response.', 'raw' => $api_response]);
    exit();
}

$ai_text_cleaned = trim($ai_text);
$ai_text_cleaned = preg_replace('/^```(?:json)?\s*/', '', $ai_text_cleaned);
$ai_text_cleaned = preg_replace('/\s*```\s*$/', '', $ai_text_cleaned);
$ai_text_cleaned = trim($ai_text_cleaned);

$analysis = json_decode($ai_text_cleaned, true);
if (!is_array($analysis)) {
    $finish_reason = $api_response['candidates'][0]['finishReason'] ?? 'UNKNOWN';
    $reason_hint = '';
    if ($finish_reason === 'MAX_TOKENS') {
        $reason_hint = ' The response was cut off (finishReason: MAX_TOKENS). Try increasing maxOutputTokens in analyze_customer_history.php.';
    }
    http_response_code(500);
    echo json_encode([
        'error'         => 'Failed to parse AI response as JSON.' . $reason_hint,
        'finish_reason' => $finish_reason,
        'raw'           => mb_substr($ai_text, 0, 1000)
    ]);
    exit();
}

// 10. RETURN SUCCESS
$usage = $api_response['usageMetadata'] ?? [];
$output_tokens_used = $usage['candidatesTokenCount'] ?? null;
$remaining_tokens = is_numeric($output_tokens_used) ? max(0, $max_output_tokens - $output_tokens_used) : null;

echo json_encode([
    'success'  => true,
    'analysis' => $analysis,
    'meta'     => [
        'model'             => $model,
        'provider'          => 'Google Gemini',
        'input_tokens'      => $usage['promptTokenCount']     ?? null,
        'output_tokens'     => $output_tokens_used,
        'max_output_tokens' => $max_output_tokens,
        'remaining_tokens'  => $remaining_tokens,
    ]
]);