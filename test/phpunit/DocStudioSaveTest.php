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
	 * Images: the real type must be the type of the extension, then the content is encoded again by the server:
	 * metadata (EXIF) and data hidden after the image are gone, transparency of PNG kept
	 *
	 * @return void
	 */
	public function testImageContent()
	{
		$types = DocStudio::getEditableImageTypes();
		if (!isset($types['png'], $types['jpg'])) {
			$this->markTestSkipped('GD without PNG or JPEG');
		}
		$image = imagecreatetruecolor(40, 30);
		imagealphablending($image, false);
		imagesavealpha($image, true);
		imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
		imagefilledrectangle($image, 10, 10, 20, 20, imagecolorallocate($image, 200, 30, 30));
		ob_start();
		imagepng($image);
		$png = (string) ob_get_clean();
		ob_start();
		imagejpeg($image, null, 90);
		$jpeg = (string) ob_get_clean();
		imagedestroy($image);

		// Type of the content checked, not the name
		$this->assertTrue(DocStudio::isValidContent('png', $this->tmp($png)));
		$this->assertFalse(DocStudio::isValidContent('jpg', $this->tmp($png)));
		$this->assertFalse(DocStudio::isValidContent('png', $this->tmp($jpeg)));
		$this->assertFalse(DocStudio::isValidContent('gif', $this->tmp($png)));
		$this->assertFalse(DocStudio::isValidContent('svg', $this->tmp('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')));
		$this->assertFalse(DocStudio::isValidContent('png', $this->tmp("<?php echo 1; ?>\n".$png)));

		// JPEG with an EXIF segment (camera name) and a script after its end: both removed
		$exif = "Exif\0\0TestCamera-GPS";
		$withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2)."<?php system('id'); ?>";
		$file = $this->tmp($withExif);
		$this->assertTrue(DocStudio::isValidContent('jpg', $file));
		$this->assertTrue(DocStudio::cleanContent('jpg', $file));
		$clean = (string) file_get_contents($file);
		$this->assertStringNotContainsString('TestCamera', $clean);
		$this->assertStringNotContainsString('<?php', $clean);
		$this->assertSame(array(40, 30), array_slice((array) getimagesize($file), 0, 2));

		// PNG: still transparent where it was, still red in the middle
		$file = $this->tmp($png."<script>alert(1)</script>");
		$this->assertTrue(DocStudio::cleanContent('png', $file));
		$this->assertStringNotContainsString('<script>', (string) file_get_contents($file));
		$out = imagecreatefrompng($file);
		$this->assertSame(127, (imagecolorat($out, 2, 2) >> 24) & 0x7F);
		$this->assertSame(200, (imagecolorat($out, 15, 15) >> 16) & 0xFF);
		imagedestroy($out);

		// Not an image: refused
		$this->assertFalse(DocStudio::cleanContent('png', $this->tmp('not an image')));
		// Other types untouched
		$this->assertTrue(DocStudio::cleanContent('pdf', $this->tmp(self::PDF)));
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
