<?php

/****************************************************
 Simple PHP File Manager – Hardened Public Fork
 ----------------------------------------------------
 Original Author: John Campbell
 Original License: MIT

 Public Fork & Maintainer:
 Notelseit S.R.L.S.
 https://www.notelseit.com

 Enhancements & Maintenance:
 - PHP 8.x / PHP-FPM compatibility
 - Secure path handling (anti path traversal)
 - Session-based authentication (login/password)
 - XSRF protection
 - Safe upload & download handling
 - Recursive permission & delete checks
 - Improved AJAX stability
 - Minor UI & UX fixes

 Project Type:
 Public open-source fork, intended for
 internal, private or restricted environments.

 Security Notice:
 This software MUST NOT be exposed to the public
 internet without authentication and proper
 access restrictions.

 License:
 MIT License (original and forked work)

 This fork preserves original credits and license,
 while extending functionality and security.
*****************************************************/

declare(strict_types=1);
session_start();

/* ===================== CONFIG ===================== */

error_reporting(E_ALL);
ini_set('display_errors', '0');
setlocale(LC_ALL, 'en_US.UTF-8');

$BASE_DIR = realpath(__DIR__);

/* 🔐 PASSWORD HASH (OBBLIGATORIO) */
$PASSWORD_HASH = 'INSERISCI_HASH_GENERATO';

/* ===================== AUTH ===================== */

if (isset($_GET['logout'])) {
	session_destroy();
	header('Location: ?');
	exit;
}

