<?php

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require dirname(__DIR__).'/vendor/autoload.php';
$folder = dirname(__DIR__, 2).'/private/audit';
if (! is_dir($folder)) {
    mkdir($folder, 0700, true);
}
$image = imagecreatetruecolor(400, 220);
$background = imagecolorallocate($image, 230, 241, 244);
$ink = imagecolorallocate($image, 22, 50, 79);
imagefill($image, 0, 0, $background);
imagestring($image, 5, 70, 80, 'FOTO METER DUMMY', $ink);
imagestring($image, 5, 130, 120, '277.000 m3', $ink);
imagepng($image, $folder.'/dummy-meter.png');
imagedestroy($image);
$book = new Spreadsheet;
$sheet = $book->getActiveSheet();
$sheet->setTitle('Pelanggan Dummy');
$sheet->fromArray([['No', null, 'Nama pelanggan', 'Alamat', null, null, null, 'Phone', null, 'Status'], [1, null, 'Pelanggan Dummy A', 'Alamat simulasi A', null, null, null, '00001234', null, 'Aktif'], [2, null, 'Pelanggan Dummy B', 'Alamat simulasi B', null, null, null, '00005678', null, 'Aktif']]);
(new Xlsx($book))->save($folder.'/preview-dummy.xlsx');
echo "Synthetic fixtures ready.\n";
