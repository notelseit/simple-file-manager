<?php
declare(strict_types=1);

/* ===================== CONFIG ===================== */

error_reporting(E_ALL);
ini_set('display_errors', '0');
setlocale(LC_ALL, 'en_US.UTF-8');

$BASE_DIR = realpath(__DIR__);

/* ===================== HELPERS ===================== */

function json_response(array $data): void {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($data);
	exit;
}

function err(int $code, string $msg): void {
	json_response(['error' => ['code' => $code, 'msg' => $msg]]);
}

function rmrf(string $path): void {
	if (is_dir($path)) {
		foreach (array_diff(scandir($path), ['.', '..']) as $f) {
			rmrf("$path/$f");
		}
		rmdir($path);
	} elseif (is_file($path)) {
		unlink($path);
	}
}

function is_recursively_deleteable(string $dir): bool {
	if (!is_readable($dir) || !is_writable($dir)) return false;
	foreach (array_diff(scandir($dir), ['.', '..']) as $f) {
		$p = "$dir/$f";
		if (is_dir($p) && !is_recursively_deleteable($p)) return false;
	}
	return true;
}

function asBytes(string $v): int {
	$v = trim($v);
	$u = strtolower(substr($v, -1));
	$n = (int)$v;
	return match ($u) {
		'g' => $n << 30,
		'm' => $n << 20,
		'k' => $n << 10,
		default => $n
	};
}

/* ===================== XSRF ===================== */

if (!isset($_COOKIE['_sfm_xsrf'])) {
	setcookie('_sfm_xsrf', bin2hex(random_bytes(16)), 0, '', '', false, true);
}
$XSRF = $_COOKIE['_sfm_xsrf'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (($_POST['xsrf'] ?? '') !== $XSRF) {
		err(403, 'XSRF Failure');
	}
}

/* ===================== PATH HANDLING ===================== */

$file = trim(str_replace("\0", '', $_REQUEST['file'] ?? ''), '/');
$target = realpath($BASE_DIR . '/' . ($file ?: '.'));

if ($target === false || strpos($target, $BASE_DIR) !== 0) {
	err(403, 'Forbidden');
}

/* ===================== LIMITS ===================== */

$MAX_UPLOAD_SIZE = min(
	asBytes(ini_get('post_max_size')),
	asBytes(ini_get('upload_max_filesize'))
);

/* ===================== ACTIONS ===================== */

$do = $_REQUEST['do'] ?? '';

if ($do === 'list') {
	if (!is_dir($target)) err(412, 'Not a directory');

	$out = [];
	foreach (array_diff(scandir($target), ['.', '..']) as $e) {
		if ($e === basename(__FILE__)) continue;
		$p = "$target/$e";
		$s = stat($p);

		$out[] = [
			'name' => $e,
			'path' => ltrim(str_replace($BASE_DIR, '', $p), '/'),
			'size' => $s['size'],
			'mtime' => $s['mtime'],
			'is_dir' => is_dir($p),
			'is_readable' => is_readable($p),
			'is_writable' => is_writable($p),
			'is_executable' => is_executable($p),
			'is_deleteable' =>
				(!is_dir($p) && is_writable($target)) ||
				(is_dir($p) && is_writable($target) && is_recursively_deleteable($p))
		];
	}

	json_response([
		'success' => true,
		'is_writable' => is_writable($target),
		'results' => $out
	]);
}

/* DELETE */
if ($do === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	rmrf($target);
	exit;
}

/* MKDIR */
if ($do === 'mkdir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	$name = basename($_POST['name'] ?? '');
	if ($name) mkdir("$target/$name", 0755);
	exit;
}

/* UPLOAD */
if ($do === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!isset($_FILES['file_data']) || $_FILES['file_data']['error'] !== UPLOAD_ERR_OK) {
		err(400, 'Upload failed');
	}
	move_uploaded_file(
		$_FILES['file_data']['tmp_name'],
		"$target/" . basename($_FILES['file_data']['name'])
	);
	exit;
}

/* DOWNLOAD */
if ($do === 'download') {
	if (!is_file($target)) err(404, 'File not found');
	header('Content-Type: application/octet-stream');
	header('Content-Length: ' . filesize($target));
	header('Content-Disposition: attachment; filename="' . basename($target) . '"');
	readfile($target);
	exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Simple PHP File Manager</title>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
body{font-family:Segoe UI,Arial,sans-serif;font-size:14px;margin:20px}
table{border-collapse:collapse;width:100%}
th,td{padding:8px;border-bottom:1px solid #ddd}
th{cursor:pointer;background:#f3f6fb}
tr:hover{background:#f9fbff}
.is_dir{font-weight:bold}
#top{margin-bottom:15px}
#breadcrumb a{text-decoration:none;color:#0066cc}
#drop{border:2px dashed #bbb;padding:15px;margin-bottom:15px}
</style>
</head>
<body>

<div id="top">
	<form id="mkdir">
		<input type="text" name="name" placeholder="New folder">
		<button>Create</button>
	</form>
	<div id="breadcrumb"></div>
</div>

<div id="drop">
	<input type="file" multiple>
</div>

<table>
<thead>
<tr>
	<th>Name</th>
	<th>Size</th>
	<th>Modified</th>
	<th>Actions</th>
</tr>
</thead>
<tbody id="list"></tbody>
</table>

<script>
const XSRF = document.cookie.match(/_sfm_xsrf=([^;]+)/)?.[1] || '';

function list() {
	const dir = location.hash.slice(1);
	$.get('?do=list&file='+encodeURIComponent(dir), data => {
		const $t = $('#list').empty();
		$('#breadcrumb').html('<a href="#">Home</a> ' + dir);

		data.results.forEach(f => {
			const tr = $('<tr>').toggleClass('is_dir', f.is_dir);
			const link = $('<a>')
				.text(f.name)
				.attr('href', f.is_dir ? '#'+f.path : '?do=download&file='+f.path);

			tr.append($('<td>').append(link));
			tr.append($('<td>').text(f.is_dir ? '--' : f.size));
			tr.append($('<td>').text(new Date(f.mtime*1000).toLocaleString()));

			const del = f.is_deleteable
				? $('<button>Delete</button>').click(() =>
					$.post('', {do:'delete',file:f.path,xsrf:XSRF}, list)
				  )
				: '';

			tr.append($('<td>').append(del));
			$t.append(tr);
		});
	});
}

$(window).on('hashchange', list);
list();

$('#mkdir').on('submit', e => {
	e.preventDefault();
	const name = $('[name=name]').val();
	const dir = location.hash.slice(1);
	if (!name) return;
	$.post('', {do:'mkdir',file:dir,name,xsrf:XSRF}, list);
	$('[name=name]').val('');
});

$('input[type=file]').on('change', function(){
	const dir = location.hash.slice(1);
	[...this.files].forEach(f => {
		const fd = new FormData();
		fd.append('do','upload');
		fd.append('file',dir);
		fd.append('file_data',f);
		fd.append('xsrf',XSRF);
		fetch('', {method:'POST', body:fd}).then(list);
	});
});
</script>

</body>
</html>
