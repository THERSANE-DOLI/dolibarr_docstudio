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
 * \file    htdocs/docstudio/class/docstudiodesktop.class.php
 * \ingroup docstudio
 * \brief   « Open in Excel / Word / PowerPoint »: the Office installed on the computer opens the file by a signed
 *          address of dav.php (small WebDAV server: read, lock, write back), with the links ms-excel:, ms-word: and
 *          ms-powerpoint: of Microsoft Office (lot 4).
 *
 * - The address holds a token of the office server tokens (same key, kind « dav »): user, entity, file, mode, time,
 *   and the address of the computer that asked for it. Office calls dav.php without the session of the browser.
 * - Locks shared with the office server (WOPI): Excel and Collabora never write the same file at the same time.
 */

dol_include_once('/docstudio/class/docstudiowopi.class.php');

/**
 * Opening in the desktop Office
 */
class DocStudioDesktop
{
	/** Scheme of the link by extension (Microsoft Office URI schemes) */
	const SCHEMES = array(
		'doc' => 'ms-word', 'docx' => 'ms-word', 'docm' => 'ms-word', 'dot' => 'ms-word', 'dotx' => 'ms-word', 'rtf' => 'ms-word', 'odt' => 'ms-word',
		'xls' => 'ms-excel', 'xlsx' => 'ms-excel', 'xlsm' => 'ms-excel', 'xlsb' => 'ms-excel', 'xlt' => 'ms-excel', 'xltx' => 'ms-excel', 'ods' => 'ms-excel', 'csv' => 'ms-excel',
		'ppt' => 'ms-powerpoint', 'pptx' => 'ms-powerpoint', 'pptm' => 'ms-powerpoint', 'pps' => 'ms-powerpoint', 'ppsx' => 'ms-powerpoint', 'pot' => 'ms-powerpoint', 'potx' => 'ms-powerpoint', 'odp' => 'ms-powerpoint',
	);

	/**
	 * Types that may hold macros: never opened by the desktop Office through a link of the server (no « Mark of the
	 * Web », the Protected View and the blocking of macros from Internet would not apply, audit DSTU-018)
	 */
	const MACRO_EXTENSIONS = array('doc', 'docm', 'dot', 'dotm', 'xls', 'xlsm', 'xlsb', 'xlt', 'xltm', 'ppt', 'pptm', 'pps', 'ppsm', 'pot', 'potm');

	/** Sources whose files come from outside (attachments received): never opened by the desktop Office */
	const EXTERNAL_SOURCES = array('inbox');

	/** Types written back: ZIP packages only, checked like the office server (no macro file: docm, xlsm, pptm) */
	const WRITABLE_EXTENSIONS = array('docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp');

	/** Types of the content sent to Office */
	const MIME_TYPES = array(
		'doc' => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls' => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt' => 'application/vnd.ms-powerpoint',
		'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'odt' => 'application/vnd.oasis.opendocument.text',
		'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
		'odp' => 'application/vnd.oasis.opendocument.presentation',
		'rtf' => 'application/rtf',
		'csv' => 'text/csv',
	);

	/**
	 * Option of the setup, and a secret to sign the addresses
	 *
	 * @return bool
	 */
	public static function isEnabled()
	{
		return getDolGlobalInt('DOCSTUDIO_DESKTOP_OFFICE') > 0 && DocStudioWopi::hasKey();
	}

	/**
	 * @param string $action 'view' or 'edit'
	 * @return string[]
	 */
	public static function getExtensions($action)
	{
		$list = $action === 'edit' ? self::WRITABLE_EXTENSIONS : array_keys(self::SCHEMES);
		$list = array_values(array_diff($list, self::MACRO_EXTENSIONS));
		sort($list);

		return $list;
	}

	/**
	 * The desktop Office may open this file: not a received attachment, not a type with macros
	 *
	 * @param string $source
	 * @param string $extension
	 * @return bool
	 */
	public static function isAllowedFor($source, $extension)
	{
		return !in_array($source, self::EXTERNAL_SOURCES, true) && !in_array(strtolower($extension), self::MACRO_EXTENSIONS, true) && isset(self::SCHEMES[strtolower($extension)]);
	}

	/**
	 * @param string $extension
	 * @return string Name of the application (Excel, Word, PowerPoint)
	 */
	public static function getApplication($extension)
	{
		$scheme = isset(self::SCHEMES[$extension]) ? self::SCHEMES[$extension] : '';

		return $scheme === 'ms-excel' ? 'Excel' : ($scheme === 'ms-powerpoint' ? 'PowerPoint' : ($scheme === 'ms-word' ? 'Word' : ''));
	}

	/**
	 * Hash of the address of the computer (in the token: the address itself is not written in it)
	 *
	 * @param string $ip
	 * @return string
	 */
	public static function ipHash($ip)
	{
		// Keyed with the secret of the tokens: not computable from outside (audit DSTU-019)
		return substr(hash_hmac('sha256', 'docstudio-dav|'.$ip, DocStudioWopi::ipKey()), 0, 16);
	}

	/**
	 * Address of dav.php seen by Office (the same as the browser)
	 *
	 * @return string
	 */
	public static function getDavUrl()
	{
		return dol_buildpath('/docstudio/dav.php', 2);
	}

