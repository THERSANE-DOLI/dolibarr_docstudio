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
 * \file    htdocs/docstudio/dav.php
 * \ingroup docstudio
 * \brief   Small WebDAV server for the Office installed on the computer (« Open in Excel », lot 4): one file per
 *          address, called by Office without the session of the browser.
 *
 *   /dav.php/{entity}/{token}/            the folder of the token, holding the file only (PROPFIND, OPTIONS)
 *   /dav.php/{entity}/{token}/{file name} OPTIONS, HEAD, GET, PROPFIND, PROPPATCH, LOCK, UNLOCK, PUT
 *
 * Every call: the signed token (kind « dav ») gives the user, the entity, the file and the mode; the computer must be
 * the one that asked for the link (hash of its address in the token, DOCSTUDIO_DESKTOP_IP_CHECK_OFF hidden); the user
 * must still be active and the file is loaded again by its provider with his rights. Saving: write permission checked
 * again, lock of the caller, ZIP package of the type of the file, size, hook docstudioBeforeSave, antivirus of the
 * provider. Never: creating, deleting, moving or copying a file.
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
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}
// Entity of the file (multicompany), first part of the path, checked against the token below
$davPath = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';
if (preg_match('#^/(\d{1,6})/#', $davPath, $davMatch) && (int) $davMatch[1] > 0) {
	define('DOLENTITY', (int) $davMatch[1]);
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
dol_include_once('/docstudio/class/docstudiodesktop.class.php');
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

/**
 * Answer with a HTTP code and stop
 *
 * @param int                  $code
 * @param string               $body
 * @param array<string,string> $headers
 * @return never
 */
function docstudioDavAnswer($code, $body = '', array $headers = array())
{
	global $db;

	http_response_code($code);
	header('DAV: 1, 2');
	header('MS-Author-Via: DAV');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	foreach ($headers as $name => $value) {
		header($name.': '.str_replace(array("\r", "\n"), '', $value));
	}
	if ($body !== '') {
		if (!isset($headers['Content-Type'])) {
			header('Content-Type: application/xml; charset=utf-8');
		}
		header('Content-Length: '.strlen($body));
		print $body;
	}
	$db->close();
	exit;
}

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
$allow = 'OPTIONS, GET, HEAD, PROPFIND, PROPPATCH, LOCK, UNLOCK, PUT';

if (!isModEnabled('docstudio') || !DocStudioDesktop::isEnabled()) {
	docstudioDavAnswer(404);
}

// Route: /{entity}/{token}/ or /{entity}/{token}/{file name}
if (!preg_match('#^/(\d{1,6})/([A-Za-z0-9_-]+\.[A-Za-z0-9_-]+)(/([^/]*))?$#', $davPath, $m)) {
	// Discovery of Office on the folders above: nothing to see there
	if ($method === 'OPTIONS') {
		docstudioDavAnswer(200, '', array('Allow' => 'OPTIONS'));
	}
	docstudioDavAnswer(404);
}
$token = $m[2];
$askedName = isset($m[4]) ? (string) $m[4] : '';
$isFolder = $askedName === '';

$data = DocStudioWopi::checkToken($token, null);
if ($data === null || (int) $data['e'] !== (int) $conf->entity || (int) $m[1] !== (int) $conf->entity || !isset($data['k']) || $data['k'] !== 'dav') {
	docstudioDavAnswer(404);
}
// Same computer as the one that asked for the link: a token read in a log is useless elsewhere
$remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
if (!getDolGlobalInt('DOCSTUDIO_DESKTOP_IP_CHECK_OFF') && (!isset($data['i']) || !hash_equals((string) $data['i'], DocStudioDesktop::ipHash($remote)))) {
	dol_syslog('docstudio/dav.php refused: token used from another address '.$remote, LOG_WARNING);
	docstudioDavAnswer(403);
}

// User of the token, still active, with his rights of now
$wuser = new User($db);
if ($wuser->fetch((int) $data['u']) <= 0 || (int) $wuser->statut !== 1) {
	docstudioDavAnswer(404);
}
if (method_exists($wuser, 'loadRights')) {
	$wuser->loadRights();
} else {
	$wuser->getrights();
}
$user = $wuser;

$registry = new DocStudio($db);
$provider = $registry->getProvider((string) $data['s']);
if ($provider === null || $provider->load($data['p'], $wuser) <= 0 || !$provider->runDownloadHooks($wuser)) {
	docstudioDavAnswer(404);
}
$fileId = DocStudioWopi::fileId((string) $data['s'], $provider->getParams(), (int) $conf->entity);
if (!hash_equals((string) $data['f'], $fileId)) {
	docstudioDavAnswer(404);
}
$fileName = $provider->getFileName();
// Never a received attachment nor a type with macros (audit DSTU-018), even with a token made before
if (!DocStudioDesktop::isAllowedFor((string) $data['s'], $provider->getExtension())) {
	docstudioDavAnswer(404);
}
// Office may encode the name its own way: compared decoded
if (!$isFolder && rawurldecode($askedName) !== $fileName) {
	docstudioDavAnswer(404);
}
$extension = $provider->getExtension();
$canWrite = $data['m'] === 'edit' && in_array($extension, DocStudioDesktop::WRITABLE_EXTENSIONS, true) && $provider->canReplaceInPlace() && $provider->canWrite($wuser);
$modified = $provider->getModifiedTime();
$etag = $modified.'-'.$provider->getSize();
$current = DocStudioWopi::getLock($fileId);
// Lock tokens sent by Office (If and Lock-Token headers)
$sentLocks = DocStudioDesktop::parseLockTokens((isset($_SERVER['HTTP_IF']) ? (string) $_SERVER['HTTP_IF'] : '').' '.(isset($_SERVER['HTTP_LOCK_TOKEN']) ? (string) $_SERVER['HTTP_LOCK_TOKEN'] : ''));
// Held by the caller: its token and the same user (a lock taken by another user is never given nor lifted, DSTU-017)
$lockOwner = DocStudioWopi::getLockOwner($fileId);
$ownLock = $current !== '' && ($lockOwner === 0 || $lockOwner === (int) $wuser->id);
$holdsLock = $ownLock && in_array(strtolower($current), $sentLocks, true);
$folderHref = preg_replace('#/[^/]*$#', '/', (string) parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH));
if ($isFolder) {
	$folderHref = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
}


