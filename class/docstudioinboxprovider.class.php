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
 * \file    htdocs/docstudio/class/docstudioinboxprovider.class.php
 * \ingroup docstudio
 * \brief   Source « inbox »: an attachment of a mail of the module inbox, read only (lot 5).
 *
 * Adapter to the read-only access given by inbox (InboxAttachmentFile): inbox checks the rights of the user on the
 * mailbox and the antivirus (an infected attachment is never given, a not scanned one only after the user chose to
 * open it anyway, « force »). Nothing is ever written: a received attachment is not changed.
 */

dol_include_once('/docstudio/class/docstudiofileprovider.class.php');

/**
 * Attachment of a mail of inbox
 */
class DocStudioInboxProvider extends DocStudioFileProvider
{
	/** @var InboxAttachmentFile|null */
	protected $file = null;

	/** @var int */
	protected $mailId = 0;

	/** @var int */
	protected $part = -1;

	/** @var int */
	protected $force = 0;

	/**
	 * The module inbox is there and gives its read-only access to the attachments
	 *
	 * @return bool
	 */
	public static function isAvailable()
	{
		return isModEnabled('inbox') && (bool) dol_include_once('/inbox/class/inboxattachmentfile.class.php') && class_exists('InboxAttachmentFile');
	}

	/**
	 * @return array<string,string>
	 */
	public function getRequestParams()
	{
		return array('mail' => 'int', 'part' => 'int', 'force' => 'int');
	}

	/**
	 * @param array<string,mixed> $params Values of getRequestParams()
	 * @param User                $fuser  User asking for the file
	 * @return int                        1 if the file may be read, 0 if not found, -1 if forbidden
	 */
	public function load(array $params, User $fuser)
	{
		$this->error = '';
		$this->file = null;
		if (!self::isAvailable() || !empty($fuser->socid)) {
			return -1;
		}
		$this->mailId = isset($params['mail']) ? (int) $params['mail'] : 0;
		$this->part = isset($params['part']) ? (int) $params['part'] : -1;
		$this->force = !empty($params['force']) ? 1 : 0;
		if ($this->mailId <= 0 || $this->part < 0) {
			return 0;
		}
		$file = new InboxAttachmentFile($this->db);
		$res = $file->load($fuser, $this->mailId, $this->part, $this->force === 1);
		if ($res <= 0) {
			// Message of inbox (infected, not scanned...) shown as it is
			$this->error = $file->error;
			return $res;
		}
		$this->file = $file;

		return 1;
	}

	/**
	 * @return string
	 */
	public function getFileName()
	{
		return $this->file ? $this->file->getFileName() : '';
	}

	/**
	 * @return int
	 */
	public function getSize()
	{
		return $this->file ? $this->file->getSize() : 0;
	}

	/**
	 * @return bool
	 */
	public function outputContent()
	{
		if (!$this->file) {
			return false;
		}
		print $this->file->getContent();

		return true;
	}

	/**
	 * @return string Download by inbox
	 */
	public function getDownloadUrl()
	{
		return $this->file ? $this->file->getDownloadUrl() : '';
	}

	/**
	 * @return array<string,string|int>
	 */
	public function getParams()
	{
		return array('mail' => $this->mailId, 'part' => $this->part, 'force' => $this->force);
	}
}
