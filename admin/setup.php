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
dol_include_once('/docstudio/class/docstudiopdftools.class.php');
dol_include_once('/docstudio/class/docstudiowopi.class.php');

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

// Server tools of the PDF editor: Stirling PDF and Ghostscript (optional)
if ($action == 'updatetools') {
	$url = trim(GETPOST('DOCSTUDIO_STIRLING_URL', 'alphanohtml'));
	$gs = trim(GETPOST('DOCSTUDIO_GHOSTSCRIPT_PATH', 'alphanohtml'));
	$apiKey = GETPOST('DOCSTUDIO_STIRLING_API_KEY', 'none');
	$error = 0;
	if ($url !== '' && !preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
		setEventMessages($langs->trans('DocStudioStirlingBadUrl'), null, 'errors');
		$error++;
	}
	// A path only (no options nor shell characters): the command is built by DocStudio
	if ($gs !== '' && !preg_match('/^[A-Za-z0-9_\/\\.:\- ]+$/', $gs)) {
		setEventMessages($langs->trans('DocStudioGhostscriptBadPath'), null, 'errors');
		$error++;
	}
	if (!$error) {
		dolibarr_set_const($db, 'DOCSTUDIO_STIRLING_URL', $url, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOCSTUDIO_GHOSTSCRIPT_PATH', $gs, 'chaine', 0, '', $conf->entity);
		// Empty field: the key saved stays (it is never shown again); box ticked: removed
		if (GETPOST('DOCSTUDIO_STIRLING_API_KEY_REMOVE', 'int')) {
			dolibarr_del_const($db, 'DOCSTUDIO_STIRLING_API_KEY', $conf->entity);
		} elseif (is_string($apiKey) && trim($apiKey) !== '') {
			// Name ending with _KEY: stored encrypted by dolibarr_set_const
			dolibarr_set_const($db, 'DOCSTUDIO_STIRLING_API_KEY', trim($apiKey), 'chaine', 0, '', $conf->entity);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#docstudio-tools');
	exit;
}

// Office server (WOPI)
if ($action == 'updatewopi') {
	$url = trim(GETPOST('DOCSTUDIO_WOPI_URL', 'alphanohtml'));
	$hostUrl = trim(GETPOST('DOCSTUDIO_WOPI_HOST_URL', 'alphanohtml'));
	$ips = trim(GETPOST('DOCSTUDIO_WOPI_ALLOWED_IPS', 'alphanohtml'));
	$badIp = '';
	foreach (preg_split('/[\s,;]+/', $ips, -1, PREG_SPLIT_NO_EMPTY) as $ip) {
		$base = strpos($ip, '/') !== false ? substr($ip, 0, strpos($ip, '/')) : $ip;
		if (!filter_var($base, FILTER_VALIDATE_IP) || (strpos($ip, '/') !== false && (!filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !preg_match('#/(\d|[12]\d|3[0-2])$#', $ip)))) {
			$badIp = $ip;
			break;
		}
	}
	if (($url !== '' && !preg_match('/^https?:\/\/[^\s]+$/i', $url)) || ($hostUrl !== '' && !preg_match('/^https?:\/\/[^\s]+$/i', $hostUrl))) {
		setEventMessages($langs->trans('DocStudioWopiBadUrl'), null, 'errors');
	} elseif ($badIp !== '') {
		setEventMessages($langs->trans('DocStudioWopiBadIp', $badIp), null, 'errors');
	} else {
		dolibarr_set_const($db, 'DOCSTUDIO_WOPI_ALLOWED_IPS', $ips, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOCSTUDIO_WOPI_URL', $url, 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'DOCSTUDIO_WOPI_HOST_URL', $hostUrl, 'chaine', 0, '', $conf->entity);
		if ($url !== '') {
			// Types of the office server read now, then kept one hour
			$wopi = new DocStudioWopi();
			$wopi->getDiscovery(true);
		}
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF'].'#docstudio-wopi');
	exit;
}

// Test of the tools, from the values saved
$testResults = array();
if ($action == 'testwopi' && DocStudioWopi::isEnabled()) {
	$wopi = new DocStudioWopi();
	$discovery = $wopi->getDiscovery(true);
	if (count($discovery)) {
		$version = $wopi->getServerVersion();
		$testResults['wopi'] = array(true, $langs->transnoentitiesnoconv('DocStudioWopiOk', $version !== '' ? $version : '?', implode(', ', DocStudioWopi::getExtensions('edit')), count(DocStudioWopi::getExtensions('view'))));
		// Signature of the calls (proof keys): said, with what to do when the office server has none
		$testResults['wopiproof'] = count($wopi->getProofKeys()) ? array(true, $langs->transnoentitiesnoconv('DocStudioWopiProofOn')) : array(false, $langs->transnoentitiesnoconv('DocStudioWopiProofOff'));
		if (!count($wopi->getProofKeys())) {
			// Key removed on purpose by the administrator, seen by this test: no longer required by wopi.php
			dolibarr_del_const($db, 'DOCSTUDIO_WOPI_PROOF_SEEN', $conf->entity);
		}
	} else {
		$testResults['wopi'] = array(false, $langs->transnoentitiesnoconv('DocStudioWopiKo', $wopi->error));
	}
}
if ($action == 'testtools') {
	$tools = new DocStudioPdfTools();
	if (DocStudioPdfTools::hasStirling()) {
		$version = $tools->getStirlingVersion();
		$testResults['stirling'] = $version !== '' ? array(true, $langs->transnoentitiesnoconv('DocStudioStirlingOk', $version)) : array(false, $langs->transnoentitiesnoconv('DocStudioStirlingKo', $tools->error));
	}
	if (DocStudioPdfTools::hasGhostscript()) {
		$tools->error = '';
		$version = $tools->getGhostscriptVersion();
		$testResults['ghostscript'] = $version !== '' ? array(true, $langs->transnoentitiesnoconv('DocStudioGhostscriptOk', $version)) : array(false, $langs->transnoentitiesnoconv('DocStudioGhostscriptKo', $tools->error));
	}
}


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

// Server tools of the PDF editor
print '<a id="docstudio-tools"></a>';
print load_fiche_titre($langs->trans("DocStudioServerTools"), '', '');
print '<p class="opacitymedium">'.$langs->trans('DocStudioServerToolsHelp').'</p>';
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="updatetools">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
print '<tr class="oddeven"><td><label for="DOCSTUDIO_GHOSTSCRIPT_PATH">'.$langs->trans('DocStudioGhostscriptPath').'</label><br><span class="opacitymedium small">'.$langs->trans('DocStudioGhostscriptPathHelp').'</span></td>';
print '<td><input type="text" class="minwidth300" id="DOCSTUDIO_GHOSTSCRIPT_PATH" name="DOCSTUDIO_GHOSTSCRIPT_PATH" value="'.dol_escape_htmltag(getDolGlobalString('DOCSTUDIO_GHOSTSCRIPT_PATH')).'" placeholder="/usr/bin/gs" autocomplete="off" spellcheck="false"></td></tr>';
print '<tr class="oddeven"><td><label for="DOCSTUDIO_STIRLING_URL">'.$langs->trans('DocStudioStirlingUrl').'</label><br><span class="opacitymedium small">'.$langs->trans('DocStudioStirlingUrlHelp').'</span></td>';
print '<td><input type="url" class="minwidth300" id="DOCSTUDIO_STIRLING_URL" name="DOCSTUDIO_STIRLING_URL" value="'.dol_escape_htmltag(getDolGlobalString('DOCSTUDIO_STIRLING_URL')).'" placeholder="http://127.0.0.1:8080" autocomplete="off" spellcheck="false"></td></tr>';
$hasKey = getDolGlobalString('DOCSTUDIO_STIRLING_API_KEY') !== '';
print '<tr class="oddeven"><td><label for="DOCSTUDIO_STIRLING_API_KEY">'.$langs->trans('DocStudioStirlingApiKey').'</label><br><span class="opacitymedium small">'.$langs->trans('DocStudioStirlingApiKeyHelp').'</span></td>';
print '<td><input type="password" class="minwidth300" id="DOCSTUDIO_STIRLING_API_KEY" name="DOCSTUDIO_STIRLING_API_KEY" value="" autocomplete="new-password" placeholder="'.dol_escape_htmltag($hasKey ? $langs->trans('DocStudioStirlingApiKeySaved') : '').'">';
if ($hasKey) {
	print ' <label class="nowraponall"><input type="checkbox" name="DOCSTUDIO_STIRLING_API_KEY_REMOVE" value="1"> '.$langs->trans('DocStudioStirlingApiKeyRemove').'</label>';
}
print '</td></tr>';
print '</table>';
print '</div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
if (DocStudioPdfTools::canCompress()) {
	print ' <a class="button button-cancel" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?action=testtools&token='.newToken().'#docstudio-tools').'">'.$langs->trans('DocStudioTestTools').'</a>';
}
print '</div>';
print '</form>';
foreach ($testResults as $key => $res) {
	if ($key === 'wopi' || $key === 'wopiproof') {
		continue;
	}
	print '<div class="'.($res[0] ? 'ok' : 'error').' margintoponly">'.img_picto('', $res[0] ? 'tick' : 'error', 'class="pictofixedwidth"').dol_escape_htmltag($res[1]).'</div>';
}
// Office server (WOPI)
print '<br><a id="docstudio-wopi"></a>';
print load_fiche_titre($langs->trans("DocStudioWopiServer"), '', '');
print '<p class="opacitymedium">'.$langs->trans('DocStudioWopiServerHelp').'</p>';
if (!DocStudioWopi::hasKey()) {
	// No secret to sign the tokens (audit DSTU-011): office server and desktop Office stay off
	print info_admin($langs->trans('DocStudioNoInstanceKey'), 0, 0, 'error');
}
print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="updatewopi">';
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
print '<tr class="oddeven"><td><label for="DOCSTUDIO_WOPI_URL">'.$langs->trans('DocStudioWopiUrl').'</label><br><span class="opacitymedium small">'.$langs->trans('DocStudioWopiUrlHelp').'</span></td>';
print '<td><input type="url" class="minwidth300" id="DOCSTUDIO_WOPI_URL" name="DOCSTUDIO_WOPI_URL" value="'.dol_escape_htmltag(getDolGlobalString('DOCSTUDIO_WOPI_URL')).'" placeholder="https://office.example.com" autocomplete="off" spellcheck="false"></td></tr>';
print '<tr class="oddeven"><td><label for="DOCSTUDIO_WOPI_HOST_URL">'.$langs->trans('DocStudioWopiHostUrl').'</label><br><span class="opacitymedium small">'.$langs->trans('DocStudioWopiHostUrlHelp', dol_escape_htmltag(DocStudioWopi::getHostUrl())).'</span></td>';
print '<td><input type="url" class="minwidth300" id="DOCSTUDIO_WOPI_HOST_URL" name="DOCSTUDIO_WOPI_HOST_URL" value="'.dol_escape_htmltag(getDolGlobalString('DOCSTUDIO_WOPI_HOST_URL')).'" placeholder="'.dol_escape_htmltag(DOL_MAIN_URL_ROOT).'" autocomplete="off" spellcheck="false"></td></tr>';
print '<tr class="oddeven"><td><label for="DOCSTUDIO_WOPI_ALLOWED_IPS">'.$langs->trans('DocStudioWopiAllowedIps').'</label><br><span class="opacitymedium small">'.$langs->trans('DocStudioWopiAllowedIpsHelp').'</span></td>';
print '<td><input type="text" class="minwidth300" id="DOCSTUDIO_WOPI_ALLOWED_IPS" name="DOCSTUDIO_WOPI_ALLOWED_IPS" value="'.dol_escape_htmltag(getDolGlobalString('DOCSTUDIO_WOPI_ALLOWED_IPS')).'" placeholder="203.0.113.10, 10.0.0.0/8" autocomplete="off" spellcheck="false"></td></tr>';
print '</table>';
print '</div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
if (DocStudioWopi::isEnabled()) {
	print ' <a class="button button-cancel" href="'.dol_escape_htmltag($_SERVER['PHP_SELF'].'?action=testwopi&token='.newToken().'#docstudio-wopi').'">'.$langs->trans('DocStudioTestTools').'</a>';
}
print '</div>';
print '</form>';
foreach (array('wopi', 'wopiproof') as $key) {
	if (isset($testResults[$key])) {
		$res = $testResults[$key];
		// No proof key: a warning, the office server still works with the tokens
		$warn = $key === 'wopiproof' && !$res[0];
		print '<div class="'.($res[0] ? 'ok' : ($warn ? 'warning' : 'error')).' margintoponly">'.img_picto('', $res[0] ? 'tick' : ($warn ? 'warning' : 'error'), 'class="pictofixedwidth"').dol_escape_htmltag($res[1]).'</div>';
		unset($testResults[$key]);
	}
}

// Office installed on the computer (« Open in Excel »)
print '<br><a id="docstudio-desktop"></a>';
print load_fiche_titre($langs->trans("DocStudioDesktopSetup"), '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td class="right">'.$langs->trans("Status").'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DocStudioDesktopEnable').'<br><span class="opacitymedium small">'.$langs->trans('DocStudioDesktopEnableHelp', dol_escape_htmltag(dol_buildpath('/docstudio/dav.php', 2))).'</span></td>';
print '<td class="right">'.ajax_constantonoff('DOCSTUDIO_DESKTOP_OFFICE', array(), $conf->entity).'</td></tr>';
print '</table>';
print '</div>';
if (getDolGlobalInt('DOCSTUDIO_DESKTOP_OFFICE') && strpos(dol_buildpath('/', 2), 'https://') !== 0) {
	print info_admin($langs->trans('DocStudioDesktopHttps'), 0, 0, 'warning');
}

// What redaction really does, written where the tools are chosen (analysis 5.1)
print '<br>';
print info_admin($langs->trans('DocStudioRedactionSetupHelp'), 0, 0, 'info');
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
