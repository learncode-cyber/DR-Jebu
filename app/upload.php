<?php
declare(strict_types=1);

/** Secure image upload (magic bytes, size cap, random name, re-encode). Shared by admin forms and the rich editor. */

/** Responsive WebP renditions made for every uploaded image (px width; aspect ratio kept, never upscaled). */
const IMG_VARIANT_WIDTHS = [400, 800, 1600];
const IMG_VARIANT_QUALITY = 85;

/** ASCII file-name slug from a free text (Bangla or junk becomes 'image'). Used in stored file names. */
function img_slug(string $text): string {
    $t = strtolower(trim((string) preg_replace('/\.[A-Za-z0-9]+$/', '', $text)));
    $t = trim((string) preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    return substr($t, 0, 60) !== '' ? substr($t, 0, 60) : 'image';
}

/**
 * Writes the WebP renditions ($baseNoExt-400.webp, -800, -1600) of an image file at 85% quality.
 * Phone orientation is applied, EXIF is dropped (re-encoding), GD memory is freed after each size.
 * Returns false when GD/WebP is missing or the file is not a supported image.
 */
function make_webp_variants(string $srcFile, string $baseNoExt): bool {
    if (!function_exists('imagewebp') || !is_file($srcFile)) {
        return false;
    }
    $info = @getimagesize($srcFile);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        return false;
    }
    $img = @imagecreatefromstring((string) file_get_contents($srcFile));
    if (!$img) {
        return false;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($srcFile);
        $deg = match ((int) ($ex['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($deg !== 0 && ($rot = imagerotate($img, $deg, 0))) {
            $img = $rot;
        }
    }
    $ok = true;
    foreach (IMG_VARIANT_WIDTHS as $w) {
        $scaled = rich_scale_image($img, $w);
        $ok = imagewebp($scaled, $baseNoExt . '-' . $w . '.webp', IMG_VARIANT_QUALITY) && $ok;
        unset($scaled);
    }
    return $ok;
}

const IMG_MAX_BYTES = 20 * 1024 * 1024;   // file size accepted from the admin (hosting limit must be at least this, see .htaccess)
const IMG_MAX_PIXELS = 40000000;          // 40 megapixels: larger images are refused before decoding
const IMG_MASTER_MAX = 1800;              // the stored master copy is at most 1800 px wide/high (aspect kept)

/** Image type from the file's first bytes (name and browser type are ignored). Null when unknown. */
function img_detect_type(string $file): ?string {
    $h = (string) @file_get_contents($file, false, null, 0, 16);
    if (strlen($h) < 12) {
        return null;
    }
    if (str_starts_with($h, "\xFF\xD8\xFF")) return 'jpeg';
    if (str_starts_with($h, "\x89PNG\r\n\x1a\n")) return 'png';
    if (str_starts_with($h, 'GIF87a') || str_starts_with($h, 'GIF89a')) return 'gif';
    if (str_starts_with($h, 'RIFF') && substr($h, 8, 4) === 'WEBP') return 'webp';
    if (str_starts_with($h, 'BM')) return 'bmp';
    if (str_starts_with($h, "II*\0") || str_starts_with($h, "MM\0*")) return 'tiff';
    $brand = substr($h, 4, 8);
    if (in_array($brand, ['ftypheic', 'ftypheix', 'ftyphevc', 'ftypheim', 'ftypheis', 'ftypmif1', 'ftypmsf1'], true)) return 'heic';
    if (in_array($brand, ['ftypavif', 'ftypavis'], true)) return 'avif';
    return null;
}

/**
 * Decodes any supported image to a GD canvas. GD handles JPEG, PNG, GIF, WebP and BMP; TIFF, HEIC and AVIF
 * need the Imagick extension (when the host has it). Phone orientation is applied to JPEG.
 */
function img_decode(string $file, string $type): ?GdImage {
    $gd = ['jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'gif' => 'imagecreatefromgif', 'webp' => 'imagecreatefromwebp', 'bmp' => 'imagecreatefrombmp'];
    if (isset($gd[$type]) && function_exists($gd[$type])) {
        $img = @($gd[$type])($file);
        if ($img instanceof GdImage) {
            if ($type === 'jpeg' && function_exists('exif_read_data')) {
                $ex = @exif_read_data($file);
                $deg = match ((int) ($ex['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
                if ($deg !== 0 && ($rot = imagerotate($img, $deg, 0)) instanceof GdImage) {
                    $img = $rot;
                }
            }
            return $img;
        }
    }
    if (!class_exists('Imagick')) {
        return null;
    }
    try {
        $im = new Imagick();
        $im->readImage($file . '[0]'); // first page or frame
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient();
        }
        $im->setImageFormat('png');
        $img = @imagecreatefromstring((string) $im->getImageBlob());
        $im->clear();
        return $img instanceof GdImage ? $img : null;
    } catch (Throwable $e) {
        error_log('[jebu] imagick decode: ' . $e->getMessage());
        return null;
    }
}

/** Writes the master WebP (<= 1800 px) and the 400/800/1600 px renditions at 85% quality. */
function img_store_webp(GdImage $img, string $dir, string $base): bool {
    $master = rich_scale_image($img, IMG_MASTER_MAX);
    $ok = imagewebp($master, $dir . '/' . $base . '.webp', IMG_VARIANT_QUALITY);
    unset($master);
    foreach (IMG_VARIANT_WIDTHS as $w) {
        $s = rich_scale_image($img, $w);
        $ok = imagewebp($s, $dir . '/' . $base . '-' . $w . '.webp', IMG_VARIANT_QUALITY) && $ok;
        unset($s);
    }
    return $ok;
}

/** Alt text table: one row per stored image (path => alt text). Created on first use. */
function img_meta_table(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    q_exec("CREATE TABLE IF NOT EXISTS image_meta (
        path VARCHAR(190) NOT NULL PRIMARY KEY,
        alt_text VARCHAR(255) NOT NULL DEFAULT '',
        source VARCHAR(10) NOT NULL DEFAULT 'auto',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4");
}

/** Saves the alt text of an image. 'auto' = generated from the title, 'manual' = typed by the admin. */
function img_meta_save(string $url, string $alt, string $source): void {
    img_meta_table();
    $alt = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $alt)), 0, 200);
    q_exec('INSERT INTO image_meta (path, alt_text, source) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE alt_text = VALUES(alt_text), source = VALUES(source)', [$url, $alt, $source]);
}

/** Stored alt text of an image URL ('' when none). Cached per request. */
/**
 * Size and alt text for the og:image tags. Size is read from the real file (local uploads and assets only).
 * Alt falls back to the page title when the image has no saved alt text.
 * @return array{w:int,h:int,alt:string}
 */
function og_image_meta(string $absUrl, string $pageTitle): array {
    $path = (string) parse_url($absUrl, PHP_URL_PATH);
    $w = 0; $h = 0;
    $file = WEB_ROOT . strtok($path, '?');
    if ($path !== '' && is_file($file)) {
        $dim = @getimagesize($file);
        if ($dim) { [$w, $h] = [(int) $dim[0], (int) $dim[1]]; }
    }
    $alt = $path !== '' ? img_alt_for($path) : '';
    return ['w' => $w, 'h' => $h, 'alt' => $alt !== '' ? $alt : $pageTitle];
}

function img_alt_for(string $url): string {
    static $cache = [];
    $key = strtok($url, '?') ?: '';
    if (!array_key_exists($key, $cache)) {
        try {
            img_meta_table();
            $cache[$key] = (string) q_val('SELECT alt_text FROM image_meta WHERE path = ?', [$key]);
        } catch (Throwable $e) {
            $cache[$key] = '';
        }
    }
    return $cache[$key];
}

/**
 * Admin upload of any image: type checked from its bytes, decoded, oriented, scaled and stored as WebP
 * (master + 400/800/1600 renditions) under a slug + random name. The alt text is the admin's text when
 * given, otherwise it is generated from the title. Returns the master URL or null (with $err set).
 */
function save_upload(string $inputName, string &$err, string $alt = '', string $title = ''): ?string {
    if (empty($_FILES[$inputName]) || ($_FILES[$inputName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $f = $_FILES[$inputName];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $err = match ((int) $f['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'ছবিটি সার্ভারের সীমার চেয়ে বড়। ছবি ছোট করে আবার দিন (সর্বোচ্চ ২০MB)।',
            UPLOAD_ERR_PARTIAL => 'ছবিটি পুরো আপলোড হয়নি। ইন্টারনেট দেখে আবার চেষ্টা করুন।',
            default => 'ছবি আপলোড ব্যর্থ হয়েছে। আবার চেষ্টা করুন।',
        };
        return null;
    }
    if ((int) $f['size'] > IMG_MAX_BYTES) {
        $err = 'ছবি ২০MB-এর বেশি। ছবি ছোট করে আবার দিন।';
        return null;
    }
    $type = img_detect_type((string) $f['tmp_name']);
    if ($type === null) {
        $err = 'এই ফাইলটি ছবি নয় বা সমর্থিত নয়। JPG, PNG, WEBP, GIF, BMP ছবি দিন।';
        return null;
    }
    if (in_array($type, ['tiff', 'heic', 'avif'], true) && !class_exists('Imagick')) {
        $err = 'এই সার্ভারে ' . strtoupper($type) . ' ছবি প্রক্রিয়া করা যায় না। ফোনে ছবিটি JPG হিসেবে সেভ করে আবার দিন।';
        return null;
    }
    @ini_set('memory_limit', '512M');
    $dir = WEB_ROOT . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $err = 'uploads ফোল্ডারে লেখার অনুমতি নেই। File Manager-এ uploads ফোল্ডারের Permission 755 করুন।';
        return null;
    }
    if (in_array($type, ['jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
        $dim = @getimagesize((string) $f['tmp_name']);
        if (!$dim || (int) $dim[0] * (int) $dim[1] > IMG_MAX_PIXELS) {
            $err = 'ছবির মাপ অনেক বড়। ছবি ছোট করে আবার দিন।';
            return null;
        }
    }
    try {
        $img = img_decode((string) $f['tmp_name'], $type);
        if (!$img) {
            $err = 'ছবিটি পড়া যায়নি। অন্য ছবি দিয়ে চেষ্টা করুন।';
            return null;
        }
        if (imagesx($img) * imagesy($img) > IMG_MAX_PIXELS) {
            $err = 'ছবির মাপ অনেক বড়। ছবি ছোট করে আবার দিন।';
            return null;
        }
        // Slug from the title (or the file name) + 16 random hex chars: unique and unguessable, no traversal
        $base = img_slug($title !== '' ? $title : (string) $f['name']) . '-' . bin2hex(random_bytes(8));
        if (!img_store_webp($img, $dir, $base)) {
            $err = 'ছবি সংরক্ষণ করা যায়নি।';
            return null;
        }
    } catch (Throwable $e) {
        error_log('[jebu] upload: ' . $e->getMessage());
        $err = 'ছবিটি প্রক্রিয়া করা যায়নি। অন্য ছবি দিয়ে চেষ্টা করুন।';
        return null;
    }
    $url = '/uploads/' . $base . '.webp';
    $alt = trim($alt);
    img_meta_save($url, $alt !== '' ? $alt : $title, $alt !== '' ? 'manual' : 'auto');
    return $url;
}


/** Files in the uploads folder: name => ['size' => bytes, 'mtime' => unix time, 'path' => absolute path]. */
function upload_files(): array {
    $dir = WEB_ROOT . '/uploads';
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (scandir($dir) ?: [] as $name) {
        $path = $dir . '/' . $name;
        if ($name === '.' || $name === '..' || !is_file($path) || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
            continue;
        }
        if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $name) !== 1) {
            continue;
        }
        if (preg_match('/-(400|800|1600)\.webp$/', $name) === 1) {
            continue; // generated responsive copy, not a separate upload
        }
        $out[$name] = ['size' => (int) filesize($path), 'mtime' => (int) filemtime($path), 'path' => $path];
    }
    return $out;
}

const RICH_IMAGE_MAX_BYTES = 4 * 1024 * 1024;

/**
 * Image for the rich editor: magic-byte check, decoded and re-encoded (EXIF and any embedded payload are dropped),
 * then saved as WebP at 1600 px and 800 px under a random name. Returns null and sets $err on failure.
 * @return array{url:string, small:string, width:int, height:int}|null
 */
function rich_store_image(string $tmp, string &$err): ?array {
    if (!is_file($tmp) || (int) @filesize($tmp) <= 0 || (int) @filesize($tmp) > RICH_IMAGE_MAX_BYTES) {
        $err = 'ছবি ৪MB-এর বেশি হলে আপলোড হবে না। ছবি ছোট করে আবার দিন।';
        return null;
    }
    if (!function_exists('imagewebp')) {
        $err = 'সার্ভারে WebP সাপোর্ট নেই। হোস্টিংয়ে GD (WebP) চালু করুন।';
        return null;
    }
    $info = @getimagesize($tmp); // reads the file signature, not the name or the browser's type
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        $err = 'শুধু JPG, PNG, WEBP বা GIF ছবি দেওয়া যাবে।';
        return null;
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) {
        $err = 'ছবির মাপ অনেক বড়। ছবি ছোট করে আবার দিন।';
        return null;
    }
    $img = @imagecreatefromstring((string) file_get_contents($tmp));
    if (!$img) {
        $err = 'ছবিটি পড়া যায়নি। অন্য ছবি দিয়ে চেষ্টা করুন।';
        return null;
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($tmp);
        $deg = match ((int) ($ex['Orientation'] ?? 1)) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
        if ($deg !== 0) {
            $rot = imagerotate($img, $deg, 0);
            if ($rot) {
                $img = $rot;
            }
        }
    }
    $dir = WEB_ROOT . '/uploads';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $err = 'uploads ফোল্ডারে লেখার অনুমতি নেই। File Manager-এ uploads ফোল্ডারের Permission 755 করুন।';
        return null;
    }
    $base = bin2hex(random_bytes(16));
    $full = rich_scale_image($img, 1600);
    $small = rich_scale_image($img, 800);
    if (!imagewebp($full, $dir . '/' . $base . '-1600.webp', IMG_VARIANT_QUALITY) || !imagewebp($small, $dir . '/' . $base . '-800.webp', IMG_VARIANT_QUALITY)) {
        $err = 'ছবি সংরক্ষণ করা যায়নি।';
        return null;
    }
    return ['url' => '/uploads/' . $base . '-1600.webp', 'small' => '/uploads/' . $base . '-800.webp', 'width' => imagesx($full), 'height' => imagesy($full)];
}

/** Scales down to at most $maxW wide (never up), keeping transparency. */
function rich_scale_image(GdImage $img, int $maxW): GdImage {
    $w = imagesx($img);
    $h = imagesy($img);
    $nw = min($maxW, $w);
    $nh = max(1, (int) round($h * $nw / $w));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}
