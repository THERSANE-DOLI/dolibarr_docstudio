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
 * \file    htdocs/docstudio/user_opening.php
 * \ingroup docstudio
 * \brief   Tab of the user card: how this user opens the file types for which the administrator left a choice.
 */

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

require_once DOL_DOCUMENT_ROOT.'/core/lib/usergroups.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
dol_include_once('/docstudio/class/docstudio.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('users', 'docstudio@docstudio'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

$object = new User($db);
if ($id <= 0 || $object->fetch($id) <= 0) {
	accessforbidden();
}
$object->getrights();
$object->loadPersonalConf();

// The user himself, or an administrator
$canedit = ($user->id == $object->id || $user->admin);
if (!isModEnabled('docstudio') || !$canedit || ($object->entity != $conf->entity && !empty($object->entity) && !$user->admin)) {
	accessforbidden();
}

$hookmanager->initHooks(array('docstudiouseropening', 'globalcard'));

$registry = new DocStudio($db);

// Types where the administrator left a choice, for viewing and for editing
$types = array(DocStudio::ACTION_VIEW => array(), DocStudio::ACTION_EDIT => array());
foreach ($types as $act => $list) {
	foreach ($registry->getSupportedExtensions($act) as $extension) {
		if (count($registry->getUserChoices($extension, $act)) > 1) {
			$types[$act][] = $extension;
		}
	}
	sort($types[$act]);
}


/*
 * Actions
 */

if ($action == 'update') {
	$posted = GETPOST('handler', 'array');
	$params = array();
	foreach ($types as $act => $list) {
		foreach ($list as $extension) {
			$choices = $registry->getUserChoices($extension, $act);
			$value = (isset($posted[$act][$extension]) && is_string($posted[$act][$extension])) ? $posted[$act][$extension] : '';
			// Only a handler allowed by the administrator is kept; the one of the administrator means « no own choice »
			$params[DocStudio::getConstName($extension, '', $act)] = (in_array($value, $choices, true) && $value !== $choices[0]) ? $value : '';
		}
	}
	if (dol_set_user_param($db, $conf, $object, $params) > 0) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
	exit;
}


/*
 * View
 */

llxHeader('', $langs->trans('DocStudioOpeningTab'), '', '', 0, 0, '', '', '', 'mod-docstudio page-user_opening');

$head = user_prepare_head($object);
print dol_get_fiche_head($head, 'docstudio', $langs->trans('User'), -1, 'user');

$linkback = '<a href="'.DOL_URL_ROOT.'/user/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'id', $linkback, $user->hasRight('user', 'user', 'lire') || $user->admin);

print '<div class="underbanner clearboth"></div>';
print '<br>';

if (empty($types[DocStudio::ACTION_VIEW]) && empty($types[DocStudio::ACTION_EDIT])) {
	print '<span class="opacitymedium">'.$langs->trans('DocStudioNoUserChoice').'</span>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('DocStudioUserChoicesHelp').'</span><br><br>';
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	foreach (array(DocStudio::ACTION_VIEW => 'DocStudioOpenBy', DocStudio::ACTION_EDIT => 'DocStudioEditedBy') as $act => $columnLabel) {
		if (empty($types[$act])) {
			continue;
		}
		print '<tr class="liste_titre"><td>'.$langs->trans('DocStudioFileType').'</td><td>'.$langs->trans($columnLabel).'</td></tr>';
		foreach ($types[$act] as $extension) {
			$handlers = $registry->getHandlersFor($extension, $act);
			$choices = $registry->getUserChoices($extension, $act);
			$current = $registry->getHandler($extension, $object, $act);
			print '<tr class="oddeven">';
			print '<td class="nowraponall"><strong>.'.dol_escape_htmltag($extension).'</strong></td>';
			print '<td><select class="flat minwidth200" name="handler['.$act.']['.dol_escape_htmltag($extension).']">';
			foreach ($choices as $index => $code) {
				$label = $handlers[$code].($index === 0 ? ' ('.$langs->trans('DocStudioAdminChoice').')' : '');
				print '<option value="'.dol_escape_htmltag($code).'"'.($code === $current ? ' selected' : '').'>'.dol_escape_htmltag($label).'</option>';
			}
			print '</select></td>';
			print '</tr>';
		}
	}
	print '</table>';
	print '</div>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
	print '</form>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
