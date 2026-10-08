<?php
/**
 * ==========================================================================
 *  OUTING MANAGEMENT SYSTEM — libs/Xlsx.php
 * --------------------------------------------------------------------------
 *  Library Excel **.xlsx MURNI PHP** (tanpa PhpSpreadsheet / Composer).
 *  .xlsx pada dasarnya adalah file ZIP berisi XML, jadi cukup memakai
 *  ekstensi `zip` (ZipArchive) + SimpleXML yang sudah aktif di shared hosting.
 *
 *  Kemampuan:
 *   - Xlsx::write()    : membuat file .xlsx multi-sheet (Sheet DATA + PETUNJUK)
 *   - Xlsx::download() : membuat lalu mengirim ke browser sebagai attachment
 *   - Xlsx::read()     : membaca .xlsx -> array baris per sheet
 *   - Xlsx::sheetNames(): daftar nama sheet
 *
 *  Catatan keamanan: file yang dibaca divalidasi (magic byte ZIP + ukuran),
 *  XML dimuat dengan LIBXML_NONET (tanpa jaringan) untuk menekan risiko XXE.
 * ==========================================================================
 */

defined('APP_STARTED') or exit('Direct access is not allowed.');

class Xlsx
{
    /** Style index yang tersedia (lihat buildStyles()). */
    const S_DEFAULT = 0;
    const S_HEADER  = 1;
    const S_CELL    = 2;
    const S_NOTE    = 3;
    const S_EXAMPLE = 4;
    const S_TITLE   = 5;

    /* ======================================================================
     * PENULISAN (WRITE)
     * ====================================================================== */

    /**
     * Buat file .xlsx.
     *
     * @param array  $sheets  [['name'=>'DATA','rows'=>[[...]],'widths'=>[18,20],'freeze'=>1]]
     * @param string $path    path file tujuan
     * @return bool
     * @throws Exception bila ekstensi zip tidak tersedia / gagal menulis
     */
    public static function write(array $sheets, $path)
    {
        self::ensureZip();

        if (count($sheets) === 0) {
            throw new Exception('Tidak ada sheet untuk ditulis.');
        }
        if (@file_put_contents($path, '') === false) {
            throw new Exception('Folder tujuan tidak dapat ditulis.');
        }
        @unlink($path);

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            throw new Exception('Gagal membuat arsip ZIP.');
        }

        $zip->addFromString('[Content_Types].xml', self::buildContentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', self::buildRootRels());
        $zip->addFromString('xl/workbook.xml', self::buildWorkbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::buildWorkbookRels(count($sheets)));
        $zip->addFromString('xl/styles.xml', self::buildStyles());

        foreach ($sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', self::buildSheet($sheet));
        }

        $zip->close();

        return is_file($path) && filesize($path) > 0;
    }