if (!isset($_SESSION['sfm_logged'])) {

	$error = '';

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		if (!isset($_SESSION['fail'])) $_SESSION['fail'] = 0;

		if ($_SESSION['fail'] >= 5) {
			$error = 'Troppi tentativi. Riprova più tardi.';
		} elseif (password_verify($_POST['password'] ?? '', $PASSWORD_HASH)) {
			$_SESSION['sfm_logged'] = true;
			$_SESSION['fail'] = 0;
			header('Location: ?');
			exit;
		} else {
			$_SESSION['fail']++;
			$error = 'Password errata';
		}
	}

	?>
	<!doctype html>
	<html lang="it">
	<head>
	<meta charset="utf-8">
	<title>Login – File Manager</title>
	<style>
	body{font-family:Segoe UI,Arial;background:#f4f6fb;display:flex;justify-content:center;align-items:center;height:100vh}
	form{background:#fff;padding:30px;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.1);width:300px}
	input,button{width:100%;padding:10px;margin-top:10px}
	button{background:#193C6D;color:#fff;border:0;border-radius:4px}
	.error{color:#c00;font-size:13px;margin-top:10px}
	</style>
	</head>
	<body>
	<form method="post">
		<h3>Accesso File Manager</h3>
		<input type="password" name="password" placeholder="Password" required>
		<button>Accedi</button>
		<?php if($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?>
	</form>
	</body>
	</html>
	<?php
	exit;
}

/* ===================== HELPERS ===================== */

function json_response(array $data): void {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($data);
	exit;
}
function err(int $code, string $msg): void {
	json_response(['error' => ['code'=>$code,'msg'=>$msg]]);
}
function rmrf(string $p): void {
	if (is_dir($p)) {
		foreach (array_diff(scandir($p),['.','..']) as $f) rmrf("$p/$f");
		rmdir($p);
	} elseif (is_file($p)) unlink($p);
}
function is_recursively_deleteable(string $d): bool {
	if (!is_readable($d)||!is_writable($d)) return false;
	foreach (array_diff(scandir($d),['.','..']) as $f) {
		$p="$d/$f";
		if (is_dir($p)&&!is_recursively_deleteable($p)) return false;
	}
	return true;
}
function is_deleteable(string $p): bool {
	if (is_dir($p)) {
		return is_writable(dirname($p)) && is_recursively_deleteable($p);
	}
	if (is_file($p)) {
		return is_writable(dirname($p));
	}
	return false;
}
function require_target_dir(string $p): void {
	if (!is_dir($p)) {
		err(412, 'Not a directory');
	}
}
function safe_basename(string $name): string {
	$name = basename($name);
	return trim($name);
}

/* ===================== XSRF ===================== */

if (!isset($_COOKIE['_sfm_xsrf'])) {
	$secure_cookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
	setcookie('_sfm_xsrf', bin2hex(random_bytes(16)), [
		'expires' => 0,
		'path' => '',
		'secure' => $secure_cookie,
		'httponly' => true,
		'samesite' => 'Lax',
	]);
}
$XSRF = $_COOKIE['_sfm_xsrf'] ?? '';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['xsrf']??'')!==$XSRF) {
	err(403,'XSRF Failure');
}

/* ===================== PATH ===================== */

$file = trim(str_replace("\0",'',$_REQUEST['file']??''),'/');
$target = realpath($BASE_DIR.'/'.($file?:'.'));
if ($target===false || strpos($target,$BASE_DIR)!==0) err(403,'Forbidden');

/* ===================== ACTIONS ===================== */

$do = $_REQUEST['do'] ?? '';

if ($do==='list') {
	require_target_dir($target);
	$r=[];
	foreach(array_diff(scandir($target),['.','..']) as $e){
		if($e===basename(__FILE__)) continue;
		$p="$target/$e"; $s=stat($p);
		$r[]=[
			'name'=>$e,
			'path'=>ltrim(str_replace($BASE_DIR,'',$p),'/'),
			'size'=>$s['size'],
			'mtime'=>$s['mtime'],
			'is_dir'=>is_dir($p),
			'is_deleteable'=>is_deleteable($p)
		];
	}
	json_response(['success'=>true,'results'=>$r]);
}

if ($do==='delete') {
	if (!is_deleteable($target)) err(403, 'Forbidden');
	rmrf($target);
	exit;
}
if ($do==='mkdir') {
	require_target_dir($target);
	if (!is_writable($target)) err(403, 'Forbidden');
	$dir = safe_basename($_POST['name'] ?? '');
	if ($dir === '') err(422, 'Invalid name');
	if (!mkdir("$target/$dir", 0755)) err(500, 'Unable to create directory');
	exit;
}
if ($do==='upload') {
	require_target_dir($target);
	if (!is_writable($target)) err(403, 'Forbidden');
	if (!isset($_FILES['file_data'])) err(422, 'Missing file');
	if (!is_uploaded_file($_FILES['file_data']['tmp_name'])) err(400, 'Invalid upload');
	$name = safe_basename($_FILES['file_data']['name'] ?? '');
	if ($name === '') err(422, 'Invalid name');
	if (!move_uploaded_file($_FILES['file_data']['tmp_name'], "$target/$name")) {
		err(500, 'Upload failed');
	}
	exit;
}
if ($do==='download') {
	if (!is_file($target) || !is_readable($target)) err(404, 'Not found');
	header('Content-Type: application/octet-stream');
	header('Content-Length: '.(string)filesize($target));
	header('Content-Disposition: attachment; filename="'.basename($target).'"');
	readfile($target); exit;
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>File Manager</title>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<style>
body{font-family:Segoe UI,Arial;margin:20px}
table{width:100%;border-collapse:collapse}
th,td{padding:8px;border-bottom:1px solid #ddd}
.is_dir{font-weight:bold}
.logout{float:right}
</style>
</head>
<body>

<a class="logout" href="?logout=1">Logout</a>
<h2>File Manager</h2>

<input type="file" multiple>

<table>
<thead><tr><th>Nome</th><th>Azioni</th></tr></thead>
<tbody id="list"></tbody>
</table>

<script>
const XSRF = document.cookie.match(/_sfm_xsrf=([^;]+)/)?.[1]||'';
function list(){
	$.get('?do=list&file='+location.hash.slice(1),d=>{
		const t=$('#list').empty();
		d.results.forEach(f=>{
			const tr=$('<tr>').toggleClass('is_dir',f.is_dir);
			const a=$('<a>').text(f.name)
				.attr('href',f.is_dir?'#'+f.path:'?do=download&file='+f.path);
			tr.append($('<td>').append(a));
			const del=f.is_deleteable?$('<button>Del</button>').click(()=>
				$.post('',{do:'delete',file:f.path,xsrf:XSRF},list)
			):'';
			tr.append($('<td>').append(del));
			t.append(tr);
		});
	});
}
$('input[type=file]').on('change',function(){
	[...this.files].forEach(f=>{
		const fd=new FormData();
		fd.append('do','upload');
		fd.append('file_data',f);
		fd.append('xsrf',XSRF);
		fetch('',{method:'POST',body:fd}).then(list);
	});
});
$(window).on('hashchange',list);
list();
</script>

</body>
</html>
