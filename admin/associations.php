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
 * \file    htdocs/docstudio/admin/associations.php
 * \ingroup docstudio
 * \brief   File associations: for each type, what opens it (viewer of DocStudio, preview of Dolibarr or download)
 *          and which other ways the users may choose.
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

$langs->loadLangs(array("admin", "docstudio@docstudio"));

$hookmanager->initHooks(array('docstudioassociations', 'globalsetup'));

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

if (!$user->admin) {
	accessforbidden();
}

$registry = new DocStudio($db);


/*
 * Actions
 */

if ($action == 'update') {
	$postedHandlers = GETPOST('handler', 'array');
	$postedChoices = GETPOST('choices', 'array');
	$error = 0;
	$hasChoices = 0;
	$db->begin();
	foreach (array(DocStudio::ACTION_VIEW, DocStudio::ACTION_EDIT) as $act) {
		foreach ($registry->getSupportedExtensions($act) as $extension) {
			$handlers = $registry->getHandlersFor($extension, $act);
			$posted = (isset($postedHandlers[$act]) && is_array($postedHandlers[$act])) ? $postedHandlers[$act] : array();
			// Only a handler of the list of this type is kept (never a value of the browser as such)
			$handler = (isset($posted[$extension]) && is_string($posted[$extension]) && isset($handlers[$posted[$extension]]))
				? $posted[$extension] : $registry->getDefaultHandler($extension, $act);
			$choices = array();
			if (isset($postedChoices[$act][$extension]) && is_array($postedChoices[$act][$extension])) {
				foreach ($postedChoices[$act][$extension] as $code) {
					if (is_string($code) && isset($handlers[$code]) && $code !== $handler && !in_array($code, $choices, true)) {
						$choices[] = $code;
					}
				}
			}
			$hasChoices = $hasChoices || count($choices);

			if ($handler === $registry->getDefaultHandler($extension, $act)) {
				$res = dolibarr_del_const($db, DocStudio::getConstName($extension, '', $act), $conf->entity);
			} else {
				$res = dolibarr_set_const($db, DocStudio::getConstName($extension, '', $act), $handler, 'chaine', 0, '', $conf->entity);
			}
			$error += ($res < 0) ? 1 : 0;
			if (count($choices)) {
				$res = dolibarr_set_const($db, DocStudio::getConstName($extension, '_CHOICES', $act), implode(',', $choices), 'chaine', 0, '', $conf->entity);
			} else {
				$res = dolibarr_del_const($db, DocStudio::getConstName($extension, '_CHOICES', $act), $conf->entity);
			}
			$error += ($res < 0) ? 1 : 0;
		}
	}
	// Shows the tab « Opening of files » on the user cards only when the users have something to choose
	if ($hasChoices) {
		$res = dolibarr_set_const($db, 'DOCSTUDIO_HAS_USER_CHOICES', '1', 'chaine', 0, '', $conf->entity);
	} else {
		$res = dolibarr_del_const($db, 'DOCSTUDIO_HAS_USER_CHOICES', $conf->entity);
	}
	$error += ($res < 0) ? 1 : 0;

	if (!$error) {
		$db->commit();
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		$db->rollback();
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

$help_url = '';
$title = "DocStudioSetup";

llxHeader('', $langs->trans($title), $help_url, '', 0, 0, '', '', '', 'mod-docstudio page-admin_associations');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';

print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = docstudioAdminPrepareHead();
print dol_get_fiche_head($head, 'associations', $langs->trans($title), -1, 'docstudio@docstudio');

print '<span class="opacitymedium">'.$langs->trans("DocStudioAssociationsHelp").'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

foreach (array(DocStudio::ACTION_VIEW => 'DocStudioOpenBy', DocStudio::ACTION_EDIT => 'DocStudioEditedBy') as $act => $columnLabel) {
	$families = $registry->getExtensionsByFamily($act);
	if (empty($families)) {
		continue;
	}
	print load_fiche_titre($langs->trans($act === DocStudio::ACTION_EDIT ? 'DocStudioAssociationsEdit' : 'DocStudioAssociationsView'), '', '');
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<td>'.$langs->trans("DocStudioFileType").'</td>';
	print '<td>'.$langs->trans($columnLabel).'</td>';
	print '<td>'.$langs->trans("DocStudioUserChoices").'</td>';
	print '</tr>';

	foreach ($families as $family => $extensions) {
		print '<tr class="liste_titre_filter"><td colspan="3"><strong>'.$langs->trans('DocStudioFamily'.ucfirst($family)).'</strong></td></tr>';
		$helpKey = 'DocStudioFamilyHelp'.($act === DocStudio::ACTION_EDIT ? 'Edit' : '').ucfirst($family);
		$help = $langs->trans($helpKey);
		if ($help !== $helpKey) {
			print '<tr class="oddeven"><td colspan="3">'.info_admin($help, 0, 0, 'info').'</td></tr>';
		}
		foreach ($extensions as $extension) {
			$handlers = $registry->getHandlersFor($extension, $act);
			$current = $registry->getAdminHandler($extension, $act);
			$choices = $registry->getUserChoices($extension, $act);
			$default = $registry->getDefaultHandler($extension, $act);
			$fieldExt = dol_escape_htmltag($extension);

			print '<tr class="oddeven">';
			print '<td class="nowraponall"><strong>.'.$fieldExt.'</strong> <span class="opacitymedium small">'.dol_escape_htmltag(dol_mimetype('file.'.$extension)).'</span></td>';
			print '<td><select class="flat minwidth200 docstudio-handler" name="handler['.$act.']['.$fieldExt.']">';
			foreach ($handlers as $code => $label) {
				print '<option value="'.dol_escape_htmltag($code).'"'.($code === $current ? ' selected' : '').'>'.dol_escape_htmltag($label.($code === $default ? ' ('.$langs->trans('Default').')' : '')).'</option>';
			}
			print '</select></td>';
			print '<td>';
			foreach ($handlers as $code => $label) {
				$id = 'choice_'.$act.'_'.$extension.'_'.$code;
				print '<label class="nowraponall marginrightonly" for="'.dol_escape_htmltag($id).'">';
				print '<input type="checkbox" id="'.dol_escape_htmltag($id).'" name="choices['.$act.']['.$fieldExt.'][]" value="'.dol_escape_htmltag($code).'"'.(in_array($code, $choices, true) && $code !== $current ? ' checked' : '').'> ';
				print dol_escape_htmltag($label).'</label> ';
			}
			print '</td>';
			print '</tr>';
		}
	}
	print '</table>';
	print '</div>';
	print '<br>';
}

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

print dol_get_fiche_end();

print '<script nonce="'.getNonce().'" src="'.dol_escape_htmltag(docstudioAssetUrl('/docstudio/js/docstudio-admin.js')).'"></script>'."\n";

llxFooter();
$db->close();
