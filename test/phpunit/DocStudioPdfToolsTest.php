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
 * \file    docstudio/test/phpunit/DocStudioPdfToolsTest.php
 * \ingroup docstudio
 * \brief   Server tools of the PDF editor: settings checked, compression keeps the original when nothing is gained
 */

use PHPUnit\Framework\TestCase;

dol_include_once('/docstudio/class/docstudiopdftools.class.php');

/**
 * Tests of DocStudioPdfTools
 */
class DocStudioPdfToolsTest extends TestCase
{
	/** @var array<string,mixed> */
	private $saved = array();

	/**
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf;
		foreach (array('DOCSTUDIO_STIRLING_URL', 'DOCSTUDIO_GHOSTSCRIPT_PATH') as $name) {
			$this->saved[$name] = isset($conf->global->$name) ? $conf->global->$name : null;
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
	 * Only http(s) addresses for Stirling PDF, compression offered when one tool is set
	 *
	 * @return void
	 */
	public function testSettings()
	{
		global $conf;
		$conf->global->DOCSTUDIO_GHOSTSCRIPT_PATH = '';
		$conf->global->DOCSTUDIO_STIRLING_URL = 'file:///etc/passwd';
		$this->assertSame('', DocStudioPdfTools::getStirlingUrl());
		$this->assertFalse(DocStudioPdfTools::canCompress());
		$conf->global->DOCSTUDIO_STIRLING_URL = 'http://127.0.0.1:8080/';
		$this->assertSame('http://127.0.0.1:8080', DocStudioPdfTools::getStirlingUrl());
		$this->assertTrue(DocStudioPdfTools::canCompress());
	}

	/**
	 * Unknown level refused; with Ghostscript, a PDF without images is not made bigger (original kept)
	 *
	 * @return void
	 */
	public function testCompress()
	{
		global $conf;
		$conf->global->DOCSTUDIO_STIRLING_URL = '';
		$conf->global->DOCSTUDIO_GHOSTSCRIPT_PATH = is_executable('/usr/bin/gs') ? '/usr/bin/gs' : '';
		$tools = new DocStudioPdfTools();
		$this->assertFalse($tools->compress('/nonexistent.pdf', 'huge'));
		if (!DocStudioPdfTools::hasGhostscript()) {
			$this->markTestSkipped('Ghostscript not installed');
		}
		// Work directory made by the web server: not writable by the user running the tests
		$dir = DOL_DATA_ROOT.'/docstudio/temp';
		if (is_dir($dir) && !is_writable($dir)) {
			$this->markTestSkipped($dir.' not writable by this user');
		}
		$src = tempnam(sys_get_temp_dir(), 'dsp');
		$ps = tempnam(sys_get_temp_dir(), 'dsps');
		file_put_contents($ps, "%!PS\n/Helvetica findfont 20 scalefont setfont 72 700 moveto (DocStudio) show showpage\n");
		exec('/usr/bin/gs -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -sOutputFile='.escapeshellarg($src).' '.escapeshellarg($ps));
		$result = $tools->compress($src, 'strong');
		$this->assertIsString($result);
		$this->assertSame('%PDF-', file_get_contents($result, false, null, 0, 5));
		$this->assertLessThanOrEqual(filesize($src), filesize($result));
		$tools->clean();
		@unlink($src);
		@unlink($ps);
	}
}
