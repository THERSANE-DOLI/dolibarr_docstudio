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
 * - Proof keys of WOPI: when the discovery gives a public key, every call must be signed by the office server
 *   (X-WOPI-Proof over the token, the address and the time); optional list of the addresses allowed to call.
 */

/**
 * WOPI host of DocStudio
 */
class DocStudioWopi
{
	/**
	 * Life of an access token by default, in seconds (a session of work): 4 hours, DOCSTUDIO_WOPI_TOKEN_HOURS (1 to 24)
	 * changes it (audit DSTU-014)
	 */
	const TOKEN_TTL = 14400;

	/** Life of a lock of WOPI, in seconds */
	const LOCK_TTL = 1800;

	/** Life of the discovery cached, in seconds */
	const DISCOVERY_TTL = 3600;

	/** Biggest gap between the time of a signed call and now, in seconds (WOPI: 20 minutes) */
	const PROOF_MAX_AGE = 1200;

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
		return self::getServerUrl() !== '' && self::hasKey();
	}

	/**
	 * The instance has a secret to sign the tokens: unique id of conf.php long enough (a missing one would give a key
	 * known by everyone, so the office server and the opening in the desktop Office are refused)
	 *
	 * @return bool
	 */
	public static function hasKey()
	{
		return strlen(self::instanceSecret()) >= 16;
	}

	/**
	 * @return string Unique id of the instance (conf.php), '' when missing
	 */
	protected static function instanceSecret()
	{
		global $dolibarr_main_instance_unique_id, $conf;

		$unique = !empty($dolibarr_main_instance_unique_id) ? $dolibarr_main_instance_unique_id : (isset($conf->file->instance_unique_id) ? $conf->file->instance_unique_id : '');

		return trim((string) $unique);
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
		$data = $this->loadDiscovery($refresh);

		return $data['actions'];
	}

	/**
	 * Discovery cached or read again: array('actions' => ..., 'proof' => list of public keys)
	 *
	 * @param bool $refresh
	 * @return array{actions:array<string,array<string,string>>,proof:array<int,array{modulus:string,exponent:string}>}
	 */
	protected function loadDiscovery($refresh)
	{
		$empty = array('actions' => array(), 'proof' => array());
		$cache = self::cacheFile();
		if (!$refresh && is_readable($cache) && filemtime($cache) > dol_now() - self::DISCOVERY_TTL) {
			$data = json_decode((string) file_get_contents($cache), true);
			if (is_array($data) && isset($data['actions'])) {
				return $data + $empty;
			}
		}
		$xml = $this->httpGet(self::getServerUrl().'/hosting/discovery');
		$data = $xml !== '' ? array('actions' => self::parseDiscovery($xml), 'proof' => self::parseProofKeys($xml)) : $empty;
		if ($xml !== '' && !count($data['actions'])) {
			$this->error = 'Discovery without actions';
		}
		if (!count($data['actions'])) {
			// Office server not answering (restart, network): the last discovery read stays the reference, so its
			// proof keys are still required
			$old = is_readable($cache) ? json_decode((string) file_get_contents($cache), true) : null;
			if (!$refresh && is_array($old) && isset($old['actions'])) {
				return $old + $empty;
			}
			return $empty;
		}
		if (dol_mkdir(dirname($cache)) >= 0) {
			@file_put_contents($cache, json_encode($data));
		}
		// Remembered: once the office server has signed its calls, a discovery without key is refused (wopi.php)
		if (count($data['proof']) && getDolGlobalString('DOCSTUDIO_WOPI_PROOF_SEEN') !== self::serverHash()) {
			global $conf;
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			dolibarr_set_const($GLOBALS['db'], 'DOCSTUDIO_WOPI_PROOF_SEEN', self::serverHash(), 'chaine', 0, '', $conf->entity);
		}

		return $data;
	}

	/**
	 * @return string Hash of the address of the office server (a new server starts without remembered key)
	 */
	public static function serverHash()
	{
		return dol_hash(self::getServerUrl(), 'md5');
	}

	/**
	 * The office server of the setup has already given proof keys: its calls must stay signed
	 *
	 * @return bool
	 */
	public static function isProofExpected()
	{
		return getDolGlobalString('DOCSTUDIO_WOPI_PROOF_SEEN') === self::serverHash();
	}

	/**
	 * Public keys of the office server (current, then old one during a change of key)
	 *
	 * @param bool $refresh
	 * @return array<int,array{modulus:string,exponent:string}>
	 */
	public function getProofKeys($refresh = false)
	{
		$data = $this->loadDiscovery($refresh);

		return $data['proof'];
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
	 * @param string $xml Content of /hosting/discovery
	 * @return array<int,array{modulus:string,exponent:string}>
	 */
	public static function parseProofKeys($xml)
	{
		$previous = libxml_use_internal_errors(true);
		$doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
		libxml_use_internal_errors($previous);
		$keys = array();
		if ($doc === false) {
			return $keys;
		}
		foreach ($doc->xpath('//proof-key') ?: array() as $key) {
			foreach (array('', 'old') as $prefix) {
				$modulus = (string) $key[$prefix.'modulus'];
				$exponent = (string) $key[$prefix.'exponent'];
				if (preg_match('/^[A-Za-z0-9+\/=]{100,}$/', $modulus) && preg_match('/^[A-Za-z0-9+\/=]{2,16}$/', $exponent)) {
					$keys[] = array('modulus' => $modulus, 'exponent' => $exponent);
				}
			}
		}

		return $keys;
	}

	/**
	 * Public key in PEM from the modulus and the exponent of the discovery (base64, big endian)
	 *
	 * @param string $modulus
	 * @param string $exponent
	 * @return string
	 */
	public static function publicKeyPem($modulus, $exponent)
	{
		$der = function ($tag, $bytes) {
			$len = strlen($bytes);
			if ($len < 0x80) {
				$size = chr($len);
			} else {
				$hex = ltrim(pack('N', $len), "\0");
				$size = chr(0x80 | strlen($hex)).$hex;
			}
			return chr($tag).$size.$bytes;
		};
		$int = function ($bytes) use ($der) {
			$bytes = ltrim($bytes, "\0");
			// Positive integer: a leading zero when the first bit is set
			if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
				$bytes = "\0".$bytes;
			}
			return $der(0x02, $bytes);
		};
		$rsa = $der(0x30, $int((string) base64_decode($modulus)).$int((string) base64_decode($exponent)));
		// AlgorithmIdentifier rsaEncryption + NULL
		$algo = $der(0x30, $der(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01").$der(0x05, ''));
		$spki = $der(0x30, $algo.$der(0x03, "\0".$rsa));

		return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
	}

	/**
	 * Signature of a call of the office server (WOPI proof): token, address in upper case and time, signed with
	 * RSA-SHA256 by the current or the old key of the discovery
	 *
	 * @param array<int,array{modulus:string,exponent:string}> $keys
	 * @param string   $token     Access token of the call
	 * @param string[] $urls      Addresses of the call (as the office server may have written it)
	 * @param string   $timestamp X-WOPI-TimeStamp (ticks of .NET, UTC)
	 * @param string   $proof     X-WOPI-Proof
	 * @param string   $proofOld  X-WOPI-ProofOld
	 * @return bool
	 */
	public static function checkProof(array $keys, $token, array $urls, $timestamp, $proof, $proofOld)
	{
		if (!count($keys) || !preg_match('/^\d{15,20}$/', $timestamp) || $proof === '' || !function_exists('openssl_verify')) {
			return false;
		}
		// Ticks: 100 ns since 0001-01-01
		$unix = (int) floor(((float) $timestamp - 621355968000000000) / 10000000);
		if (abs(dol_now() - $unix) > self::PROOF_MAX_AGE) {
			return false;
		}
		$ticks = pack('J', (int) $timestamp);
		$signatures = array_filter(array(base64_decode($proof, true), $proofOld !== '' ? base64_decode($proofOld, true) : false));
		foreach ($urls as $url) {
			$url = strtoupper($url);
			$data = pack('N', strlen($token)).$token.pack('N', strlen($url)).$url.pack('N', 8).$ticks;
			foreach ($keys as $key) {
				$pem = self::publicKeyPem($key['modulus'], $key['exponent']);
				foreach ($signatures as $signature) {
					if (openssl_verify($data, $signature, $pem, OPENSSL_ALGO_SHA256) === 1) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * The address of the caller is allowed (setting DOCSTUDIO_WOPI_ALLOWED_IPS: addresses or IPv4 ranges a.b.c.d/n,
	 * separated by commas or spaces; empty: every address)
	 *
	 * @param string $ip REMOTE_ADDR
	 * @return bool
	 */
	public static function isAllowedIp($ip)
	{
		$list = preg_split('/[\s,;]+/', trim(getDolGlobalString('DOCSTUDIO_WOPI_ALLOWED_IPS')), -1, PREG_SPLIT_NO_EMPTY);
		if (!$list) {
			return true;
		}
		foreach ($list as $allowed) {
			if (strpos($allowed, '/') !== false) {
				list($net, $bits) = explode('/', $allowed, 2);
				$bits = (int) $bits;
				if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $bits >= 0 && $bits <= 32) {
					$mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
					if ((ip2long($ip) & $mask) === (ip2long($net) & $mask)) {
						return true;
					}
				}
			} elseif (@inet_pton($allowed) !== false && @inet_pton($allowed) === @inet_pton($ip)) {
				return true;
			}
		}

		return false;
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
		$data = is_array($data) && isset($data['actions']) ? $data['actions'] : null;
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
		// Entity in the path, no query string: OnlyOffice adds « ?access_token= » after the address as it is
		$src = self::getHostUrl().'/'.((int) $entity).'/files/'.$fileId;

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
		return hash('sha256', 'docstudio-wopi|'.self::instanceSecret(), true);
	}

	/**
	 * Secret of the signature of the tokens: the key of the files plus a generation, changed by « Revoke the tokens »
	 * of the setup (every token made before is refused; the ids of the files stay the same)
	 *
	 * @return string
	 */
	protected static function tokenKey()
	{
		return hash_hmac('sha256', 'tokens|'.getDolGlobalInt('DOCSTUDIO_WOPI_TOKEN_GEN'), self::key(), true);
	}

	/**
	 * @return int Seconds
	 */
	public static function getTokenTtl()
	{
		$hours = getDolGlobalInt('DOCSTUDIO_WOPI_TOKEN_HOURS');

		return $hours > 0 ? min(24, $hours) * 3600 : self::TOKEN_TTL;
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
	 * @param array<string,string>     $extra  Other fields signed with the token (k: kind of token, i: hash of an address)
	 * @return array{token:string,expire:int}
	 */
	public static function makeToken(User $fuser, $entity, $source, array $params, $mode, array $extra = array())
	{
		$expire = dol_now() + self::getTokenTtl();
		$payload = self::b64(json_encode(array(
			'u' => (int) $fuser->id,
			'e' => (int) $entity,
			's' => (string) $source,
			'p' => $params,
			'm' => $mode === 'edit' ? 'edit' : 'view',
			'x' => $expire,
			'f' => self::fileId($source, $params, $entity),
		) + $extra));

		return array('token' => $payload.'.'.self::b64(hash_hmac('sha256', $payload, self::tokenKey(), true)), 'expire' => $expire);
	}

	/**
	 * @param string $token
	 * @param string|null $fileId Id of the file of the request, null when the caller checks it after loading the file
	 * @return array<string,mixed>|null Content of the token, null if not valid, expired or for another file
	 */
	public static function checkToken($token, $fileId)
	{
		if (!self::hasKey() || !is_string($token) || strlen($token) > 4096 || !preg_match('/^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]+)$/', $token, $m)) {
			return null;
		}
		if (!hash_equals(self::b64(hash_hmac('sha256', $m[1], self::tokenKey(), true)), $m[2])) {
			return null;
		}
		$data = json_decode((string) base64_decode(strtr($m[1], '-_', '+/')), true);
		if (!is_array($data) || empty($data['u']) || empty($data['x']) || (int) $data['x'] < dol_now()
			|| !isset($data['f']) || ($fileId !== null && !hash_equals((string) $data['f'], (string) $fileId)) || !isset($data['p']) || !is_array($data['p'])) {
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
