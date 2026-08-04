<?php
/**
 * generate_customer_pdf.php
 * -------------------------------------------------------------
 * Renders a customer's Customer Record (exam history + trend AI analysis, if
 * available) as a branded PDF using FPDF, and saves it to:
 *
 *   /pdf_file/customer_history/{last_customer_number}.pdf
 *
 * If the customer already has a PDF saved under an older customer_number
 * (tracked via customer_list.last_pdf_number), that old file is deleted first
 * so there's only ever one current PDF per customer.
 *
 * Called automatically right after a successful "Generate AI Analysis" (see
 * customer_history.php), with:
 *   - match_key        (required) customer_list.match_key for this customer
 *   - analysis_json    (optional) the AI trend analysis result, as JSON, so
 *                       the PDF can include it without re-calling Gemini.
 * -------------------------------------------------------------
 */

session_start();
include 'db_config.php';
include 'config_helper.php';
include 'auth_check.php';
require 'fpdf/fpdf.php';

// ── Helper: FPDF ships with cp1252-ish encoding, not UTF-8 ──────────────────
function pdf_txt($s) {
    $s = (string)($s ?? '');
    $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
    return $converted !== false ? $converted : $s;
}

$match_key = trim($_POST['match_key'] ?? $_GET['match_key'] ?? '');
if ($match_key === '') {
    http_response_code(400);
    die('Missing match_key parameter.');
}

$analysis = null;
if (!empty($_POST['analysis_json'])) {
    $decoded = json_decode($_POST['analysis_json'], true);
    if (is_array($decoded)) $analysis = $decoded;
}

// ── Resolve customer (same match_key grouping as customer_history.php) ─────
$stmt = $conn->prepare("SELECT customer_name, customer_phone, last_customer_number, last_pdf_number FROM customer_list WHERE match_key = ? LIMIT 1");
$stmt->bind_param('s', $match_key);
$stmt->execute();
$cl = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cl) {
    http_response_code(404);
    die('Customer not found in customer_list for this match_key.');
}

$canon_name      = $cl['customer_name'];
$canon_phone     = $cl['customer_phone'];
$canon_number    = $cl['last_customer_number'];
$old_pdf_number  = $cl['last_pdf_number'];
$key_prefix      = substr($match_key, 0, 3);

