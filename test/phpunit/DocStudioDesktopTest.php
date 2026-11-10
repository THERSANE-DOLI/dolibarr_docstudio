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
 * \file    docstudio/test/phpunit/DocStudioDesktopTest.php
 * \ingroup docstudio
 * \brief   Desktop Office (dav.php): links, tokens of their own kind bound to a computer, WebDAV answers
 */

use PHPUnit\Framework\TestCase;

dol_include_once('/docstudio/class/docstudiodesktop.class.php');

/**
 * Tests of DocStudioDesktop
 */
class DocStudioDesktopTest extends TestCase
{
	/**
	 * Link of the right application, edit only for the types written back, address without query string
	 *
	 * @return void
	 */
	public function testLink()
	{
		global $user;
		$params = array('modulepart' => 'societe', 'file' => '213/test.xlsx', 'entity' => 1);
		$link = DocStudioDesktop::makeLink($user, 1, 'document', $params, 'test.xlsx', 'edit', '192.0.2.10');
		$this->assertIsArray($link);
		$this->assertStringStartsWith('ms-excel:ofe|u|', $link['link']);
		$this->assertSame('Excel', $link['application']);
		$this->assertStringEndsWith('/test.xlsx', $link['url']);
		$this->assertStringNotContainsString('?', $link['url'], 'Office finds the type from the end of the address');

		$doc = DocStudioDesktop::makeLink($user, 1, 'document', array('file' => '213/old.doc'), 'old.doc', 'edit', '192.0.2.10');
		$this->assertStringStartsWith('ms-word:ofv|u|', $doc['link'], 'Old Word format: read only');
		$this->assertNull(DocStudioDesktop::makeLink($user, 1, 'document', array('file' => 'x.pdf'), 'x.pdf', 'view', '192.0.2.10'));
		$this->assertStringContainsString('%20', DocStudioDesktop::makeLink($user, 1, 'document', array('file' => 'a b.pptx'), 'a b.pptx', 'view', '192.0.2.10')['url']);
	}

	/**
	 * The token of a link is of kind « dav », with the hash of the computer, and is not one of the office server
	 *
	 * @return void
	 */
	public function testTokenKind()
	{
		global $user;
		$params = array('modulepart' => 'societe', 'file' => '213/test.xlsx', 'entity' => 1);
		$link = DocStudioDesktop::makeLink($user, 1, 'document', $params, 'test.xlsx', 'edit', '192.0.2.10');
		$this->assertSame(1, preg_match('#/dav\.php/1/([^/]+)/test\.xlsx$#', $link['url'], $m));
		$data = DocStudioWopi::checkToken($m[1], null);
		$this->assertIsArray($data);
		$this->assertSame('dav', $data['k']);
		$this->assertSame(DocStudioDesktop::ipHash('192.0.2.10'), $data['i']);
		$this->assertNotSame(DocStudioDesktop::ipHash('192.0.2.11'), $data['i'], 'Another computer');
		$this->assertStringNotContainsString('192.0.2.10', (string) base64_decode(strtr(explode('.', $m[1])[0], '-_', '+/')), 'The address is not written in the token');
		$this->assertSame(DocStudioWopi::fileId('document', $params, 1), $data['f']);

		$wopi = DocStudioWopi::makeToken($user, 1, 'document', $params, 'edit');
		$this->assertArrayNotHasKey('k', DocStudioWopi::checkToken($wopi['token'], null), 'Token of the office server: no kind, refused by dav.php');
	}

	/**
	 * Lock tokens read from the headers, PROPFIND without lock offered when read only
	 *
	 * @return void
	 */
	public function testWebDav()
	{
		$lock = DocStudioDesktop::newLockToken();
		$this->assertMatchesRegularExpression('/^opaquelocktoken:[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', $lock);
		$this->assertSame(array($lock), DocStudioDesktop::parseLockTokens('<http://x/a.xlsx> (<'.$lock.'>)'));
		$this->assertSame(array(), DocStudioDesktop::parseLockTokens('(<urn:other>)'));

		$xml = DocStudioDesktop::propfindXml('/dav.php/1/t/', 'Offre & prix.xlsx', 10, 1700000000, '1-10', false, 0, '', false);
		$doc = simplexml_load_string($xml);
		$this->assertNotFalse($doc, 'Valid XML even with « & » in the name');
		$this->assertStringContainsString('<D:href>/dav.php/1/t/Offre%20%26%20prix.xlsx</D:href>', $xml);
		$this->assertStringContainsString('<D:supportedlock></D:supportedlock>', $xml, 'Read only: no lock offered');
		$folder = DocStudioDesktop::propfindXml('/dav.php/1/t/', 'a.xlsx', 10, 1700000000, '1-10', true, 1, $lock, true);
		$this->assertSame(2, substr_count($folder, '<D:response>'), 'The folder and its only file');
		$this->assertStringContainsString($lock, $folder);
	}

	/**
	 * Without a secret of the instance, no token is accepted (audit DSTU-011)
	 *
	 * @return void
	 */
	public function testNoInstanceKey()
	{
		global $user, $dolibarr_main_instance_unique_id, $conf;
		$params = array('file' => '213/test.xlsx');
		$token = DocStudioWopi::makeToken($user, 1, 'document', $params, 'view');
		$saved = $dolibarr_main_instance_unique_id;
		$savedConf = isset($conf->file->instance_unique_id) ? $conf->file->instance_unique_id : null;
		$dolibarr_main_instance_unique_id = '';
		$conf->file->instance_unique_id = 'short';
		try {
			$this->assertFalse(DocStudioWopi::hasKey());
			$this->assertNull(DocStudioWopi::checkToken($token['token'], null));
			$this->assertFalse(DocStudioDesktop::isEnabled());
		} finally {
			$dolibarr_main_instance_unique_id = $saved;
			$conf->file->instance_unique_id = $savedConf;
		}
		$this->assertTrue(DocStudioWopi::hasKey());
	}
}
