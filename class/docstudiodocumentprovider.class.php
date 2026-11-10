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
 * \file    htdocs/docstudio/class/docstudiodocumentprovider.class.php
 * \ingroup docstudio
 * \brief   File provider of the documents of Dolibarr (the files served by document.php)
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
dol_include_once('/docstudio/class/docstudiofileprovider.class.php');


/**
 * Documents of Dolibarr, identified like document.php: modulepart + file (relative path) + entity.
 *
 * The checks are the ones of document.php, made stricter:
 * - permissions by dol_check_secure_access_document() in mode 'read';
 * - external users need the permission docstudio > external > read, then see only the documents of their own
 *   thirdparty (filter of document.php, a modulepart without this filter is refused instead of allowed);
 * - a relative path with '..', a leading slash, a backslash, a control character or a pipe is refused, not cleaned;
 * - the entity must be one the user may see;
 * - the real path (symbolic links resolved) must stay inside the data directory of Dolibarr;
 * - public links (hashp) are not supported.
 */
class DocStudioDocumentProvider extends DocStudioFileProvider
{
	/** @var string */
	protected $modulepart = '';

	/** @var string Path relative to the directory of the modulepart */
	protected $relativeFile = '';

	/** @var int */
	protected $entity = 0;

	/** @var string Full path on disk, never given outside of this class */
	protected $fullPath = '';

	/**
	 * Request parameters that identify a file, with their GETPOST() type
	 *
	 * @return array<string,string>
	 */
	public function getRequestParams()
	{
		return array('modulepart' => 'aZ09', 'file' => 'alphanohtml', 'entity' => 'int');
	}

	/**
	 * Find the file and check that the user may read it
	 *
	 * @param array<string,mixed> $params Values of getRequestParams()
	 * @param User                $fuser  User asking for the file
	 * @return int                        1 if the file may be read, 0 if not found, -1 if forbidden
	 */
	public function load(array $params, User $fuser)
	{
		global $conf, $langs;

		$this->fullPath = '';
		$this->modulepart = isset($params['modulepart']) ? (string) $params['modulepart'] : '';
		$this->relativeFile = isset($params['file']) ? (string) $params['file'] : '';
		$this->entity = !empty($params['entity']) ? (int) $params['entity'] : (int) $conf->entity;

		if (!empty($fuser->socid) && !$fuser->hasRight('docstudio', 'external', 'read')) {
			$this->error = $langs->trans('DocStudioErrorExternalUser');
			return -1;
		}
		if (!self::isSafeRelativePath($this->relativeFile) || $this->modulepart === '') {
			$this->error = $langs->trans('DocStudioErrorBadFileName');
			return -1;
		}
		if (!$this->isEntityAllowed($this->entity, $fuser)) {
			return $this->refuse('entity', $fuser);
		}

		$check = dol_check_secure_access_document($this->modulepart, $this->relativeFile, $this->entity, $fuser, '', 'read');
		if (!is_array($check) || empty($check['accessallowed']) || empty($check['original_file'])) {
			return $this->refuse('permission', $fuser);
		}

		if (!empty($fuser->socid) && !$this->isOwnThirdpartyDocument($fuser, isset($check['sqlprotectagainstexternals']) ? (string) $check['sqlprotectagainstexternals'] : '')) {
			return $this->refuse('thirdparty', $fuser);
		}
		// Check per object of document.php since Dolibarr 24 (restricted sales representatives...)
		if (function_exists('checkUserAccessToDocumentObject') && !checkUserAccessToDocumentObject($fuser, $this->modulepart, $this->relativeFile, 'read')) {
			return $this->refuse('object', $fuser);
		}

		$path = (string) $check['original_file'];
		if (preg_match('/\.\.|[<>|]/', $path) || !is_file($path) || !is_readable($path)) {
			$this->error = $langs->trans('DocStudioErrorFileNotFound');
			return 0;
		}
		if (!self::isInsideDataRoot($path)) {
			dol_syslog(__METHOD__.' real path '.((string) realpath($path)).' is not inside '.((string) realpath(DOL_DATA_ROOT)), LOG_WARNING);
			return $this->refuse('dataroot', $fuser);
		}

		$this->fullPath = $path;

		return 1;
	}

