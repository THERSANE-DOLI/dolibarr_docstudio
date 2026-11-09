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
 * \file    docstudio/test/phpunit/DocStudioSaveTest.php
 * \ingroup docstudio
 * \brief   Saving of an editor: content checked, permission to write, names of copies, copy and replacement
 */

use PHPUnit\Framework\TestCase;

/**
 * Tests of the saving of the provider of documents (in the ECM directory of the test instance)
 */
class DocStudioSaveTest extends TestCase
{
	/** @var string */
	private static $dir;

	/** @var string Minimal valid PDF */
	const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n";

	/**
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf;

		self::$dir = $conf->ecm->dir_output.'/docstudio-save-test';
		dol_mkdir(self::$dir);
		file_put_contents(self::$dir.'/original.pdf', self::PDF);
	}

	/**
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		global $db;

		foreach (glob(self::$dir.'/{,.}*', GLOB_BRACE) as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}
		@rmdir(self::$dir);
		$db->query("DELETE FROM ".$db->prefix()."ecm_files WHERE filepath = 'ecm/docstudio-save-test'");
	}

	/**
	 * @param User|null $fuser User, admin by default
	 * @return DocStudioDocumentProvider
	 */
	private function provider($fuser = null)
	{
		global $db, $user;

		$provider = new DocStudioDocumentProvider($db);
		$this->assertSame(1, $provider->load(array('modulepart' => 'ecm', 'file' => 'docstudio-save-test/original.pdf', 'entity' => 1), $fuser ? $fuser : $user));

		return $provider;
	}

	/**
	 * @param string $content
	 * @return string Temporary file
	 */
	private function tmp($content)
	{
		$file = tempnam(sys_get_temp_dir(), 'dst');
		file_put_contents($file, $content);

		return $file;
	}

	/**
	 * Only a real PDF is accepted for a PDF (no HTML before the header, end marker present), nothing for other types
	 *
	 * @return void
	 */
	public function testContentCheck()
	{
		$this->assertTrue(DocStudio::isValidContent('pdf', $this->tmp(self::PDF)));
		$this->assertFalse(DocStudio::isValidContent('pdf', $this->tmp("<html><script>alert(1)</script>%PDF-1.4\n%%EOF")));
		$this->assertFalse(DocStudio::isValidContent('pdf', $this->tmp("%PDF-1.4 truncated")));
		$this->assertFalse(DocStudio::isValidContent('pdf', $this->tmp('')));
		$this->assertFalse(DocStudio::isValidContent('png', $this->tmp(self::PDF)));
	}

	/**
	 * Names of copies: no directory, not hidden, extension of the original forced
	 *
	 * @return void
	 */
	public function testCopyNames()
	{
		$provider = $this->provider();
		$this->assertSame('copy.pdf', $provider->cleanCopyName('copy.pdf'));
		$this->assertSame('copy.pdf', $provider->cleanCopyName('copy'));
		$this->assertSame('evil-php.pdf', $provider->cleanCopyName('evil.php'));
		$this->assertSame('evil-php.pdf', $provider->cleanCopyName('evil.php.pdf'));
		$this->assertSame('passwd.pdf', $provider->cleanCopyName('../../etc/passwd'));
		$this->assertSame('', $provider->cleanCopyName('.htaccess'));
		$this->assertSame('', $provider->cleanCopyName(''));
	}

	/**
	 * Copy next to the original, existing name asked again, replacement in place
	 *
	 * @return void
	 */
	public function testSaveCopyAndReplace()
	{
		global $user;

		$provider = $this->provider();
		$this->assertTrue($provider->canWrite($user));

		$res = $provider->save($this->tmp(self::PDF."% copy\n"), 'copy', 'copy.pdf', false, $user);
		$this->assertSame(1, $res['result'], $res['error']);
		$this->assertSame('copy.pdf', $res['filename']);
		$this->assertFileExists(self::$dir.'/copy.pdf');

		$res = $provider->save($this->tmp(self::PDF), 'copy', 'copy.pdf', false, $user);
		$this->assertSame(0, $res['result']);
		$this->assertSame(1, $res['exists']);

		$res = $provider->save($this->tmp(self::PDF."% replaced\n"), 'replace', '', false, $user);
		$this->assertSame(1, $res['result'], $res['error']);
		$this->assertStringContainsString('% replaced', file_get_contents(self::$dir.'/original.pdf'));
		$this->assertCount(0, glob(self::$dir.'/.docstudio-*'), 'no temporary file left');
	}

	/**
	 * External users and users without permission never write
	 *
	 * @return void
	 */
	public function testNoWriteWithoutPermission()
	{
		global $db, $user;

		$provider = $this->provider();
		$external = clone $user;
		$external->socid = 1;
		$this->assertFalse($provider->canWrite($external));

		$reader = new User($db);
		$reader->id = 999997;
		$reader->admin = 0;
		$reader->rights = new stdClass();
		$reader->rights->ecm = new stdClass();
		$reader->rights->ecm->read = 1;
		$this->assertFalse($provider->canWrite($reader));
		$res = $provider->save($this->tmp(self::PDF), 'replace', '', false, $reader);
		$this->assertSame(-1, $res['result']);
	}

	/**
	 * Editing associations: the PDF editor by default, « no editing » possible
	 *
	 * @return void
	 */
	public function testEditAssociations()
	{
		global $db, $conf;

		$registry = new DocStudio($db);
		$this->assertSame('pdfedit', $registry->getHandler('pdf', null, DocStudio::ACTION_EDIT));
		$this->assertArrayNotHasKey(DocStudio::HANDLER_DOLIBARR, $registry->getHandlersFor('pdf', DocStudio::ACTION_EDIT));
		$conf->global->DOCSTUDIO_EDIT_PDF = DocStudio::HANDLER_NONE;
		$this->assertSame(DocStudio::HANDLER_NONE, $registry->getHandler('pdf', null, DocStudio::ACTION_EDIT));
		unset($conf->global->DOCSTUDIO_EDIT_PDF);
		$this->assertArrayNotHasKey('pdfedit', $registry->getViewersFor('pdf', 100));
		$this->assertArrayHasKey('pdfedit', $registry->getViewersFor('pdf', 100, DocStudio::ACTION_EDIT));
	}
}
