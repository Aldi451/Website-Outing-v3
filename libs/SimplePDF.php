<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — libs/SimplePDF.php
 * --------------------------------------------------------------------------
 *  Library pembuat PDF **MURNI PHP** (tanpa Composer, tanpa FPDF/TCPDF,
 *  tanpa ekstensi khusus). Cocok untuk shared hosting InfinityFree.
 *
 *  Fitur :
 *   - Ukuran A4 Portrait / Landscape
 *   - Font inti Helvetica (regular/bold/oblique) & Courier
 *   - Cell, MultiCell (auto wrap), Line, Rect, warna teks/fill/garis
 *   - Auto page break + header & footer otomatis + nomor halaman "x dari y"
 *   - Helper Table() untuk tabel bergaris lengkap dengan header berulang
 *   - Kompresi stream FlateDecode (otomatis nonaktif jika zlib tidak ada)
 *   - Output: inline browser (I), download attachment (D), file (F), string (S)
 *
 *  Catatan: PDF memakai encoding WinAnsi (CP1252). Teks UTF-8 dikonversi
 *  otomatis; karakter khusus di luar CP1252 akan ditransliterasi.
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

class SimplePDF
{
    /* ---------------- properti halaman ---------------- */
    protected $pageW = 595.28;   // A4 portrait (point)
    protected $pageH = 841.89;
    protected $lMargin = 36;
    protected $rMargin = 36;
    protected $tMargin = 42;
    protected $bMargin = 42;
    protected $x = 36;
    protected $y = 42;

    /* ---------------- isi dokumen ---------------- */
    protected $pages = [];          // index halaman => content stream
    protected $pageNo = 0;
    protected $fonts = [];          // 'F1' => ['name'=>'Helvetica','widths'=>[]]
    protected $fontSeq = 0;
    protected $fontKey = '';
    protected $fontSize = 10;
    protected $fontStyle = '';
    protected $fontFamily = 'helvetica';

    /* ---------------- warna & garis ---------------- */
    protected $textRgb = [17, 24, 39];
    protected $fillRgb = [241, 243, 246];
    protected $drawRgb = [203, 210, 219];
    protected $lineWidth = 0.5;

    /* ---------------- header / footer ---------------- */
    protected $headerTitle = '';
    protected $headerSub = '';
    protected $footerLeft = '';
    protected $showHeader = true;
    protected $showFooter = true;

    /* ---------------- lain-lain ---------------- */
    protected $compress = true;
    protected $autoPageBreak = true;
    public $title = 'Laporan';
    public $subject = '';
    public $author = 'Outing Management System';

    /**
     * @param string $orientation 'P' portrait | 'L' landscape
     */
    public function __construct($orientation = 'P')
    {
        if (strtoupper($orientation) === 'L') {
            $this->pageW = 841.89;
            $this->pageH = 595.28;
        }
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        $this->SetFont('helvetica', '', 10);
        if (!function_exists('gzcompress')) {
            $this->compress = false;
        }
    }

    /* ======================================================================
     * MARGIN & POSISI
     * ====================================================================== */

    public function SetMargins($left, $top, $right = null, $bottom = null)
    {
        $this->lMargin = (float) $left;
        $this->tMargin = (float) $top;
        $this->rMargin = $right === null ? (float) $left : (float) $right;
        $this->bMargin = $bottom === null ? (float) $top : (float) $bottom;
    }

    public function SetXY($x, $y)
    {
        $this->x = (float) $x;
        $this->y = (float) $y;
    }

    public function SetX($x)
    {
        $this->x = (float) $x;
    }

    public function SetY($y)
    {
        $this->y = (float) $y;
    }

    public function GetX()
    {
        return $this->x;
    }

    public function GetY()
    {
        return $this->y;
    }

    public function PageWidth()
    {
        return $this->pageW;
    }

    public function PageHeight()
    {
        return $this->pageH;
    }

    /** Lebar area cetak yang tersedia. */
    public function ContentWidth()
    {
        return $this->pageW - $this->lMargin - $this->rMargin;
    }

    public function PageNo()
    {
        return $this->pageNo;
    }

    /** Judul & subjudul yang dicetak otomatis di bagian atas tiap halaman. */
    public function SetHeader($title, $subtitle = '')
    {
        $this->headerTitle = (string) $title;
        $this->headerSub = (string) $subtitle;
        $this->showHeader = true;
    }

    /** Teks kiri footer (kanan selalu nomor halaman). */
    public function SetFooter($text)
    {
        $this->footerLeft = (string) $text;
        $this->showFooter = true;
    }

    public function SetAutoPageBreak($enable)
    {
        $this->autoPageBreak = (bool) $enable;
    }

    /* ======================================================================
     * WARNA
     * ====================================================================== */

    public function SetTextColor($r, $g = null, $b = null)
    {
        $this->textRgb = $this->normalizeColor($r, $g, $b);
    }

