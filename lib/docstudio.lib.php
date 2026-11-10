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
 * \file    htdocs/docstudio/lib/docstudio.lib.php
 * \ingroup docstudio
 * \brief   Library files with common functions for DocStudio
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function docstudioAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("docstudio@docstudio");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/docstudio/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath("/docstudio/admin/associations.php", 1);
	$head[$h][1] = $langs->trans("DocStudioAssociations");
	$head[$h][2] = 'associations';
	$h++;

	$head[$h][0] = dol_buildpath("/docstudio/admin/fonts.php", 1);
	$head[$h][1] = $langs->trans("DocStudioFonts");
	$head[$h][2] = 'fonts';
	$h++;

	$head[$h][0] = dol_buildpath("/docstudio/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'docstudio@docstudio');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'docstudio@docstudio', 'remove');

	return $head;
}

/**
 * Headers of the viewer page: nothing can be loaded from outside, the page can only be framed by Dolibarr itself.
 * 'wasm-unsafe-eval' lets pdf.js run its own WebAssembly decoders (JPEG 2000, JBIG2), not JavaScript eval.
 *
 * The office server (WOPI), when set, is the only other origin: framed and receiving the form that opens it.
 *
 * @param string $nonce Nonce of the inline scripts
 * @return void
 */
function docstudioSendViewerHeaders($nonce)
{
	dol_include_once('/docstudio/class/docstudiowopi.class.php');
	$office = DocStudioWopi::getServerOrigin();
	$frame = $office !== '' ? "frame-src ".$office."; form-action ".$office : "form-action 'none'";
	header('Content-Type: text/html; charset=UTF-8');
	header('Cache-Control: private, no-store');
	header('X-Content-Type-Options: nosniff');
	header('X-Frame-Options: SAMEORIGIN');
	header('Referrer-Policy: no-referrer');
	header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-".$nonce."' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' blob: data:; font-src 'self' blob: data:; connect-src 'self'; worker-src 'self' blob:; frame-ancestors 'self'; base-uri 'none'; ".$frame."; object-src 'none'");
}

/**
 * Opening of a file in the office server (WOPI) for the page: address of its page and access token, or null when the
 * office server is not one of the viewers of this file
 *
 * @param DocStudio               $registry
 * @param string                  $source
 * @param DocStudioFileProvider   $provider
 * @param array<string,mixed>     $viewers  Viewers offered on the page
 * @param string                  $code     'wopi' or 'wopiedit'
 * @param string                  $mode     'view' or 'edit'
 * @return array<string,mixed>|null
 */
function docstudioWopiConfig($registry, $source, $provider, $viewers, $code, $mode)
{
	global $conf, $langs, $user;

	if (!isset($viewers[$code])) {
		return null;
	}
	dol_include_once('/docstudio/class/docstudiowopi.class.php');
	$params = $provider->getParams();
	$fileId = DocStudioWopi::fileId($source, $params, (int) $conf->entity);
	$wopi = new DocStudioWopi();
	$url = $wopi->getActionUrl($provider->getExtension(), $mode, $fileId, (int) $conf->entity, $langs->defaultlang);
	if ($url === '') {
		return array('url' => '', 'error' => $wopi->error);
	}
	$token = DocStudioWopi::makeToken($user, (int) $conf->entity, $source, $params, $mode);

	return array(
		'url' => $url,
		'origin' => DocStudioWopi::getServerOrigin(),
		'token' => $token['token'],
		// Milliseconds since 1970 (WOPI)
		'ttl' => $token['expire'] * 1000,
	);
}

/**
 * Opening in the desktop Office for the page of a viewer or an editor: the link (ms-excel:...) and its address
 *
 * @param string                $source
 * @param DocStudioFileProvider $provider
 * @param array<string,mixed>   $viewers  Viewers offered on the page
 * @param string                $code     'desktop' or 'desktopedit'
 * @param string                $mode     'view' or 'edit'
 * @return array<string,string|int>|null
 */
