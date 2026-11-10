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
 * \file    htdocs/docstudio/class/docstudiowopi.class.php
 * \ingroup docstudio
 * \brief   DocStudio as a WOPI host for an office server (Collabora Online, OnlyOffice, Euro-Office) (lot 4).
 *
 * - Discovery of the office server (/hosting/discovery): what it opens, by extension and action (view, edit), cached.
 * - Access tokens: user, entity, file and mode signed with HMAC-SHA256 (key derived from the unique id of the
 *   instance), limited in time. The office server sends the token back on every call of wopi.php; the file is loaded
 *   again with the rights of the user at each call, the token only says who and what.
 * - Locks of WOPI, kept in files of the work directory (30 minutes, refreshed by the office server).
 */

/**
 * WOPI host of DocStudio
 */
class DocStudioWopi
{
	/** Life of an access token, in seconds (a session of work in the office server) */
	const TOKEN_TTL = 36000;

	/** Life of a lock of WOPI, in seconds */
	const LOCK_TTL = 1800;

	/** Life of the discovery cached, in seconds */
	const DISCOVERY_TTL = 3600;

	/** Types opened when the discovery cannot be read (most office servers open them) */
	const DEFAULT_EXTENSIONS = array('docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'doc', 'xls', 'ppt', 'rtf');

	/**
	 * Office documents only: the office server also opens PDF, images or text, better shown by the viewers of DocStudio
	 */
	const OFFICE_EXTENSIONS = array(
		'doc', 'docx', 'docm', 'dot', 'dotx', 'odt', 'ott', 'rtf', 'fodt',
		'xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'ods', 'ots', 'fods',
		'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'pot', 'potx', 'odp', 'otp', 'fodp',
		'odg', 'vsd', 'vsdx',
	);

	/** Types the office server may write back (Office Open XML and OpenDocument: ZIP packages) */
	const WRITABLE_EXTENSIONS = array('docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp');

	/** @var string */
	public $error = '';

	/**
	 * @return string Address of the office server, '' when not set
	 */
	public static function getServerUrl()
	{
		$url = trim(getDolGlobalString('DOCSTUDIO_WOPI_URL'));

		return preg_match('/^https?:\/\/[^\s\/]+(\/[^\s]*)?$/i', $url) ? rtrim($url, '/') : '';
	}

	/**
	 * @return bool
	 */
	public static function isEnabled()
	{
		return self::getServerUrl() !== '';
	}

	/**
	 * Origin of the office server (scheme, host, port), for the content security policy
	 *
	 * @return string
	 */
	public static function getServerOrigin()
	{
		$url = self::getServerUrl();
		if ($url === '' || !preg_match('/^(https?:\/\/[^\/]+)/i', $url, $m)) {
			return '';
		}

		return $m[1];
	}

	/**
	 * Address of wopi.php seen by the office server (it may differ from the one of the browser)
	 *
	 * @return string
	 */
	public static function getHostUrl()
	{
		$base = trim(getDolGlobalString('DOCSTUDIO_WOPI_HOST_URL'));
		if (preg_match('/^https?:\/\/[^\s]+$/i', $base)) {
			return rtrim($base, '/').'/custom/docstudio/wopi.php';
		}

		return dol_buildpath('/docstudio/wopi.php', 2);
	}

	/* Discovery */

	/**
	 * @return string
	 */
	protected static function cacheFile()
	{
		return DOL_DATA_ROOT.'/docstudio/temp/wopi-discovery-'.dol_hash(self::getServerUrl(), 'md5').'.json';
	}

	/**
	 * Actions of the office server by extension: array(ext => array('view' => urlsrc, 'edit' => urlsrc))
	 *
	 * @param bool $refresh Read the office server again
	 * @return array<string,array<string,string>> Empty array with $this->error when it cannot be read
	 */
	public function getDiscovery($refresh = false)
	{
		if (!self::isEnabled()) {
			$this->error = 'Not set';
			return array();
		}
		$cache = self::cacheFile();
		if (!$refresh && is_readable($cache) && filemtime($cache) > dol_now() - self::DISCOVERY_TTL) {
			$data = json_decode((string) file_get_contents($cache), true);
			if (is_array($data)) {
				return $data;
			}
		}
		$xml = $this->httpGet(self::getServerUrl().'/hosting/discovery');
		if ($xml === '') {
			return array();
		}
		$actions = self::parseDiscovery($xml);
		if (!count($actions)) {
			$this->error = 'Discovery without actions';
			return array();
		}
		if (dol_mkdir(dirname($cache)) >= 0) {
			@file_put_contents($cache, json_encode($actions));
		}

		return $actions;
	}

	/**
	 * @param string $xml Content of /hosting/discovery
	 * @return array<string,array<string,string>>
	 */
	public static function parseDiscovery($xml)
	{
		$previous = libxml_use_internal_errors(true);
		// No entity nor DTD from a document of the network
		$doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
		libxml_use_internal_errors($previous);
		$result = array();
		if ($doc === false) {
			return $result;
		}
		foreach ($doc->xpath('//action') ?: array() as $action) {
			$ext = strtolower((string) $action['ext']);
			$name = strtolower((string) $action['name']);
			$urlsrc = (string) $action['urlsrc'];
			if (!preg_match('/^[a-z0-9]{1,8}$/', $ext) || !preg_match('/^https?:\/\//i', $urlsrc)) {
				continue;
			}
			if ($name === 'view' || $name === 'edit') {
				$result[$ext][$name] = $urlsrc;
			}
		}

		return $result;
	}

	/**
	 * Extensions opened for an action, from the cached discovery only (no call to the network on every page)
	 *
	 * @param string $action 'view' or 'edit'
	 * @return string[]
	 */
	public static function getExtensions($action)
	{
		$cache = self::cacheFile();
		$data = is_readable($cache) ? json_decode((string) file_get_contents($cache), true) : null;
		if (!is_array($data)) {
			$list = self::DEFAULT_EXTENSIONS;
		} else {
			$list = array();
			foreach ($data as $ext => $actions) {
				if (isset($actions[$action]) || ($action === 'view' && isset($actions['edit']))) {
					$list[] = (string) $ext;
				}
			}
		}
		$list = array_values(array_intersect($list, $action === 'edit' ? self::WRITABLE_EXTENSIONS : self::OFFICE_EXTENSIONS));
		sort($list);

		return $list;
	}

	/**
	 * Address to open in the frame: urlsrc of the discovery, its placeholders removed, with WOPISrc and the language
	 *
	 * @param string $extension
	 * @param string $action    'view' or 'edit'
	 * @param string $fileId
	 * @param int    $entity
	 * @param string $lang      fr_FR...
	 * @return string '' when the office server cannot open this type
	 */
	public function getActionUrl($extension, $action, $fileId, $entity, $lang)
	{
		$discovery = $this->getDiscovery();
		$urlsrc = '';
		if (isset($discovery[$extension][$action])) {
			$urlsrc = $discovery[$extension][$action];
		} elseif ($action === 'view' && isset($discovery[$extension]['edit'])) {
			$urlsrc = $discovery[$extension]['edit'];
		}
		if ($urlsrc === '') {
			return '';
		}
		// Optional parameters of the discovery: <name=PLACEHOLDER&>, removed (WOPISrc and lang added below)
		$urlsrc = preg_replace('/<[^>]*>/', '', $urlsrc);
		$urlsrc = rtrim($urlsrc, '?&');
		$src = self::getHostUrl().'/files/'.$fileId.'?entity='.((int) $entity);

		return $urlsrc.(strpos($urlsrc, '?') === false ? '?' : '&').'WOPISrc='.rawurlencode($src).'&lang='.rawurlencode(str_replace('_', '-', $lang));
	}

	/**
	 * Version and name of the office server, for the test of the setup
	 *
	 * @return string '' with $this->error when it cannot be read
	 */
	public function getServerVersion()
	{
		$body = $this->httpGet(self::getServerUrl().'/hosting/capabilities');
		$json = json_decode($body, true);
		if (is_array($json) && !empty($json['productVersion'])) {
			return trim((isset($json['productName']) ? $json['productName'].' ' : '').$json['productVersion']);
		}

		return '';
	}

	/**
	 * @param string $url
	 * @return string Body, '' with $this->error
	 */
	protected function httpGet($url)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		if ($body === false || $code !== 200) {
			$this->error = $body === false ? $curlError : 'HTTP '.$code;
			return '';
		}

		return (string) $body;
	}

