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
- Integrate the patient's age, gender, reported symptoms (both selected from the options AND any manually typed symptoms), visual habits, and digital device usage into your interpretation. This contextual reasoning is your main value.
- If a previous prescription is provided, comment on progression (stable, progressing, regressing).
- Use standard optometric severity categories (mild / moderate / high / severe).
- Be concise but informative. This is a clinical reference, not a textbook.
- LANGUAGE: Write ALL descriptive/explanatory text (summary, description, symptoms, causes, management, contextual_insights, recommendations, referral reason) in BAHASA INDONESIA, clear and easy for the optometrist to discuss with the patient.
- EXCEPTION: the "name" field of every condition (in main_findings, right_eye, and left_eye) must stay in ENGLISH, in UPPERCASE (e.g. MODERATE MYOPIA, CATARACT, ASTIGMATISM, EARLY PRESBYOPIA, EMMETROPIA / NORMAL), because it is used as a machine-readable tag elsewhere in the system.
- Never use markdown formatting like **bold** or *italics* in your output values.
- AVOID REPETITION: do not restate the same sentence or fact in multiple fields (e.g. do not repeat the referral reason inside contextual_insights, or repeat a main_findings explanation inside an eye's description). Each field must add distinct information. Keep every field as short as possible while staying clear — this keeps the response efficient and avoids wasting output tokens.

RESPONSE FORMAT:
Respond with a single valid JSON object using this exact structure:

{
  "referral": {
    "recommended": true or false,
    "specialist": "Jenis dokter spesialis yang disarankan, mis. Dokter Spesialis Mata (Oftalmologis), dalam Bahasa Indonesia. Kosongkan string jika recommended = false.",
    "reason": "1-2 kalimat dalam Bahasa Indonesia menjelaskan MENGAPA pasien perlu dirujuk ke dokter spesialis (bukan cukup ditangani optician), misal dugaan katarak, tekanan bola mata tinggi, penurunan tajam penglihatan signifikan, atau kondisi di luar kelainan refraksi biasa. Kosongkan string jika recommended = false."
  },
  "main_findings": [
    {
      "name": "OVERALL CONDITION NAME IN UPPERCASE ENGLISH (e.g. CATARACT, MODERATE MYOPIA, ASTIGMATISM, EARLY PRESBYOPIA, EMMETROPIA / NORMAL). This is the patient's likely condition considering BOTH eyes and their full context together, not just raw refractive numbers.",
      "severity": "one of: normal | mild | moderate | high | severe",
      "explanation": "2-4 sentences in Bahasa Indonesia explaining what this condition is and why it applies to this patient, considering both eyes and context together",
      "causes": ["Penyebab / faktor risiko 1 dalam Bahasa Indonesia", "Penyebab 2", "..."],
      "management": ["Langkah penanggulangan / tindakan yang disarankan 1 dalam Bahasa Indonesia", "Langkah 2", "..."]
    }
  ],
  "right_eye": {
    "summary": "Ringkasan satu kalimat kondisi mata kanan, dalam Bahasa Indonesia",
    "conditions": [
      {
        "name": "CONDITION NAME IN UPPERCASE ENGLISH (e.g. MODERATE MYOPIA, MILD ASTIGMATISM, EARLY PRESBYOPIA, EMMETROPIA / NORMAL)",
        "severity": "one of: normal | mild | moderate | high | severe",
        "value": "the refractive value e.g. -4.25 or +1.50",
        "description": "1-2 kalimat dalam Bahasa Indonesia menjelaskan kondisi ini secara sederhana",
        "symptoms": ["gejala spesifik 1 (Bahasa Indonesia)", "gejala 2"],
        "causes": ["penyebab / faktor risiko 1 (Bahasa Indonesia)", "penyebab 2"]
      }
    ]
  },
  "left_eye": { "summary": "...(Bahasa Indonesia)", "conditions": [ ... ] },
  "contextual_insights": "2-5 kalimat dalam Bahasa Indonesia yang menghubungkan hasil pemeriksaan dengan usia, gejala, gaya hidup pasien ini secara spesifik, dan (jika tersedia) progres dibanding resep sebelumnya. Bagian ini PALING PENTING — buat benar-benar personal, bukan generik.",
  "recommendations": [
    "Rekomendasi lensa / coating / perawatan 1, dalam Bahasa Indonesia",
    "Rekomendasi 2"
  ]
}

RULES FOR referral:
- Set "recommended" to true ONLY when something suggests the patient needs an eye specialist beyond a routine glasses prescription — e.g. suspected cataract, very high/asymmetric refractive error, sudden or severe vision loss reported in symptoms, signs of eye disease, or unusual symptoms unrelated to simple refractive error.
- A routine myopia/hyperopia/astigmatism/presbyopia case with no red flags should have "recommended": false.

RULES FOR main_findings:
- Usually ONE entry summarizing the patient's overall likely condition. Only add a second entry if there are two clearly distinct, unrelated conditions worth separating (e.g. a refractive error AND a suspected pathology like cataract mentioned in symptoms).
- If there is no significant refractive error or reported pathology (patient is essentially normal), use name "EMMETROPIA / NORMAL", severity "normal", and explain there is no significant abnormality found; causes and management can be short/preventive in nature (empty arrays are also acceptable here).
- Base main_findings on ALL available context, not just the raw SPH/CYL/AXIS/ADD numbers — factor in reported symptoms (including manually typed ones), age, visual habits, digital usage, and prescription progression.

RULES FOR right_eye / left_eye CONDITIONS:
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
$model = 'gemini-3.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

$max_output_tokens = 6144;

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
        'maxOutputTokens'   => $max_output_tokens,
        'responseMimeType'  => 'application/json',  // Force JSON output - no markdown fences!
        // Gemini 3.x replaced "thinkingBudget" with "thinkingLevel".
        // "low" keeps some reasoning (useful for this clinical-interpretation
        // task) while leaving plenty of token budget for the JSON answer.
        'thinkingConfig' => [
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
    $finish_reason = $api_response['candidates'][0]['finishReason'] ?? 'UNKNOWN';
    $reason_hint = '';
    if ($finish_reason === 'MAX_TOKENS') {
        $reason_hint = ' The response was cut off because it ran out of tokens (finishReason: MAX_TOKENS). Try increasing maxOutputTokens further in analyze_prescription.php.';
    }
    http_response_code(500);
    echo json_encode([
        'error'         => 'Failed to parse AI response as JSON.' . $reason_hint,
        'finish_reason' => $finish_reason,
        'raw'           => mb_substr($ai_text, 0, 1000)
    ]);
    exit();
}

// 11. RETURN SUCCESS
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