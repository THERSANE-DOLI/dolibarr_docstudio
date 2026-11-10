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
 * \file    htdocs/docstudio/ajax/content.php
 * \ingroup docstudio
 * \brief   Raw content of a file for the viewers, after the checks of its provider.
 *
 * Read only, like document.php: the provider checks the permissions again on every call. The content is always sent
 * as an attachment of unknown type with a sandbox policy, so opening this URL directly never runs nor renders it.
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
 * @var Translate $langs
 * @var User $user
 */

$langs->load('docstudio@docstudio');

$source = GETPOST('source', 'aZ09');


/*
 * Actions
 */

if (!isModEnabled('docstudio')) {
	httponly_accessforbidden('Module not enabled', 403);
}
// Only a request of a page of the same site may read the content (no link from another site, no top navigation)
$fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
$fetchMode = isset($_SERVER['HTTP_SEC_FETCH_MODE']) ? $_SERVER['HTTP_SEC_FETCH_MODE'] : '';
if (($fetchSite !== '' && $fetchSite !== 'same-origin') || $fetchMode === 'navigate') {
	httponly_accessforbidden('Forbidden', 403);
}

$registry = new DocStudio($db);
$error = '';
$provider = $registry->loadFromRequest($source, $user, $error);
if ($provider === null) {
	httponly_accessforbidden(dol_escape_htmltag($error), 403, 1);
}

// A viewer or an editor must exist for this type and this size: DocStudio never serves a file none would show
if (!count($registry->getViewersFor($provider->getExtension(), $provider->getSize()))
	&& !count($registry->getViewersFor($provider->getExtension(), $provider->getSize(), DocStudio::ACTION_EDIT))) {
	httponly_accessforbidden(dol_escape_htmltag($langs->trans('DocStudioErrorNoViewer')), 403, 1);
}


// A reading Dolibarr traces itself (main document of a validated invoice): read through its document.php, which
// runs its own checks, hooks and logs (same origin, followed by the fetch of the viewer)
$tracked = $provider->getTrackedContentUrl();
if ($tracked !== '') {
	header('Cache-Control: private, no-store');
	header('Location: '.$tracked, true, 303);
	$db->close();
	exit;
}

// Modules refusing or logging the downloads (hook downloadDocument of document.php)
if (!$provider->runDownloadHooks($user)) {
	httponly_accessforbidden(dol_escape_htmltag($provider->error !== '' ? $provider->error : $langs->trans('DocStudioErrorForbidden')), 403, 1);
}


/*
 * View
 */

dol_syslog('docstudio/ajax/content.php source='.$source.' file='.$provider->getFileName(), LOG_DEBUG);

docstudioSendContentHeaders($provider->getSize());
if (!$provider->outputContent()) {
	dol_syslog('docstudio/ajax/content.php could not read the content', LOG_ERR);
}
$db->close();