function docstudioDesktopConfig($source, $provider, $viewers, $code, $mode)
{
	global $conf, $user;

	if (!isset($viewers[$code])) {
		return null;
	}
	dol_include_once('/docstudio/class/docstudiodesktop.class.php');
	$canWrite = $mode === 'edit' && $provider->canReplaceInPlace() && $provider->canWrite($user);
	$link = DocStudioDesktop::makeLink($user, (int) $conf->entity, $source, $provider->getParams(), $provider->getFileName(), $canWrite ? 'edit' : 'view', isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
	if ($link === null) {
		return null;
	}
	$link['edit'] = $canWrite && strpos($link['link'], ':ofe|') !== false ? 1 : 0;

	return $link;
}

/**
 * Headers of the raw content of a file: always a download of unknown type, never run nor shown by the browser,
 * even when its URL is opened directly or embedded by another site
 *
 * @param int $size Size in bytes
 * @return void
 */
function docstudioSendContentHeaders($size)
{
	header('Content-Type: application/octet-stream');
	header('Content-Length: '.((int) $size));
	header('Content-Disposition: attachment; filename="document.bin"');
	header('Cache-Control: private, no-store');
	header('X-Content-Type-Options: nosniff');
	header('Cross-Origin-Resource-Policy: same-origin');
	header("Content-Security-Policy: default-src 'none'; sandbox");
	header('Referrer-Policy: no-referrer');
}

/**
 * Print the JavaScript context of Dolibarr on a page without llxHeader: from the core in Dolibarr 24 and more, from
 * the module backportdolcontext in Dolibarr 23. Without it, DocStudio works the same, without the JavaScript hooks.
 *
 * @param string $nonce Nonce of the inline scripts
 * @return bool         False when the context is not available
 */
function docstudioPrintJsContext($nonce)
{
	global $langs;

	if ((int) DOL_VERSION >= 24) {
		$path = DOL_URL_ROOT.'/public/includes/dolibarr-js-context';
		$langUrl = dol_buildpath('public/langs/langs-tool-interface.php', 1);
	} elseif (isModEnabled('backportdolcontext')) {
		$path = dol_buildpath('backportdolcontext/backport/includes/dolibarr-js-context', 1);
		$langUrl = dol_buildpath('backportdolcontext/backport/langs/langs-tool-interface.php', 1);
	} else {
		return false;
	}
	$ext = 'v='.urlencode(DOL_VERSION);
	foreach (array('dolibarr-context.umd.js', 'dolibarr-tool.seteventmessage.js', 'dolibarr-tool.langs.js') as $file) {
		print '<script nonce="'.$nonce.'" src="'.dol_escape_htmltag($path.'/'.$file.'?'.$ext).'"></script>'."\n";
	}
	$context = array(
		'DOL_VERSION' => DOL_VERSION,
		'DOL_URL_ROOT' => DOL_URL_ROOT,
		'MAIN_LANG_DEFAULT' => $langs->getDefaultLang(),
		'DOL_LANG_INTERFACE_URL' => $langUrl,
	);
	print '<script nonce="'.$nonce.'">Dolibarr.setContextVars('.json_encode($context, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');</script>'."\n";

	return true;
}

/**
 * Print a block of data read by the JavaScript of DocStudio (JSON, never run)
 *
 * @param string              $id   Id of the element
 * @param array<string,mixed> $data Data
 * @return void
 */
function docstudioPrintJsonData($id, array $data)
{
	print '<script type="application/json" id="'.dol_escape_htmltag($id).'">'.json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).'</script>'."\n";
}

/**
 * Translations used by the JavaScript of DocStudio, given as data with the page
 *
 * @param string[] $keys Translation keys
 * @return array<string,string>
 */
function docstudioJsTranslations(array $keys)
{
	global $langs;

	$result = array();
	foreach ($keys as $key) {
		// %s kept for the JavaScript, which puts its own values
		$result[$key] = $langs->transnoentitiesnoconv($key, '%s', '%s', '%s', '%s');
	}

	return $result;
}

/**
 * URL of a file of a module with its modification time, so that browsers load it again after an update
 *
 * @param string $path Path relative to a module directory, '/docstudio/js/docstudio.js'
 * @return string
 */
function docstudioAssetUrl($path)
{
	$full = dol_buildpath($path, 0);

	return dol_buildpath($path, 1).'?v='.(is_file($full) ? filemtime($full) : '0');
}