    public function SetFillColor($r, $g = null, $b = null)
    {
        $this->fillRgb = $this->normalizeColor($r, $g, $b);
    }

    public function SetDrawColor($r, $g = null, $b = null)
    {
        $this->drawRgb = $this->normalizeColor($r, $g, $b);
    }

    public function SetLineWidth($w)
    {
        $this->lineWidth = (float) $w;
    }

    protected function normalizeColor($r, $g, $b)
    {
        if (is_array($r)) {
            $g = isset($r[1]) ? (int) $r[1] : 0;
            $b = isset($r[2]) ? (int) $r[2] : 0;
            $r = (int) $r[0];
        }
        if ($g === null) {
            $g = $r;
            $b = $r;
        }

        return [max(0, min(255, (int) $r)), max(0, min(255, (int) $g)), max(0, min(255, (int) $b))];
    }

    protected function rgbString(array $rgb)
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    /* ======================================================================
     * FONT
     * ====================================================================== */

    /**
     * @param string $family helvetica|arial|times|courier
     * @param string $style  ''|'B'|'I'|'BI'
     * @param float  $size
     */
    public function SetFont($family, $style = '', $size = 0)
    {
        $family = strtolower(trim((string) $family));
        if ($family === '' ) {
            $family = $this->fontFamily;
        }
        if ($family === 'arial' || $family === 'sans') {
            $family = 'helvetica';
        }
        $style = strtoupper(str_replace('U', '', (string) $style));
        if (strpos($style, 'IB') !== false) {
            $style = 'BI';
        }
        if ($size > 0) {
            $this->fontSize = (float) $size;
        }

        $this->fontFamily = $family;
        $this->fontStyle = $style;
        $this->fontKey = $this->registerFont($family, $style);
    }

    public function SetFontSize($size)
    {
        $this->fontSize = (float) $size;
    }

    protected function registerFont($family, $style)
    {
        // Courier & Times dipetakan ke Helvetica/Courier inti (pasti tersedia di semua PDF reader)
        if ($family === 'times') {
            $family = 'helvetica';
        }
        $base = ($family === 'courier') ? 'Courier' : 'Helvetica';
        if ($family === 'courier') {
            $style = '';
        }
        $name = $base;
        if ($style === 'B') {
            $name = $base . '-Bold';
        } elseif ($style === 'I') {
            $name = $base . '-Oblique';
        } elseif ($style === 'BI') {
            $name = $base . '-BoldOblique';
        }

        foreach ($this->fonts as $key => $f) {
            if ($f['name'] === $name) {
                return $key;
            }
        }

        $this->fontSeq++;
        $key = 'F' . $this->fontSeq;
        $this->fonts[$key] = [
            'name'   => $name,
            'widths' => $this->fontWidths($family, strpos($style, 'B') !== false),
            'fixed'  => ($family === 'courier'),
        ];

        return $key;
    }

    /** Lebar karakter font inti (unit 1/1000). */
    protected function fontWidths($family, $bold)
    {
        if ($family === 'courier') {
            return ['fixed' => 600];
        }

        $digits = [];
        foreach (range(0, 9) as $d) {
            $digits[(string) $d] = 556;
        }

        $base = [
            ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667,
            "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584, ',' => 278, '-' => 333,
            '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584,
            '?' => 556, '@' => 1015, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556,
            '`' => 333, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
            'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
            'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722,
            'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722,
            'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
            'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556, 'f' => 278, 'g' => 556,
            'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556,
            'o' => 556, 'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556,
            'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500,
        ];

        if (!$bold) {
            return array_merge($base, $digits, ['default' => 556]);
        }

        $boldMap = [
            '!' => 333, '"' => 474, '&' => 722, "'" => 238, '?' => 611, '@' => 975,
            '[' => 333, ']' => 333, '^' => 584, '`' => 333, '{' => 389, '|' => 280, '}' => 389,
            ':' => 333, ';' => 333,
            'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
            'H' => 778, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611, 'M' => 833, 'N' => 722,
            'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722,
            'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611,
            'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611, 'e' => 556, 'f' => 333, 'g' => 611,
            'h' => 611, 'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611,
            'o' => 611, 'p' => 611, 'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611,
            'v' => 556, 'w' => 778, 'x' => 556, 'y' => 556, 'z' => 500,
        ];

        return array_merge($base, $digits, $boldMap, ['default' => 556]);
    }

    /** Lebar teks dalam point. */
    public function GetStringWidth($text)
    {
        $text = $this->toWinAnsi((string) $text);
        if (!isset($this->fonts[$this->fontKey])) {
            return strlen($text) * $this->fontSize * 0.5;
        }
        $widths = $this->fonts[$this->fontKey]['widths'];
        if (isset($widths['fixed'])) {
            return strlen($text) * $widths['fixed'] * $this->fontSize / 1000;
        }
        $total = 0;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            $total += isset($widths[$ch]) ? $widths[$ch] : $widths['default'];
        }