/*
 * OPTIONS, PROPFIND, PROPPATCH
 */

if ($method === 'OPTIONS') {
	docstudioDavAnswer(200, '', array('Allow' => $allow));
}

if ($method === 'PROPFIND') {
	$depth = isset($_SERVER['HTTP_DEPTH']) && $_SERVER['HTTP_DEPTH'] === '0' ? 0 : 1;
	// The lock token only to its holder (with a token for editing): the others see that the file is locked
	docstudioDavAnswer(207, DocStudioDesktop::propfindXml($folderHref, $fileName, $provider->getSize(), $modified, $etag, $isFolder, $depth, $current, $canWrite, $canWrite && $ownLock));
}

if ($isFolder) {
	// MKCOL, PUT of another file, DELETE...: nothing is created in the folder
	docstudioDavAnswer(403);
}

if ($method === 'PROPPATCH') {
	// Properties of Windows (attributes, times) sent by Office: accepted, not stored
	$body = (string) file_get_contents('php://input', false, null, 0, 65536);
	$props = '';
	if (preg_match_all('#<(?:[A-Za-z0-9_-]+:)?prop>(.*?)</(?:[A-Za-z0-9_-]+:)?prop>#s', $body, $pm)) {
		foreach ($pm[1] as $propBlock) {
			if (preg_match_all('#<([A-Za-z0-9_-]+:)?([A-Za-z0-9_-]+)(\s+xmlns(?::[A-Za-z0-9_-]+)?="([^"]*)")?[^>]*?/?>#', $propBlock, $tags, PREG_SET_ORDER)) {
				foreach ($tags as $tag) {
					$ns = isset($tag[4]) && $tag[4] !== '' ? $tag[4] : 'urn:schemas-microsoft-com:';
					$props .= '<x:'.$tag[2].' xmlns:x="'.htmlspecialchars($ns, ENT_XML1 | ENT_QUOTES, 'UTF-8').'"/>';
				}
			}
		}
	}
	$href = htmlspecialchars($folderHref.rawurlencode($fileName), ENT_XML1 | ENT_QUOTES, 'UTF-8');
	docstudioDavAnswer(207, '<?xml version="1.0" encoding="utf-8"?>'."\n".'<D:multistatus xmlns:D="DAV:"><D:response><D:href>'.$href.'</D:href><D:propstat><D:prop>'.$props.'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response></D:multistatus>');
}


/*
 * GET, HEAD
 */

if ($method === 'GET' || $method === 'HEAD') {
	http_response_code(200);
	header('DAV: 1, 2');
	header('MS-Author-Via: DAV');
	header('Content-Type: '.(isset(DocStudioDesktop::MIME_TYPES[$extension]) ? DocStudioDesktop::MIME_TYPES[$extension] : 'application/octet-stream'));
	header('Content-Length: '.$provider->getSize());
	header('Last-Modified: '.DocStudioDesktop::httpDate($modified));
	header('ETag: "'.$etag.'"');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	header("Content-Security-Policy: default-src 'none'; sandbox");
	if ($method === 'GET') {
		$provider->outputContent();
	}
	$db->close();
	exit;
}


/*
 * LOCK, UNLOCK
 */

