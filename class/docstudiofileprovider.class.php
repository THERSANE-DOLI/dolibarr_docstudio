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
 * \file    htdocs/docstudio/class/docstudiofileprovider.class.php
 * \ingroup docstudio
 * \brief   Base class of the file providers: the only way for DocStudio to reach the content of a file
 */


/**
 * A file provider finds one file from request parameters, checks that the user may read it, and gives its name, size
 * and content. Viewers never receive a path on disk nor a direct URL: they only get the content, through
 * ajax/content.php, which asks the provider again on every call.
 *
 * Providers of other modules are declared with the hook docstudioRegisterProviders (see DocStudio::getProviders()).
 * A provider must:
 * - read its parameters only from the array given to load() (already filtered by the types of getRequestParams());
 * - check the permissions of the user in load(), before giving anything;
 * - never follow a path given by the browser without checking it is inside its own storage.
 */
abstract class DocStudioFileProvider
{
	/** @var DoliDB Database handler */
	public $db;

	/** @var string Error message (shown to the user, no path nor technical detail) */
	public $error = '';

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
	 * Request parameters that identify a file, with their GETPOST() type
	 *
	 * @return array<string,string> Name => GETPOST type, for example array('modulepart' => 'aZ09')
	 */
	abstract public function getRequestParams();

	/**
	 * Find the file and check that the user may read it
	 *
	 * @param array<string,mixed> $params Values of getRequestParams(), read by DocStudio with their GETPOST type
	 * @param User                $fuser  User asking for the file
	 * @return int                        1 if the file may be read, 0 if not found, -1 if forbidden
	 */
	abstract public function load(array $params, User $fuser);

	/**
	 * Name of the file as shown to the user (no directory)
	 *
	 * @return string
	 */
	abstract public function getFileName();

	/**
	 * Size of the file in bytes
	 *
	 * @return int
	 */
	abstract public function getSize();

	/**
	 * Print the raw content of the file (headers are sent by DocStudio)
	 *
	 * @return bool False if the content could not be read
	 */
	abstract public function outputContent();

	/**
	 * The user may change this file (permission to write on its owner)
	 *
	 * @param User $fuser User
	 * @return bool
	 */
	public function canWrite(User $fuser)
	{
		return false;
	}

	/**
	 * Save a new content for the loaded file, already checked by DocStudio (type, size, antivirus is done here)
	 *
	 * @param string $tmpFile   Full path of the new content (temporary file, removed by DocStudio after the call)
	 * @param string $mode      'copy': new file next to the original, 'replace': instead of the original
	 * @param string $fileName  Name of the copy (mode 'copy' only)
	 * @param bool   $overwrite Overwrite a file of the same name (mode 'copy' only)
	 * @param User   $fuser     User saving
	 * @return array{result:int,error:string,exists:int,filename:string} result 1 = saved, 0 = a file of this name
	 *                                                                   exists, -1 = error
	 */
	public function save($tmpFile, $mode, $fileName, $overwrite, User $fuser)
	{
		return array('result' => -1, 'error' => 'Not supported', 'exists' => 0, 'filename' => '');
	}

	/**
	 * Hooks of other modules run before the content is sent (document.php: downloadDocument). None by default.
	 *
	 * @param User $fuser User reading the file
	 * @return bool       False if refused (error in $this->error)
	 */
	public function runDownloadHooks(User $fuser)
	{
		return true;
	}

	/**
	 * URL to download the file with the usual mechanism of its owner (document.php...), or '' when there is none
	 *
	 * @return string
	 */
	public function getDownloadUrl()
	{
		return '';
	}

	/**
	 * Values of the request parameters of the loaded file, to build the URLs of DocStudio again
	 *
	 * @return array<string,string|int>
	 */
	abstract public function getParams();

	/**
	 * Lowercase extension of the file name, '' if none
	 *
	 * @return string
	 */
	public function getExtension()
	{
		$name = $this->getFileName();
		$pos = strrpos($name, '.');

		return ($pos === false) ? '' : strtolower(substr($name, $pos + 1));
	}
}
