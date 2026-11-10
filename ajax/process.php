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
 * \file    htdocs/docstudio/ajax/process.php
 * \ingroup docstudio
 * \brief   PDF tools run by the server for the editor (compression), before the content is saved by save.php.
 *
 * POST only, with the CSRF token of Dolibarr, same checks as save.php (the user may edit the file, editing allowed
 * for its type, real PDF, size). Nothing is stored: the result is sent back, then saved by save.php like any content.
 */

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

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/docstudio/class/docstudio.class.php');
dol_include_once('/docstudio/lib/docstudio.lib.php');
dol_include_once('/docstudio/class/docstudiopdftools.class.php');
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->load('docstudio@docstudio');

$source = GETPOST('source', 'aZ09');
$operation = GETPOST('operation', 'aZ09');
$level = GETPOST('level', 'aZ09');

/**
 * Answer an error in JSON and stop
 *
 * @param string $error Message
 * @param int    $code  HTTP code
 * @return never
 */
function docstudioProcessError($error, $code)
{
	global $db;

	http_response_code($code);
	top_httphead('application/json');
	print json_encode(array('result' => -1, 'error' => $error));
	$db->close();
	exit;
}


/*
 * Actions
 */

if (!isModEnabled('docstudio') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	docstudioProcessError('Forbidden', 403);
}
$fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
	docstudioProcessError('Forbidden', 403);
}

$registry = new DocStudio($db);
$error = '';
$provider = $registry->loadFromRequest($source, $user, $error);
if ($provider === null) {
	docstudioProcessError($error, 403);
}
$extension = $provider->getExtension();
if ($extension !== 'pdf'
	|| !count($registry->getViewersFor($extension, $provider->getSize(), DocStudio::ACTION_EDIT))
	|| $registry->getHandler($extension, $user, DocStudio::ACTION_EDIT) === DocStudio::HANDLER_NONE) {
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorNoEditor'), 403);
}
if (!$provider->canWrite($user)) {
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorCannotWrite'), 403);
}
if ($operation !== 'compress' || !isset(DocStudioPdfTools::LEVELS[$level]) || !DocStudioPdfTools::canCompress()) {
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorCompress'), 400);
}

$upload = isset($_FILES['content']) ? $_FILES['content'] : null;
if (!is_array($upload) || !isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorCompress'), 400);
}
// Compression limited in size (DOCSTUDIO_MAX_COMPRESS_SIZE) and to one at a time for a user (audit DSTU-013)
$maxSize = min(getDolGlobalInt('DOCSTUDIO_MAX_SAVE_SIZE', 104857600), DocStudioPdfTools::getMaxCompressSize());
if ((int) $upload['size'] <= 0 || (int) $upload['size'] > $maxSize) {
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorTooBig'), 400);
}
// One compression at a time for a user and a few for the instance, taken before the checks that read the file
// (antivirus, Ghostscript: audit DSTU-013, DSTU-020)
$userLock = DocStudioPdfTools::lockUser((int) $user->id);
$slotLock = $userLock !== false ? DocStudioPdfTools::lockSlot() : false;
if ($userLock === false || $slotLock === false) {
	@unlink($upload['tmp_name']);
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorCompressBusy'), 429);
}
if (!DocStudio::isValidContent($extension, $upload['tmp_name'])) {
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorBadContent'), 400);
}
// Antivirus before Ghostscript or Stirling PDF read it (audit DSTU-015)
$virus = dolCheckVirus($upload['tmp_name'], $provider->getFileName());
if (count($virus)) {
	@unlink($upload['tmp_name']);
	dol_syslog('docstudio/ajax/process.php content refused by the antivirus: '.implode(', ', $virus), LOG_WARNING);
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorVirus'), 403);
}
$tools = new DocStudioPdfTools();
$result = $tools->compress($upload['tmp_name'], $level);
if ($result === false || !DocStudio::isValidContent($extension, $result)) {
	$tools->clean();
	@unlink($upload['tmp_name']);
	dol_syslog('docstudio/ajax/process.php compress failed: '.$tools->error, LOG_WARNING);
	docstudioProcessError($langs->transnoentitiesnoconv('DocStudioErrorCompress'), 502);
}

$sizeBefore = (int) $upload['size'];
clearstatcache();
$sizeAfter = (int) filesize($result);
dol_syslog('docstudio/ajax/process.php compress '.$level.' with '.$tools->usedTool.' '.$sizeBefore.' -> '.$sizeAfter.' file='.$provider->getFileName(), LOG_INFO);

top_httphead('application/pdf');
header('Content-Length: '.$sizeAfter);
header('X-DocStudio-Tool: '.$tools->usedTool);
header('Cache-Control: no-store');
readfile($result);
$tools->clean();
@unlink($upload['tmp_name']);
$db->close();
