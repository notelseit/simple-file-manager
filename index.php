<?php
/********************************
 Simple PHP File Manager (patched)
 Original: John Campbell
 License: MIT
********************************/

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

setlocale(LC_ALL, 'en_US.UTF-8');

/* ===================== SECURITY ===================== */

function err(int $code, string $msg): void {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(['error' => ['code' => $code, 'msg' => $msg]]);
	exit;
}

$baseDir = realpath(__DIR__);
$file = $_REQUEST['file'] ?? '.';
$file = str_replace("\0", '', $file);

$tmp = realpath($file);
if ($tmp === false) err(404, 'File or Directory Not Found');
if (strpos($tmp, $baseDir) !== 0) err(403, 'Forbidden');

/* ===================== XSRF ===================== */

if (!isset($_COOKIE['_sfm_xsrf'])) {
	setcookie('_sfm_xsrf', bin2hex(random_bytes(16)), 0, '', '', false, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!isset($_POST['xsrf']) || $_POST['xsrf'] !== ($_COOKIE['_sfm_xsrf'] ?? '')) {
		err(403, 'XSRF Failure');
	}
}

/* ===================== HELPERS ===================== */

function rmrf(string $path): void {
	if (is_dir($path)) {
		foreach (array_diff(scandir($path), ['.', '..']) as $item) {
			rmrf("$path/$item");
		}
		rmdir($path);
	} elseif (is_file($path)) {
		unlink($path);
	}
}

function is_recursively_deleteable(string $dir): bool {
	if (!is_readable($dir) || !is_writable($dir)) return false;
	foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
		$path = "$dir/$item";
		if (is_dir($path) && !is_recursively_deleteable($path)) return false;
	}
	return true;
}

function asBytes(string $val): int {
	$val = trim($val);
	$unit = strtolower(substr($val, -1));
	$num = (int)$val;
	return match ($unit) {
		'g' => $num << 30,
		'm' => $num << 20,
		'k' => $num << 10,
		default => $num,
	};
}

$MAX_UPLOAD_SIZE = min(
	asBytes(ini_get('post_max_size')),
	asBytes(ini_get('upload_max_filesize'))
);

/* ===================== ACTIONS ===================== */

if ($_GET['do'] ?? '' === 'list') {
	if (!is_dir($tmp)) err(412, 'Not a Directory');

	$result = [];
	foreach (array_diff(scandir($tmp), ['.', '..']) as $entry) {
		if ($entry === basename(__FILE__)) continue;
		$p = "$tmp/$entry";
		$stat = stat($p);
		$result[] = [
			'name' => $entry,
			'path' => ltrim(str_replace($baseDir, '', $p), '/'),
			'size' => $stat['size'],
			'mtime' => $stat['mtime'],
			'is_dir' => is_dir($p),
			'is_readable' => is_readable($p),
			'is_writable' => is_writable($p),
			'is_executable' => is_executable($p),
			'is_deleteable' =>
				(!is_dir($p) && is_writable($tmp)) ||
				(is_dir($p) && is_writable($tmp) && is_recursively_deleteable($p))
		];
	}

	header('Content-Type: application/json');
	echo json_encode([
		'success' => true,
		'is_writable' => is_writable($tmp),
		'results' => $result
	]);
	exit;
}

/* DELETE */
if ($_POST['do'] ?? '' === 'delete') {
	rmrf($tmp);
	exit;
}

/* MKDIR */
if ($_POST['do'] ?? '' === 'mkdir') {
	$name = basename($_POST['name'] ?? '');
	if ($name) mkdir("$tmp/$name", 0755);
	exit;
}

/* UPLOAD */
if ($_POST['do'] ?? '' === 'upload') {
	if (!isset($_FILES['file_data']) || $_FILES['file_data']['error'] !== UPLOAD_ERR_OK) {
		err(400, 'Upload failed');
	}
	$dest = "$tmp/" . basename($_FILES['file_data']['name']);
	move_uploaded_file($_FILES['file_data']['tmp_name'], $dest);
	exit;
}

/* DOWNLOAD */
if ($_GET['do'] ?? '' === 'download') {
	if (!is_file($tmp)) err(404, 'File not found');
	$mime = function_exists('mime_content_type')
		? mime_content_type($tmp)
		: 'application/octet-stream';

	header('Content-Type: ' . $mime);
	header('Content-Length: ' . filesize($tmp));
	header('Content-Disposition: attachment; filename="' . basename($tmp) . '"');
	readfile($tmp);
	exit;
}
?>
