<?php
/* Copyright (C) 2026		John BOTELLA				<john.botella@thersane.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/docstudio/wopi.php
 * \ingroup docstudio
 * \brief   WOPI host: called by the office server (server to server, no session of Dolibarr).
 *
 *   GET  wopi.php/files/{id}?access_token=...            CheckFileInfo
 *   GET  wopi.php/files/{id}/contents?access_token=...   GetFile
 *   POST wopi.php/files/{id}/contents?access_token=...   PutFile (mode edit, lock of the editor)
 *   POST wopi.php/files/{id}?access_token=...            X-WOPI-Override: LOCK, GET_LOCK, REFRESH_LOCK, UNLOCK
 *
 * Every call: the signed token gives the user, the entity, the file and the mode; the user must still be active and
 * the file is loaded again by its provider with the rights of this user (same checks as the pages of DocStudio).
 * Saving: write permission checked again, ZIP package of the type of the file, size, antivirus, hook docstudioBeforeSave.
 * Caller: address in DOCSTUDIO_WOPI_ALLOWED_IPS when set; signature of the office server (WOPI proof keys) when its
 * discovery gives a public key (DOCSTUDIO_WOPI_PROOF_OFF, hidden: only logged, for a troubleshooting).
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
// Entity of the file (multicompany), checked against the token below. Before main.inc.php: GETPOST is not there yet
$entity = (!empty($_GET['entity']) ? (int) $_GET['entity'] : 1);
if ($entity > 0) {
	define('DOLENTITY', $entity);
}

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/docstudio/class/docstudio.class.php');
dol_include_once('/docstudio/class/docstudiowopi.class.php');
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

/**
 * Answer with a HTTP code (and JSON) and stop
 *
 * @param int                       $code
 * @param array<string,mixed>|null  $json
 * @param array<string,string>      $headers
 * @return never
 */
function docstudioWopiAnswer($code, $json = null, array $headers = array())
{
	global $db;

	http_response_code($code);
	foreach ($headers as $name => $value) {
		header($name.': '.str_replace(array("\r", "\n"), '', $value));
	}
	header('Cache-Control: no-store');
	if ($json !== null) {
		header('Content-Type: application/json');
		print json_encode($json);
	}
	$db->close();
	exit;
}

/**
 * Time of the file in the format of WOPI (ISO 8601, UTC)
 *
 * @param int $time
 * @return string
 */
function docstudioWopiTime($time)
{
	return gmdate('Y-m-d\TH:i:s', $time).'.000Z';
}

if (!isModEnabled('docstudio') || !DocStudioWopi::isEnabled()) {
	docstudioWopiAnswer(404);
}

// Route: /files/{id} or /files/{id}/contents
$path = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
if (!preg_match('#^/files/([a-f0-9]{32})(/contents)?$#', $path, $m)) {
	docstudioWopiAnswer(404);
}
$fileId = $m[1];
$contents = !empty($m[2]);
$method = $_SERVER['REQUEST_METHOD'];

// Address of the caller (the office server), without trusting the headers of proxies
$remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
if (!DocStudioWopi::isAllowedIp($remote)) {
	dol_syslog('docstudio/wopi.php refused: address '.$remote.' not allowed', LOG_WARNING);
	docstudioWopiAnswer(403);
}

$token = isset($_GET['access_token']) ? (string) $_GET['access_token'] : '';