	/* Files and tokens */

	/**
	 * Id of a file for the office server: same file, same id (several users edit the same document together)
	 *
	 * @param string                   $source
	 * @param array<string,string|int> $params
	 * @param int                      $entity
	 * @return string 32 hexadecimal characters
	 */
	public static function fileId($source, array $params, $entity)
	{
		ksort($params);

		return substr(hash_hmac('sha256', $source.'|'.json_encode($params).'|'.((int) $entity), self::key()), 0, 32);
	}

	/**
	 * @return string Secret of the tokens, never stored nor sent
	 */
	protected static function key()
	{
		global $dolibarr_main_instance_unique_id, $conf;

		$unique = !empty($dolibarr_main_instance_unique_id) ? $dolibarr_main_instance_unique_id : (isset($conf->file->instance_unique_id) ? $conf->file->instance_unique_id : '');

		return hash('sha256', 'docstudio-wopi|'.$unique, true);
	}

	/**
	 * @param string $data
	 * @return string
	 */
	protected static function b64($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	/**
	 * Access token for the office server
	 *
	 * @param User                     $fuser
	 * @param int                      $entity
	 * @param string                   $source
	 * @param array<string,string|int> $params
	 * @param string                   $mode   'view' or 'edit'
	 * @return array{token:string,expire:int}
	 */
	public static function makeToken(User $fuser, $entity, $source, array $params, $mode)
	{
		$expire = dol_now() + self::TOKEN_TTL;
		$payload = self::b64(json_encode(array(
			'u' => (int) $fuser->id,
			'e' => (int) $entity,
			's' => (string) $source,
			'p' => $params,
			'm' => $mode === 'edit' ? 'edit' : 'view',
			'x' => $expire,
			'f' => self::fileId($source, $params, $entity),
		)));

		return array('token' => $payload.'.'.self::b64(hash_hmac('sha256', $payload, self::key(), true)), 'expire' => $expire);
	}

	/**
	 * @param string $token
	 * @param string $fileId Id of the file of the request
	 * @return array<string,mixed>|null Content of the token, null if not valid, expired or for another file
	 */
	public static function checkToken($token, $fileId)
	{
		if (!is_string($token) || strlen($token) > 4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $m)) {
			return null;
		}
		if (!hash_equals(self::b64(hash_hmac('sha256', $m[1], self::key(), true)), $m[2])) {
			return null;
		}
		$data = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
		if (!is_array($data) || empty($data['u']) || empty($data['x']) || (int) $data['x'] < dol_now()
			|| !isset($data['f']) || !hash_equals((string) $data['f'], (string) $fileId) || !isset($data['p']) || !is_array($data['p'])) {
			return null;
		}

		return $data;
	}

