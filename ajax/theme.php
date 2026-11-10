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
 * \file    htdocs/docstudio/ajax/theme.php
 * \ingroup docstudio
 * \brief   Light or dark theme of the pages of DocStudio, chosen with the switch of the status bar.
 *
 * POST only, with the CSRF token. Saved as a parameter of the user (DOCSTUDIO_THEME): the theme of Dolibarr itself
 * does not change. An empty value goes back to the choice of the theme of Dolibarr.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */

$theme = GETPOST('theme', 'aZ09');

top_httphead('application/json');
$fetchSite = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? $_SERVER['HTTP_SEC_FETCH_SITE'] : '';
if (!isModEnabled('docstudio') || $_SERVER['REQUEST_METHOD'] !== 'POST' || empty($user->id)
	|| ($fetchSite !== '' && $fetchSite !== 'same-origin') || !in_array($theme, array('light', 'dark', ''), true)) {
	http_response_code(403);
	print json_encode(array('result' => -1));
	$db->close();
	exit;
}

$result = dol_set_user_param($db, $conf, $user, array('DOCSTUDIO_THEME' => $theme));
print json_encode(array('result' => $result > 0 ? 1 : -1, 'theme' => $theme));
$db->close();
