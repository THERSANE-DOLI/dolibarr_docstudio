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
 * \file    docstudio/test/phpunit/DocStudioFontsTest.php
 * \ingroup docstudio
 * \brief   Fonts of the editors: only TrueType / OpenType files, only from the font directory
 */

use PHPUnit\Framework\TestCase;

dol_include_once('/docstudio/class/docstudiofonts.class.php');

/**
 * Tests of DocStudioFonts
 */
class DocStudioFontsTest extends TestCase
{
	/**
	 * @param string $content
	 * @return string
	 */
	private function tmp($content)
	{
		$file = tempnam(sys_get_temp_dir(), 'dsf');
		file_put_contents($file, $content);

		return $file;
	}

	/**
	 * Signature of the file decides, never its name
	 *
	 * @return void
	 */
	public function testDetectFormat()
	{
		$this->assertSame('ttf', DocStudioFonts::detectFormat($this->tmp("\x00\x01\x00\x00rest")));
		$this->assertSame('otf', DocStudioFonts::detectFormat($this->tmp('OTTOrest')));
		$this->assertSame('', DocStudioFonts::detectFormat($this->tmp('wOF2rest')), 'WOFF2 cannot be embedded in a PDF');
		$this->assertSame('', DocStudioFonts::detectFormat($this->tmp('<?php echo 1;')));
	}

	/**
	 * Standard fonts always there, uploaded fonts with a URL, paths only inside the font directory
	 *
	 * @return void
	 */
	public function testListAndPaths()
	{
		$list = DocStudioFonts::forEditor();
		$this->assertSame('std-helvetica', $list[0]['key']);
		$this->assertSame('', DocStudioFonts::getFilePath('../../conf', 400));
		$this->assertSame('', DocStudioFonts::getFilePath('u-missing', 400));
		foreach (DocStudioFonts::listInstalled() as $slug => $font) {
			$path = DocStudioFonts::getFilePath($slug, 400);
			$this->assertStringStartsWith(DocStudioFonts::getDir().'/'.$slug.'/', $path);
			$this->assertSame('', DocStudioFonts::getFilePath($slug, 500), 'only weights 400 and 700');
		}
	}
}