if ($key_prefix === 'PN:' && $canon_phone && $canon_name) {
    $stmt2 = $conn->prepare("
        SELECT o.* FROM customer_orders o
        INNER JOIN customer_examinations ce ON ce.invoice_number = o.invoice_number
        WHERE o.customer_phone = ? AND ce.customer_name = ?
        ORDER BY o.order_date ASC
    ");
    $stmt2->bind_param('ss', $canon_phone, $canon_name);
} elseif ($key_prefix === 'PH:' && $canon_phone) {
    $stmt2 = $conn->prepare("SELECT * FROM customer_orders WHERE customer_phone = ? ORDER BY order_date ASC");
    $stmt2->bind_param('s', $canon_phone);
} else {
    $stmt2 = $conn->prepare("
        SELECT o.* FROM customer_orders o
        INNER JOIN customer_examinations ce ON ce.invoice_number = o.invoice_number
        WHERE ce.customer_name = ?
        ORDER BY o.order_date ASC
    ");
    $stmt2->bind_param('s', $canon_name);
}
$stmt2->execute();
$orders = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

$order_invoices = array_filter(array_column($orders, 'invoice_number'), fn($v) => $v && $v !== '00');

// Examinations: name is NOT unique across customers, so only fall back to a
// name match when this customer has no order invoices at all to disambiguate by.
$exam_conditions = [];
$exam_types  = '';
$exam_params = [];
if (!empty($order_invoices)) {
    $placeholders = implode(',', array_fill(0, count($order_invoices), '?'));
    $exam_conditions[] = "invoice_number IN ($placeholders)";
    foreach ($order_invoices as $inv) { $exam_types .= 's'; $exam_params[] = $inv; }
} elseif ($canon_name) {
    $exam_conditions[] = 'customer_name = ?';
    $exam_types .= 's';
    $exam_params[] = $canon_name;
}
$examinations = [];
if (!empty($exam_conditions)) {
    $sql = "SELECT * FROM customer_examinations WHERE " . implode(' OR ', $exam_conditions) . " ORDER BY examination_date ASC";
    $stmt3 = $conn->prepare($sql);
    $stmt3->bind_param($exam_types, ...$exam_params);
    $stmt3->execute();
    $examinations = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt3->close();
}

if (empty($examinations)) {
    http_response_code(404);
    die('No examination records found for this customer.');
}

// ── PDF class with branded header/footer (same brand as customer_history.php,
//    minus the logout button) ────────────────────────────────────────────────
class CustomerRecordPDF extends FPDF {
    public $storeName;
    public $storeAddress;
    public $logoPath;
    public $reportTitle;

    function Header() {
        if ($this->logoPath && file_exists($this->logoPath)) {
            $this->Image($this->logoPath, 12, 8, 18);
            $this->SetX(34);
        } else {
            $this->SetX(12);
        }
        $this->SetY(9);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 6, pdf_txt($this->storeName), 0, 1, 'C');
        $this->SetFont('Arial', '', 9);
        $this->SetX(0);
        $this->Cell(0, 5, pdf_txt($this->storeAddress), 0, 1, 'C');
        $this->SetFont('Arial', 'B', 11);
        $this->SetX(0);
        $this->Ln(2);
        $this->Cell(0, 6, pdf_txt($this->reportTitle), 0, 1, 'C');
        $this->SetDrawColor(180, 180, 180);
        $this->Line(12, 32, 198, 32);
        $this->SetY(37);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetDrawColor(200, 200, 200);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(2);
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(120, 120, 120);
        $this->Cell(0, 5, pdf_txt('(c) ' . date('Y') . ' ' . $this->storeName . '. All Rights Reserved.'), 0, 0, 'L');
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    function SectionTitle($title) {
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(30, 32, 40);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 7, '  ' . pdf_txt($title), 0, 1, 'L', true);
        $this->SetTextColor(20, 20, 20);
        $this->Ln(2);
    }

    function SeverityColor($severity) {
        switch (strtolower($severity)) {
            case 'severe':
            case 'high':     return [220, 53, 69];
            case 'moderate': return [241, 196, 15];
            case 'mild':     return [52, 152, 219];
            default:         return [39, 174, 96]; // normal
        }
    }
}

$pdf = new CustomerRecordPDF();
$pdf->storeName    = $STORE_NAME ?? 'LENZA OPTIC';
$pdf->storeAddress = $STORE_ADDRESS ?? '';
$pdf->logoPath     = isset($BRAND_IMAGE_PATH) && file_exists($BRAND_IMAGE_PATH) ? $BRAND_IMAGE_PATH : null;
$pdf->reportTitle  = 'CUSTOMER RECORD REPORT';
$pdf->AliasNbPages();
$pdf->AddPage();

// ── Identity block ───────────────────────────────────────────────────────
$pdf->SetFont('Arial', 'B', 12);
$pdf->Cell(0, 7, pdf_txt($canon_name), 0, 1);
$pdf->SetFont('Arial', '', 10);
$pdf->Cell(95, 6, pdf_txt('Phone: ' . ($canon_phone ?: '-')), 0, 0);
$pdf->Cell(0, 6, pdf_txt('Customer Number: ' . $canon_number), 0, 1);
$pdf->Cell(95, 6, pdf_txt('Total Examinations: ' . count($examinations)), 0, 0);
$pdf->Cell(0, 6, pdf_txt('Total Orders: ' . count($orders)), 0, 1);
$pdf->Cell(0, 6, pdf_txt('Report generated: ' . date('d M Y H:i')), 0, 1);
$pdf->Ln(4);

// ── Exam History table ───────────────────────────────────────────────────
$pdf->SectionTitle('Examination History');

function rxDisp($v) {
    if ($v === null || $v === '' ) return '-';
    $f = (float)$v;
    return $f > 0 ? ('+' . $v) : (string)$v;
}
function pdf_visual_habit_label($v) {
    switch ((int)$v) { case 1: return 'Indoor'; case 2: return 'Outdoor'; case 3: return 'Both'; default: return '-'; }
}
function pdf_digital_usage_label($v) {
    switch ((int)$v) { case 1: return 'Low (<2H)'; case 2: return 'Moderate (2H-5H)'; case 3: return 'High (>5H)'; default: return '-'; }
}

foreach ($examinations as $idx => $e) {
    if ($pdf->GetY() > 250) $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 9.5);
    $pdf->SetFillColor(235, 235, 240);
    $header = date('d M Y', strtotime($e['examination_date'])) . '   -   ' . $e['examination_code'];
    if (!empty($e['invoice_number']) && $e['invoice_number'] !== '00') {
        $header .= '   -   #' . $e['invoice_number'];
    }
    $pdf->Cell(0, 6, pdf_txt($header), 0, 1, 'L', true);

    // Rx table
    $pdf->SetFont('Arial', 'B', 8);
    $cols = [12, 20, 20, 16, 20, 20, 16, 16, 20];
    $labels = ['Eye', 'Old SPH', 'Old CYL', 'Old AX', 'New SPH', 'New CYL', 'New AX', 'ADD', 'VA'];
    foreach ($labels as $i => $lab) $pdf->Cell($cols[$i], 6, $lab, 1, 0, 'C');
    $pdf->Ln();

    $pdf->SetFont('Arial', '', 8);
    $rows = [
        ['OD', $e['old_r_sph'], $e['old_r_cyl'], $e['old_r_ax'], $e['new_r_sph'], $e['new_r_cyl'], $e['new_r_ax'], $e['new_r_add'], $e['new_r_visus']],
        ['OS', $e['old_l_sph'], $e['old_l_cyl'], $e['old_l_ax'], $e['new_l_sph'], $e['new_l_cyl'], $e['new_l_ax'], $e['new_l_add'], $e['new_l_visus']],
    ];
    foreach ($rows as $row) {
        $pdf->Cell($cols[0], 6, $row[0], 1, 0, 'C');
        $pdf->Cell($cols[1], 6, rxDisp($row[1]), 1, 0, 'C');
        $pdf->Cell($cols[2], 6, rxDisp($row[2]), 1, 0, 'C');
        $pdf->Cell($cols[3], 6, $row[3] ?: '-', 1, 0, 'C');
        $pdf->Cell($cols[4], 6, rxDisp($row[4]), 1, 0, 'C');
        $pdf->Cell($cols[5], 6, rxDisp($row[5]), 1, 0, 'C');
        $pdf->Cell($cols[6], 6, $row[6] ?: '-', 1, 0, 'C');
        $pdf->Cell($cols[7], 6, rxDisp($row[7]), 1, 0, 'C');
        $pdf->Cell($cols[8], 6, $row[8] ?: '-', 1, 0, 'C');
        $pdf->Ln();
    }

    // Meta line
    $pdf->SetFont('Arial', '', 8);
    $meta = [];
    if (!empty($e['age']))      $meta[] = 'Age: ' . (int)$e['age'];
    $meta[] = 'Visual Habit: ' . pdf_visual_habit_label($e['visual_habit'] ?? null);
    if (!empty($e['digital_usage'])) $meta[] = 'Digital Usage: ' . pdf_digital_usage_label($e['digital_usage']);
    $needs = [];
    if (!empty($e['need_distance']))     $needs[] = 'Distance';
    if (!empty($e['need_intermediate'])) $needs[] = 'Intermediate';
    if (!empty($e['need_near']))         $needs[] = 'Near';
    if (!empty($needs)) $meta[] = 'Lens Need: ' . implode('/', $needs);
    $pdf->Cell(0, 5, pdf_txt(implode('   |   ', $meta)), 0, 1);

    // Symptoms / notes
    if (!empty(trim($e['symptoms'] ?? ''))) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(0, 5, 'Symptoms:', 0, 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(0, 5, pdf_txt($e['symptoms']));
    }
    if (!empty(trim($e['exam_notes'] ?? ''))) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell(0, 5, 'Examiner Notes:', 0, 1);
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell(0, 5, pdf_txt($e['exam_notes']));
    }

    $pdf->Ln(3);
}