	/**
	 * Refuse the file: the reason is logged, and shown to administrators only (support without access to the logs)
	 *
	 * @param string $reason Code of the check that failed: entity, permission, thirdparty, object or dataroot
	 * @param User   $fuser  User asking for the file
	 * @return int           -1
	 */
	protected function refuse($reason, User $fuser)
	{
		global $langs;

		dol_syslog(__METHOD__.' refused ('.$reason.') modulepart='.$this->modulepart.' file='.$this->relativeFile.' entity='.$this->entity.' user='.$fuser->id, LOG_WARNING);
		$this->error = $langs->trans('DocStudioErrorForbidden');
		if (!empty($fuser->admin)) {
			$this->error .= ' ('.$reason.')';
		}

		return -1;
	}

	/**
	 * Document of the thirdparty of an external user: every owner found by the query of dol_check_secure_access_document()
	 * must be this thirdparty. Stricter than document.php: no query (modulepart without known owner) or no owner found
	 * means refused.
	 *
	 * @param User   $fuser External user
	 * @param string $sql   Query giving the thirdparties owning the document (column fk_soc)
	 * @return bool
	 */
	protected function isOwnThirdpartyDocument(User $fuser, $sql)
	{
		if ($sql === '') {
			return false;
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$found = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$found++;
			if ((int) $obj->fk_soc !== (int) $fuser->socid) {
				$this->db->free($resql);
				return false;
			}
		}
		$this->db->free($resql);

		return $found > 0;
	}

	/**
	 * A relative path given by the browser is accepted only if it cannot leave its directory
	 *
	 * @param string $path Relative path
	 * @return bool
	 */
	public static function isSafeRelativePath($path)
	{
		if ($path === '' || strlen($path) > 1024) {
			return false;
		}
		// No parent directory, no absolute path, no Windows separator, no control character, no stream wrapper
		if (preg_match('/(^|\/)\.\.(\/|$)|^\/|\\\\|[\x00-\x1F\x7F<>|]|^[a-z][a-z0-9+.-]*:/i', $path)) {
			return false;
		}
		// No hidden file nor hidden directory
		if (preg_match('/(^|\/)\./', $path)) {
			return false;
		}

		return true;
	}

	/**
	 * The file is inside the data directory of Dolibarr.
	 *
	 * Directories of the data directory may be symbolic links to another disk (set by the administrator of the server,
	 * Dolibarr never creates links): the path given by Dolibarr must be inside the data directory, then only the file
	 * itself is checked. A file that is a symbolic link must point inside the data directory or inside its own directory.
	 *
	 * @param string $path Full path (without '..', checked by the caller)
	 * @return bool
	 */
	public static function isInsideDataRoot($path)
	{
		$root = rtrim(preg_replace('#/+#', '/', DOL_DATA_ROOT), '/').'/';
		$path = preg_replace('#/+#', '/', $path);
		$realRoot = realpath(DOL_DATA_ROOT);
		$real = realpath($path);
		if ($realRoot === false || $real === false || preg_match('/(^|\/)\.\.(\/|$)/', $path)) {
			return false;
		}
		$realRoot = rtrim($realRoot, '/').'/';

		if (strpos($path, $root) !== 0) {
			// Path not given under DOL_DATA_ROOT: only accepted if it really is inside it
			return strpos($real, $realRoot) === 0;
		}
		if (!is_link($path)) {
			return true;
		}
		$realDir = realpath(dirname($path));

		return strpos($real, $realRoot) === 0 || ($realDir !== false && strpos($real, rtrim($realDir, '/').'/') === 0);
	}

	/**
	 * The entity of the document is the current one, or one shared with it. A superadmin (administrator of entity 0)
	 * may see the documents of every entity, like with document.php.
	 *
	 * @param int  $entity Entity asked
	 * @param User $fuser  User asking for the file
	 * @return bool
	 */
	protected function isEntityAllowed($entity, User $fuser)
	{
		global $conf;

		if ($entity == $conf->entity) {
			return true;
		}
		if (!isModEnabled('multicompany')) {
			return false;
		}
		if (!empty($fuser->admin) && empty($fuser->entity)) {
			return true;
		}
		$allowed = array_map('intval', explode(',', getEntity($this->modulepart)));

		return in_array((int) $entity, $allowed, true);
	}

