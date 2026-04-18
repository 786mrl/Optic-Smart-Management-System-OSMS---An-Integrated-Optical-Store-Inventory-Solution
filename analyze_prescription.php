<?php
/**
 * analyze_prescription.php
 * -------------------------------------------------------------
 * Backend endpoint that forwards prescription + patient context
 * to Google Gemini API (FREE TIER) and returns a structured
 * clinical analysis as JSON.
 *
 * SETUP:
 *   1. Get a FREE API key at: https://aistudio.google.com/app/apikey
 *      (Just sign in with Google account, NO credit card needed)
 *   2. Add this line to config_helper.php:
 *        define('GEMINI_API_KEY', 'AIzaSy...........................');
 *
 * FREE TIER LIMITS (as of April 2026):
 *   - gemini-2.5-flash: 10 requests/minute, 500 requests/day
 *   - Resets daily at midnight Pacific Time
 *
 * SECURITY:
 *   - API key is NEVER exposed to the browser.
 *   - Endpoint requires a valid logged-in session.
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
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit();
}

// 5. BUILD PATIENT CONTEXT STRING
$context_lines = [];

if (!empty($input['age']) && is_numeric($input['age'])) {
    $context_lines[] = 'Age: ' . (int)$input['age'] . ' years';
}
if (!empty($input['gender'])) {
    $context_lines[] = 'Gender: ' . preg_replace('/[^A-Za-z]/', '', $input['gender']);
}
if (!empty($input['symptoms']) && is_array($input['symptoms'])) {
    $safe_symptoms = array_map(function($s) {
        return preg_replace('/[\x00-\x1F\x7F]/', '', $s);
    }, $input['symptoms']);
    $context_lines[] = 'Reported symptoms / complaints: ' . implode(', ', $safe_symptoms);
}

$habit_map = [1 => 'Indoor (most of the day)', 2 => 'Outdoor (most of the day)', 3 => 'Both indoor and outdoor'];
if (!empty($input['visual_habit']) && isset($habit_map[(int)$input['visual_habit']])) {
    $context_lines[] = 'Visual habits: ' . $habit_map[(int)$input['visual_habit']];
}

$usage_map = [1 => 'Low (less than 2 hours/day)', 2 => 'Moderate (2-5 hours/day)', 3 => 'High (more than 5 hours/day)'];
if (!empty($input['digital_usage']) && isset($usage_map[(int)$input['digital_usage']])) {
    $context_lines[] = 'Digital device usage: ' . $usage_map[(int)$input['digital_usage']];
}

$patient_context = empty($context_lines)
    ? '(No additional patient context provided.)'
    : implode("\n", $context_lines);

// 6. FORMAT PRESCRIPTIONS
function formatRx($label, $rx) {
    if (!is_array($rx)) return '';
    $r_sph = $rx['r_sph'] ?? '0.00';
    $r_cyl = $rx['r_cyl'] ?? '0.00';
    $r_ax  = $rx['r_ax']  ?? '0';
    $r_add = $rx['r_add'] ?? '0.00';
    $l_sph = $rx['l_sph'] ?? '0.00';
    $l_cyl = $rx['l_cyl'] ?? '0.00';
    $l_ax  = $rx['l_ax']  ?? '0';
    $l_add = $rx['l_add'] ?? '0.00';
    return "$label:\n" .
           "  RIGHT (OD): SPH $r_sph | CYL $r_cyl | AXIS $r_ax | ADD $r_add\n" .
           "  LEFT  (OS): SPH $l_sph | CYL $l_cyl | AXIS $l_ax | ADD $l_add";
}

$new_rx_text = formatRx('NEW PRESCRIPTION (current exam)', $input['new_prescription'] ?? []);

$old_rx_text = '';
if (!empty($input['old_prescription']) && is_array($input['old_prescription'])) {
    $old_rx_text = "\n\n" . formatRx('PREVIOUS PRESCRIPTION (for progression comparison)', $input['old_prescription']);
}

$ucva_text = '';
if (!empty($input['ucva']) && is_array($input['ucva'])) {
    $r = $input['ucva']['r'] ?? '';
    $l = $input['ucva']['l'] ?? '';
    if ($r || $l) {
        $ucva_text = "\n\nUNCORRECTED VISUAL ACUITY (UCVA): Right = $r | Left = $l";
    }
}

// 7. BUILD SYSTEM PROMPT
$system_prompt = <<<SYS
You are an experienced optometry clinical assistant helping a licensed optometrist interpret refractive examination results. Your role is to provide a clear, structured, contextual analysis of the numbers — NOT to diagnose the patient. The optometrist is always the final decision-maker.

IMPORTANT GUIDELINES:
- Integrate the patient's age, gender, reported symptoms, visual habits, and digital device usage into your interpretation. This contextual reasoning is your main value.
- If a previous prescription is provided, comment on progression (stable, progressing, regressing).
- Use standard optometric severity categories (mild / moderate / high / severe).
- Be concise but informative. This is a clinical reference, not a textbook.
- Write in clear English. Use plain language the optometrist can discuss with the patient.
- Never use markdown formatting like **bold** or *italics* in your output values.

RESPONSE FORMAT:
Respond with a single valid JSON object using this exact structure:

{
  "right_eye": {
    "summary": "One-sentence summary of the right eye status",
    "conditions": [
      {
        "name": "CONDITION NAME IN UPPERCASE (e.g. MODERATE MYOPIA, MILD ASTIGMATISM, EARLY PRESBYOPIA, EMMETROPIA / NORMAL)",
        "severity": "one of: normal | mild | moderate | high | severe",
        "value": "the refractive value e.g. -4.25 or +1.50",
        "description": "1-2 sentences explaining what this condition is in plain language",
        "symptoms": ["specific symptom 1", "specific symptom 2"],
        "causes": ["cause or risk factor 1", "cause 2"]
      }
    ]
  },
  "left_eye": { "summary": "...", "conditions": [ ... ] },
  "contextual_insights": "2-5 sentences connecting the prescription findings to this specific patient's age, symptoms, lifestyle, and (if available) progression from the previous prescription. This is the MOST IMPORTANT part — make it truly personalized, not generic.",
  "recommendations": [
    "Specific lens / coating / care recommendation 1",
    "Recommendation 2"
  ]
}

RULES FOR CONDITIONS:
- If an eye has no significant refractive error (all values are 0 or near 0), include a SINGLE condition with name "EMMETROPIA / NORMAL", severity "normal", value "0.00", and appropriate description. symptoms and causes should be empty arrays [] in that case.
- List each refractive error present as a separate condition (e.g. a myopic astigmatic eye will have TWO conditions: myopia + astigmatism).
- Only include ADD as a condition if it is greater than 0.
- Keep symptoms and causes lists between 3-6 items each (unless emmetropia).
- Keep recommendations between 3-7 items, personalized to the patient when possible.
SYS;

// 8. BUILD USER MESSAGE
$user_message = "Please analyze the following refractive examination.\n\n" .
                "PATIENT CONTEXT:\n$patient_context\n\n" .
                "$new_rx_text$old_rx_text$ucva_text";

// 9. CALL GEMINI API
// Using gemini-2.5-flash (free tier: 10 RPM, 500 RPD, best balance for medical reasoning)
$model = 'gemini-2.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

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
        'temperature'       => 0.4,
        'maxOutputTokens'   => 2500,
        'responseMimeType'  => 'application/json'  // Force JSON output - no markdown fences!
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

// SSL handling: on localhost / dev environments (XAMPP Windows), PHP's CA bundle
// is often missing. We detect localhost and disable verification there only.
// On production, verification remains ON (secure).
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

$response    = curl_exec($ch);
$http_code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error  = curl_error($ch);
curl_close($ch);

if ($curl_error) {
    http_response_code(502);
    echo json_encode(['error' => 'Network error reaching Gemini AI: ' . $curl_error]);
    exit();
}

if ($http_code !== 200) {
    $api_err = json_decode($response, true);
    $err_msg = $api_err['error']['message'] ?? $response;

    // Special handling for common free-tier errors
    $friendly_hint = '';
    if ($http_code === 429) {
        $friendly_hint = ' (Free tier limit reached: 10 requests/minute or 500 requests/day. Wait a moment and try again, or the quota resets daily at midnight Pacific Time.)';
    } elseif ($http_code === 400 && stripos($err_msg, 'api key') !== false) {
        $friendly_hint = ' (Please check that GEMINI_API_KEY in config_helper.php is valid. Get one at https://aistudio.google.com/app/apikey)';
    } elseif ($http_code === 403) {
        $friendly_hint = ' (API key may be invalid, restricted, or the Gemini API is not enabled for this key.)';
    }

    http_response_code($http_code);
    echo json_encode([
        'error'  => 'Gemini API returned an error.' . $friendly_hint,
        'status' => $http_code,
        'details'=> $err_msg
    ]);
    exit();
}

// 10. PARSE GEMINI'S RESPONSE
$api_response = json_decode($response, true);

// Gemini structure: candidates[0].content.parts[0].text
$ai_text = '';
if (!empty($api_response['candidates'][0]['content']['parts'])) {
    foreach ($api_response['candidates'][0]['content']['parts'] as $part) {
        if (isset($part['text'])) {
            $ai_text .= $part['text'];
        }
    }
}

if (empty($ai_text)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Gemini returned an empty response.',
        'raw'   => $api_response
    ]);
    exit();
}

// Strip any accidental markdown fences (just in case)
$ai_text_cleaned = trim($ai_text);
$ai_text_cleaned = preg_replace('/^```(?:json)?\s*/', '', $ai_text_cleaned);
$ai_text_cleaned = preg_replace('/\s*```\s*$/', '', $ai_text_cleaned);
$ai_text_cleaned = trim($ai_text_cleaned);

$analysis = json_decode($ai_text_cleaned, true);
if (!is_array($analysis)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to parse AI response as JSON.',
        'raw'   => mb_substr($ai_text, 0, 500)
    ]);
    exit();
}

// 11. RETURN SUCCESS
$usage = $api_response['usageMetadata'] ?? [];
echo json_encode([
    'success'  => true,
    'analysis' => $analysis,
    'meta'     => [
        'model'         => $model,
        'provider'      => 'Google Gemini',
        'input_tokens'  => $usage['promptTokenCount']     ?? null,
        'output_tokens' => $usage['candidatesTokenCount'] ?? null,
    ]
]);