if ($method === 'LOCK') {
	if (!$canWrite) {
		docstudioDavAnswer(403);
	}
	$body = (string) file_get_contents('php://input', false, null, 0, 65536);
	if (trim($body) === '') {
		// Refresh of a lock held by the caller
		if (!$holdsLock) {
			docstudioDavAnswer(412);
		}
		$lock = $current;
	} else {
		if ($current !== '' && !$holdsLock) {
			// Locked by someone else (another Office, the office server)
			docstudioDavAnswer(423);
		}
		$lock = $current !== '' ? $current : DocStudioDesktop::newLockToken();
	}
	if (!DocStudioWopi::setLock($fileId, $lock, (int) $wuser->id)) {
		docstudioDavAnswer(500);
	}
	$owner = '';
	if (preg_match('#<(?:[A-Za-z0-9_-]+:)?owner>(.*?)</(?:[A-Za-z0-9_-]+:)?owner>#s', $body, $om)) {
		$owner = '<D:owner>'.htmlspecialchars(trim(strip_tags($om[1])), ENT_XML1 | ENT_QUOTES, 'UTF-8').'</D:owner>';
	}
	$xml = '<?xml version="1.0" encoding="utf-8"?>'."\n".'<D:prop xmlns:D="DAV:"><D:lockdiscovery><D:activelock>'
		.'<D:locktype><D:write/></D:locktype><D:lockscope><D:exclusive/></D:lockscope><D:depth>0</D:depth>'.$owner
		.'<D:timeout>Second-'.DocStudioWopi::LOCK_TTL.'</D:timeout>'
		.'<D:locktoken><D:href>'.$lock.'</D:href></D:locktoken></D:activelock></D:lockdiscovery></D:prop>';
	docstudioDavAnswer(200, $xml, array('Lock-Token' => '<'.$lock.'>'));
}

if ($method === 'UNLOCK') {
	if (!$canWrite) {
		docstudioDavAnswer(403);
	}
	if ($current === '') {
		docstudioDavAnswer(204);
	}
	if (!$holdsLock) {
		docstudioDavAnswer(409);
	}
	DocStudioWopi::setLock($fileId, '');
	docstudioDavAnswer(204);
}

if ($method !== 'PUT') {
	// DELETE, MOVE, COPY, MKCOL...: never
	docstudioDavAnswer(405, '', array('Allow' => $allow));
}


/*
 * PUT
 */

if (!$canWrite) {
	docstudioDavAnswer(403);
}
if ($current !== '' && !$holdsLock) {
	docstudioDavAnswer(423);
}

$maxSize = getDolGlobalInt('DOCSTUDIO_MAX_SAVE_SIZE', 104857600);
$tmpDir = DOL_DATA_ROOT.'/docstudio/temp';
if (dol_mkdir($tmpDir) < 0) {
	docstudioDavAnswer(500);
}
$tmpFile = $tmpDir.'/dav-'.$fileId.'-'.dol_hash(uniqid((string) mt_rand(), true), 'md5').'.'.$extension;
$in = fopen('php://input', 'rb');
$out = fopen($tmpFile, 'wb');
$size = 0;
if ($in === false || $out === false) {
	docstudioDavAnswer(500);
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
		docstudioDavAnswer(413);
	}
	fwrite($out, $chunk);
}
fclose($in);
fclose($out);

if ($size <= 0) {
	// Office may create an empty file before writing it: the document is never emptied
	@unlink($tmpFile);
	docstudioDavAnswer(204);
}
if (!DocStudioWopi::isValidContent($extension, $tmpFile)) {
	@unlink($tmpFile);
	dol_syslog('docstudio/dav.php PUT refused (not a package of type '.$extension.') file='.$fileName, LOG_WARNING);
	docstudioDavAnswer(400);
}

$hookmanager->initHooks(array('docstudio'));
$parameters = array('source' => (string) $data['s'], 'mode' => 'replace', 'filename' => $fileName, 'tmpfile' => $tmpFile, 'dav' => 1);
$action = 'save';
$reshook = $hookmanager->executeHooks('docstudioBeforeSave', $parameters, $provider, $action);
if ($reshook != 0) {
	@unlink($tmpFile);
	docstudioDavAnswer(403);
}

$result = $provider->save($tmpFile, 'replace', $fileName, true, $wuser);
@unlink($tmpFile);
if ($result['result'] <= 0) {
	dol_syslog('docstudio/dav.php PUT failed: '.$result['error'], LOG_WARNING);
	docstudioDavAnswer(500);
}
dol_syslog('docstudio/dav.php PUT file='.$fileName.' user='.$wuser->login.' size='.$size, LOG_INFO);

$provider->load($data['p'], $wuser);
docstudioDavAnswer(204, '', array('ETag' => '"'.$provider->getModifiedTime().'-'.$provider->getSize().'"'));