	/* Locks */

	/**
	 * @param string $fileId
	 * @return string
	 */
	protected static function lockFile($fileId)
	{
		return DOL_DATA_ROOT.'/docstudio/temp/wopi-lock-'.preg_replace('/[^a-f0-9]/', '', $fileId).'.json';
	}

	/**
	 * @param string $fileId
	 * @return string Lock in place, '' when none (or expired)
	 */
	public static function getLock($fileId)
	{
		$file = self::lockFile($fileId);
		if (!is_readable($file)) {
			return '';
		}
		$data = json_decode((string) file_get_contents($file), true);
		if (!is_array($data) || empty($data['lock']) || (int) $data['expire'] < dol_now()) {
			return '';
		}

		return (string) $data['lock'];
	}

	/**
	 * @param string $fileId
	 * @param string $lock   '' removes the lock
	 * @return bool
	 */
	public static function setLock($fileId, $lock)
	{
		$file = self::lockFile($fileId);
		if ($lock === '') {
			return !is_file($file) || @unlink($file);
		}
		if (dol_mkdir(dirname($file)) < 0) {
			return false;
		}

		return @file_put_contents($file, json_encode(array('lock' => $lock, 'expire' => dol_now() + self::LOCK_TTL)), LOCK_EX) !== false;
	}

	/**
	 * Content written back by the office server: a ZIP package (Office Open XML, OpenDocument) of the type of the file
	 *
	 * @param string $extension
	 * @param string $file
	 * @return bool
	 */
	public static function isValidContent($extension, $file)
	{
		if (!in_array($extension, self::WRITABLE_EXTENSIONS, true)) {
			return false;
		}
		$head = (string) @file_get_contents($file, false, null, 0, 4);
		if ($head !== "PK\x03\x04") {
			return false;
		}
		if (!class_exists('ZipArchive')) {
			return true;
		}
		$zip = new ZipArchive();
		if ($zip->open($file) !== true) {
			return false;
		}
		$isOdf = in_array($extension, array('odt', 'ods', 'odp'), true);
		$ok = $isOdf ? $zip->locateName('mimetype') !== false : $zip->locateName('[Content_Types].xml') !== false;
		$zip->close();

		return $ok;
	}
}
