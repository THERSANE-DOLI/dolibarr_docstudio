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
 * \file    docstudio/test/phpunit/DocStudioSecurityTest.php
 * \ingroup docstudio
 * \brief   Security contract of DocStudio: who may read which file, which declarations of other modules are accepted
 */

use PHPUnit\Framework\TestCase;

/**
 * Security tests of the provider of documents and of the registry
 */
class DocStudioSecurityTest extends TestCase
{
	/** @var string Test directory inside the ECM directory */
	private static $dir;

	/** @var string Directory outside of the data directory, reached by a symbolic link */
	private static $extDir;

	/**
	 * Files used by the tests, in the ECM directory of the test instance
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf;

		self::$dir = $conf->ecm->dir_output.'/docstudio-test';
		dol_mkdir(self::$dir);
		file_put_contents(self::$dir.'/note.txt', "Hello DocStudio\n");
		file_put_contents(self::$dir.'/.hidden.txt', "hidden\n");
		file_put_contents(self::$dir.'/big.txt', str_repeat('x', DocStudio::MAX_TEXT_SIZE + 1));
		file_put_contents(self::$dir.'/script.exe.noexe', 'MZ');
		@unlink(self::$dir.'/escape.txt');
		symlink('/etc/hostname', self::$dir.'/escape.txt');

		// Directory of the data directory moved to another disk by the administrator (symbolic link)
		self::$extDir = sys_get_temp_dir().'/docstudio-ext-test';
		dol_mkdir(self::$extDir);
		file_put_contents(self::$extDir.'/moved.txt', "moved\n");
		@unlink(self::$extDir.'/escape.txt');
		symlink('/etc/hostname', self::$extDir.'/escape.txt');
		@unlink(self::$dir.'/linkeddir');
		symlink(self::$extDir, self::$dir.'/linkeddir');
	}

	/**
	 * Remove the test files
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		foreach (array('note.txt', '.hidden.txt', 'big.txt', 'script.exe.noexe', 'escape.txt') as $file) {
			@unlink(self::$dir.'/'.$file);
		}
		@unlink(self::$dir.'/linkeddir');
		@rmdir(self::$dir);
		@unlink(self::$extDir.'/moved.txt');
		@unlink(self::$extDir.'/escape.txt');
		@rmdir(self::$extDir);
	}

	/**
	 * Accepted: file in a directory that is a symbolic link to another disk. Refused: file inside it that is a
	 * symbolic link leaving its directory.
	 *
	 * @return void
	 */
	public function testSymlinkedDirectory()
	{
		list($res, $provider) = $this->load(array('file' => 'docstudio-test/linkeddir/moved.txt'));
		$this->assertSame(1, $res, $provider->error);
		list($res) = $this->load(array('file' => 'docstudio-test/linkeddir/escape.txt'));
		$this->assertSame(-1, $res, 'symbolic link to /etc/hostname in a linked directory must be refused');
	}

	/**
	 * @param array<string,mixed> $params Parameters of the provider
	 * @param User|null           $fuser  User, the admin by default
	 * @return array{0:int,1:DocStudioDocumentProvider}
	 */
	private function load(array $params, $fuser = null)
	{
		global $db, $user;

		$provider = new DocStudioDocumentProvider($db);
		$res = $provider->load($params + array('modulepart' => 'ecm', 'entity' => 1), $fuser ? $fuser : $user);

		return array($res, $provider);
	}

	/**
	 * Paths that could leave their directory are refused before any check
	 *
	 * @return void
	 */
	public function testUnsafeRelativePaths()
	{
		$bad = array('', '../conf/conf.php', 'a/../../conf.php', '..', '/etc/passwd', 'a\\..\\b', "a\0b.txt", 'a|b', 'php://filter/resource=x',
			'file:///etc/passwd', '.htaccess', 'dir/.git/config', str_repeat('a', 1100));
		foreach ($bad as $path) {
			$this->assertFalse(DocStudioDocumentProvider::isSafeRelativePath($path), 'accepted: '.$path);
		}
		foreach (array('note.txt', 'docstudio-test/note.txt', 'PR2401-0001/PR2401-0001.pdf', 'a..b.txt') as $path) {
			$this->assertTrue(DocStudioDocumentProvider::isSafeRelativePath($path), 'refused: '.$path);
		}
	}