// ── AI Trend Analysis (only if the browser sent one along) ─────────────────
if ($analysis) {
    if ($pdf->GetY() > 220) $pdf->AddPage();
    $pdf->SectionTitle('AI Analysis');

    if (!empty($analysis['trend_summary'])) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, 'Trend Summary', 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(0, 5, pdf_txt($analysis['trend_summary']));
        $pdf->Ln(2);
    }

    if (!empty($analysis['referral']['recommended'])) {
        $pdf->SetFillColor(253, 235, 235);
        $pdf->SetTextColor(180, 30, 30);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->MultiCell(0, 6, pdf_txt('REFERRAL RECOMMENDED: ' . ($analysis['referral']['specialist'] ?? '')), 0, 'L', true);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->MultiCell(0, 5, pdf_txt($analysis['referral']['reason'] ?? ''), 0, 'L', true);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->Ln(3);
    }

    if (!empty($analysis['main_findings']) && is_array($analysis['main_findings'])) {
        foreach ($analysis['main_findings'] as $f) {
            if ($pdf->GetY() > 250) $pdf->AddPage();
            [$r, $g, $b] = $pdf->SeverityColor($f['severity'] ?? 'normal');
            $pdf->SetFont('Arial', 'B', 9.5);
            $pdf->SetTextColor($r, $g, $b);
            $pdf->Cell(0, 6, pdf_txt(($f['name'] ?? 'FINDING') . '  [' . strtoupper($f['severity'] ?? 'normal') . ']'), 0, 1);
            $pdf->SetTextColor(20, 20, 20);
            $pdf->SetFont('Arial', '', 8.5);
            $pdf->MultiCell(0, 5, pdf_txt($f['explanation'] ?? ''));

            if (!empty($f['causes']) && is_array($f['causes'])) {
                $pdf->SetFont('Arial', 'B', 8.5);
                $pdf->Cell(0, 5, 'Kemungkinan Penyebab:', 0, 1);
                $pdf->SetFont('Arial', '', 8.5);
                foreach ($f['causes'] as $c) $pdf->MultiCell(0, 5, pdf_txt('- ' . $c));
            }
            if (!empty($f['management']) && is_array($f['management'])) {
                $pdf->SetFont('Arial', 'B', 8.5);
                $pdf->Cell(0, 5, 'Saran Penanganan:', 0, 1);
                $pdf->SetFont('Arial', '', 8.5);
                foreach ($f['management'] as $m) $pdf->MultiCell(0, 5, pdf_txt('- ' . $m));
            }
            $pdf->Ln(2);
        }
    }

    if (!empty($analysis['recommendations']) && is_array($analysis['recommendations'])) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(0, 6, 'Rekomendasi', 0, 1);
        $pdf->SetFont('Arial', '', 8.5);
        foreach ($analysis['recommendations'] as $r) $pdf->MultiCell(0, 5, pdf_txt('- ' . $r));
    }
}

// ── Save to /pdf_file/customer_history/{customer_number}.pdf ───────────────
$safe_number = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$canon_number);
$dir = __DIR__ . '/pdf_file/customer_history/';
if (!is_dir($dir)) mkdir($dir, 0775, true);

// Delete the old PDF (previous customer_number) if this customer's number changed.
if ($old_pdf_number && $old_pdf_number !== $canon_number) {
    $old_safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$old_pdf_number);
    $old_path = $dir . $old_safe . '.pdf';
    if (file_exists($old_path)) @unlink($old_path);
}
// Also overwrite any existing file under the current number (regenerating).
$new_path = $dir . $safe_number . '.pdf';
if (file_exists($new_path)) @unlink($new_path);

$pdf->Output('F', $new_path);

$stmt4 = $conn->prepare("UPDATE customer_list SET last_pdf_number = ? WHERE match_key = ?");
$stmt4->bind_param('ss', $canon_number, $match_key);
$stmt4->execute();
$stmt4->close();

// ── Respond ──────────────────────────────────────────────────────────────
header('Content-Type: application/json');
echo json_encode([
    'success'   => true,
    'file_path' => 'pdf_file/customer_history/' . $safe_number . '.pdf',
]);