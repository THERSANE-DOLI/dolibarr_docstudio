<?php
/* Copyright (C) 2026		John BOTELLA				<john.botella@thersane.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    docstudio/test/phpunit/DocStudioAssociationsTest.php
 * \ingroup docstudio
 * \brief   File associations: defaults, choice of the administrator, choices left to the users
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of the associations (constants set in memory only, nothing written in the database)
 */
class DocStudioAssociationsTest extends TestCase
{
	/** @var array<string,mixed> */
	private $saved = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;

		foreach (array('DOCSTUDIO_VIEW_PDF', 'DOCSTUDIO_VIEW_PDF_CHOICES', 'DOCSTUDIO_VIEW_CSV', 'DOCSTUDIO_VIEW_CSV_CHOICES', 'DOCSTUDIO_VIEW_XLSX', 'DOCSTUDIO_VIEW_XLSX_CHOICES') as $name) {
			$this->saved[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
			unset($conf->global->$name);
		}
	}

	/**
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		foreach ($this->saved as $name => $value) {
			if ($value === null) {
				unset($conf->global->$name);
			} else {
				$conf->global->$name = $value;
			}
		}
	}

	/**
	 * Defaults: the preview of Dolibarr for what the core shows (PDF), else the best viewer
	 *
	 * @return void
	 */
	public function testDefaults()
	{
		global $db;

		$registry = new DocStudio($db);
		$this->assertSame(DocStudio::HANDLER_DOLIBARR, $registry->getDefaultHandler('pdf'));
		$this->assertSame('csv', $registry->getDefaultHandler('csv'));
		$this->assertSame(array('pdf', DocStudio::HANDLER_DOLIBARR, DocStudio::HANDLER_NONE), array_keys($registry->getHandlersFor('pdf')));
		$this->assertSame(DocStudio::HANDLER_DOLIBARR, $registry->getHandler('pdf'));
		$this->assertSame(array(DocStudio::HANDLER_DOLIBARR), $registry->getUserChoices('pdf'));
	}

	/**
	 * Choice of the administrator, and a value that is not a handler of the type falls back to the default
	 *
	 * @return void
	 */
	public function testAdminChoice()
	{
		global $db, $conf;

		$registry = new DocStudio($db);
		$conf->global->DOCSTUDIO_VIEW_PDF = 'pdf';
		$this->assertSame('pdf', $registry->getHandler('pdf'));
		$conf->global->DOCSTUDIO_VIEW_PDF = 'csv';
		$this->assertSame(DocStudio::HANDLER_DOLIBARR, $registry->getHandler('pdf'), 'a viewer that cannot show PDF is ignored');
	}

	/**
	 * Choice of a user: only among the handlers allowed by the administrator
	 *
	 * @return void
	 */
	public function testUserChoice()
	{
		global $db, $conf, $user;

		$registry = new DocStudio($db);
		$someone = clone $user;
		$someone->conf = new stdClass();
		$someone->conf->DOCSTUDIO_VIEW_PDF = 'pdf';

		// No choice left by the administrator: the user preference is ignored
		$this->assertSame(DocStudio::HANDLER_DOLIBARR, $registry->getHandler('pdf', $someone));

		// The administrator allows DocStudio and « none »
		$conf->global->DOCSTUDIO_VIEW_PDF_CHOICES = 'pdf,none,evil';
		$this->assertSame(array(DocStudio::HANDLER_DOLIBARR, 'pdf', DocStudio::HANDLER_NONE), $registry->getUserChoices('pdf'));
		$this->assertSame('pdf', $registry->getHandler('pdf', $someone));

		// A preference outside of the choices is ignored
		$someone->conf->DOCSTUDIO_VIEW_PDF = 'csv';
		$this->assertSame(DocStudio::HANDLER_DOLIBARR, $registry->getHandler('pdf', $someone));
	}

	/**
	 * Attachments of inbox: the handler of the user, else of the administrator, comes first (inbox opens the first one)
	 *
	 * @return void
	 */
	public function testInboxViewersOrder()
	{
		global $db, $conf, $user;

		dol_include_once('/docstudio/class/actions_docstudio.class.php');
		$registry = new DocStudio($db);
		$viewers = $registry->getViewersFor('xlsx', 1000);
		if (!isModEnabled('docstudio') || $registry->getProvider('inbox') === null || !isset($viewers['wopi'], $viewers['office'])) {
			$this->markTestSkipped('Needs inbox and the office server set up on the test database');
		}
		// A mail the office server may read (not in a protected mailbox)
		dol_include_once('/docstudio/class/docstudioinboxprovider.class.php');
		$row = $db->getRow("SELECT m.rowid FROM ".$db->prefix()."inbox_mail as m INNER JOIN ".$db->prefix()."inbox_account as a ON a.rowid = m.fk_account ORDER BY m.rowid DESC");
		$mail = is_object($row) ? (int) $row->rowid : 0;
		if (!$mail || DocStudioInboxProvider::needsSession($db, $mail)) {
			$this->markTestSkipped('Needs a mail of a mailbox not protected in the test database');
		}
		$first = function () use ($db, $mail) {
			$hooks = new ActionsDocStudio($db);
			$object = null;
			$action = '';
			$hm = null;
			$hooks->inboxAttachmentViewers(array('mail' => $mail, 'attachments' => array(1 => array('part' => 1, 'name' => 'a.xlsx', 'extension' => 'xlsx', 'size' => 1000))), $object, $action, $hm);

			return array_column($hooks->results['inboxAttachmentViewers'][1], 'code');
		};
		$savedConf = $user->conf;
		try {
			$user->conf = new stdClass();
			// Nothing chosen: the best viewer alone
			$this->assertSame(array('wopi'), $first());
			// The administrator chooses Office and leaves the office server to the users: Office first
			$conf->global->DOCSTUDIO_VIEW_XLSX = 'office';
			$conf->global->DOCSTUDIO_VIEW_XLSX_CHOICES = 'wopi';
			$this->assertSame(array('office', 'wopi'), $first());
			// The user chooses the office server: it comes first
			$user->conf->DOCSTUDIO_VIEW_XLSX = 'wopi';
			$this->assertSame(array('wopi', 'office'), $first());
		} finally {
			$user->conf = $savedConf;
		}
	}

	/**
	 * Codes reserved for the handlers cannot be used by a viewer
	 *
	 * @return void
	 */
	public function testReservedCodes()
	{
		global $db;

		$registry = new DocStudio($db);
		$registry->load();
		foreach (array(DocStudio::HANDLER_DOLIBARR, DocStudio::HANDLER_NONE) as $code) {
			$this->assertFalse($registry->registerViewer(array('code' => $code, 'extensions' => array('xyz'), 'script' => '/inbox/js/inbox.js')));
		}
		$this->assertTrue($registry->registerViewer(array('code' => 'inbox_test', 'extensions' => array('xyz'), 'script' => '/inbox/js/inbox.js')));
	}
}
