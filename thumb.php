<?php

/**
 * Ảnh thu nhỏ vuông, tạo lần đầu rồi lưu cache tại uploads/thumbs/<kích thước>/.
 * Dùng: /thumb.php?f=<tên file trong uploads>&s=120|300
 * Giảm RAM giải mã ảnh trên Safari iOS (ảnh gốc 2560px ~25MB RAM mỗi ảnh -> thumbnail ~0,3MB).
 */
$name = basename((string)($_GET['f'] ?? ''));
$size = (int)($_GET['s'] ?? 300);
if (!in_array($size, [120, 300], true)) {
    $size = 300;
}
if (!preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i', $name)) {
    http_response_code(400);
    exit;
}
$src = __DIR__ . '/uploads/' . $name;
if (!is_file($src)) {
    http_response_code(404);
    exit;
}

$useWebp = function_exists('imagewebp');
$dir = __DIR__ . '/uploads/thumbs/' . $size;
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    http_response_code(500);
    exit;
}
$dst = $dir . '/' . pathinfo($name, PATHINFO_FILENAME) . '.' . ($useWebp ? 'webp' : 'jpg');

if (!is_file($dst) || filemtime($dst) < filemtime($src)) {
    @ini_set('memory_limit', '512M');
    $info = @getimagesize($src);
    if (!$info) {
        http_response_code(415);
        exit;
    }
    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            $im = @imagecreatefromjpeg($src);
            break;
        case IMAGETYPE_PNG:
            $im = @imagecreatefrompng($src);
            break;
        case IMAGETYPE_WEBP:
            $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
            break;
        default:
            $im = false;
    }
    if (!$im) {
        http_response_code(415);
        exit;
    }
    // Ảnh chụp từ điện thoại: xoay đúng chiều theo EXIF.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($src);
        $o = (int)($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle !== 0) {
            $im = imagerotate($im, $angle, 0);
        }
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $side = min($w, $h); // cắt vuông ở giữa
    $out = imagecreatetruecolor($size, $size);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    imagecopyresampled($out, $im, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
    $tmp = $dst . '.tmp' . getmypid();
    $useWebp ? imagewebp($out, $tmp, 80) : imagejpeg($out, $tmp, 80);
    imagedestroy($im);
    imagedestroy($out);
    if (!is_file($tmp) || !@rename($tmp, $dst)) {
        @unlink($tmp);
        http_response_code(500);
        exit;
    }
}

header('Content-Type: ' . ($useWebp ? 'image/webp' : 'image/jpeg'));
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . (string)filesize($dst));
readfile($dst);