	/**
	 * A readable file is given with its name and size, never its path
	 *
	 * @return void
	 */
	public function testReadableFile()
	{
		list($res, $provider) = $this->load(array('file' => 'docstudio-test/note.txt'));
		$this->assertSame(1, $res, $provider->error);
		$this->assertSame('note.txt', $provider->getFileName());
		$this->assertSame('txt', $provider->getExtension());
		$this->assertSame(16, $provider->getSize());
		$this->assertStringNotContainsString(DOL_DATA_ROOT, json_encode($provider->getParams()));
		$this->assertStringStartsWith(DOL_URL_ROOT.'/document.php?modulepart=ecm&file=', $provider->getDownloadUrl());
	}

	/**
	 * Refused: parent directory, hidden file, symbolic link leaving the data directory, missing file
	 *
	 * @return void
	 */
	public function testRefusedFiles()
	{
		list($res) = $this->load(array('file' => 'docstudio-test/../../../conf/conf.php'));
		$this->assertSame(-1, $res);
		list($res) = $this->load(array('file' => 'docstudio-test/.hidden.txt'));
		$this->assertSame(-1, $res);
		list($res) = $this->load(array('file' => 'docstudio-test/escape.txt'));
		$this->assertSame(-1, $res, 'symbolic link to /etc/hostname must be refused');
		list($res) = $this->load(array('file' => 'docstudio-test/missing.txt'));
		$this->assertSame(0, $res);
		list($res) = $this->load(array('modulepart' => '', 'file' => 'docstudio-test/note.txt'));
		$this->assertSame(-1, $res);
	}

	/**
	 * Another entity than the current one is refused without multicompany
	 *
	 * @return void
	 */
	public function testOtherEntityRefused()
	{
		list($res) = $this->load(array('file' => 'docstudio-test/note.txt', 'entity' => 2));
		$this->assertSame(-1, $res);
	}

	/**
	 * External users are refused, users without the permission of the document too
	 *
	 * @return void
	 */
	public function testUsersRefused()
	{
		global $db, $user;

		$external = clone $user;
		$external->socid = 1;
		$external->admin = 0;
		list($res) = $this->load(array('file' => 'docstudio-test/note.txt'), $external);
		$this->assertSame(-1, $res, 'external user without the permission of DocStudio');

		// A user without any permission
		$nobody = new User($db);
		$nobody->id = 999999;
		$nobody->admin = 0;
		$nobody->rights = new stdClass();
		list($res) = $this->load(array('file' => 'docstudio-test/note.txt'), $nobody);
		$this->assertSame(-1, $res);
	}

	/**
	 * Internal user restricted to his own customers (no « see all customers »): documents of a thirdparty he is not
	 * the sales representative of are refused in reading and writing, like document.php of Dolibarr 24
	 * (checkUserAccessToDocumentObject). Before Dolibarr 24 the function does not exist: same result as document.php.
	 *
	 * @return void
	 */
	public function testRestrictedSalesRepresentative()
	{
		global $db, $conf, $user;

		$socid = (int) $db->getRow("SELECT rowid FROM ".$db->prefix()."societe ORDER BY rowid")->rowid;
		$dir = $conf->societe->multidir_output[$conf->entity].'/'.$socid;
		dol_mkdir($dir);
		file_put_contents($dir.'/docstudio-test.txt', "restricted\n");
		$params = array('modulepart' => 'societe', 'file' => $socid.'/docstudio-test.txt');

		$restricted = new User($db);
		$restricted->id = 999997;
		$restricted->admin = 0;
		$restricted->socid = 0;
		$restricted->rights = new stdClass();
		$restricted->rights->societe = new stdClass();
		$restricted->rights->societe->lire = 1;
		$restricted->rights->societe->creer = 1;
		$restricted->rights->societe->client = new stdClass();
		$restricted->rights->societe->client->voir = 0;

		try {
			$withCheck = function_exists('checkUserAccessToDocumentObject');
			list($res, $provider) = $this->load($params, $restricted);
			$this->assertSame($withCheck ? -1 : 1, $res, $provider->error);

			// Same user allowed to see all customers
			$restricted->rights->societe->client->voir = 1;
			list($res, $provider) = $this->load($params, $restricted);
			$this->assertSame(1, $res, $provider->error);
			$this->assertTrue($provider->canWrite($restricted));

			// Writing checked per object too (file loaded by the administrator)
			$restricted->rights->societe->client->voir = 0;
			list($res, $provider) = $this->load($params, $user);
			$this->assertSame(1, $res, $provider->error);
			$this->assertSame(!$withCheck, $provider->canWrite($restricted));

			// No module refuses the download on the test instance
			$this->assertTrue($provider->runDownloadHooks($user));
		} finally {
			@unlink($dir.'/docstudio-test.txt');
		}
	}

