<?php
// lisani_aos/ajax/_invoice_pdf.php
// FPDF renderer for the invoice PDF. Included by print_invoice.php when
// ?format=pdf is requested; all data is prepared there and passed in $c.
// FPDF lives in a folder next to lisani_aos (optic_pos/fpdf/fpdf.php).

$pdfLib = dirname(__DIR__, 2) . '/fpdf/fpdf.php';
if (!is_file($pdfLib)) {
    pi_page_error(500, 'FPDF library was not found at /fpdf/fpdf.php (next to the lisani_aos folder).');
}
require_once $pdfLib;

function pi_pdf_t($s): string
{
    $s = str_replace(['→', '–', '—', "\u{2019}"], ['->', '-', '-', "'"], (string) $s);
    $r = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
    return $r === false ? '' : $r;
}

class PiPdf extends FPDF
{
    const NAVY = [23, 50, 77];
    const GREEN = [29, 107, 59];
    const MUTED = [91, 102, 114];
    const RED = [179, 38, 30];
    const AMBER = [161, 92, 0];
    const BLUE = [29, 79, 145];
    const SOFT = [243, 246, 248];
    const LINE = [213, 219, 225];
    const INK = [27, 36, 48];

    public $L = 14.0;
    public $W = 182.0;

    public function col(array $c, string $kind = 'text'): void
    {
        if ($kind === 'fill') {
            $this->SetFillColor($c[0], $c[1], $c[2]);
        } elseif ($kind === 'draw') {
            $this->SetDrawColor($c[0], $c[1], $c[2]);
        } else {
            $this->SetTextColor($c[0], $c[1], $c[2]);
        }
    }

    // Word-wrap $txt into lines no wider than $w (current font).
    public function wrap(string $txt, float $w): array
    {
        $out = [];
        foreach (preg_split('/\R/', pi_pdf_t($txt)) as $para) {
            $words = preg_split('/ +/', $para);
            $cur = '';
            foreach ($words as $word) {
                $try = $cur === '' ? $word : $cur . ' ' . $word;
                if ($cur !== '' && $this->GetStringWidth($try) > $w) {
                    $out[] = $cur;
                    $cur = $word;
                } else {
                    $cur = $try;
                }
                while ($this->GetStringWidth($cur) > $w && strlen($cur) > 1) {   // very long single word
                    $k = strlen($cur);
                    while ($k > 1 && $this->GetStringWidth(substr($cur, 0, $k)) > $w) {
                        $k--;
                    }
                    $out[] = substr($cur, 0, $k);
                    $cur = substr($cur, $k);
                }
            }
            $out[] = $cur;
        }
        return $out;
    }

    public static function lh(float $size): float
    {
        return $size * 0.3528 * 1.38;
    }

    // $cell = ['w'=>mm, 'align'=>'L|R|C', 'lines'=>[[text, style, size, color], ...]]
    // Returns the height the cell needs (without padding).
    public function cellHeight(array $cell, float $pad = 1.2): float
    {
        $h = 0.0;
        foreach ($cell['lines'] as $ln) {
            $this->SetFont('Helvetica', $ln[1], $ln[2]);
            $n = count($this->wrap($ln[0], $cell['w'] - 2 * $pad));
            $h += $n * self::lh($ln[2]);
        }
        return $h;
    }

    public function drawCell(array $cell, float $x, float $y, float $pad = 1.2): void
    {
        $cy = $y;
        foreach ($cell['lines'] as $ln) {
            $this->SetFont('Helvetica', $ln[1], $ln[2]);
            $this->col($ln[3] ?? self::INK);
            foreach ($this->wrap($ln[0], $cell['w'] - 2 * $pad) as $t) {
                $lh = self::lh($ln[2]);
                $this->SetXY($x + $pad, $cy);
                $this->Cell($cell['w'] - 2 * $pad, $lh, $t, 0, 0, $cell['align'] ?? 'L');
                $cy += $lh;
            }
        }
    }