// Signature of the office server: the call comes from it, not from someone holding a token
$wopiTools = new DocStudioWopi();
$proofKeys = $wopiTools->getProofKeys();
if (count($proofKeys)) {
	$host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
	$uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
	$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https');
	$urls = array(($https ? 'https' : 'http').'://'.$host.$uri);
	// Behind a proxy, the address given in the setup (the one the office server calls)
	$hostUrl = DocStudioWopi::getHostUrl();
	if (preg_match('#^(https?://[^/]+)#i', $hostUrl, $mh) && strpos($uri, '/wopi.php') !== false) {
		$urls[] = $hostUrl.substr($uri, strpos($uri, '/wopi.php') + strlen('/wopi.php'));
		$urls[] = $mh[1].$uri;
	}
	$valid = DocStudioWopi::checkProof(
		$proofKeys,
		$token,
		array_unique($urls),
		isset($_SERVER['HTTP_X_WOPI_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_WOPI_TIMESTAMP'] : '',
		isset($_SERVER['HTTP_X_WOPI_PROOF']) ? (string) $_SERVER['HTTP_X_WOPI_PROOF'] : '',
		isset($_SERVER['HTTP_X_WOPI_PROOFOLD']) ? (string) $_SERVER['HTTP_X_WOPI_PROOFOLD'] : ''
	);
	if (!$valid) {
		dol_syslog('docstudio/wopi.php call without a valid signature of the office server from '.$remote, LOG_WARNING);
		if (!getDolGlobalInt('DOCSTUDIO_WOPI_PROOF_OFF')) {
			docstudioWopiAnswer(401);
		}
	}
}

$data = DocStudioWopi::checkToken($token, $fileId);
if ($data === null || (int) $data['e'] !== (int) $conf->entity) {
	docstudioWopiAnswer(401);
}

// User of the token, still active, with his rights of now
$wuser = new User($db);
if ($wuser->fetch((int) $data['u']) <= 0 || (int) $wuser->statut !== 1) {
	docstudioWopiAnswer(401);
}
if (method_exists($wuser, 'loadRights')) {
	$wuser->loadRights();
} else {
	$wuser->getrights();
}
$user = $wuser;
$langs->setDefaultLang(!empty($wuser->conf->MAIN_LANG_DEFAULT) ? $wuser->conf->MAIN_LANG_DEFAULT : $langs->defaultlang);
$langs->load('docstudio@docstudio');

$registry = new DocStudio($db);
$provider = $registry->getProvider((string) $data['s']);
if ($provider === null || $provider->load($data['p'], $wuser) <= 0 || !$provider->runDownloadHooks($wuser)) {
	docstudioWopiAnswer(404);
}
// Same file as the one the token was made for
if (DocStudioWopi::fileId((string) $data['s'], $provider->getParams(), (int) $conf->entity) !== $fileId) {
	docstudioWopiAnswer(404);
}
$extension = $provider->getExtension();
$canWrite = $data['m'] === 'edit' && in_array($extension, DocStudioWopi::WRITABLE_EXTENSIONS, true) && $provider->canWrite($wuser);
$modified = $provider->getModifiedTime();
$version = (string) $modified.'-'.$provider->getSize();


/*
 * CheckFileInfo
 */

if (!$contents && $method === 'GET') {
	docstudioWopiAnswer(200, array(
		'BaseFileName' => $provider->getFileName(),
		'Size' => $provider->getSize(),
		'Version' => $version,
		'LastModifiedTime' => docstudioWopiTime($modified),
		'OwnerId' => 'dolibarr-'.((int) $conf->entity),
		'UserId' => (string) $wuser->id,
		'UserFriendlyName' => $wuser->getFullName($langs),
		'UserCanWrite' => $canWrite,
		'ReadOnly' => !$canWrite,
		'UserCanNotWriteRelative' => true,
		'SupportsUpdate' => $canWrite,
		'SupportsLocks' => true,
		'SupportsGetLock' => true,
		'SupportsRename' => false,
		'UserCanRename' => false,
		// Messages of the frame only to the page of Dolibarr that opened it
		'PostMessageOrigin' => preg_replace('#^(https?://[^/]+).*$#i', '$1', dol_buildpath('/', 2)),
		'DisableExport' => false,
		'DisablePrint' => false,
		'HideSaveOption' => !$canWrite,
		'EnableInsertRemoteImage' => false,
		'EnableShare' => false,
	));
}


/*
 * GetFile
 */

if ($contents && $method === 'GET') {
	http_response_code(200);
	header('Content-Type: application/octet-stream');
	header('Content-Length: '.$provider->getSize());
	header('X-WOPI-ItemVersion: '.$version);
	header('Cache-Control: no-store');
	$provider->outputContent();
	$db->close();
	exit;
}

if ($method !== 'POST') {
	docstudioWopiAnswer(405);
}


/*
 * Locks
 */

$override = isset($_SERVER['HTTP_X_WOPI_OVERRIDE']) ? strtoupper((string) $_SERVER['HTTP_X_WOPI_OVERRIDE']) : '';
$lock = isset($_SERVER['HTTP_X_WOPI_LOCK']) ? substr((string) $_SERVER['HTTP_X_WOPI_LOCK'], 0, 1024) : '';
$current = DocStudioWopi::getLock($fileId);

if (!$contents) {
	if ($override === 'GET_LOCK') {
		docstudioWopiAnswer(200, null, array('X-WOPI-Lock' => $current));
	}
	// Only an editor locks: a token for reading must not block the others
	if (!$canWrite && in_array($override, array('LOCK', 'REFRESH_LOCK', 'UNLOCK'), true)) {
		docstudioWopiAnswer(401);
	}
	if ($override === 'LOCK' || $override === 'REFRESH_LOCK') {
		$old = isset($_SERVER['HTTP_X_WOPI_OLDLOCK']) ? (string) $_SERVER['HTTP_X_WOPI_OLDLOCK'] : '';
		if ($lock === '' || ($current !== '' && $current !== $lock && !($old !== '' && $current === $old))) {
			docstudioWopiAnswer(409, null, array('X-WOPI-Lock' => $current));
		}
		if ($override === 'REFRESH_LOCK' && $current !== $lock) {
			docstudioWopiAnswer(409, null, array('X-WOPI-Lock' => $current));
		}
		DocStudioWopi::setLock($fileId, $lock);
		docstudioWopiAnswer(200, null, array('X-WOPI-ItemVersion' => $version));
	}
	if ($override === 'UNLOCK') {
		if ($current !== $lock) {
			docstudioWopiAnswer(409, null, array('X-WOPI-Lock' => $current));
		}
		DocStudioWopi::setLock($fileId, '');
		docstudioWopiAnswer(200, null, array('X-WOPI-ItemVersion' => $version));
	}
	// PUT_RELATIVE, RENAME_FILE, DELETE...: not offered (UserCanNotWriteRelative, SupportsRename false)
	docstudioWopiAnswer(501);
}


/*
 * PutFile
 */

if (!$canWrite) {
	docstudioWopiAnswer(401);
}
if ($current !== '' && $current !== $lock) {
	docstudioWopiAnswer(409, null, array('X-WOPI-Lock' => $current));
}
if ($current === '' && $lock === '' && $provider->getSize() > 0) {
	// Not locked: only an empty file may be written without a lock (WOPI)
	docstudioWopiAnswer(409, null, array('X-WOPI-Lock' => ''));
}
// Collabora: the file changed elsewhere since it was opened (the user is asked what to do)
$known = isset($_SERVER['HTTP_X_COOL_WOPI_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_COOL_WOPI_TIMESTAMP'] : (isset($_SERVER['HTTP_X_LOOL_WOPI_TIMESTAMP']) ? (string) $_SERVER['HTTP_X_LOOL_WOPI_TIMESTAMP'] : '');
if ($known !== '' && $known !== docstudioWopiTime($modified)) {
	docstudioWopiAnswer(409, array('COOLStatusCode' => 1010, 'LOOLStatusCode' => 1010));
}

$maxSize = getDolGlobalInt('DOCSTUDIO_MAX_SAVE_SIZE', 104857600);
$tmpDir = DOL_DATA_ROOT.'/docstudio/temp';
if (dol_mkdir($tmpDir) < 0) {
	docstudioWopiAnswer(500);
}
$tmpFile = $tmpDir.'/wopi-'.$fileId.'-'.dol_hash(uniqid((string) mt_rand(), true), 'md5').'.'.$extension;
$in = fopen('php://input', 'rb');
$out = fopen($tmpFile, 'wb');
$size = 0;
if ($in === false || $out === false) {
	docstudioWopiAnswer(500);
}
while (!feof($in)) {
	$chunk = fread($in, 1048576);
	if ($chunk === false) {
		break;
	}
	$size += strlen($chunk);
	if ($size > $maxSize) {
		fclose($in);
		fclose($out);
		@unlink($tmpFile);
		docstudioWopiAnswer(413);
	}
	fwrite($out, $chunk);
}
fclose($in);
fclose($out);

if ($size <= 0 || !DocStudioWopi::isValidContent($extension, $tmpFile)) {
	@unlink($tmpFile);
	dol_syslog('docstudio/wopi.php PutFile refused (not a package of type '.$extension.') file='.$provider->getFileName(), LOG_WARNING);
	docstudioWopiAnswer(400);
}

$hookmanager->initHooks(array('docstudio'));
$parameters = array('source' => (string) $data['s'], 'mode' => 'replace', 'filename' => $provider->getFileName(), 'tmpfile' => $tmpFile, 'wopi' => 1);
$action = 'save';
$reshook = $hookmanager->executeHooks('docstudioBeforeSave', $parameters, $provider, $action);
if ($reshook != 0) {
	@unlink($tmpFile);
	docstudioWopiAnswer(409, null, array('X-WOPI-LockFailureReason' => dol_trunc($hookmanager->error, 200)));
}

$result = $provider->save($tmpFile, 'replace', $provider->getFileName(), true, $wuser);
@unlink($tmpFile);
if ($result['result'] <= 0) {
	dol_syslog('docstudio/wopi.php PutFile failed: '.$result['error'], LOG_WARNING);
	docstudioWopiAnswer(500);
}
dol_syslog('docstudio/wopi.php PutFile file='.$provider->getFileName().' user='.$wuser->login.' size='.$size, LOG_INFO);

$provider->load($data['p'], $wuser);
$modified = $provider->getModifiedTime();
docstudioWopiAnswer(200, array('LastModifiedTime' => docstudioWopiTime($modified)), array('X-WOPI-ItemVersion' => $modified.'-'.$provider->getSize()));
