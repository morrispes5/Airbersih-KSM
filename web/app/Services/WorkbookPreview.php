<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use ZipArchive;

class WorkbookPreview
{
    public function inspect(string $path): array
    {
        $zip = new ZipArchive;
        abort_unless($zip->open($path) === true, 422, 'File bukan workbook XLSX.');
        $xmlBytes = 0;
        $uncompressed = 0;
        $media = 0;
        abort_if($zip->numFiles > 5000, 422, 'Workbook terlalu kompleks.');
        for ($n = 0; $n < $zip->numFiles; $n++) {
            $s = $zip->statIndex($n);
            $uncompressed += $s['size'];
            if (str_starts_with($s['name'], 'xl/media/')) {
                $media++;
            }if (str_ends_with($s['name'], '.xml')) {
                $xmlBytes += $s['size'];
            }
        }
        abort_if($uncompressed > 150 * 1024 * 1024 || $xmlBytes > 32 * 1024 * 1024, 422, 'Ukuran isi workbook melewati batas aman.');
        $xml = $zip->getFromName('xl/workbook.xml');
        abort_unless($xml !== false && ! str_contains($xml, '<!DOCTYPE'), 422, 'Metadata workbook tidak sah.');
        $meta = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        $states = [];
        foreach ($meta->sheets->sheet as $s) {
            $states[(string) $s['name']] = (string) ($s['state'] ?? 'visible');
        }
        $zip->close();
        $reader = new Xlsx;
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $info = $reader->listWorksheetInfo($path);
        $sheets = [];
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= 10;
            }
        });
        foreach ($info as $i) {
            $name = $i['worksheetName'];
            $reader->setLoadSheetsOnly([$name]);
            $book = $reader->load($path);
            $sheet = $book->getSheetByName($name);
            $sample = [];
            $periods = [];
            $headers = [];
            foreach ($sheet->getRowIterator(1, min(10, $i['totalRows'])) as $row) {
                $values = [];
                foreach ($row->getCellIterator() as $cell) {
                    $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                    if (is_scalar($value) && $value !== '') {
                        $text = mb_substr((string) $value, 0, 100);
                        $values[$cell->getColumn()] = $text;
                        if (preg_match('/(?:Januari|Februari|Maret|April|Mei|Juni|Juli|Agustus|September|Oktober|November|Desember)\s+20\d{2}/iu', $text, $m)) {
                            $periods[] = $m[0];
                        }if (preg_match('/nama|alamat|phone|no hp|awal|akhir|status|admin|tagihan|pelanggan/i', $text)) {
                            $headers[$cell->getColumn()] = $text;
                        }
                    }
                }if ($values) {
                    $sample[] = ['row' => $row->getRowIndex(), 'cells' => $values];
                }
            }
            $warnings = [];
            if (str_contains($name, 'Mei') && count(preg_grep('/April/i', $periods))) {
                $warnings[] = 'Nama sheet Mei tetapi label periode April; tentukan periode sebelum impor nyata.';
            }if (str_contains(implode(' ', $headers), 'Anggota')) {
                $warnings[] = 'Daftar anggota organisasi, tidak otomatis menjadi pelanggan.';
            }if ($states[$name] !== 'visible') {
                $warnings[] = 'Sheet tersembunyi.';
            }
            $sheets[] = ['name' => $name, 'state' => $states[$name] ?: 'visible', 'rows' => $i['totalRows'], 'columns' => $i['lastColumnLetter'], 'headers' => $headers, 'period_labels' => array_values(array_unique($periods)), 'warnings' => $warnings, 'sample' => $sample];
            $book->disconnectWorksheets();
            unset($book);
            gc_collect_cycles();
        }

        return ['sheets' => $sheets, 'media_count' => $media, 'mode' => 'preview_only', 'notice' => 'Tidak ada data pelanggan, meter, invoice atau kas yang diposting. Formula memakai cache tersimpan, tidak dihitung ulang.', 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024,1)];
    }
}