    /**
     * Buat .xlsx lalu kirim sebagai download.
     *
     * @param string $filename
     * @param array  $sheets
     * @return void
     */
    public static function download($filename, array $sheets)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new Exception('Folder sementara server tidak dapat ditulis.');
        }

        try {
            self::write($sheets, $tmp);
        } catch (Exception $e) {
            @unlink($tmp);
            throw $e;
        }

        $filename = preg_replace('/[^A-Za-z0-9._\-]/', '_', $filename);
        if (strtolower(substr($filename, -5)) !== '.xlsx') {
            $filename .= '.xlsx';
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($tmp));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    /* ---------------- bagian XML ---------------- */

    protected static function ensureZip()
    {
        if (!class_exists('ZipArchive')) {
            throw new Exception('Ekstensi PHP "zip" tidak aktif. Aktifkan extension=zip di php.ini '
                . '(InfinityFree: ekstensi zip sudah aktif secara default).');
        }
    }

    protected static function xmlHeader()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    }

    protected static function buildContentTypes($sheetCount)
    {
        $xml = self::xmlHeader();
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $xml .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $xml .= '</Types>';

        return $xml;
    }

    protected static function buildRootRels()
    {
        return self::xmlHeader()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    protected static function buildWorkbook(array $sheets)
    {
        $xml = self::xmlHeader();
        $xml .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($sheets as $i => $sheet) {
            $name = isset($sheet['name']) ? $sheet['name'] : ('Sheet' . ($i + 1));
            $xml .= '<sheet name="' . self::esc(self::sanitizeSheetName($name)) . '" sheetId="' . ($i + 1)
                . '" r:id="rId' . ($i + 1) . '"/>';
        }
        $xml .= '</sheets></workbook>';

        return $xml;
    }

    protected static function buildWorkbookRels($sheetCount)
    {
        $xml = self::xmlHeader();
        $xml .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        }
        $xml .= '<Relationship Id="rId' . ($sheetCount + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $xml .= '</Relationships>';

        return $xml;
    }

    protected static function buildStyles()
    {
        $xml = self::xmlHeader();
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Fonts: 0 normal, 1 bold putih, 2 abu kecil, 3 bold gelap, 4 biru bold
        $xml .= '<fonts count="5">'
            . '<font><sz val="11"/><color rgb="FF1F2937"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '<font><sz val="10"/><color rgb="FF6B7280"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><color rgb="FF111827"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="12"/><color rgb="FF0D6EFD"/><name val="Calibri"/></font>'
            . '</fonts>';

        // Fills: 0 none, 1 gray125 (wajib), 2 biru header, 3 abu contoh
        $xml .= '<fills count="4">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0D6EFD"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3F6FB"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>';

        $xml .= '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFD0D7E2"/></left>'
            . '<right style="thin"><color rgb="FFD0D7E2"/></right>'
            . '<top style="thin"><color rgb="FFD0D7E2"/></top>'
            . '<bottom style="thin"><color rgb="FFD0D7E2"/></bottom><diagonal/></border>'
            . '</borders>';

        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';

        $xml .= '<cellXfs count="6">'
            // 0 default
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            // 1 header tabel
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            // 2 sel data bergaris
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            // 3 teks petunjuk
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'
            . '<alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
            // 4 baris contoh (abu-abu)
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0" applyFill="1" applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="left" vertical="center" wrapText="1"/></xf>'
            // 5 judul
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>';

        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $xml .= '</styleSheet>';

        return $xml;
    }

    /**
     * Bangun XML satu sheet.
     * Format $sheet:
     *   name    : nama sheet
     *   rows    : array baris; tiap baris array sel; sel bisa string atau
     *             ['v'=>nilai,'s'=>styleId,'n'=>true(angka)]
     *   widths  : lebar kolom (opsional)
     *   freeze  : jumlah baris dibekukan (opsional, default 0)
     */
    protected static function buildSheet(array $sheet)
    {
        $rows = isset($sheet['rows']) ? $sheet['rows'] : [];
        $widths = isset($sheet['widths']) ? $sheet['widths'] : [];
        $freeze = isset($sheet['freeze']) ? (int) $sheet['freeze'] : 0;

        $xml = self::xmlHeader();
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($freeze > 0) {
            $xml .= '<sheetViews><sheetView workbookViewId="0">'
                . '<pane ySplit="' . $freeze . '" topLeftCell="A' . ($freeze + 1) . '" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';
        }

        $xml .= '<sheetFormatPr defaultRowHeight="16"/>';

        if (count($widths) > 0) {
            $xml .= '<cols>';
            foreach ($widths as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $rowNum = 0;
        foreach ($rows as $row) {
            $rowNum++;
            if (!is_array($row)) {
                $row = [$row];
            }
            $height = 0;
            foreach ($row as $cell) {
                if (is_array($cell) && isset($cell['h'])) {
                    $height = max($height, (float) $cell['h']);
                }
            }
            $xml .= '<row r="' . $rowNum . '"' . ($height > 0 ? ' ht="' . $height . '" customHeight="1"' : '') . '>';

            $colNum = 0;
            foreach ($row as $cell) {
                $colNum++;
                $ref = self::columnName($colNum) . $rowNum;

                if ($cell === null || $cell === '') {
                    continue;
                }

                $value = $cell;
                $style = 0;
                $isNumber = false;

                if (is_array($cell)) {
                    $value = isset($cell['v']) ? $cell['v'] : '';
                    $style = isset($cell['s']) ? (int) $cell['s'] : 0;
                    $isNumber = !empty($cell['n']);
                }

                if ($value === null || $value === '') {
                    if ($style > 0) {
                        $xml .= '<c r="' . $ref . '" s="' . $style . '"/>';
                    }
                    continue;
                }

                if ($isNumber || (is_int($value) || is_float($value))) {
                    $num = (float) $value;
                    $xml .= '<c r="' . $ref . '"' . ($style > 0 ? ' s="' . $style . '"' : '') . '><v>' . self::num($num) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '"' . ($style > 0 ? ' s="' . $style . '"' : '') . ' t="inlineStr">'
                        . '<is><t xml:space="preserve">' . self::esc((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        $xml .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>';
        $xml .= '</worksheet>';

        return $xml;
    }

    /** Angka float -> string XML aman. */
    protected static function num($value)
    {
        $value = (float) $value;
        if (abs($value - round($value)) < 0.000001) {
            return (string) (int) round($value);
        }

        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }

    /** 1 -> A, 27 -> AA */
    public static function columnName($index)
    {
        $index = (int) $index;
        $name = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $name = chr(65 + $mod) . $name;
            $index = (int) (($index - $mod) / 26);
        }

        return $name === '' ? 'A' : $name;
    }

    /** "B" -> 2, "AA" -> 27 */
    public static function columnIndex($letters)
    {
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $letters));
        if ($letters === '') {
            return 0;
        }
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - 64);
        }

        return $index;
    }

    protected static function sanitizeSheetName($name)
    {
        $name = str_replace(['[', ']', '*', '?', ':', '/', '\\', "'"], '', (string) $name);
        $name = trim($name);
        if ($name === '') {
            $name = 'Sheet';
        }

        return substr($name, 0, 31);
    }

    protected static function esc($text)
    {
        $text = (string) $text;
        // buang karakter kontrol ilegal untuk XML
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text);

        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /* ======================================================================
     * PEMBACAAN (READ)
     * ====================================================================== */

    /**
     * Validasi file .xlsx (magic byte ZIP + ukuran).
     *
     * @param string $path
     * @param int    $maxSize
     * @return array ['ok'=>bool,'message'=>string]
     */
    public static function validateFile($path, $maxSize = 2097152)
    {
        if (!is_file($path) || !is_readable($path)) {
            return ['ok' => false, 'message' => 'File tidak ditemukan atau tidak dapat dibaca.'];
        }
        $size = filesize($path);
        if ($size <= 0) {
            return ['ok' => false, 'message' => 'File kosong.'];
        }
        if ($size > $maxSize) {
            return ['ok' => false, 'message' => 'Ukuran file maksimal ' . format_bytes($maxSize) . '.'];
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return ['ok' => false, 'message' => 'File tidak dapat dibuka.'];
        }
        $magic = fread($handle, 4);
        fclose($handle);

        if ($magic !== "PK\x03\x04" && $magic !== "PK\x05\x06") {
            return ['ok' => false, 'message' => 'File bukan .xlsx yang valid (bukan arsip ZIP/Office Open XML). '
                . 'Simpan ulang sebagai "Excel Workbook (.xlsx)" lalu coba lagi.'];
        }

        self::ensureZip();

        return ['ok' => true, 'message' => ''];
    }

    /** Nama-nama sheet dalam file. */
    public static function sheetNames($path)
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception('Gagal membuka file Excel.');
        }
        $wb = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        if ($wb === false) {
            throw new Exception('Struktur file Excel tidak dikenali (xl/workbook.xml tidak ada).');
        }

        $xml = self::loadXml($wb);
        $names = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $names[] = (string) $sheet['name'];
        }

        return $names;
    }

    /**
     * Baca seluruh sheet.
     *
     * @param string $path
     * @param int    $maxRows  batas baris per sheet (pengaman memori)
     * @return array ['NAMA SHEET' => [ [baris1...], [baris2...] ], ...]
     */
    public static function read($path, $maxRows = 5000)
    {
        self::ensureZip();

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception('Gagal membuka file Excel. Pastikan file tidak rusak.');
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false) {
            $zip->close();
            throw new Exception('File .xlsx tidak valid: bagian workbook tidak ditemukan.');
        }

        // shared strings (opsional)
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $shared = self::parseSharedStrings($ssXml);
        }

        // peta rId -> target file
        $relMap = [];
        if ($relsXml !== false) {
            $rels = self::loadXml($relsXml);
            foreach ($rels->Relationship as $rel) {
                $relMap[(string) $rel['Id']] = ltrim((string) $rel['Target'], '/');
            }
        }

        $workbook = self::loadXml($workbookXml);
        $result = [];
        $index = 0;
        foreach ($workbook->sheets->sheet as $sheet) {
            $index++;
            $name = (string) $sheet['name'];
            $target = '';

            // ambil r:id (namespace relationships)
            foreach ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') as $rid) {
                $target = isset($relMap[(string) $rid]) ? $relMap[(string) $rid] : '';
            }
            if ($target === '') {
                $target = 'xl/worksheets/sheet' . $index . '.xml';
            } elseif (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
            }

            $sheetXml = $zip->getFromName($target);
            if ($sheetXml === false) {
                $sheetXml = $zip->getFromName('xl/worksheets/sheet' . $index . '.xml');
            }
            $result[$name] = ($sheetXml === false) ? [] : self::parseSheet($sheetXml, $shared, $maxRows);
        }
        $zip->close();

        return $result;
    }

    /**
     * Baca satu sheet (berdasarkan nama, atau sheet pertama jika tidak ditemukan).
     *
     * @return array baris
     */
    public static function readSheet($path, $sheetName = null, $maxRows = 5000)
    {
        $all = self::read($path, $maxRows);
        if (count($all) === 0) {
            return [];
        }
        if ($sheetName !== null) {
            foreach ($all as $name => $rows) {
                if (strtolower(trim($name)) === strtolower(trim($sheetName))) {
                    return $rows;
                }
            }
        }
        $first = array_shift($all);

        return is_array($first) ? $first : [];
    }

    protected static function loadXml($string)
    {
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            $previous = libxml_disable_entity_loader(true);
        } else {
            $previous = false;
        }
        $useErrors = libxml_use_internal_errors(true);

        $xml = simplexml_load_string($string, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);

        libxml_clear_errors();
        libxml_use_internal_errors($useErrors);
        if (PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            libxml_disable_entity_loader($previous);
        }

        if ($xml === false) {
            throw new Exception('Gagal membaca XML di dalam file Excel (file mungkin rusak).');
        }

        return $xml;
    }

    protected static function parseSharedStrings($xmlString)
    {
        $xml = self::loadXml($xmlString);
        $list = [];
        foreach ($xml->si as $si) {
            $text = '';
            foreach ($si->t as $t) {
                $text .= (string) $t;
            }
            foreach ($si->r as $r) {
                foreach ($r->t as $t) {
                    $text .= (string) $t;
                }
            }
            $list[] = self::decodeEntities($text);
        }

        return $list;
    }

    /**
     * Ubah XML sheet menjadi array baris (index kolom 0-based).
     */
    protected static function parseSheet($xmlString, array $shared, $maxRows)
    {
        $xml = self::loadXml($xmlString);
        $rows = [];
        $rowIndex = 0;

        foreach ($xml->sheetData->row as $row) {
            if ($rowIndex >= $maxRows) {
                break;
            }
            $cells = [];
            $autoCol = 0;

            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                if ($ref !== '' && preg_match('/^([A-Za-z]+)(\d+)$/', $ref, $m)) {
                    $col = self::columnIndex($m[1]);
                } else {
                    $col = $autoCol + 1;
                }
                $autoCol = $col;

                $type = (string) $c['t'];
                $value = '';

                if ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($c->is->t as $t) {
                        $parts[] = (string) $t;
                    }
                    $value = implode('', $parts);
                } elseif ($type === 's') {
                    $idx = (int) $c->v;
                    $value = isset($shared[$idx]) ? $shared[$idx] : '';
                } elseif ($type === 'b') {
                    $value = ((string) $c->v === '1') ? 'TRUE' : 'FALSE';
                } elseif ($type === 'e') {
                    $value = '';
                } else {
                    $value = (string) $c->v;
                }

                $cells[$col - 1] = self::decodeEntities(trim((string) $value));
            }

            // rapikan index agar berurutan mulai 0
            if (count($cells) > 0) {
                ksort($cells);
                $max = max(array_keys($cells));
                $filled = [];
                for ($i = 0; $i <= $max; $i++) {
                    $filled[$i] = isset($cells[$i]) ? $cells[$i] : '';
                }
                $cells = $filled;
            }

            $rows[] = $cells;
            $rowIndex++;
        }

        return $rows;
    }

    protected static function decodeEntities($text)
    {
        $text = (string) $text;
        if (function_exists('mb_convert_encoding')) {
            return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }

    /* ======================================================================
     * HELPER IMPORT
     * ====================================================================== */

    /**
     * Normalisasi nama header: "User ID" / "user_id" / "USERID" -> "userid".
     */
    public static function normalizeHeader($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/[^a-z0-9]+/', '', $text);

        return $text;
    }

    /**
     * Petakan baris header ke index kolom berdasarkan daftar alias.
     *
     * @param array $headerRow baris header dari Excel
     * @param array $aliases   ['userid' => ['userid','iduser','username'], ...]
     * @return array ['userid' => 0, 'nama' => 1, ...] atau key tidak ada bila tak ditemukan
     */
    public static function mapHeader(array $headerRow, array $aliases)
    {
        $normalized = [];
        foreach ($headerRow as $i => $h) {
            $normalized[self::normalizeHeader($h)] = $i;
        }

        $map = [];
        foreach ($aliases as $field => $names) {
            foreach ((array) $names as $alias) {
                $key = self::normalizeHeader($alias);
                if (isset($normalized[$key])) {
                    $map[$field] = $normalized[$key];
                    break;
                }
            }
        }

        return $map;
    }

    /** Ambil nilai sel dengan aman. */
    public static function cell(array $row, $index, $default = '')
    {
        if ($index === null || $index === '' || !isset($row[$index])) {
            return $default;
        }
        $value = trim((string) $row[$index]);

        return $value === '' ? $default : $value;
    }

    /** Apakah baris seluruhnya kosong? */
    public static function isEmptyRow(array $row)
    {
        foreach ($row as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }
}