    // One table row with automatic height and page break. $onBreak redraws the header.
    public function tableRow(array $cells, ?callable $onBreak = null, float $vpad = 1.4, string $fill = ''): void
    {
        $h = 0.0;
        foreach ($cells as $c) {
            $h = max($h, $this->cellHeight($c));
        }
        $h += 2 * $vpad;
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->SetY(12);
            if ($onBreak) {
                $onBreak();
            }
        }
        $y = $this->GetY();
        $x = $this->L;
        if ($fill !== '') {
            $this->col(self::SOFT, 'fill');
            $this->Rect($this->L, $y, $this->W, $h, 'F');
        }
        foreach ($cells as $c) {
            $this->drawCell($c, $x, $y + $vpad);
            $x += $c['w'];
        }
        $this->col(self::LINE, 'draw');
        $this->SetLineWidth(0.2);
        $this->Line($this->L, $y + $h, $this->L + $this->W, $y + $h);
        $this->SetY($y + $h);
    }

    public function headerRow(array $heads): void
    {
        $this->SetFont('Helvetica', 'B', 7.5);
        $h = 6.4;
        $y = $this->GetY();
        $this->col(self::NAVY, 'fill');
        $this->Rect($this->L, $y, $this->W, $h, 'F');
        $this->col([255, 255, 255]);
        $x = $this->L;
        foreach ($heads as $hd) {
            $this->SetXY($x + 1.2, $y);
            $this->Cell($hd[0] - 2.4, $h, strtoupper(pi_pdf_t($hd[1])), 0, 0, $hd[2] ?? 'L');
            $x += $hd[0];
        }
        $this->SetY($y + $h);
    }

    public function section(string $title): void
    {
        $this->SetFont('Helvetica', 'B', 8);
        $this->col(self::NAVY);
        $this->SetX($this->L);
        $this->Cell($this->W, 5, strtoupper(pi_pdf_t($title)), 0, 1);
    }

    public function ensure(float $h): void
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage();
            $this->SetY(12);
        }
    }

    public function letterhead(string $path): void
    {
        $this->SetY(10);
        if (!is_file($path)) {
            return;
        }
        $size = @getimagesize($path);
        if (!$size || $size[0] <= 0) {
            return;
        }
        $h = $this->W * $size[1] / $size[0];
        try {
            $this->Image($path, $this->L, 10, $this->W);
            $this->SetY(10 + $h + 2);
        } catch (Throwable $e) {
            error_log('invoice pdf letterhead: ' . $e->getMessage());
        }
    }

    // FPDF has no dash support built in: [black white] 0 d (mm), no args = solid.
    public function SetDash($black = null, $white = null): void
    {
        $this->_out($black !== null
            ? sprintf('[%.3F %.3F] 0 d', $black * $this->k, $white * $this->k)
            : '[] 0 d');
    }

    public function Footer()
    {
        $this->SetY(-10);
        $this->SetFont('Helvetica', '', 7);
        $this->col(self::MUTED);
        $this->Cell(0, 4, pi_pdf_t($this->footerText) . '  -  ' . $this->PageNo(), 0, 0, 'C');
    }

    public $footerText = '';
}

function pi_pdf_info_boxes(PiPdf $p, array $left, array $right): void
{
    $y = $p->GetY();
    $bw = ($p->W - 6) / 2;
    $hL = 4;
    foreach ($left as $ln) {
        $hL += PiPdf::lh($ln[2]);
    }
    $hR = 4 + count($right) * PiPdf::lh(8.5);
    $h = max($hL, $hR) + 3;
    $p->col(PiPdf::LINE, 'draw');
    $p->SetLineWidth(0.25);
    $p->Rect($p->L, $y, $bw, $h);
    $p->Rect($p->L + $bw + 6, $y, $bw, $h);
    $cy = $y + 2.5;
    foreach ($left as $ln) {
        $p->SetFont('Helvetica', $ln[1], $ln[2]);
        $p->col($ln[3]);
        $p->SetXY($p->L + 3, $cy);
        $p->Cell($bw - 6, PiPdf::lh($ln[2]), pi_pdf_t($ln[0]), 0, 0);
        $cy += PiPdf::lh($ln[2]);
    }
    $cy = $y + 2.5;
    foreach ($right as $r) {
        $lh = PiPdf::lh(8.5);
        $p->SetFont('Helvetica', '', 8.5);
        $p->col(PiPdf::MUTED);
        $p->SetXY($p->L + $bw + 9, $cy);
        $p->Cell(30, $lh, pi_pdf_t($r[0]), 0, 0);
        $p->SetFont('Helvetica', $r[2] ?? '', 8.5);
        $p->col($r[3] ?? PiPdf::INK);
        $p->Cell($bw - 6 - 30, $lh, pi_pdf_t($r[1]), 0, 0);
        $cy += $lh;
    }
    $p->SetY($y + $h + 4);
}