	/**
	 * Link that opens the file in the desktop Office, and its address for a manual opening (File > Open)
	 *
	 * @param User                     $fuser
	 * @param int                      $entity
	 * @param string                   $source
	 * @param array<string,string|int> $params
	 * @param string                   $fileName
	 * @param string                   $mode     'view' or 'edit'
	 * @param string                   $ip       Address of the computer asking
	 * @return array{link:string,url:string,application:string,expire:int}|null Null for a type Office does not open
	 */
	public static function makeLink(User $fuser, $entity, $source, array $params, $fileName, $mode, $ip)
	{
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		if (!isset(self::SCHEMES[$extension])) {
			return null;
		}
		if ($mode === 'edit' && !in_array($extension, self::WRITABLE_EXTENSIONS, true)) {
			$mode = 'view';
		}
		$token = DocStudioWopi::makeToken($fuser, $entity, $source, $params, $mode, array('k' => 'dav', 'i' => self::ipHash($ip)));
		// Name of the file last, no query string: Office shows the name and finds the type from it
		$url = self::getDavUrl().'/'.((int) $entity).'/'.$token['token'].'/'.rawurlencode($fileName);
		// ofe: open for editing, ofv: open for viewing (read only)
		$link = self::SCHEMES[$extension].':'.($mode === 'edit' ? 'ofe' : 'ofv').'|u|'.$url;

		return array('link' => $link, 'url' => $url, 'application' => self::getApplication($extension), 'expire' => $token['expire']);
	}

	/**
	 * Lock of WebDAV in the store of the office server locks
	 *
	 * @return string opaquelocktoken:...
	 */
	public static function newLockToken()
	{
		$hex = bin2hex(random_bytes(16));

		return 'opaquelocktoken:'.substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
	}

	/**
	 * Lock tokens given in the headers If and Lock-Token of a request
	 *
	 * @param string $header
	 * @return string[]
	 */
	public static function parseLockTokens($header)
	{
		preg_match_all('/<(opaquelocktoken:[a-f0-9-]{36})>/i', (string) $header, $m);

		return array_map('strtolower', $m[1]);
	}

	/**
	 * Date of HTTP (RFC 1123)
	 *
	 * @param int $time
	 * @return string
	 */
	public static function httpDate($time)
	{
		return gmdate('D, d M Y H:i:s', $time).' GMT';
	}

	/**
	 * Answer of PROPFIND: the folder of the token (holding only the file) or the file
	 *
	 * @param string $folderHref Path of the folder (encoded)
	 * @param string $fileName
	 * @param int    $size
	 * @param int    $modified
	 * @param string $etag
	 * @param bool   $isFolder   The folder is asked
	 * @param int    $depth      0 or 1
	 * @param string $lock       Lock in place ('' when none)
	 * @param bool   $canWrite
	 * @param bool   $showLockToken The token of the lock is given (to its holder only)
	 * @return string XML
	 */
	public static function propfindXml($folderHref, $fileName, $size, $modified, $etag, $isFolder, $depth, $lock, $canWrite, $showLockToken = true)
	{
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		$mime = isset(self::MIME_TYPES[$extension]) ? self::MIME_TYPES[$extension] : 'application/octet-stream';
		$esc = function ($value) {
			return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
		};
		$xml = '<?xml version="1.0" encoding="utf-8"?>'."\n".'<D:multistatus xmlns:D="DAV:">';
		$folder = '<D:response><D:href>'.$esc($folderHref).'</D:href><D:propstat><D:prop>'
			.'<D:displayname></D:displayname><D:resourcetype><D:collection/></D:resourcetype>'
			.'<D:getlastmodified>'.$esc(self::httpDate($modified)).'</D:getlastmodified>'
			.'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
		$lockXml = '';
		if ($lock !== '') {
			$lockXml = '<D:activelock><D:locktype><D:write/></D:locktype><D:lockscope><D:exclusive/></D:lockscope>'
				.'<D:depth>0</D:depth><D:timeout>Second-'.DocStudioWopi::LOCK_TTL.'</D:timeout>'
				.($showLockToken ? '<D:locktoken><D:href>'.$esc($lock).'</D:href></D:locktoken>' : '').'</D:activelock>';
		}
		$file = '<D:response><D:href>'.$esc($folderHref.rawurlencode($fileName)).'</D:href><D:propstat><D:prop>'
			.'<D:displayname>'.$esc($fileName).'</D:displayname><D:resourcetype/>'
			.'<D:getcontentlength>'.((int) $size).'</D:getcontentlength>'
			.'<D:getcontenttype>'.$esc($mime).'</D:getcontenttype>'
			.'<D:getlastmodified>'.$esc(self::httpDate($modified)).'</D:getlastmodified>'
			.'<D:creationdate>'.$esc(gmdate('Y-m-d\TH:i:s\Z', $modified)).'</D:creationdate>'
			.'<D:getetag>'.$esc('"'.$etag.'"').'</D:getetag>'
			// Read only: no lock offered, Office opens it read only
			.'<D:supportedlock>'.($canWrite ? '<D:lockentry><D:lockscope><D:exclusive/></D:lockscope><D:locktype><D:write/></D:locktype></D:lockentry>' : '').'</D:supportedlock>'
			.'<D:lockdiscovery>'.$lockXml.'</D:lockdiscovery>'
			.'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>';
		if ($isFolder) {
			$xml .= $folder.($depth > 0 ? $file : '');
		} else {
			$xml .= $file;
		}

		return $xml.'</D:multistatus>';
	}
}
