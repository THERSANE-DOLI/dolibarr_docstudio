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
 * \file    htdocs/docstudio/class/docstudio.class.php
 * \ingroup docstudio
 * \brief   Registry of the file providers and of the viewers
 */

dol_include_once('/docstudio/class/docstudiofileprovider.class.php');
dol_include_once('/docstudio/class/docstudiodocumentprovider.class.php');


/**
 * Registry of DocStudio: which sources of files exist (providers) and which viewers can show which file types.
 *
 * Other modules add theirs with two hooks of the context 'docstudio', where $object is this registry:
 * - docstudioRegisterProviders: call $object->registerProvider('code', 'ClassName', '/mymodule/class/file.class.php');
 * - docstudioRegisterViewers: call $object->registerViewer(array(...)), see registerViewer().
 * Everything given by a hook is checked: a declaration that does not pass the checks is ignored and logged.
 */
class DocStudio
{
	/** Handler of a type: the preview of Dolibarr (document_preview of the core) */
	const HANDLER_DOLIBARR = 'dolibarr';

	/** Handler of a type: no preview, download only */
	const HANDLER_NONE = 'none';

	/** Max size of a file read by the text viewer, in bytes */
	const MAX_TEXT_SIZE = 5242880;

	/** @var DoliDB */
	public $db;

	/** @var string[] Errors of declarations refused, for the setup page */
	public $errors = array();

	/** @var array<string,array{class:string,file:string,module:string}> */
	protected $providers = array();

	/** @var array<string,array<string,mixed>> */
	protected $viewers = array();

	/** @var bool */
	protected $loaded = false;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Built-in providers and viewers, then the ones of the hooks (once)
	 *
	 * @return void
	 */
	public function load()
	{
		global $hookmanager, $langs;

		if ($this->loaded) {
			return;
		}
		$this->loaded = true;

		$langs->load('docstudio@docstudio');

		$this->providers['document'] = array('class' => 'DocStudioDocumentProvider', 'file' => '/docstudio/class/docstudiodocumentprovider.class.php', 'module' => 'docstudio');

		$this->addViewer(array(
			'code' => 'pdf',
			'label' => $langs->trans('DocStudioViewerPdf'),
			'extensions' => array('pdf'),
			'script' => '/docstudio/js/viewers/pdf.js',
			'priority' => 50,
		), 'docstudio');
		$this->addViewer(array(
			'code' => 'image',
			'label' => $langs->trans('DocStudioViewerImage'),
			'extensions' => array_keys(self::getImageMimeTypes()),
			'script' => '/docstudio/js/viewers/image.js',
			'priority' => 50,
		), 'docstudio');
		$this->addViewer(array(
			'code' => 'csv',
			'label' => $langs->trans('DocStudioViewerCsv'),
			'extensions' => array('csv', 'tsv'),
			'script' => '/docstudio/js/viewers/csv.js',
			'priority' => 50,
			'maxsize' => self::MAX_TEXT_SIZE,
		), 'docstudio');
		$this->addViewer(array(
			'code' => 'text',
			'label' => $langs->trans('DocStudioViewerText'),
			'extensions' => array('txt', 'text', 'log', 'md', 'csv', 'tsv', 'json', 'xml', 'ini', 'conf', 'yml', 'yaml', 'sql', 'html', 'htm', 'eml', 'vcf', 'ics'),
			'script' => '/docstudio/js/viewers/text.js',
			'priority' => 10,
			'maxsize' => self::MAX_TEXT_SIZE,
		), 'docstudio');

		if (!is_object($hookmanager)) {
			include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
			$hookmanager = new HookManager($this->db);
		}
		$hookmanager->initHooks(array('docstudio'));
		$parameters = array();
		$action = '';
		$registry = $this;	// $this cannot be given by reference
		$hookmanager->executeHooks('docstudioRegisterProviders', $parameters, $registry, $action);
		$hookmanager->executeHooks('docstudioRegisterViewers', $parameters, $registry, $action);
	}

	/**
	 * Image types shown by the image viewer, with the type given to the browser.
	 * SVG is shown in an <img> element only, where its scripts never run.
	 *
	 * @return array<string,string> Extension => MIME type
	 */
	public static function getImageMimeTypes()
	{
		return array(
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'bmp' => 'image/bmp',
			'avif' => 'image/avif',
			'svg' => 'image/svg+xml',
		);
	}