function pi_pdf_render(array $c): string
{
    $inv = $c['inv'];
    $p = new PiPdf('P', 'mm', 'A4');
    $p->SetAutoPageBreak(true, 14);
    $p->SetMargins(14, 10, 14);
    $p->SetTitle(pi_pdf_t('Invoice ' . $inv['invoice_number']));
    $p->SetAuthor('PT. Lisani Alaf Jaya');
    $p->footerText = 'Invoice ' . $inv['invoice_number'];
    $p->AliasNbPages();
    $p->AddPage();

    $header = $c['assets'] . '/invoice_header.png';
    $p->letterhead($header);

    // ---- Title ----
    $p->SetFont('Helvetica', 'B', 18);
    $p->col(PiPdf::NAVY);
    $p->SetX($p->L);
    $p->Cell($p->W, 8, 'I N V O I C E', 0, 1, 'C');
    $p->SetFont('Helvetica', '', 9);
    $p->col(PiPdf::MUTED);
    $p->SetX($p->L);
    $p->Cell($p->W, 5, pi_pdf_t('No. ' . $inv['invoice_number']), 0, 1, 'C');
    $p->Ln(1);

    // ---- Customer / meta ----
    $left = [['KEPADA YTH.', 'B', 7, PiPdf::MUTED], [$inv['customer_name'], 'B', 11, PiPdf::INK]];
    if (trim((string) $inv['phone_number']) !== '') {
        $left[] = ['Telp. ' . $inv['phone_number'], '', 8.5, PiPdf::INK];
    }
    $right = [
        ['No. Invoice', $inv['invoice_number']],
        ['Tanggal Invoice', pi_date_id($inv['created_at'])],
        ['Periode', $c['periodLabel']],
        ['Status', $c['isPaid'] ? 'LUNAS' : 'BELUM LUNAS', 'B', $c['isPaid'] ? PiPdf::GREEN : PiPdf::AMBER],
    ];
    pi_pdf_info_boxes($p, $left, $right);

    // ---- Main table ----
    $W = [7, 31, 40, 16, 25, 27, 36];
    $heads = [[$W[0], 'No', 'C'], [$W[1], 'Tanggal'], [$W[2], 'Produk'], [$W[3], 'Jumlah *', 'R'],
              [$W[4], 'Harga Satuan', 'R'], [$W[5], 'Total', 'R'], [$W[6], 'Keterangan']];
    $drawHead = function () use ($p, $heads) {
        $p->headerRow($heads);
    };
    $drawHead();
    if (!$c['groups']) {
        $p->SetFont('Helvetica', '', 8.5);
        $p->col(PiPdf::MUTED);
        $p->Cell($p->W, 9, 'Tidak ada rincian barang pada invoice ini.', 0, 1, 'C');
    }
    $tagColor = ['tag-ret' => PiPdf::AMBER, 'tag-adj' => PiPdf::BLUE, 'tag-def' => PiPdf::RED];
    foreach ($c['groups'] as $i => $g) {
        $dateLines = [[pi_date_id($g['first']), '', 7.5, PiPdf::INK]];
        if (substr((string) $g['first'], 0, 10) !== substr((string) $g['last'], 0, 10)) {
            $dateLines[] = ['s.d. ' . pi_date_id($g['last']), '', 7, PiPdf::MUTED];
        }
        $ket = [];
        if ($g['tag']) {
            $ket[] = [$g['tag'][0], 'B', 7, $tagColor[$g['tag'][1]] ?? PiPdf::INK];
        }
        if ($g['notes']) {
            $ket[] = [implode(' · ', $g['notes']), '', 7, PiPdf::MUTED];
        }
        $priceCol = $g['adj'] ? PiPdf::RED : PiPdf::INK;
        $totCol = $g['deduct'] ? PiPdf::RED : PiPdf::INK;
        $p->tableRow([
            ['w' => $W[0], 'align' => 'C', 'lines' => [[(string) ($i + 1), '', 8, PiPdf::INK]]],
            ['w' => $W[1], 'lines' => $dateLines],
            ['w' => $W[2], 'lines' => [[$g['name'], 'B', 8, PiPdf::INK]]],
            ['w' => $W[3], 'align' => 'R', 'lines' => [[pi_qty($g['show_qty']), '', 8, PiPdf::INK]]],
            ['w' => $W[4], 'align' => 'R', 'lines' => [[pi_money_signed($g['price'], (bool) $g['adj']), '', 8, $priceCol]]],
            ['w' => $W[5], 'align' => 'R', 'lines' => [[pi_money_signed($g['show_total'], $g['deduct']), '', 8, $totCol]]],
            ['w' => $W[6], 'lines' => $ket ?: [['', '', 7, PiPdf::INK]]],
        ], $drawHead);
    }
    $p->Ln(3);

    // ---- Terbilang + summary ----
    $sumRows = [['Total Pengambilan Barang', pi_money_signed($c['outSum'] - $c['inSum']), '', PiPdf::INK]];
    if ($c['adjSum'] > 0.004) {
        $sumRows[] = ['Potongan Harga', pi_money_signed($c['adjSum'], true), '', PiPdf::RED];
    }
    $sumRows[] = ['TOTAL TAGIHAN', pi_money_signed($c['totalAmount']), 'B', PiPdf::INK, 'grand'];
    $sumRows[] = ['Sudah Dibayar', pi_money($c['paidAmount']), '', PiPdf::INK];
    if ($c['remaining'] < -0.004) {
        $sumRows[] = ['Kelebihan Pembayaran', pi_money($c['remaining']), 'B', PiPdf::INK];
    } else {
        $sumRows[] = ['Sisa Tagihan', pi_money(max(0, $c['remaining'])), 'B', PiPdf::INK];
    }
    $p->ensure(7 * count($sumRows) + 14);
    $y0 = $p->GetY();
    $lw = 96.0;
    $sx = $p->L + $lw + 8;
    $sw = $p->W - $lw - 8;
    if ($c['terbilangText'] !== '') {
        $p->SetXY($p->L, $y0);
        $p->SetFont('Helvetica', 'B', 7);
        $p->col(PiPdf::MUTED);
        $p->Cell($lw, 4, 'TERBILANG', 0, 1);
        $p->SetFont('Helvetica', 'I', 8.5);
        $lines = $p->wrap($c['terbilangText'], $lw - 6);
        $bh = count($lines) * PiPdf::lh(8.5) + 4;
        $p->col(PiPdf::SOFT, 'fill');
        $p->col(PiPdf::LINE, 'draw');
        $p->SetLineWidth(0.25);
        $p->SetDash(1, 1);
        $p->Rect($p->L, $y0 + 4, $lw, $bh, 'DF');
        $p->SetDash();
        $p->col(PiPdf::INK);
        $ty = $y0 + 6;
        foreach ($lines as $t) {
            $p->SetXY($p->L + 3, $ty);
            $p->Cell($lw - 6, PiPdf::lh(8.5), $t, 0, 0);
            $ty += PiPdf::lh(8.5);
        }
    }
    $sy = $y0;
    foreach ($sumRows as $r) {
        $grand = isset($r[4]);
        $rh = $grand ? 7.5 : 5.2;
        $p->SetFont('Helvetica', $r[2], $grand ? 10 : 8.5);
        $p->col($r[3]);
        $p->SetXY($sx, $sy);
        $p->Cell($sw * 0.55, $rh, pi_pdf_t($r[0]), 0, 0);
        $p->Cell($sw * 0.45, $rh, pi_pdf_t($r[1]), 0, 0, 'R');
        if ($grand) {
            $p->col(PiPdf::NAVY, 'draw');
            $p->SetLineWidth(0.5);
            $p->Line($sx, $sy, $sx + $sw, $sy);
            $p->Line($sx, $sy + $rh, $sx + $sw, $sy + $rh);
        }
        $sy += $rh;
    }
    if ($c['isPaid']) {
        $p->SetFont('Helvetica', 'B', 15);
        $p->col(PiPdf::GREEN);
        $p->col(PiPdf::GREEN, 'draw');
        $p->SetLineWidth(0.8);
        $tw = $p->GetStringWidth('LUNAS') + 8;
        $p->Rect($sx + $sw - $tw, $sy + 3, $tw, 9);
        $p->SetXY($sx + $sw - $tw, $sy + 3);
        $p->Cell($tw, 9, 'LUNAS', 0, 0, 'C');
        $sy += 13;
    }
    $p->SetY(max($y0 + 4 + (isset($bh) ? $bh : 0), $sy) + 3);

    // ---- Payments ----
    if ($c['payments']) {
        $p->ensure(20);
        $p->section('Pembayaran Diterima');
        $PW = [40, $p->W - 40 - 40, 40];
        $ph = function () use ($p, $PW) {
            $p->headerRow([[$PW[0], 'Tanggal'], [$PW[1], 'Rekening Penerima'], [$PW[2], 'Jumlah', 'R']]);
        };
        $ph();
        foreach ($c['payments'] as $pay) {
            $bank = trim((string) $pay['bank']);
            if (strtoupper($bank) === 'CREDIT BALANCE') {
                $bank = 'Saldo Kredit';
            }
            $label = implode(' · ', array_filter([$bank, trim((string) $pay['holder'])], 'strlen'));
            $p->tableRow([
                ['w' => $PW[0], 'lines' => [[pi_date_id($pay['payment_date']), '', 8, PiPdf::INK]]],
                ['w' => $PW[1], 'lines' => [[$label !== '' ? $label : '-', '', 8, PiPdf::INK]]],
                ['w' => $PW[2], 'align' => 'R', 'lines' => [[pi_money($pay['amount']), '', 8, PiPdf::INK]]],
            ], $ph, 1.0);
        }
        $p->Ln(2);
    }

    // ---- Signature (left) + bank accounts (right) ----
    $bankBlocks = [];
    $bw = ($p->W - 10) / 2;
    $bankH = 0.0;
    foreach ($c['banks'] as $b) {
        $lines = [[$b['bank_name'], 'B', 9, PiPdf::INK], [$b['account_number'], 'B', 11, PiPdf::INK]];
        if ($b['account_name'] !== '') {
            $lines[] = ['a.n. ' . $b['account_name'], '', 7.5, PiPdf::MUTED];
        }
        if ($b['currency'] !== '' && $b['currency'] !== 'IDR') {
            $lines[] = ['Mata uang: ' . $b['currency'] . ($b['swift_code'] !== '' ? ' · SWIFT: ' . $b['swift_code'] : ''), '', 7.5, PiPdf::MUTED];
            if ($b['address'] !== '') {
                $lines[] = [$b['address'], '', 7.5, PiPdf::MUTED];
            }
        }
        $cell = ['w' => $bw - 5, 'lines' => $lines];
        $hh = $p->cellHeight($cell, 0.0) + 3;
        $bankBlocks[] = [$cell, $hh];
        $bankH += $hh + 2;
    }
    $sigPath = $c['assets'] . '/invoice_signature.png';
    $sigH = 0.0;
    $sigW = 0.0;
    if (is_file($sigPath) && ($isz = @getimagesize($sigPath)) && $isz[0] > 0) {
        $sigW = 42.0;
        $sigH = $sigW * $isz[1] / $isz[0];
        if ($sigH > 22) {
            $sigH = 22.0;
            $sigW = $sigH * $isz[0] / $isz[1];
        }
    }
    $leftH = 6 + $sigH + 12;
    $rightH = $c['banks'] ? 6 + $bankH + 6 : 0;
    $p->ensure(max($leftH, $rightH) + 4);
    $y0 = $p->GetY();
    $p->SetXY($p->L, $y0);
    $p->SetFont('Helvetica', 'B', 8);
    $p->col(PiPdf::NAVY);
    $p->Cell($bw, 5, 'HORMAT KAMI,', 0, 1);
    $iy = $y0 + 6;
    if ($sigH > 0) {
        try {
            $p->Image($sigPath, $p->L, $iy, $sigW, $sigH);
        } catch (Throwable $e) {
            error_log('invoice pdf signature: ' . $e->getMessage());
            $sigH = 0.0;
        }
    }
    $ny = $iy + $sigH + 1.5;
    $p->SetXY($p->L, $ny);
    $p->SetFont('Helvetica', 'B', 9);
    $p->col(PiPdf::INK);
    $p->Cell($bw, 4.5, pi_pdf_t(PI_SIGNER_NAME), 0, 1);
    $tw = $p->GetStringWidth(pi_pdf_t(PI_SIGNER_NAME));
    $p->col(PiPdf::INK, 'draw');
    $p->SetLineWidth(0.2);
    $p->Line($p->L, $ny + 4.3, $p->L + $tw, $ny + 4.3);
    $p->SetXY($p->L, $ny + 4.6);
    $p->SetFont('Helvetica', '', 8.5);
    $p->col(PiPdf::MUTED);
    $p->Cell($bw, 4.5, pi_pdf_t(PI_SIGNER_ROLE), 0, 1);
    $endLeft = $ny + 9.5;

    $endRight = $y0;
    if ($c['banks']) {
        $bx = $p->L + $bw + 10;
        $p->SetXY($bx, $y0);
        $p->SetFont('Helvetica', 'B', 8);
        $p->col(PiPdf::NAVY);
        $p->Cell($bw, 5, 'INFORMASI PEMBAYARAN', 0, 1);
        $by = $y0 + 6;
        foreach ($bankBlocks as $bb) {
            [$cell, $hh] = $bb;
            $p->col(PiPdf::LINE, 'draw');
            $p->SetLineWidth(0.25);
            $p->Rect($bx, $by, $bw, $hh);
            $p->col(PiPdf::GREEN, 'fill');
            $p->Rect($bx, $by, 1.2, $hh, 'F');
            $p->drawCell($cell, $bx + 4, $by + 1.5, 0.0);
            $by += $hh + 2;
        }
        $p->SetXY($bx, $by);
        $p->SetFont('Helvetica', '', 7.5);
        $p->col(PiPdf::MUTED);
        $p->Cell($bw, 4, 'Mohon cantumkan nomor invoice pada berita transfer.', 0, 1);
        $endRight = $by + 4;
    }
    $p->SetY(max($endLeft, $endRight) + 4);

    // ---- "* Satuan Produk" note (bottom, with spacing) ----
    if ($c['unitRows']) {
        $anyS = $c['anySecondary'];
        $UW = $anyS ? [64, 50, 68] : [100, 82];
        $rowsH = 0.0;
        $p->ensure(26);
        $p->col(PiPdf::LINE, 'draw');
        $p->SetLineWidth(0.25);
        $p->SetDash(1, 1);
        $p->Line($p->L, $p->GetY(), $p->L + $p->W, $p->GetY());
        $p->SetDash();
        $p->Ln(2.5);
        $p->section('* Satuan Produk');
        $p->SetX($p->L);
        $p->SetFont('Helvetica', '', 7.5);
        $p->col(PiPdf::MUTED);
        $p->Cell($p->W, 4, '* Satuan untuk kolom Jumlah pada tabel di atas.', 0, 1);
        $uh = [[$UW[0], 'Produk'], [$UW[1], 'Satuan Primary']];
        if ($anyS) {
            $uh[] = [$UW[2], 'Satuan Secondary'];
        }
        $uhead = function () use ($p, $uh) {
            $p->headerRow($uh);
        };
        $uhead();
        foreach ($c['unitRows'] as $u) {
            $pl = [[$u['p'] !== '' ? $u['p'] : '-', '', 8, PiPdf::INK]];
            if ($u['p'] !== '' && $u['pw'] > 0) {
                $pl[] = ['@ ' . pi_qty($u['pw']) . ' kg', '', 7, PiPdf::MUTED];
            }
            $cells = [
                ['w' => $UW[0], 'lines' => [[$u['name'], 'B', 8, PiPdf::INK]]],
                ['w' => $UW[1], 'lines' => $pl],
            ];
            if ($anyS) {
                $sl = [];
                if ($u['s'] !== '') {
                    $sl[] = [$u['s'], '', 8, PiPdf::INK];
                    if ($u['ssub']) {
                        $sl[] = [implode(' · ', $u['ssub']), '', 7, PiPdf::MUTED];
                    }
                }
                $cells[] = ['w' => $UW[2], 'lines' => $sl ?: [['', '', 8, PiPdf::INK]]];
            }
            $p->tableRow($cells, $uhead, 1.0);
        }
    }

    // ---- Attachment (Lampiran) ----
    if ($c['rows'] && !empty($c['withAttachment'])) {
        $p->AddPage();
        $p->letterhead($header);
        $p->SetFont('Helvetica', 'B', 15);
        $p->col(PiPdf::NAVY);
        $p->SetX($p->L);
        $p->Cell($p->W, 7, 'L A M P I R A N', 0, 1, 'C');
        $p->SetFont('Helvetica', '', 9);
        $p->col(PiPdf::MUTED);
        $p->SetX($p->L);
        $p->Cell($p->W, 5, 'Rincian Pengambilan Barang per Tanggal', 0, 1, 'C');
        $p->Ln(3);
        pi_pdf_info_boxes(
            $p,
            [['KEPADA YTH.', 'B', 7, PiPdf::MUTED], [$inv['customer_name'], 'B', 11, PiPdf::INK]],
            [['No. Invoice', $inv['invoice_number']], ['Periode', $c['periodLabel']]]
        );

        $AW = [60, 18, 30, 32, 42];
        $ah = [[$AW[0], 'Produk'], [$AW[1], 'Jumlah', 'R'], [$AW[2], 'Harga Satuan', 'R'], [$AW[3], 'Total', 'R'], [$AW[4], 'Keterangan']];
        $ahead = function () use ($p, $ah) {
            $p->headerRow($ah);
        };
        $ahead();
        foreach ($c['byDate'] as $date => $list) {
            $sub = 0.0;
            $p->ensure(22);
            $p->tableRow([['w' => $p->W, 'lines' => [[pi_date_id($date), 'B', 8.5, PiPdf::NAVY]]]], $ahead, 1.2, 'soft');
            foreach ($list as $r) {
                $sub += $r['deduct'] ? -$r['total'] : $r['total'];
                $ket = [];
                if ($r['tag']) {
                    $ket[] = [$r['tag'][0], 'B', 7, $tagColor[$r['tag'][1]] ?? PiPdf::INK];
                }
                if ($r['notes']) {
                    $ket[] = [implode(' · ', $r['notes']), '', 7, PiPdf::MUTED];
                }
                $isAdj = $r['type'] === 'price_adjustment';
                $p->tableRow([
                    ['w' => $AW[0], 'lines' => [[$r['name'], 'B', 8, PiPdf::INK]]],
                    ['w' => $AW[1], 'align' => 'R', 'lines' => [[pi_qty($r['qty']), '', 8, PiPdf::INK]]],
                    ['w' => $AW[2], 'align' => 'R', 'lines' => [[pi_money_signed($r['price'], $isAdj), '', 8, $isAdj ? PiPdf::RED : PiPdf::INK]]],
                    ['w' => $AW[3], 'align' => 'R', 'lines' => [[pi_money_signed($r['total'], $r['deduct']), '', 8, $r['deduct'] ? PiPdf::RED : PiPdf::INK]]],
                    ['w' => $AW[4], 'lines' => $ket ?: [['', '', 7, PiPdf::INK]]],
                ], $ahead);
            }
            $p->tableRow([
                ['w' => $AW[0] + $AW[1] + $AW[2], 'align' => 'R', 'lines' => [['Subtotal ' . pi_date_id($date), 'B', 8, PiPdf::INK]]],
                ['w' => $AW[3], 'align' => 'R', 'lines' => [[pi_money_signed($sub), 'B', 8, $sub < 0 ? PiPdf::RED : PiPdf::INK]]],
                ['w' => $AW[4], 'lines' => [['', '', 8, PiPdf::INK]]],
            ], $ahead);
        }
        $net = $c['netSum'];
        $p->tableRow([
            ['w' => $AW[0] + $AW[1] + $AW[2], 'align' => 'R', 'lines' => [['TOTAL', 'B', 9, PiPdf::INK]]],
            ['w' => $AW[3], 'align' => 'R', 'lines' => [[pi_money_signed($net), 'B', 9, $net < 0 ? PiPdf::RED : PiPdf::INK]]],
            ['w' => $AW[4], 'lines' => [['', '', 8, PiPdf::INK]]],
        ], $ahead, 1.6);
    }

    return $p->Output('S');
}