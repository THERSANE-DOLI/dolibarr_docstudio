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
 * \file    htdocs/docstudio/view.php
 * \ingroup docstudio
 * \brief   Viewer page of a file: own light page (no menu) with a strict content security policy,
 *          shown in a dialog of Dolibarr (embed=1) or in its own tab.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

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

dol_include_once('/docstudio/class/docstudio.class.php');
dol_include_once('/docstudio/lib/docstudio.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'docstudio@docstudio'));

$source = GETPOST('source', 'aZ09');
$viewerAsked = GETPOST('viewer', 'aZ09');
$embed = GETPOSTINT('embed');


/*
 * Actions
 */

if (!isModEnabled('docstudio')) {
	accessforbidden();
}

$registry = new DocStudio($db);
$error = '';
$provider = $registry->loadFromRequest($source, $user, $error);


/*
 * View
 */

$nonce = getNonce();
docstudioSendViewerHeaders($nonce);
if ($provider === null) {
	http_response_code(403);
}

$title = $provider !== null ? $provider->getFileName() : $langs->trans('DocStudio');
$viewers = $provider !== null ? $registry->getViewersFor($provider->getExtension(), $provider->getSize()) : array();
// « Open with »: the viewers the administrator allows for this type; all of them when none is allowed (direct opening
// by another module, like the attachments of a mail)
$defaultViewer = '';
if ($provider !== null) {
	$allowed = array_intersect_key($viewers, array_flip($registry->getUserChoices($provider->getExtension())));
	if (count($allowed)) {
		$viewers = $allowed;
	}
	$handler = $registry->getHandler($provider->getExtension(), $user);
	$defaultViewer = isset($viewers[$handler]) ? $handler : '';
}

$cssUrl = docstudioAssetUrl('/docstudio/css/docstudio-view.css');
// Same choice as the theme: 0 = light (default), 1 = dark, 2 = like the system
$dark = isset($user->conf->THEME_DARKMODEENABLED) ? (int) $user->conf->THEME_DARKMODEENABLED : getDolGlobalInt('THEME_DARKMODEENABLED');
// Light or dark chosen in DocStudio (switch of the status bar) wins, the theme of Dolibarr does not change
$docstudioTheme = isset($user->conf->DOCSTUDIO_THEME) ? (string) $user->conf->DOCSTUDIO_THEME : '';
if ($docstudioTheme === 'dark' || $docstudioTheme === 'light') {
	$dark = $docstudioTheme === 'dark' ? 1 : 0;
}

print '<!DOCTYPE html>'."\n";
print '<html lang="'.dol_escape_htmltag(substr($langs->defaultlang, 0, 2)).'" data-darkmode="'.((int) $dark).'"'.($embed ? ' class="docstudio-embed"' : '').'>'."\n";
print '<head>'."\n";
print '<meta charset="utf-8">'."\n";
print '<meta name="viewport" content="width=device-width, initial-scale=1">'."\n";
print '<meta name="robots" content="noindex,nofollow">'."\n";
print '<title>'.dol_escape_htmltag($title).'</title>'."\n";
print '<link rel="stylesheet" href="'.dol_escape_htmltag($cssUrl).'">'."\n";
foreach ($viewers as $viewer) {
	if ($viewer['css'] !== '') {
		print '<link rel="stylesheet" href="'.dol_escape_htmltag(docstudioAssetUrl($viewer['css'])).'">'."\n";
	}
}
docstudioPrintJsContext($nonce);
print '</head>'."\n";
print '<body>'."\n";

// Icons of the buttons (inline sprite: no request, allowed by the content security policy)
print file_get_contents(__DIR__.'/img/docstudio-icons.svg');
print '<header class="docstudio-toolbar">';
print '<span class="docstudio-filename" title="'.dol_escape_htmltag($title).'">'.dol_escape_htmltag($title).'</span>';
print '<span class="docstudio-tools" id="docstudio-tools"></span>';
if (count($viewers) > 1) {
	print '<label class="docstudio-openwith">'.dol_escape_htmltag($langs->trans('DocStudioOpenWith')).' <select id="docstudio-viewer-select">';
	foreach ($viewers as $code => $viewer) {
		print '<option value="'.dol_escape_htmltag($code).'">'.dol_escape_htmltag($viewer['label']).'</option>';
	}
	print '</select></label>';
}
if ($embed && $provider !== null) {
	$selfUrl = DocStudio::buildUrl('view.php', $source, $provider->getParams());
	print '<a class="docstudio-button" href="'.dol_escape_htmltag($selfUrl).'" target="_blank" rel="noopener" title="'.dol_escape_htmltag($langs->trans('DocStudioOpenInNewTab')).'">&#8599;</a>';
}
if ($provider !== null && $provider->getDownloadUrl() !== '') {
	print '<a class="docstudio-button" href="'.dol_escape_htmltag($provider->getDownloadUrl()).'" download>'.dol_escape_htmltag($langs->trans('Download')).'</a>';
}
print '</header>'."\n";

print '<main id="docstudio-main" class="docstudio-main" tabindex="-1">';
if ($provider === null) {
	print '<div class="docstudio-message docstudio-error">'.dol_escape_htmltag($error).'</div>';
} elseif (!count($viewers)) {
	print '<div class="docstudio-message">'.dol_escape_htmltag($langs->trans('DocStudioErrorNoViewer')).'</div>';
} else {
	print '<div class="docstudio-message" id="docstudio-loading">'.dol_escape_htmltag($langs->trans('DocStudioLoading')).'</div>';
}
print '</main>'."\n";

if ($provider !== null && count($viewers)) {
	$viewerList = array();
	foreach ($viewers as $code => $viewer) {
		$viewerList[] = array('code' => $code, 'label' => $viewer['label']);
	}
	$config = array(
		'source' => $source,
		'file' => array(
			'name' => $provider->getFileName(),
			'extension' => $provider->getExtension(),
			'size' => $provider->getSize(),
		),
		'contentUrl' => DocStudio::buildUrl('ajax/content.php', $source, $provider->getParams()),
		'viewers' => $viewerList,
		'viewerAsked' => $viewerAsked,
		'viewerDefault' => $defaultViewer,
		'imageMimeTypes' => DocStudio::getImageMimeTypes(),
		'pdfjs' => array(
			'lib' => dol_buildpath('/docstudio/vendor/pdfjs/pdf.min.js', 1),
			'worker' => dol_buildpath('/docstudio/vendor/pdfjs/pdf.worker.min.js', 1),
			'cMapUrl' => dol_buildpath('/docstudio/vendor/pdfjs/cmaps/', 1),
			'standardFontDataUrl' => dol_buildpath('/docstudio/vendor/pdfjs/standard_fonts/', 1),
			'wasmUrl' => dol_buildpath('/docstudio/vendor/pdfjs/wasm/', 1),
			'iccUrl' => dol_buildpath('/docstudio/vendor/pdfjs/iccs/', 1),
		),
		// Office server (WOPI): address of its page for this file and the token it sends back to wopi.php
		'wopi' => docstudioWopiConfig($registry, $source, $provider, $viewers, 'wopi', 'view'),
		// Office viewer: entry modules of @silurus/ooxml (its workers and WASM files are found next to them)
		'ooxml' => array(
			'docx' => dol_buildpath('/docstudio/vendor/ooxml/docx.mjs', 1),
			'xlsx' => dol_buildpath('/docstudio/vendor/ooxml/xlsx.mjs', 1),
			'pptx' => dol_buildpath('/docstudio/vendor/ooxml/pptx.mjs', 1),
		),
		'langs' => docstudioJsTranslations(array(
			'DocStudioLoading', 'DocStudioErrorLoading', 'DocStudioErrorNoViewer', 'DocStudioOpeningCancelled',
			'DocStudioZoomIn', 'DocStudioZoomOut', 'DocStudioZoomFit', 'DocStudioZoomActual', 'DocStudioPage',
			'DocStudioPdfPassword', 'DocStudioRows', 'DocStudioRowsTruncated', 'DocStudioWrapLines', 'DocStudioEncoding',
			'DocStudioThumbnails', 'DocStudioSearch', 'DocStudioSearchPrevious', 'DocStudioSearchNext', 'DocStudioSearchResults',
			'DocStudioSearchNone', 'DocStudioPrint', 'DocStudioPreparingPrint', 'DocStudioSlide', 'DocStudioSheet', 'DocStudioOfficeError',
			'DocStudioWopiUnavailable', 'DocStudioWopiSaving',
		)),
	);
	docstudioPrintJsonData('docstudio-config', $config);

	print '<script nonce="'.$nonce.'" src="'.dol_escape_htmltag(docstudioAssetUrl('/docstudio/js/docstudio.js')).'"></script>'."\n";
	foreach ($viewers as $viewer) {
		print '<script nonce="'.$nonce.'" type="module" src="'.dol_escape_htmltag(docstudioAssetUrl($viewer['script'])).'"></script>'."\n";
	}
	print '<script nonce="'.$nonce.'" type="module" src="'.dol_escape_htmltag(docstudioAssetUrl('/docstudio/js/docstudio-view.js')).'"></script>'."\n";
}

print '</body>'."\n";
print '</html>'."\n";
$db->close();
