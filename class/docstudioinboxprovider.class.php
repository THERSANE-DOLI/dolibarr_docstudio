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

	/** @var string Type of the piece receiving the copy (« Annotate and file », C2), '' when only read */
	protected $recordType = '';

	/** @var int */
	protected $recordId = 0;

	/** @var array{type:string,id:int,kind:string,ref:string,label:string,url:string}|null Piece described by inbox */
	protected $record = null;

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
		return array('mail' => 'int', 'part' => 'int', 'force' => 'int', 'record_type' => 'aZ09', 'record_id' => 'int');
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
		$this->recordType = isset($params['record_type']) ? (string) $params['record_type'] : '';
		$this->recordId = isset($params['record_id']) ? (int) $params['record_id'] : 0;
		$this->record = null;
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
		if ($this->recordType !== '' && $this->recordId > 0 && method_exists($file, 'describeRecord')) {
			// Piece chosen in inbox: the user must be allowed to put files in it, else the attachment stays read only
			$this->record = $file->describeRecord($fuser, $this->recordType, $this->recordId);
		}

		return 1;
	}

	/**
	 * A mail of a protected mailbox is read only by the session that unlocked it (inbox), never by the office server
	 *
	 * @return bool
	 */
	public function isReadableWithoutSession()
	{
		return $this->mailId > 0 && !self::needsSession($this->db, $this->mailId);
	}

	/**
	 * The mail belongs to a protected mailbox (read live or kept for the session that unlocked it): what the server
	 * gives of it needs the session of the user. True too when it cannot be told (safe side).
	 *
	 * @param DoliDB $db
	 * @param int    $mailId
	 * @return bool
	 */
	public static function needsSession($db, $mailId)
	{
		if (!self::isAvailable()) {
			return true;
		}
		if (dol_include_once('/inbox/class/inboxlive.class.php') && class_exists('InboxLive') && InboxLive::isMail((int) $mailId)) {
			return true;
		}
		dol_include_once('/inbox/class/inboxaccount.class.php');
		if (!class_exists('InboxAccount') || !method_exists('InboxAccount', 'isProtected')) {
			// Inbox older than its protected mailboxes: none of them
			return false;
		}
		$row = $db->getRow("SELECT fk_account FROM ".$db->prefix()."inbox_mail WHERE rowid = ".((int) $mailId));
		if (!is_object($row)) {
			return true;
		}
		$account = new InboxAccount($db);
		if ($account->fetch((int) $row->fk_account) <= 0) {
			return true;
		}

		return (bool) $account->isProtected();
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
		$params = array('mail' => $this->mailId, 'part' => $this->part, 'force' => $this->force);
		if ($this->recordType !== '' && $this->recordId > 0) {
			$params['record_type'] = $this->recordType;
			$params['record_id'] = $this->recordId;
		}

		return $params;
	}

	/**
	 * Copies only, in the piece chosen in inbox: a received attachment is never changed
	 *
	 * @param User $fuser User
	 * @return bool
	 */
	public function canWrite(User $fuser)
	{
		return $this->file !== null && $this->record !== null && method_exists($this->file, 'fileToRecord');
	}

	/**
	 * @return bool
	 */
	public function canReplaceInPlace()
	{
		return false;
	}

	/**
	 * @return array{label:string,url:string}|null
	 */
	public function getSaveTarget()
	{
		if ($this->record === null) {
			return null;
		}
		$label = trim($this->record['kind'].' '.$this->record['ref'].($this->record['label'] !== '' ? ' ('.$this->record['label'].')' : ''));

		return array('label' => $label, 'url' => $this->record['url']);
	}

	/**
	 * Copy in the documents of the piece, by inbox (same rules as its « Put in the documents of »: never over an existing
	 * file, the piece linked to the mail, journal of the mailbox)
	 *
	 * @param string $tmpFile   Full path of the new content
	 * @param string $mode      'copy' only
	 * @param string $fileName  Name of the copy
	 * @param bool   $overwrite Not used: inbox numbers the name instead
	 * @param User   $fuser     User saving
	 * @return array{result:int,error:string,exists:int,filename:string}
	 */
	public function save($tmpFile, $mode, $fileName, $overwrite, User $fuser)
	{
		if ($mode !== 'copy' || !$this->canWrite($fuser)) {
			return array('result' => -1, 'error' => 'Not supported', 'exists' => 0, 'filename' => '');
		}
		$fileName = dol_sanitizeFileName((string) $fileName);
		if ($fileName === '' || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) !== $this->getExtension()) {
			$fileName = preg_replace('/\.[^.]*$/', '', $this->getFileName()).'.'.$this->getExtension();
		}
		// Antivirus of Dolibarr on the new content, like an upload
		include_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
		$virus = dolCheckVirus($tmpFile, $fileName);
		if (count($virus)) {
			global $langs;
			dol_syslog(__METHOD__.' content refused by the antivirus: '.implode(', ', $virus), LOG_WARNING);
			return array('result' => -1, 'error' => $langs->transnoentitiesnoconv('DocStudioErrorVirus'), 'exists' => 0, 'filename' => '');
		}
		$content = (string) file_get_contents($tmpFile);
		$written = $this->file->fileToRecord($fuser, $this->record['type'], $this->record['id'], $fileName, $content);
		if ($written === '') {
			return array('result' => -1, 'error' => $this->file->error, 'exists' => 0, 'filename' => '');
		}

		return array('result' => 1, 'error' => '', 'exists' => 0, 'filename' => $written);
	}
}
