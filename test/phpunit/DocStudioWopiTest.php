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
 * \file    docstudio/test/phpunit/DocStudioWopiTest.php
 * \ingroup docstudio
 * \brief   WOPI host: tokens signed and bound to one file, discovery read without network entities, content checked
 */

use PHPUnit\Framework\TestCase;

dol_include_once('/docstudio/class/docstudiowopi.class.php');

/**
 * Tests of DocStudioWopi
 */
class DocStudioWopiTest extends TestCase
{
	/**
	 * A token is valid for its file only, any change of it is refused
	 *
	 * @return void
	 */
	public function testToken()
	{
		global $user;
		$params = array('modulepart' => 'company', 'file' => '213/doc.docx');
		$fileId = DocStudioWopi::fileId('document', $params, 1);
		$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $fileId);
		$this->assertSame($fileId, DocStudioWopi::fileId('document', array_reverse($params, true), 1), 'Order of the parameters does not matter');
		$this->assertNotSame($fileId, DocStudioWopi::fileId('document', $params, 2), 'Another entity, another file');

		$token = DocStudioWopi::makeToken($user, 1, 'document', $params, 'edit');
		$data = DocStudioWopi::checkToken($token['token'], $fileId);
		$this->assertIsArray($data);
		$this->assertSame('edit', $data['m']);
		$this->assertSame((int) $user->id, (int) $data['u']);

		$this->assertNull(DocStudioWopi::checkToken($token['token'], DocStudioWopi::fileId('document', array('file' => 'other.docx'), 1)), 'Other file');
		list($payload, $sig) = explode('.', $token['token']);
		$forged = rtrim(strtr(base64_encode(str_replace('"m":"edit"', '"m":"edit"', (string) base64_decode(strtr($payload, '-_', '+/'))).' '), '+/', '-_'), '=');
		$this->assertNull(DocStudioWopi::checkToken($forged.'.'.$sig, $fileId), 'Payload changed');
		$this->assertNull(DocStudioWopi::checkToken($payload.'.'.strrev($sig), $fileId), 'Signature changed');
		$this->assertNull(DocStudioWopi::checkToken('', $fileId));
		$this->assertNull(DocStudioWopi::checkToken(str_repeat('a', 5000).'.b', $fileId));
	}

	/**
	 * Actions of the discovery, without entities of the network
	 *
	 * @return void
	 */
	public function testDiscovery()
	{
		$xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><wopi-discovery><net-zone><app name="writer">'
			.'<action ext="docx" name="edit" urlsrc="http://office.test/browser/cool.html?"/>'
			.'<action ext="docx" name="view" urlsrc="http://office.test/browser/cool.html?&lt;ui=UI_LLCC&amp;&gt;"/>'
			.'<action ext="js" name="view" urlsrc="javascript:alert(1)"/>'
			.'<action ext="pdf" name="view" urlsrc="http://office.test/browser/cool.html?"/>'
			.'</app></net-zone></wopi-discovery>';
		$actions = DocStudioWopi::parseDiscovery($xml);
		$this->assertArrayHasKey('docx', $actions);
		$this->assertSame('http://office.test/browser/cool.html?', $actions['docx']['edit']);
		$this->assertArrayNotHasKey('js', $actions, 'Only http(s) addresses');
		$this->assertSame(array(), DocStudioWopi::parseDiscovery('not xml'));
	}

	/**
	 * What the office server writes back must be a package of the type of the file
	 *
	 * @return void
	 */
	public function testContent()
	{
		$dir = sys_get_temp_dir();
		$fake = tempnam($dir, 'dsw');
		file_put_contents($fake, '<html>not a docx</html>');
		$this->assertFalse(DocStudioWopi::isValidContent('docx', $fake));
		if (class_exists('ZipArchive')) {
			$docx = $dir.'/dsw-'.uniqid().'.docx';
			$zip = new ZipArchive();
			$zip->open($docx, ZipArchive::CREATE);
			$zip->addFromString('[Content_Types].xml', '<Types/>');
			$zip->close();
			$this->assertTrue(DocStudioWopi::isValidContent('docx', $docx));
			$this->assertFalse(DocStudioWopi::isValidContent('odt', $docx), 'An OpenDocument has a mimetype entry');
			$this->assertFalse(DocStudioWopi::isValidContent('pdf', $docx), 'Not a type written back');
			@unlink($docx);
		}
		@unlink($fake);
	}
}