	/**
	 * Declare a provider of files (called by the hook docstudioRegisterProviders)
	 *
	 * @param string $code      Code of the source, used in URLs (a-z, 0-9, _)
	 * @param string $className Class extending DocStudioFileProvider
	 * @param string $classFile Path of the class file, relative to a module directory: '/mymodule/class/x.class.php'
	 * @return bool             False if the declaration is refused
	 */
	public function registerProvider($code, $className, $classFile)
	{
		$module = self::getModuleOfPath($classFile, '/\.class\.php$/');
		if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', (string) $code) || isset($this->providers[$code])
			|| !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $className) || $module === '') {
			$this->refuse('provider '.$code);
			return false;
		}
		$this->providers[$code] = array('class' => $className, 'file' => $classFile, 'module' => $module);

		return true;
	}

	/**
	 * Declare a viewer (called by the hook docstudioRegisterViewers)
	 *
	 * @param array<string,mixed> $viewer Keys:
	 *                                    - code: unique code (a-z, 0-9, _), also used by the JavaScript registration
	 *                                    - label: translated name shown in « Open with »
	 *                                    - extensions: list of lowercase extensions it can show
	 *                                    - script: JavaScript module of the viewer, '/mymodule/js/viewer.js'
	 *                                    - css: optional style sheet, '/mymodule/css/viewer.css'
	 *                                    - priority: optional, the highest is chosen by default (built-in: 10 to 50)
	 *                                    - maxsize: optional, files bigger than this (bytes) are not offered
	 * @return bool                       False if the declaration is refused
	 */
	public function registerViewer(array $viewer)
	{
		$module = isset($viewer['script']) ? self::getModuleOfPath((string) $viewer['script'], '/\.js$/') : '';
		if ($module === '' || $module === 'docstudio') {
			$this->refuse('viewer '.(isset($viewer['code']) ? (string) $viewer['code'] : ''));
			return false;
		}

		return $this->addViewer($viewer, $module);
	}

	/**
	 * Check and store a viewer
	 *
	 * @param array<string,mixed> $viewer See registerViewer()
	 * @param string              $module Module owning the viewer
	 * @return bool
	 */
	protected function addViewer(array $viewer, $module)
	{
		$code = isset($viewer['code']) ? (string) $viewer['code'] : '';
		$extensions = array();
		foreach ((isset($viewer['extensions']) && is_array($viewer['extensions'])) ? $viewer['extensions'] : array() as $ext) {
			$ext = strtolower((string) $ext);
			if (preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
				$extensions[] = $ext;
			}
		}
		$css = isset($viewer['css']) ? (string) $viewer['css'] : '';
		if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $code) || isset($this->viewers[$code]) || empty($extensions)
			|| in_array($code, array(self::HANDLER_DOLIBARR, self::HANDLER_NONE), true)
			|| self::getModuleOfPath((string) $viewer['script'], '/\.js$/') !== $module
			|| ($css !== '' && self::getModuleOfPath($css, '/\.css$/') !== $module)) {
			$this->refuse('viewer '.$code);
			return false;
		}

		$this->viewers[$code] = array(
			'code' => $code,
			'label' => isset($viewer['label']) ? dol_string_nohtmltag((string) $viewer['label']) : $code,
			'extensions' => array_values(array_unique($extensions)),
			'script' => (string) $viewer['script'],
			'css' => $css,
			'priority' => isset($viewer['priority']) ? (int) $viewer['priority'] : 0,
			'maxsize' => isset($viewer['maxsize']) ? (int) $viewer['maxsize'] : 0,
			'module' => $module,
		);

		return true;
	}

	/**
	 * Module directory of a path of a module file, if the path is safe and the file exists
	 *
	 * @param string $path    Path relative to a module directory, '/mymodule/js/x.js'
	 * @param string $pattern Pattern the path must match (file type)
	 * @return string         Module directory name, '' if the path is refused
	 */
	public static function getModuleOfPath($path, $pattern)
	{
		if (!preg_match('/^\/([a-z0-9_]+)\/[A-Za-z0-9_\-\/.]+$/', $path, $reg) || strpos($path, '..') !== false
			|| strpos($path, '//') !== false || !preg_match($pattern, $path)) {
			return '';
		}
		$full = dol_buildpath($path, 0);
		if (!is_file($full)) {
			return '';
		}

		return $reg[1];
	}

	/**
	 * Log a refused declaration
	 *
	 * @param string $what What was refused
	 * @return void
	 */
	protected function refuse($what)
	{
		$this->errors[] = $what;
		dol_syslog(__CLASS__.' refused declaration of '.$what, LOG_WARNING);
	}

	/**
	 * Declared providers
	 *
	 * @return array<string,array{class:string,file:string,module:string}>
	 */
	public function getProviders()
	{
		$this->load();

		return $this->providers;
	}

	/**
	 * Declared viewers, highest priority first
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getViewers()
	{
		$this->load();
		$viewers = $this->viewers;
		uasort($viewers, function ($a, $b) {
			return $b['priority'] <=> $a['priority'];
		});

		return $viewers;
	}

	/**
	 * Viewers able to show a file, highest priority first
	 *
	 * @param string $extension Lowercase extension
	 * @param int    $size      Size of the file in bytes
	 * @return array<string,array<string,mixed>>
	 */
	public function getViewersFor($extension, $size)
	{
		$result = array();
		foreach ($this->getViewers() as $code => $viewer) {
			if (in_array($extension, $viewer['extensions'], true) && (empty($viewer['maxsize']) || $size <= $viewer['maxsize'])) {
				$result[$code] = $viewer;
			}
		}

		return $result;
	}

	/**
	 * Every extension that at least one viewer can show
	 *
	 * @return string[]
	 */
	public function getSupportedExtensions()
	{
		$list = array();
		foreach ($this->getViewers() as $viewer) {
			$list = array_merge($list, $viewer['extensions']);
		}

		return array_values(array_unique($list));
	}

	/**
	 * Family of a file type, to group the types on the setup pages
	 *
	 * @param string $extension Lowercase extension
	 * @return string           'pdf', 'image', 'table', 'text' or 'other'
	 */
	public static function getFamily($extension)
	{
		if ($extension === 'pdf') {
			return 'pdf';
		}
		if (isset(self::getImageMimeTypes()[$extension])) {
			return 'image';
		}
		if (in_array($extension, array('csv', 'tsv', 'xls', 'xlsx', 'ods'), true)) {
			return 'table';
		}
		if (in_array($extension, array('txt', 'text', 'log', 'md', 'json', 'xml', 'ini', 'conf', 'yml', 'yaml', 'sql', 'html', 'htm', 'eml', 'vcf', 'ics'), true)) {
			return 'text';
		}

		return 'other';
	}

	/**
	 * Ways to open a file type: the viewers able to show it, the preview of Dolibarr when the core can show it, or
	 * no preview (download only)
	 *
	 * @param string $extension Lowercase extension
	 * @return array<string,string> Handler code => label
	 */
	public function getHandlersFor($extension)
	{
		global $langs;

		$handlers = array();
		foreach ($this->getViewers() as $code => $viewer) {
			if (in_array($extension, $viewer['extensions'], true)) {
				$handlers[$code] = $langs->trans('DocStudioHandlerViewer', $viewer['label']);
			}
		}
		if (dolIsAllowedForPreview('file.'.$extension)) {
			$handlers[self::HANDLER_DOLIBARR] = $langs->trans('DocStudioHandlerDolibarr');
		}
		$handlers[self::HANDLER_NONE] = $langs->trans('DocStudioHandlerNone');

		return $handlers;
	}

	/**
	 * Handler used when the administrator chose nothing: the preview of Dolibarr when the core can show the type
	 * (PDF, images...), else the viewer of highest priority
	 *
	 * @param string $extension Lowercase extension
	 * @return string           Handler code
	 */
	public function getDefaultHandler($extension)
	{
		$handlers = $this->getHandlersFor($extension);
		if (isset($handlers[self::HANDLER_DOLIBARR])) {
			return self::HANDLER_DOLIBARR;
		}

		return (string) key($handlers);
	}

	/**
	 * Name of the constant of the handler chosen by the administrator for a type
	 *
	 * @param string $extension Lowercase extension
	 * @param string $suffix    '' for the handler, '_CHOICES' for the handlers left to the choice of the users
	 * @return string
	 */
	public static function getConstName($extension, $suffix = '')
	{
		return 'DOCSTUDIO_VIEW_'.strtoupper(preg_replace('/[^a-z0-9]/', '', $extension)).$suffix;
	}

	/**
	 * Handler chosen by the administrator for a type (the default one if the choice is no longer available)
	 *
	 * @param string $extension Lowercase extension
	 * @return string           Handler code
	 */
	public function getAdminHandler($extension)
	{
		$handlers = $this->getHandlersFor($extension);
		$chosen = getDolGlobalString(self::getConstName($extension));

		return isset($handlers[$chosen]) ? $chosen : $this->getDefaultHandler($extension);
	}

	/**
	 * Handlers the administrator allows the users to choose for a type, the handler of the administrator first.
	 * Only one handler means no choice for the users.
	 *
	 * @param string $extension Lowercase extension
	 * @return string[]         Handler codes
	 */
	public function getUserChoices($extension)
	{
		$handlers = $this->getHandlersFor($extension);
		$admin = $this->getAdminHandler($extension);
		$choices = array($admin);
		foreach (explode(',', getDolGlobalString(self::getConstName($extension, '_CHOICES'))) as $code) {
			if ($code !== '' && isset($handlers[$code]) && !in_array($code, $choices, true)) {
				$choices[] = $code;
			}
		}

		return $choices;
	}

	/**
	 * Handler used for a user: the one of the user when the administrator allows it, else the one of the administrator
	 *
	 * @param string    $extension Lowercase extension
	 * @param User|null $fuser     User, null for the administrator choice only
	 * @return string              Handler code
	 */
	public function getHandler($extension, $fuser = null)
	{
		$choices = $this->getUserChoices($extension);
		if ($fuser !== null && count($choices) > 1) {
			$name = self::getConstName($extension);
			$mine = (isset($fuser->conf->$name) ? (string) $fuser->conf->$name : '');
			if (in_array($mine, $choices, true)) {
				return $mine;
			}
		}

		return $choices[0];
	}

	/**
	 * Handler of every type a viewer can show, for a user
	 *
	 * @param User|null $fuser User
	 * @return array<string,string> Extension => handler code
	 */
	public function getHandlers($fuser = null)
	{
		$result = array();
		foreach ($this->getSupportedExtensions() as $extension) {
			$result[$extension] = $this->getHandler($extension, $fuser);
		}

		return $result;
	}

	/**
	 * Types a viewer can show, grouped by family then sorted
	 *
	 * @return array<string,string[]> Family => extensions
	 */
	public function getExtensionsByFamily()
	{
		$result = array('pdf' => array(), 'image' => array(), 'table' => array(), 'text' => array(), 'other' => array());
		foreach ($this->getSupportedExtensions() as $extension) {
			$result[self::getFamily($extension)][] = $extension;
		}
		foreach ($result as $family => $list) {
			sort($list);
			$result[$family] = $list;
		}

		return array_filter($result);
	}

	/**
	 * Provider of a source, ready to load a file
	 *
	 * @param string $source Code of the source
	 * @return DocStudioFileProvider|null Null if the source is unknown or its class is not a provider
	 */
	public function getProvider($source)
	{
		$providers = $this->getProviders();
		if (!isset($providers[$source])) {
			return null;
		}
		$def = $providers[$source];
		dol_include_once($def['file']);
		if (!class_exists($def['class']) || !is_subclass_of($def['class'], 'DocStudioFileProvider')) {
			$this->refuse('provider '.$source.' (class)');
			return null;
		}

		return new $def['class']($this->db);
	}

	/**
	 * Read the request parameters of a source with their declared type, then load the file
	 *
	 * @param string $source Code of the source
	 * @param User   $fuser  User asking for the file
	 * @param string $error  Message for the user when the file cannot be given
	 * @return DocStudioFileProvider|null The provider with its file loaded, null if not found or forbidden
	 */
	public function loadFromRequest($source, User $fuser, &$error)
	{
		global $langs;

		$provider = $this->getProvider($source);
		if ($provider === null) {
			$error = $langs->trans('DocStudioErrorUnknownSource');
			return null;
		}
		$params = array();
		foreach ($provider->getRequestParams() as $name => $type) {
			if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) || in_array($name, array('source', 'viewer', 'embed', 'token', 'action'), true)) {
				continue;
			}
			$params[$name] = ($type === 'int') ? GETPOSTINT($name) : GETPOST($name, $type);
		}
		$res = $provider->load($params, $fuser);
		if ($res <= 0) {
			$error = $provider->error !== '' ? $provider->error : $langs->trans($res < 0 ? 'DocStudioErrorForbidden' : 'DocStudioErrorFileNotFound');
			return null;
		}

		return $provider;
	}

	/**
	 * URL of a DocStudio page for a file
	 *
	 * @param string                   $page   'view.php' or 'ajax/content.php'
	 * @param string                   $source Code of the source
	 * @param array<string,string|int> $params Parameters of the provider
	 * @param int                      $mode   0 = relative URL, 2 = absolute URL
	 * @return string
	 */
	public static function buildUrl($page, $source, array $params, $mode = 0)
	{
		return dol_buildpath('/docstudio/'.$page, $mode === 2 ? 2 : 1).'?'.http_build_query(array('source' => $source) + $params, '', '&', PHP_QUERY_RFC3986);
	}
}
