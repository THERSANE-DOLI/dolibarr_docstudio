<?php
/* Copyright (C) 2026		John BOTELLA				<john.botella@thersane.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/docstudio/admin/fonts.php
 * \ingroup docstudio
 * \brief   Fonts of the editors: the standard fonts of PDF, and fonts uploaded by the administrator
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/docstudio.lib.php';
dol_include_once('/docstudio/class/docstudiofonts.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "docstudio@docstudio"));

$hookmanager->initHooks(array('docstudiofonts', 'globalsetup'));

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$slug = GETPOST('slug', 'aZ09');

if (!$user->admin) {
	accessforbidden();
}


/*
 * Actions
 */

if ($action == 'upload') {
	$result = DocStudioFonts::installUpload(GETPOST('label', 'alphanohtml'), isset($_FILES['regular']) ? $_FILES['regular'] : array(), isset($_FILES['bold']) ? $_FILES['bold'] : array());
	if ($result !== '') {
		setEventMessages($langs->trans('DocStudioFontAdded'), null, 'mesgs');
	} else {
		setEventMessages(DocStudioFonts::$error, null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action == 'confirm_delete' && $confirm == 'yes') {
	if (DocStudioFonts::delete($slug)) {
		setEventMessages($langs->trans('DocStudioFontDeleted'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$form = new Form($db);
$title = "DocStudioSetup";

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-docstudio page-admin_fonts');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = docstudioAdminPrepareHead();
print dol_get_fiche_head($head, 'fonts', $langs->trans($title), -1, 'docstudio@docstudio');

if ($action == 'delete' && $slug !== '') {
	print $form->formconfirm($_SERVER['PHP_SELF'].'?slug='.urlencode($slug), $langs->trans('DocStudioFontDelete'), $langs->trans('DocStudioFontDeleteConfirm'), 'confirm_delete', '', 0, 1);
}

print '<span class="opacitymedium">'.$langs->trans("DocStudioFontsHelp").'</span><br><br>';

$fonts = DocStudioFonts::listInstalled();
$fontUrl = dol_buildpath('/docstudio/ajax/font.php', 1);
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('DocStudioFontName').'</td><td>'.$langs->trans('DocStudioFontPreview').'</td><td>'.$langs->trans('DocStudioFontFaces').'</td><td></td></tr>';
foreach (DocStudioFonts::standardFonts() as $font) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($font['label']).' <span class="opacitymedium small">('.$langs->trans('DocStudioFontStandard').')</span></td>';
	print '<td><span style="font-family: '.dol_escape_htmltag($font['css']).'; font-size: 1.3em">Aa Bb Éé 123 &amp; €</span></td>';
	print '<td>'.$langs->trans('DocStudioFontRegular').', '.$langs->trans('DocStudioFontBold').'</td><td></td></tr>';
}
foreach ($fonts as $font) {
	$family = 'DocStudio-'.$font['slug'];
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($font['label']).'</td>';
	print '<td><style>@font-face{font-family:"'.dol_escape_htmltag($family).'";src:url("'.dol_escape_htmltag($fontUrl.'?font='.rawurlencode($font['slug']).'&weight=400').'");}</style>';
	print '<span style="font-family: \''.dol_escape_htmltag($family).'\'; font-size: 1.3em">Aa Bb Éé 123 &amp; €</span></td>';
	print '<td>'.$langs->trans('DocStudioFontRegular').(isset($font['faces'][700]) ? ', '.$langs->trans('DocStudioFontBold') : '').'</td>';
	print '<td class="right"><a class="reposition" href="'.$_SERVER['PHP_SELF'].'?action=delete&token='.newToken().'&slug='.urlencode($font['slug']).'">'.img_delete().'</a></td></tr>';
}
print '</table>';
print '</div>';
print '<br>';

print load_fiche_titre($langs->trans('DocStudioFontAdd'), '', '');
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="upload">';
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefield fieldrequired">'.$langs->trans('DocStudioFontName').'</td><td><input type="text" name="label" class="minwidth300" maxlength="60" required></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('DocStudioFontRegular').'</td><td><input type="file" name="regular" accept=".ttf,.otf" required></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DocStudioFontBold').'</td><td><input type="file" name="bold" accept=".ttf,.otf"></td></tr>';
print '</table>';
print '<div class="center"><input type="submit" class="button" value="'.$langs->trans('Add').'"></div>';
print '</form>';

print dol_get_fiche_end();

llxFooter();
$db->close();
