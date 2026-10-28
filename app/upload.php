<?php
declare(strict_types=1);

/** Secure image upload (magic bytes, size cap, random name, re-encode). Shared by admin forms and the rich editor. */
function resize_uploaded_image(string $src, string $dest, array $info, int $max): bool {
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) {
        return false;
    }
    [$w, $h, $type] = $info;
    if ($w <= $max && $h <= $max) {
        return false; // already small: keep the original file untouched
    }
    $img = @imagecreatefromstring((string) file_get_contents($src));
    if (!$img) {
        return false;
    }
    // Re-encoding strips EXIF, so apply phone orientation ourselves
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($src);
        $deg = match ((int) ($ex['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($deg !== 0) {
            $img = imagerotate($img, $deg, 0);
            [$w, $h] = [$h, $w];
        }
    }
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, imagesx($img), imagesy($img));
    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($dst, $dest, 88),
        IMAGETYPE_PNG => imagepng($dst, $dest, 6),
        IMAGETYPE_WEBP => imagewebp($dst, $dest, 88),
        default => imagegif($dst, $dest),
    };
    return (bool) $ok;
}

function save_upload(string $inputName, string &$err): ?string {
    if (empty($_FILES[$inputName]) || ($_FILES[$inputName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$inputName];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $err = match ((int) $f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ছবিটি সার্ভারের সীমার চেয়ে বড়। ছবি ছোট করে আবার দিন (সর্বোচ্চ ১০MB)।',
            UPLOAD_ERR_PARTIAL => 'ছবিটি পুরো আপলোড হয়নি। ইন্টারনেট দেখে আবার চেষ্টা করুন।',
            default => 'ছবি আপলোড ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
        };
        return null;
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        $err = 'ছবি ১০MB-এর বেশি। ছবি ছোট করে আবার দিন।';
        return null;
    }
    $info = @getimagesize($f['tmp_name']);
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($map[$info[2]])) {
        $err = 'শুধু JPG, PNG, WEBP বা GIF ছবি দেওয়া যাবে। iPhone-এর HEIC ছবি হলে JPG করে নিন।';
        return null;
    }
    $dir = WEB_ROOT . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $err = 'uploads ফোল্ডারে লেখার অনুমতি নেই। File Manager-এ uploads ফোল্ডারের Permission 755 করুন।';
        return null;
    }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $map[$info[2]];
    $dest = $dir . '/' . $name;
    $resized = resize_uploaded_image($f['tmp_name'], $dest, $info, 1800);
    if (!$resized && !move_uploaded_file($f['tmp_name'], $dest)) {
        $err = 'ছবি সংরক্ষণ করা যায়নি।';
        return null;
    }
    return '/uploads/' . $name;
}

