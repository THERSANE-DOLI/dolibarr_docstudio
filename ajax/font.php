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
 * \file    htdocs/docstudio/ajax/font.php
 * \ingroup docstudio
 * \brief   File of a font uploaded in DocStudio, for the editors (internal users only)
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

dol_include_once('/docstudio/class/docstudiofonts.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */

$slug = GETPOST('font', 'aZ09');
$weight = GETPOSTINT('weight') === 700 ? 700 : 400;


/*
 * Actions
 */

if (!isModEnabled('docstudio') || empty($user->id) || !empty($user->socid)) {
	httponly_accessforbidden('Forbidden', 403);
}
$path = preg_match('/^u-[a-z0-9-]{1,60}$/', (string) $slug) ? DocStudioFonts::getFilePath($slug, $weight) : '';
if ($path === '') {
	httponly_accessforbidden('Not found', 404);
}


/*
 * View
 */

header('Content-Type: '.(substr($path, -4) === '.otf' ? 'font/otf' : 'font/ttf'));
header('Content-Length: '.filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
header('Cross-Origin-Resource-Policy: same-origin');
readfile($path);
$db->close();