	/**
	 * Name of the file as shown to the user
	 *
	 * @return string
	 */
	public function getFileName()
	{
		return $this->fullPath === '' ? '' : basename($this->fullPath);
	}

	/**
	 * Size of the file in bytes
	 *
	 * @return int
	 */
	public function getSize()
	{
		return $this->fullPath === '' ? 0 : (int) filesize($this->fullPath);
	}

	/**
	 * Print the raw content of the file
	 *
	 * @return bool
	 */
	public function outputContent()
	{
		if ($this->fullPath === '') {
			return false;
		}

		return readfile($this->fullPath) !== false;
	}

	/**
	 * Same permission as uploading a file on the owner of the document (mode 'write' of document.php checks).
	 * External users never write.
	 *
	 * @param User $fuser User
	 * @return bool
	 */
	public function canWrite(User $fuser)
	{
		if ($this->fullPath === '' || !empty($fuser->socid)) {
			return false;
		}
		$check = dol_check_secure_access_document($this->modulepart, $this->relativeFile, $this->entity, $fuser, '', 'write');
		if (!is_array($check) || empty($check['accessallowed']) || empty($check['original_file'])
			|| realpath((string) $check['original_file']) !== realpath($this->fullPath)) {
			return false;
		}

		return !function_exists('checkUserAccessToDocumentObject') || checkUserAccessToDocumentObject($fuser, $this->modulepart, $this->relativeFile, 'write');
	}

	/**
	 * Hook downloadDocument of document.php, so that the modules refusing or logging the downloads see this reading too
	 *
	 * @param User $fuser User reading the file
	 * @return bool       False if a module refused (error in $this->error)
	 */
	public function runDownloadHooks(User $fuser)
	{
		global $hookmanager;

		if ($this->fullPath === '') {
			return false;
		}
		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('document'));
		$parameters = array('ecmfile' => null, 'modulepart' => $this->modulepart, 'original_file' => $this->relativeFile,
			'entity' => $this->entity, 'fullpath_original_file' => $this->fullPath, 'filename' => $this->getFileName(),
			'fullpath_original_file_osencoded' => dol_osencode($this->fullPath), 'docstudio' => 1);
		$object = new stdClass();
		$action = '';
		$reshook = $hookmanager->executeHooks('downloadDocument', $parameters, $object, $action);
		if ($reshook < 0) {
			$this->error = $hookmanager->error.(is_array($hookmanager->errors) && count($hookmanager->errors) ? ' '.implode(', ', $hookmanager->errors) : '');
			dol_syslog(__METHOD__.' refused by the hook downloadDocument: '.$this->error.' user='.$fuser->id, LOG_WARNING);
			return false;
		}