        return $total * $this->fontSize / 1000;
    }

    /* ======================================================================
     * HALAMAN
     * ====================================================================== */

    /** Tambah halaman baru (header otomatis digambar). */
    public function AddPage()
    {
        $this->pages[] = '';
        $this->pageNo = count($this->pages);
        $this->x = $this->lMargin;
        $this->y = $this->tMargin;
        if ($this->showHeader) {
            $this->drawHeader();
        }
    }

    /** Tambahkan operator ke content stream halaman aktif. */
    protected function out($cmd)
    {
        if ($this->pageNo === 0) {
            $this->AddPage();
        }
        $idx = $this->pageNo - 1;
        $this->pages[$idx] .= $cmd . "\n";
    }

    protected function drawHeader()
    {
        $yStart = $this->y;

        // Pita judul
        $this->SetFillColor(13, 110, 253);
        $this->Rect($this->lMargin, $yStart - 14, $this->ContentWidth(), 4, 'F');

        if ($this->headerTitle !== '') {
            $this->SetFont('helvetica', 'B', 15);
            $this->SetTextColor(17, 24, 39);
            $this->SetXY($this->lMargin, $yStart - 4);
            $this->Cell($this->ContentWidth(), 16, $this->headerTitle, 0, 2, 'L');

            if ($this->headerSub !== '') {
                $this->SetFont('helvetica', '', 9.5);
                $this->SetTextColor(90, 98, 112);
                $this->Cell($this->ContentWidth(), 12, $this->headerSub, 0, 2, 'L');
                $this->SetTextColor(17, 24, 39);
            }
            $this->y += 4;
        }

        $this->SetXY($this->lMargin, $this->y);
    }

    /** Footer digambar saat Output() karena total halaman baru diketahui di akhir. */
    protected function buildFooters()
    {
        if (!$this->showFooter) {
            return;
        }
        $total = count($this->pages);
        $footerY = $this->pageH - $this->bMargin + 12;

        for ($i = 0; $i < $total; $i++) {
            $cmd = '';
            $cmd .= $this->rgbString($this->drawRgb) . " RG\n";
            $cmd .= sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $this->lineWidth,
                $this->lMargin, $this->pageH - $footerY, $this->pageW - $this->rMargin, $this->pageH - $footerY) . "\n";

            $left = $this->footerLeft !== '' ? $this->footerLeft : 'Dokumen dicetak oleh ' . $this->author;
            $left .= ' - ' . date('d/m/Y H:i');
            $right = 'Halaman ' . ($i + 1) . ' dari ' . $total;

            $cmd .= $this->rgbString([120, 128, 140]) . " rg\n";
            $cmd .= $this->textOp($left, $this->lMargin, $footerY + 5, 7.5, 'helvetica', '', 'L', $this->ContentWidth());
            $cmd .= $this->textOp($right, $this->lMargin, $footerY + 5, 7.5, 'helvetica', 'B', 'R', $this->ContentWidth());
            $cmd .= $this->rgbString($this->textRgb) . " rg\n";

            $this->pages[$i] .= $cmd;
        }
    }

    /* ======================================================================
     * PRIMITIF GAMBAR
     * ====================================================================== */

    /**
     * Persegi / kotak.
     *
     * @param string $style 'F' fill | 'D' garis | 'FD' keduanya
     */
    public function Rect($x, $y, $w, $h, $style = 'D')
    {
        $op = ($style === 'F') ? 'f' : (($style === 'FD' || $style === 'DF') ? 'B' : 'S');
        $cmd = '';
        if ($op === 'f' || $op === 'B') {
            $cmd .= $this->rgbString($this->fillRgb) . " rg\n";
        }
        if ($op === 'S' || $op === 'B') {
            $cmd .= $this->rgbString($this->drawRgb) . " RG\n";
            $cmd .= sprintf('%.2F w ', $this->lineWidth);
        }
        $cmd .= sprintf('%.2F %.2F %.2F %.2F re %s', $x, $this->pageH - $y - $h, $w, $h, $op);
        $this->out($cmd);
    }

    public function Line($x1, $y1, $x2, $y2)
    {
        $cmd = $this->rgbString($this->drawRgb) . " RG\n";
        $cmd .= sprintf('%.2F w %.2F %.2F m %.2F %.2F l S', $this->lineWidth,
            $x1, $this->pageH - $y1, $x2, $this->pageH - $y2);
        $this->out($cmd);
    }

    /** Garis pemisah horizontal selebar konten. */
    public function Rule($space = 3)
    {
        $this->y += $space;
        $this->Line($this->lMargin, $this->y, $this->pageW - $this->rMargin, $this->y);
        $this->y += $space + 1;
        $this->x = $this->lMargin;
    }

    /* ======================================================================
     * TEKS
     * ====================================================================== */

    /** Operator teks tunggal (dipakai Cell/MultiCell/Footer). */
    protected function textOp($text, $x, $yTopDown, $size, $family, $style, $align, $boxWidth = 0)
    {
        $key = $this->registerFont($family, $style);
        $prevKey = $this->fontKey;
        $prevSize = $this->fontSize;
        $prevStyle = $this->fontStyle;
        $prevFamily = $this->fontFamily;

        $this->fontKey = $key;
        $this->fontSize = $size;
        $this->fontStyle = $style;
        $this->fontFamily = $family;

        $width = $this->GetStringWidth($text);
        if ($align === 'C' && $boxWidth > 0) {
            $x += max(0, ($boxWidth - $width) / 2);
        } elseif ($align === 'R' && $boxWidth > 0) {
            $x += max(0, $boxWidth - $width);
        }

        $pdfY = $this->pageH - $yTopDown;
        $cmd = $this->rgbString($this->textRgb) . " rg\n";
        $cmd .= sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            $key, $size, $x, $pdfY, $this->escapeText($this->toWinAnsi($text)));

        $this->fontKey = $prevKey;
        $this->fontSize = $prevSize;
        $this->fontStyle = $prevStyle;
        $this->fontFamily = $prevFamily;

        return $cmd;
    }

    /**
     * Kotak teks (mirip FPDF).
     *
     * @param float  $w      lebar (0 = sampai margin kanan)
     * @param float  $h      tinggi
     * @param string $text
     * @param mixed  $border 0 | 1 | 'FD' | 'F' | 'D'
     * @param int    $ln     0 = kanan sel, 1 = awal baris baru, 2 = bawah sel
     * @param string $align  L | C | R
     * @param bool   $fill   isi latar
     */
    public function Cell($w, $h = 0, $text = '', $border = 0, $ln = 0, $align = 'L', $fill = false)
    {
        if ($w == 0) {
            $w = $this->pageW - $this->rMargin - $this->x;
        }
        $k = $h;

        if ($this->autoPageBreak && $this->y + $h > $this->pageH - $this->bMargin) {
            $this->AddPage();
        }

        $x = $this->x;
        $y = $this->y;

        if ($fill || $border === 1 || $border === 'FD' || $border === 'DF' || $border === 'F') {
            $prevFill = $this->fillRgb;
            if ($fill) {
                $style = ($border === 1 || $border === 'FD' || $border === 'DF') ? 'FD' : 'F';
            } else {
                $style = 'D';
            }
            $this->Rect($x, $y, $w, $k, $style);
            $this->fillRgb = $prevFill;
        }

        if ((string) $text !== '') {
            $pad = 2.5;
            $tx = $x + $pad;
            $baseline = $y + ($k / 2) + ($this->fontSize * 0.34);
            $cmd = $this->rgbString($this->textRgb) . " rg\n";
            $cmd .= sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                $this->fontKey, $this->fontSize, $tx, $this->pageH - $baseline,
                $this->escapeText($this->toWinAnsi($text)));
            $this->out($cmd);
        }

        if ($ln === 1) {
            $this->x = $this->lMargin;
            $this->y += $k;
        } elseif ($ln === 2) {
            $this->y += $k;
        } else {
            $this->x += $w;
        }
    }

    /**
     * Teks multi-baris (auto wrap).
     *
     * @return int jumlah baris yang tercetak
     */
    public function MultiCell($w, $h, $text, $border = 0, $align = 'L', $fill = false)
    {
        if ($w == 0) {
            $w = $this->pageW - $this->rMargin - $this->x;
        }
        $lines = $this->WrapText((string) $text, $w - 4);
        $x0 = $this->x;
        $count = 0;

        foreach ($lines as $line) {
            if ($this->autoPageBreak && $this->y + $h > $this->pageH - $this->bMargin) {
                $this->AddPage();
                $this->x = $x0;
            }
            if ($fill || $border) {
                $style = $fill ? ($border ? 'FD' : 'F') : 'D';
                $this->Rect($x0, $this->y, $w, $h, $style);
            }
            if ($line !== '') {
                $tx = $x0 + 2;
                if ($align === 'R') {
                    $tx = $x0 + $w - 2 - $this->GetStringWidth($line);
                } elseif ($align === 'C') {
                    $tx = $x0 + max(2, ($w - $this->GetStringWidth($line)) / 2);
                }
                $baseline = $this->y + ($h / 2) + ($this->fontSize * 0.34);
                $cmd = $this->rgbString($this->textRgb) . " rg\n";
                $cmd .= sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                    $this->fontKey, $this->fontSize, $tx, $this->pageH - $baseline,
                    $this->escapeText($this->toWinAnsi($line)));
                $this->out($cmd);
            }
            $this->y += $h;
            $this->x = $x0;
            $count++;
        }

        return max(1, $count);
    }

    /** Pindah baris. */
    public function Ln($h = null)
    {
        $this->x = $this->lMargin;
        $this->y += ($h === null ? $this->fontSize * 1.5 : (float) $h);
    }

    /** Teks langsung pada koordinat. */
    public function Text($x, $y, $text, $align = 'L', $boxWidth = 0)
    {
        $cmd = $this->rgbString($this->textRgb) . " rg\n";
        $tx = (float) $x;
        $width = $this->GetStringWidth($text);
        if ($align === 'R' && $boxWidth > 0) {
            $tx = $x + $boxWidth - $width;
        } elseif ($align === 'C' && $boxWidth > 0) {
            $tx = $x + ($boxWidth - $width) / 2;
        }
        $cmd .= sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
            $this->fontKey, $this->fontSize, $tx, $this->pageH - $y,
            $this->escapeText($this->toWinAnsi($text)));
        $this->out($cmd);
    }

    /**
     * Pecah teks menjadi baris sesuai lebar tersedia.
     *
     * @return array
     */
    public function WrapText($text, $maxWidth)
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        if ($text === '' || $maxWidth <= 0) {
            return [''];
        }

        $lines = [];
        $current = '';
        $words = explode(' ', $text);

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            // kata lebih panjang dari lebar: potong paksa
            while ($this->GetStringWidth($word) > $maxWidth) {
                $cut = strlen($word);
                while ($cut > 1 && $this->GetStringWidth(substr($word, 0, $cut)) > $maxWidth) {
                    $cut--;
                }
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = substr($word, 0, $cut);
                $word = substr($word, $cut);
            }
            if ($word === '') {
                continue;
            }
            $trial = ($current === '') ? $word : $current . ' ' . $word;
            if ($this->GetStringWidth($trial) <= $maxWidth) {
                $current = $trial;
            } else {
                if ($current !== '') {
                    $lines[] = $current;
                }
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return count($lines) === 0 ? [''] : $lines;
    }

    /* ======================================================================
     * KOMPONEN LAPORAN
     * ====================================================================== */

    /** Judul bagian (section) bergaris bawah tipis. */
    public function SectionTitle($text)
    {
        $this->y += 4;
        $this->SetFont('helvetica', 'B', 11);
        $this->SetTextColor(13, 110, 253);
        $this->Cell($this->ContentWidth(), 16, strtoupper((string) $text), 0, 2, 'L');
        $this->SetTextColor(17, 24, 39);
        $this->Line($this->lMargin, $this->y - 1, $this->pageW - $this->rMargin, $this->y - 1);
        $this->y += 3;
    }

    /** Baris "Label : Nilai" (label tebal). */
    public function KeyValue($label, $value, $labelWidth = 110, $lineHeight = 13)
    {
        $x = $this->x;
        $y = $this->y;

        $this->SetFont('helvetica', 'B', 9.5);
        $this->Cell($labelWidth, $lineHeight, (string) $label, 0, 0, 'L');
        $this->SetFont('helvetica', '', 9.5);
        $this->Cell(8, $lineHeight, ':', 0, 0, 'L');

        $valueWidth = $this->pageW - $this->rMargin - ($x + $labelWidth + 8);
        $lines = $this->WrapText((string) $value, max(10, $valueWidth - 4));
        $this->MultiCell($valueWidth, $lineHeight, (string) $value, 0, 'L');

        $this->x = $x;
        $this->y = max($this->y, $y + $lineHeight * count($lines));
    }

    /** Kotak ringkasan (angka besar + keterangan), disusun mendatar. */
    public function SummaryBoxes(array $items, $height = 34)
    {
        $count = count($items);
        if ($count === 0) {
            return;
        }
        $gap = 6;
        $totalW = $this->ContentWidth();
        $boxW = ($totalW - ($gap * ($count - 1))) / $count;

        if ($this->autoPageBreak && $this->y + $height > $this->pageH - $this->bMargin) {
            $this->AddPage();
        }

        $x = $this->lMargin;
        $y = $this->y;

        foreach ($items as $item) {
            $label = isset($item['label']) ? (string) $item['label'] : '';
            $value = isset($item['value']) ? (string) $item['value'] : '';
            $rgb = isset($item['color']) ? $this->normalizeColor($item['color']) : [233, 237, 241];

            $prevFill = $this->fillRgb;
            $prevDraw = $this->drawRgb;
            $this->SetFillColor($rgb);
            $this->SetDrawColor($rgb);
            $this->Rect($x, $y, $boxW, $height, 'F');
            $this->fillRgb = $prevFill;
            $this->drawRgb = $prevDraw;

            $this->SetFont('helvetica', '', 7.5);
            $this->SetTextColor(96, 105, 118);
            $this->Text($x + 5, $y + 12, strtoupper($label), 'L', $boxW - 10);

            $this->SetFont('helvetica', 'B', 10.5);
            $this->SetTextColor(17, 24, 39);
            $valText = $value;
            if ($this->GetStringWidth($valText) > $boxW - 10) {
                $wrapped = $this->WrapText($valText, $boxW - 10);
                $valText = $wrapped[0];
            }
            $this->Text($x + 5, $y + 25, $valText, 'L', $boxW - 10);

            $this->SetTextColor(17, 24, 39);
            $x += $boxW + $gap;
        }

        $this->y = $y + $height + 6;
        $this->x = $this->lMargin;
    }

    /**
     * Tabel bergaris lengkap.
     *
     * @param array $headers ['No','Nama', ...]
     * @param array $rows    [[...],[...]]
     * @param array $widths  lebar tiap kolom (point); jumlah harus <= ContentWidth
     * @param array $aligns  ['C','L','R', ...]
     * @param array $opts    fontSize, lineHeight, zebra (bool), emptyText
     */
    public function Table(array $headers, array $rows, array $widths, array $aligns = [], array $opts = [])
    {
        $fontSize = isset($opts['fontSize']) ? (float) $opts['fontSize'] : 8.5;
        $lineHeight = isset($opts['lineHeight']) ? (float) $opts['lineHeight'] : 10.5;
        $zebra = !isset($opts['zebra']) ? true : (bool) $opts['zebra'];
        $emptyText = isset($opts['emptyText']) ? $opts['emptyText'] : 'Tidak ada data.';

        // normalisasi jumlah kolom
        $cols = count($headers);
        while (count($widths) < $cols) {
            $widths[] = 40;
        }
        $scale = array_sum($widths);
        $available = $this->ContentWidth();
        if ($scale > 0 && abs($scale - $available) > 0.5) {
            foreach ($widths as $i => $w) {
                $widths[$i] = $w * ($available / $scale);
            }
        }
        while (count($aligns) < $cols) {
            $aligns[] = 'L';
        }

        $this->drawTableHeader($headers, $widths, $aligns, $fontSize, $lineHeight);

        if (count($rows) === 0) {
            $this->SetFont('helvetica', 'I', $fontSize);
            $this->SetFillColor(250, 250, 251);
            $this->Cell($available, 18, $emptyText, 1, 1, 'C', true);
            return;
        }

        $index = 0;
        foreach ($rows as $row) {
            $cells = [];
            for ($i = 0; $i < $cols; $i++) {
                $cells[$i] = isset($row[$i]) ? (string) $row[$i] : '';
            }
            // ukur tinggi baris dengan metrik font REGULER (font isi tabel)
            $this->SetFont('helvetica', '', $fontSize);
            $rowH = $this->tableRowHeight($cells, $widths, $fontSize, $lineHeight);

            if ($this->autoPageBreak && ($this->y + $rowH) > ($this->pageH - $this->bMargin)) {
                $this->AddPage();
                $this->drawTableHeader($headers, $widths, $aligns, $fontSize, $lineHeight);
            }

            $this->drawTableRow($cells, $widths, $aligns, $fontSize, $lineHeight, $rowH, ($zebra && $index % 2 === 1));
            $index++;
        }

        $this->y += 3;
        $this->x = $this->lMargin;
    }

    protected function drawTableHeader(array $headers, array $widths, array $aligns, $fontSize, $lineHeight)
    {
        $this->SetFont('helvetica', 'B', $fontSize);
        $this->SetFillColor(13, 110, 253);
        $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(255, 255, 255);

        $h = $this->tableRowHeight($headers, $widths, $fontSize, $lineHeight);
        $x = $this->lMargin;
        $y = $this->y;

        foreach ($headers as $i => $text) {
            $w = $widths[$i];
            $this->Rect($x, $y, $w, $h, 'FD');
            $this->drawCellText($text, $x, $y, $w, $h, $aligns[$i], $fontSize);
            $x += $w;
        }

        $this->SetTextColor(17, 24, 39);
        $this->SetDrawColor(203, 210, 219);
        $this->y = $y + $h;
        $this->x = $this->lMargin;
    }

    protected function drawTableRow(array $cells, array $widths, array $aligns, $fontSize, $lineHeight, $rowH, $filled = false)
    {
        $this->SetFont('helvetica', '', $fontSize);
        if ($filled) {
            $this->SetFillColor(247, 249, 251);
        } else {
            $this->SetFillColor(255, 255, 255);
        }

        $x = $this->lMargin;
        $y = $this->y;

        foreach ($cells as $i => $text) {
            $w = isset($widths[$i]) ? $widths[$i] : 40;
            $this->Rect($x, $y, $w, $rowH, 'FD');
            $this->drawCellText($text, $x, $y, $w, $rowH, isset($aligns[$i]) ? $aligns[$i] : 'L', $fontSize, $lineHeight);
            $x += $w;
        }

        $this->y = $y + $rowH;
        $this->x = $this->lMargin;
    }

    protected function tableRowHeight(array $cells, array $widths, $fontSize, $lineHeight)
    {
        $prevSize = $this->fontSize;
        $prevKey = $this->fontKey;
        $this->fontSize = $fontSize;

        $maxLines = 1;
        foreach ($cells as $i => $text) {
            $w = isset($widths[$i]) ? $widths[$i] : 40;
            $lines = $this->WrapText((string) $text, max(10, $w - 5));
            if (count($lines) > $maxLines) {
                $maxLines = count($lines);
            }
        }

        $this->fontSize = $prevSize;
        $this->fontKey = $prevKey;

        return max($lineHeight, $maxLines * $lineHeight) + 3;
    }

    /** Gambar teks (multi-baris) di dalam satu kotak sel. */
    protected function drawCellText($text, $x, $y, $w, $h, $align, $fontSize, $lineHeight = null)
    {
        if ($lineHeight === null) {
            $lineHeight = $fontSize * 1.25;
        }
        $lines = $this->WrapText((string) $text, max(10, $w - 5));
        $totalTextHeight = count($lines) * $lineHeight;
        $startY = $y + max(1.5, ($h - $totalTextHeight) / 2);

        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }
            $tx = $x + 2.5;
            $lw = $this->GetStringWidth($line);
            if ($align === 'R') {
                $tx = $x + $w - 2.5 - $lw;
            } elseif ($align === 'C') {
                $tx = $x + max(2.5, ($w - $lw) / 2);
            }
            $baseline = $startY + ($i * $lineHeight) + ($lineHeight / 2) + ($fontSize * 0.34);
            $cmd = $this->rgbString($this->textRgb) . " rg\n";
            $cmd .= sprintf('BT /%s %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                $this->fontKey, $fontSize, $tx, $this->pageH - $baseline,
                $this->escapeText($this->toWinAnsi($line)));
            $this->out($cmd);
        }
    }

    /** Baris total (tebal, latar abu). */
    public function TotalRow($label, $value, array $widths = [], $labelSpan = 0)
    {
        $this->SetFont('helvetica', 'B', 9);
        $this->SetFillColor(233, 237, 241);
        $w = $this->ContentWidth();
        $this->y += 1;
        if ($this->autoPageBreak && $this->y + 14 > $this->pageH - $this->bMargin) {
            $this->AddPage();
        }
        $y = $this->y;
        $this->Rect($this->lMargin, $y, $w, 14, 'FD');
        $this->drawCellText($label, $this->lMargin, $y, $w * 0.7, 14, 'R', 9);
        $this->drawCellText($value, $this->lMargin + ($w * 0.7), $y, $w * 0.3 - 2, 14, 'R', 9);
        $this->y = $y + 16;
        $this->x = $this->lMargin;
    }

    /** Blok tanda tangan (2 kolom). */
    public function SignatureBlock($leftTitle, $leftName, $rightTitle, $rightName, $space = 46)
    {
        if ($this->autoPageBreak && $this->y + $space + 20 > $this->pageH - $this->bMargin) {
            $this->AddPage();
        }
        $half = $this->ContentWidth() / 2;
        $y = $this->y + 14;

        $this->SetFont('helvetica', '', 9);
        $this->Text($this->lMargin, $y, $leftTitle, 'C', $half - 10);
        $this->Text($this->lMargin + $half, $y, $rightTitle, 'C', $half - 10);
        $this->Text($this->lMargin + $half, $y + 11, tgl_indo(date('Y-m-d')), 'C', $half - 10);

        $this->Line($this->lMargin + 30, $y + $space, $this->lMargin + $half - 30, $y + $space);
        $this->Line($this->lMargin + $half + 30, $y + $space, $this->pageW - $this->rMargin - 30, $y + $space);

        $this->SetFont('helvetica', 'B', 9.5);
        $this->Text($this->lMargin, $y + $space + 12, $leftName, 'C', $half - 10);
        $this->Text($this->lMargin + $half, $y + $space + 12, $rightName, 'C', $half - 10);

        $this->y = $y + $space + 24;
        $this->x = $this->lMargin;
    }

    /* ======================================================================
     * ENCODING & ESCAPE
     * ====================================================================== */

    /** UTF-8 -> CP1252 (WinAnsiEncoding). */
    protected function toWinAnsi($text)
    {
        $text = (string) $text;
        // Penggantian karakter umum yang tidak ada di CP1252
        $map = [
            "\xE2\x80\x93" => '-',  "\xE2\x80\x94" => '-',  "\xE2\x80\x99" => "'",
            "\xE2\x80\x98" => "'",  "\xE2\x80\x9C" => '"',  "\xE2\x80\x9D" => '"',
            "\xE2\x80\xA2" => '-',  "\xE2\x80\xA6" => '...', "\xC2\xA0"     => ' ',
            "\xE2\x82\xAC" => "\x80", "\xE2\x89\xA5" => '>=', "\xE2\x89\xA4" => '<=',
        ];
        $text = strtr($text, $map);

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            } else {
                $converted = @iconv('UTF-8', 'CP1252//IGNORE', $text);
                if ($converted !== false) {
                    $text = $converted;
                }
            }
        } elseif (function_exists('mb_convert_encoding')) {
            $text = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        } else {
            $text = preg_replace('/[^\x20-\x7E\x80-\xFF]/', '?', $text);
        }

        return $text;
    }

    /** Escape string PDF. */
    protected function escapeText($text)
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $out = '';
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            $ord = ord($c);
            if ($ord === 10) {
                $out .= '\\n';
            } elseif ($ord === 13) {
                $out .= '\\r';
            } elseif ($ord === 9) {
                $out .= '\\t';
            } elseif ($ord < 32 || $ord > 126) {
                $out .= ($ord >= 128 && $ord <= 255) ? $c : sprintf('\\%03o', $ord);
            } else {
                $out .= $c;
            }
        }

        return $out;
    }

    protected function escapeName($text)
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ''], (string) $text);
    }

    /* ======================================================================
     * OUTPUT DOKUMEN
     * ====================================================================== */

    /** Rakit seluruh objek PDF menjadi string biner. */
    public function Build()
    {
        if ($this->pageNo === 0) {
            $this->AddPage();
        }
        $this->buildFooters();

        $objects = [];
        $next = 3;

        // Font
        $fontIds = [];
        foreach ($this->fonts as $key => $f) {
            $fontIds[$key] = $next++;
            $objects[$fontIds[$key]] = "<< /Type /Font /Subtype /Type1 /BaseFont /"
                . $f['name'] . " /Encoding /WinAnsiEncoding >>";
        }

        // Halaman + stream isi
        $kids = [];
        foreach ($this->pages as $content) {
            $pageObj = $next++;
            $contentObj = $next++;
            $kids[] = $pageObj . ' 0 R';

            if ($this->compress) {
                $data = gzcompress($content, 6);
                $objects[$contentObj] = "<< /Length " . strlen($data) . " /Filter /FlateDecode >>\n"
                    . "stream\n" . $data . "\nendstream";
            } else {
                $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\n"
                    . "stream\n" . $content . "\nendstream";
            }

            $resources = "<< /Font << ";
            foreach ($fontIds as $key => $id) {
                $resources .= '/' . $key . ' ' . $id . ' 0 R ';
            }
            $resources .= ">> >>";

            $objects[$pageObj] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                . sprintf('%.2F %.2F', $this->pageW, $this->pageH) . "] /Resources " . $resources
                . " /Contents " . $contentObj . " 0 R >>";
        }

        // Info
        $infoObj = $next++;
        $objects[$infoObj] = "<< /Title (" . $this->escapeText($this->toWinAnsi($this->title)) . ")"
            . " /Author (" . $this->escapeText($this->toWinAnsi($this->author)) . ")"
            . " /Subject (" . $this->escapeText($this->toWinAnsi($this->subject)) . ")"
            . " /Creator (Outing Management System)"
            . " /Producer (SimplePDF pure-PHP)"
            . " /CreationDate (D:" . date('YmdHis') . substr(date('O'), 0, 3) . "'" . substr(date('O'), 3, 2) . "'"
            . ") >>";

        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>";

        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= "xref\n0 " . $size . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $off = isset($offsets[$i]) ? $offsets[$i] : 0;
            $pdf .= sprintf('%010d', $off) . " 00000 n \n";
        }
        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R /Info " . $infoObj . " 0 R >>\n";
        $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }

    /**
     * Keluarkan dokumen.
     *
     * @param string $name nama file
     * @param string $dest I=inline, D=download, F=file, S=return string
     * @return string|void
     */
    public function Output($name = 'laporan.pdf', $dest = 'I')
    {
        $name = str_replace(['"', "'", '\\', '/'], '', (string) $name);
        if (strtolower(substr($name, -4)) !== '.pdf') {
            $name .= '.pdf';
        }
        $pdf = $this->Build();

        if ($dest === 'S') {
            return $pdf;
        }
        if ($dest === 'F') {
            file_put_contents($name, $pdf);
            return null;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Length: ' . strlen($pdf));
            header('Accept-Ranges: none');
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
            if ($dest === 'D') {
                header('Content-Disposition: attachment; filename="' . $name . '"');
            } else {
                header('Content-Disposition: inline; filename="' . $name . '"');
            }
        }
        echo $pdf;
        exit;
    }
}
