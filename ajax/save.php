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
 * \file    htdocs/docstudio/ajax/save.php
 * \ingroup docstudio
 * \brief   Save the content made by an editor: copy next to the original or replacement of the original.
 *
 * POST only, with the CSRF token of Dolibarr. Checked again here, whatever the editor did in the browser:
 * - the file may be read and written by the user (provider), and editing is allowed for its type;
 * - the content is of the type of the file (for PDF: real PDF structure, for images: decoded and encoded again by
 *   the server, without metadata) and not too big;
 * - the antivirus (provider) and the hook docstudioBeforeSave of other modules.
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

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->load('docstudio@docstudio');

$source = GETPOST('source', 'aZ09');
$mode = GETPOST('mode', 'aZ09') === 'replace' ? 'replace' : 'copy';
$fileName = GETPOST('filename', 'alphanohtml');
$overwrite = GETPOSTINT('overwrite') > 0;

/**
 * Answer in JSON and stop
 *
 * @param array<string,mixed> $data Answer
 * @param int                 $code HTTP code
 * @return never
 */
function docstudioSaveAnswer(array $data, $code = 200)
{
	global $db;

	http_response_code($code);
	top_httphead('application/json');
	print json_encode($data + array('result' => -1, 'error' => '', 'exists' => 0, 'filename' => ''));
	$db->close();
	exit;
}


/*
 * Actions
 */

if (!isModEnabled('docstudio') || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	docstudioSaveAnswer(array('error' => 'Forbidden'), 403);
}
$fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
	docstudioSaveAnswer(array('error' => 'Forbidden'), 403);
}

$registry = new DocStudio($db);
$error = '';
$provider = $registry->loadFromRequest($source, $user, $error);
if ($provider === null) {
	docstudioSaveAnswer(array('error' => $error), 403);
}
$extension = $provider->getExtension();
if (!count($registry->getViewersFor($extension, $provider->getSize(), DocStudio::ACTION_EDIT))
	|| $registry->getHandler($extension, $user, DocStudio::ACTION_EDIT) === DocStudio::HANDLER_NONE) {
	docstudioSaveAnswer(array('error' => $langs->trans('DocStudioErrorNoEditor')), 403);
}
if (!$provider->canWrite($user)) {
	docstudioSaveAnswer(array('error' => $langs->trans('DocStudioErrorCannotWrite')), 403);
}

$upload = isset($_FILES['content']) ? $_FILES['content'] : null;
if (!is_array($upload) || !isset($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
	docstudioSaveAnswer(array('error' => $langs->trans('DocStudioErrorSave')), 400);
}
$maxSize = getDolGlobalInt('DOCSTUDIO_MAX_SAVE_SIZE', 104857600);
if ((int) $upload['size'] <= 0 || (int) $upload['size'] > $maxSize) {
	docstudioSaveAnswer(array('error' => $langs->trans('DocStudioErrorTooBig')), 400);
}
if (!DocStudio::isValidContent($extension, $upload['tmp_name'])) {
	docstudioSaveAnswer(array('error' => $langs->trans('DocStudioErrorBadContent')), 400);
}
// Images: only their pixels are kept (metadata and hidden data removed)
if (!DocStudio::cleanContent($extension, $upload['tmp_name'])) {
	docstudioSaveAnswer(array('error' => $langs->trans('DocStudioErrorBadContent')), 400);
}

// Other modules may refuse (versioning, signed documents...): return > 0 with $this->error
$hookmanager->initHooks(array('docstudio'));
$parameters = array('source' => $source, 'mode' => $mode, 'filename' => $fileName, 'tmpfile' => $upload['tmp_name']);
$action = 'save';
$reshook = $hookmanager->executeHooks('docstudioBeforeSave', $parameters, $provider, $action);
if ($reshook != 0) {
	docstudioSaveAnswer(array('error' => $hookmanager->error !== '' ? $hookmanager->error : $langs->trans('DocStudioErrorSave')), 403);
}

$result = $provider->save($upload['tmp_name'], $mode, $fileName, $overwrite, $user);
@unlink($upload['tmp_name']);
if ($result['result'] > 0) {
	dol_syslog('docstudio/ajax/save.php '.$mode.' source='.$source.' file='.$provider->getFileName().' saved as '.$result['filename'], LOG_INFO);
	setEventMessages($langs->trans('DocStudioSaved', $result['filename']), null, 'mesgs');
}
docstudioSaveAnswer($result, $result['result'] < 0 ? 400 : 200);