		return true;
	}

	/**
	 * Name accepted for a copy: no directory, not hidden, same extension as the original, cleaned like an upload
	 *
	 * @param string $fileName Name asked
	 * @return string          Clean name, '' if refused
	 */
	public function cleanCopyName($fileName)
	{
		$name = dol_sanitizeFileName(trim(basename(str_replace('\\', '/', (string) $fileName))));
		$ext = $this->getExtension();
		if ($name === '' || $name[0] === '.' || strlen($name) > 200 || !self::isSafeRelativePath($name)) {
			return '';
		}
		// Only one extension, the one of the original: no « name.php.pdf » that a badly set web server could run
		$base = (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === $ext) ? substr($name, 0, -strlen($ext) - 1) : $name;
		$base = trim(str_replace('.', '-', $base), '-');
		if ($base === '') {
			return '';
		}

		return $base.'.'.$ext;
	}

	/**
	 * Save a new content: a copy next to the original (indexed in the ECM with the same owner) or the original
	 * replaced in place (its ECM entry, and so its share link, are kept). The antivirus checks the new content first.
	 *
	 * @param string $tmpFile   Full path of the new content
	 * @param string $mode      'copy' or 'replace'
	 * @param string $fileName  Name of the copy
	 * @param bool   $overwrite Overwrite a file of the same name (copy)
	 * @param User   $fuser     User saving
	 * @return array{result:int,error:string,exists:int,filename:string}
	 */
	public function save($tmpFile, $mode, $fileName, $overwrite, User $fuser)
	{
		global $langs;

		$result = array('result' => -1, 'error' => '', 'exists' => 0, 'filename' => '');
		if (!$this->canWrite($fuser)) {
			$result['error'] = $langs->trans('DocStudioErrorCannotWrite');
			return $result;
		}
		$virus = dolCheckVirus($tmpFile, $this->fullPath);
		if (count($virus)) {
			$result['error'] = $langs->trans('DocStudioErrorVirus');
			dol_syslog(__METHOD__.' content refused by the antivirus: '.implode(', ', $virus), LOG_WARNING);
			return $result;
		}

		$dir = dirname($this->fullPath);
		if ($mode === 'replace') {
			$target = $this->fullPath;
		} else {
			$name = $this->cleanCopyName($fileName);
			if ($name === '') {
				$result['error'] = $langs->trans('DocStudioErrorBadFileName');
				return $result;
			}
			$target = $dir.'/'.$name;
			if (file_exists($target) && !$overwrite) {
				$result['result'] = 0;
				$result['exists'] = 1;
				$result['filename'] = $name;
				return $result;
			}
			if (file_exists($target) && realpath($target) === realpath($this->fullPath)) {
				$mode = 'replace';
			}
		}

		// Written next to the target then renamed: the target is never half written
		$work = $dir.'/.docstudio-'.bin2hex(random_bytes(8)).'.tmp';
		if (!@copy($tmpFile, $work) || !@rename($work, $target)) {
			@unlink($work);
			$result['error'] = $langs->trans('DocStudioErrorSave');
			return $result;
		}
		dolChmod($target);

		$this->indexInEcm($target, $mode === 'replace', $fuser);
		$result['result'] = 1;
		$result['filename'] = basename($target);

		return $result;
	}

	/**
	 * ECM index of a saved file: entry of the original updated (checksum), or new entry with the owner of the original
	 *
	 * @param string $target   Full path of the saved file
	 * @param bool   $replaced The original was replaced
	 * @param User   $fuser    User saving
	 * @return void
	 */
	protected function indexInEcm($target, $replaced, User $fuser)
	{
		include_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		$root = rtrim(DOL_DATA_ROOT, '/').'/';
		$rel = preg_replace('/^'.preg_quote($root, '/').'/', '', $target);
		$relSource = preg_replace('/^'.preg_quote($root, '/').'/', '', $this->fullPath);

		$ecm = new EcmFiles($this->db);
		if ($ecm->fetch(0, '', $rel) > 0) {
			$ecm->label = md5_file($target);
			$ecm->update($fuser);
			return;
		}
		if ($replaced) {
			return;
		}
		$source = new EcmFiles($this->db);
		$ecm->filepath = dirname($rel);
		$ecm->filename = basename($target);
		$ecm->label = md5_file($target);
		$ecm->fullpath_orig = basename($this->fullPath);
		$ecm->gen_or_uploaded = 'uploaded';
		$ecm->entity = $this->entity;
		if ($source->fetch(0, '', $relSource) > 0) {
			$ecm->src_object_type = $source->src_object_type;
			$ecm->src_object_id = $source->src_object_id;
		}
		if ($ecm->create($fuser) < 0) {
			dol_syslog(__METHOD__.' ECM index not created: '.$ecm->error, LOG_WARNING);
		}
	}

	/**
	 * URL of document.php for this file
	 *
	 * @return string
	 */
	public function getDownloadUrl()
	{
		return DOL_URL_ROOT.'/document.php?modulepart='.urlencode($this->modulepart).'&file='.urlencode($this->relativeFile).'&entity='.((int) $this->entity);
	}

	/**
	 * Values of the request parameters of the loaded file
	 *
	 * @return array<string,string|int>
	 */
	public function getParams()
	{
		return array('modulepart' => $this->modulepart, 'file' => $this->relativeFile, 'entity' => $this->entity);
	}
}