	/**
	 * External users with the permission of DocStudio: documents of their own thirdparty only, and only for a
	 * modulepart where the owner is known
	 *
	 * @return void
	 */
	public function testExternalUserWithPermission()
	{
		global $db, $conf;

		$socid = (int) $db->getRow("SELECT rowid FROM ".$db->prefix()."societe ORDER BY rowid")->rowid;
		$other = $db->getRow("SELECT rowid FROM ".$db->prefix()."societe WHERE rowid <> ".$socid." ORDER BY rowid");
		$dir = $conf->societe->multidir_output[$conf->entity].'/'.$socid;
		dol_mkdir($dir);
		file_put_contents($dir.'/docstudio-test.txt', "external\n");

		$external = new User($db);
		$external->id = 999998;
		$external->admin = 0;
		$external->socid = $socid;
		$external->rights = new stdClass();
		$external->rights->societe = new stdClass();
		$external->rights->societe->lire = 1;
		$external->rights->ecm = new stdClass();
		$external->rights->ecm->read = 1;

		try {
			// Without the permission of DocStudio
			list($res) = $this->load(array('modulepart' => 'societe', 'file' => $socid.'/docstudio-test.txt'), $external);
			$this->assertSame(-1, $res);

			$external->rights->docstudio = new stdClass();
			$external->rights->docstudio->external = new stdClass();
			$external->rights->docstudio->external->read = 1;

			// Own thirdparty
			list($res, $provider) = $this->load(array('modulepart' => 'societe', 'file' => $socid.'/docstudio-test.txt'), $external);
			$this->assertSame(1, $res, $provider->error);

			// Another thirdparty
			if (is_object($other)) {
				$external->socid = (int) $other->rowid;
				list($res) = $this->load(array('modulepart' => 'societe', 'file' => $socid.'/docstudio-test.txt'), $external);
				$this->assertSame(-1, $res);
				$external->socid = $socid;
			}

			// Modulepart without known owner (ECM): refused, where document.php would allow it
			list($res) = $this->load(array('file' => 'docstudio-test/note.txt'), $external);
			$this->assertSame(-1, $res);
		} finally {
			@unlink($dir.'/docstudio-test.txt');
		}
	}

	/**
	 * Only files a viewer can show are served: .noexe and too big text files have no viewer
	 *
	 * @return void
	 */
	public function testViewersForFiles()
	{
		global $db;

		$registry = new DocStudio($db);
		$this->assertArrayHasKey('pdf', $registry->getViewersFor('pdf', 1000));
		$this->assertArrayHasKey('image', $registry->getViewersFor('svg', 1000));
		$this->assertSame(array('csv', 'text'), array_keys($registry->getViewersFor('csv', 1000)));
		$this->assertCount(0, $registry->getViewersFor('noexe', 10));
		$this->assertCount(0, $registry->getViewersFor('exe', 10));
		$this->assertCount(0, $registry->getViewersFor('txt', DocStudio::MAX_TEXT_SIZE + 1));
		$this->assertNull($registry->getProvider('unknown'));
		$this->assertInstanceOf('DocStudioDocumentProvider', $registry->getProvider('document'));
	}

	/**
	 * Declarations of other modules: local module files only, no impersonation of DocStudio, no other class
	 *
	 * @return void
	 */
	public function testRegistryDeclarations()
	{
		global $db;

		$registry = new DocStudio($db);
		$registry->load();
		$base = array('code' => 'other_viewer', 'label' => 'X', 'extensions' => array('xyz'));
		$refused = array(
			'//evil.example/x.js', 'https://evil.example/x.js', '/inbox/../docstudio/js/docstudio.js', '/inbox/js/missing.js',
			'/docstudio/js/viewers/pdf.js', 'inbox/js/inbox.js', '/inbox/js/inbox.css',
		);
		foreach ($refused as $script) {
			$this->assertFalse($registry->registerViewer($base + array('script' => $script)), 'accepted script '.$script);
		}
		// Bad code, no extension, duplicate code
		$this->assertFalse($registry->registerViewer(array('code' => 'Bad-Code', 'extensions' => array('xyz'), 'script' => '/docstudio/js/docstudio.js')));
		$this->assertFalse($registry->registerViewer(array('code' => 'pdf', 'extensions' => array('pdf'), 'script' => '/docstudio/js/docstudio.js')));

		// Providers: code, class name and file are checked; a class that is not a provider is refused when used
		$this->assertFalse($registry->registerProvider('document', 'X', '/docstudio/class/docstudio.class.php'));
		$this->assertFalse($registry->registerProvider('evil', 'X', '/docstudio/../../conf/conf.php'));
		$this->assertTrue($registry->registerProvider('notprovider', 'DocStudio', '/docstudio/class/docstudio.class.php'));
		$this->assertNull($registry->getProvider('notprovider'));
	}
}
