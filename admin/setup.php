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
 * \file    htdocs/docstudio/admin/setup.php
 * \ingroup docstudio
 * \brief   DocStudio setup page: what is installed (viewers, sources of files) and in which state.
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
dol_include_once('/docstudio/class/docstudio.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

// Translations
$langs->loadLangs(array("admin", "docstudio@docstudio"));

// Initialize a technical object to manage hooks of page. Note that conf->hooks_modules contains an array of hook context
$hookmanager->initHooks(array('docstudiosetup', 'globalsetup'));

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

// Access control
if (!$user->admin) {
	accessforbidden();
}


/*
 * Actions
 */

// None: nothing to set in this version


/*
 * View
 */

$registry = new DocStudio($db);
$viewers = $registry->getViewers();
$providers = $registry->getProviders();

$help_url = '';
$title = "DocStudioSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-docstudio page-admin');

// Subheader
$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

// Configuration header
$head = docstudioAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'docstudio@docstudio');

print '<span class="opacitymedium">'.$langs->trans("DocStudioSetupPage").'</span><br><br>';

// JavaScript context of Dolibarr (hooks of the viewers)
if ((int) DOL_VERSION >= 24) {
	$contextState = $langs->trans('DocStudioJsContextCore');
	$contextOk = true;
} elseif (isModEnabled('backportdolcontext')) {
	$contextState = $langs->trans('DocStudioJsContextBackport');
	$contextOk = true;
} else {
	$contextState = $langs->trans('DocStudioJsContextMissing');
	$contextOk = false;
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans("DocStudioJsContext").'</td><td>';
print img_picto('', $contextOk ? 'tick' : 'warning', 'class="pictofixedwidth"').dol_escape_htmltag($contextState);
print '</td></tr>';
print '</table>';
print '</div>';
print '<br>';

// Viewers
print load_fiche_titre($langs->trans("DocStudioViewers"), '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("DocStudioViewer").'</td>';
print '<td>'.$langs->trans("DocStudioFileTypes").'</td>';
print '<td>'.$langs->trans("Module").'</td>';
print '<td class="right">'.$langs->trans("DocStudioPriority").'</td>';
print '</tr>';
foreach ($viewers as $code => $viewer) {
	print '<tr class="oddeven">';
	print '<td>'.dol_escape_htmltag($viewer['label']).' <span class="opacitymedium">('.dol_escape_htmltag($code).')</span></td>';
	print '<td>'.dol_escape_htmltag(implode(', ', $viewer['extensions'])).'</td>';
	print '<td>'.dol_escape_htmltag($viewer['module']).'</td>';
	print '<td class="right">'.((int) $viewer['priority']).'</td>';
	print '</tr>';
}
print '</table>';
print '</div>';
print '<br>';

// Sources of files
print load_fiche_titre($langs->trans("DocStudioProviders"), '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Code").'</td><td>'.$langs->trans("Module").'</td></tr>';
foreach ($providers as $code => $provider) {
	print '<tr class="oddeven"><td>'.dol_escape_htmltag($code).'</td><td>'.dol_escape_htmltag($provider['module']).'</td></tr>';
}
print '</table>';
print '</div>';

if (!empty($registry->errors)) {
	print '<br>';
	print info_admin($langs->trans("DocStudioRefusedDeclarations").' '.dol_escape_htmltag(implode(', ', $registry->errors)), 0, 0, 'warning');
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
