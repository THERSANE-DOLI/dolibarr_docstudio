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
		// « Revoke the tokens »: a new generation refuses every token made before, the id of the file stays
		global $conf;
		$gen = isset($conf->global->DOCSTUDIO_WOPI_TOKEN_GEN) ? $conf->global->DOCSTUDIO_WOPI_TOKEN_GEN : null;
		$conf->global->DOCSTUDIO_WOPI_TOKEN_GEN = (string) ((int) $gen + 1);
		$this->assertNull(DocStudioWopi::checkToken($token['token'], $fileId), 'Revoked');
		$this->assertSame($fileId, DocStudioWopi::fileId('document', $params, 1));
		$conf->global->DOCSTUDIO_WOPI_TOKEN_GEN = $gen;
		$this->assertIsArray(DocStudioWopi::checkToken($token['token'], $fileId));
		$this->assertLessThanOrEqual(dol_now() + 4 * 3600, $token['expire'], '4 hours by default');
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
		$withKey = '<wopi-discovery><proof-key value="x" modulus="'.str_repeat('A', 120).'" exponent="AQAB" oldmodulus="'.str_repeat('B', 120).'" oldexponent="AQAB"/></wopi-discovery>';
		$this->assertCount(2, DocStudioWopi::parseProofKeys($withKey), 'Current and old keys');
		$this->assertSame(array(), DocStudioWopi::parseProofKeys('<wopi-discovery/>'));
	}

	/**
	 * Signature of the office server: a real RSA key, given like the discovery gives it (modulus and exponent)
	 *
	 * @return void
	 */
	public function testProof()
	{
		if (!function_exists('openssl_pkey_new')) {
			$this->markTestSkipped('openssl missing');
		}
		$res = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
		$details = openssl_pkey_get_details($res);
		$keys = array(array('modulus' => base64_encode($details['rsa']['n']), 'exponent' => base64_encode($details['rsa']['e'])));
		$this->assertSame($details['key'], DocStudioWopi::publicKeyPem($keys[0]['modulus'], $keys[0]['exponent']), 'Same public key as openssl');

		$token = 'abc.def';
		$url = 'http://localhost/custom/docstudio/wopi.php/files/0123?access_token=abc.def';
		$ticks = (string) (dol_now() * 10000000 + 621355968000000000);
		$sign = function ($t, $u, $ts) use ($res) {
			$u = strtoupper($u);
			$data = pack('N', strlen($t)).$t.pack('N', strlen($u)).$u.pack('N', 8).pack('J', (int) $ts);
			openssl_sign($data, $sig, $res, OPENSSL_ALGO_SHA256);
			return base64_encode($sig);
		};
		$proof = $sign($token, $url, $ticks);
		$this->assertTrue(DocStudioWopi::checkProof($keys, $token, array($url), $ticks, $proof, ''));
		$this->assertTrue(DocStudioWopi::checkProof($keys, $token, array('http://other/', $url), $ticks, $proof, ''), 'One of the addresses');
		$this->assertTrue(DocStudioWopi::checkProof($keys, $token, array($url), $ticks, 'AAAA', $proof), 'Old proof');
		$this->assertFalse(DocStudioWopi::checkProof($keys, 'other', array($url), $ticks, $proof, ''), 'Other token');
		$this->assertFalse(DocStudioWopi::checkProof($keys, $token, array($url.'&x=1'), $ticks, $proof, ''), 'Other address');
		$old = (string) ((dol_now() - 3600) * 10000000 + 621355968000000000);
		$this->assertFalse(DocStudioWopi::checkProof($keys, $token, array($url), $old, $sign($token, $url, $old), ''), 'Too old');
		$other = openssl_pkey_get_details(openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA)));
		$this->assertFalse(DocStudioWopi::checkProof(array(array('modulus' => base64_encode($other['rsa']['n']), 'exponent' => base64_encode($other['rsa']['e']))), $token, array($url), $ticks, $proof, ''), 'Other key');
		$this->assertFalse(DocStudioWopi::checkProof(array(), $token, array($url), $ticks, $proof, ''), 'No key');
	}

	/**
	 * Addresses allowed to call wopi.php
	 *
	 * @return void
	 */
	public function testAllowedIp()
	{
		global $conf;
		$saved = isset($conf->global->DOCSTUDIO_WOPI_ALLOWED_IPS) ? $conf->global->DOCSTUDIO_WOPI_ALLOWED_IPS : null;
		$conf->global->DOCSTUDIO_WOPI_ALLOWED_IPS = '';
		$this->assertTrue(DocStudioWopi::isAllowedIp('203.0.113.9'), 'Empty: every address');
		$conf->global->DOCSTUDIO_WOPI_ALLOWED_IPS = '127.0.0.1, 10.0.0.0/8 ::1';
		$this->assertTrue(DocStudioWopi::isAllowedIp('127.0.0.1'));
		$this->assertTrue(DocStudioWopi::isAllowedIp('10.20.30.40'));
		$this->assertTrue(DocStudioWopi::isAllowedIp('::1'));
		$this->assertFalse(DocStudioWopi::isAllowedIp('11.0.0.1'));
		$this->assertFalse(DocStudioWopi::isAllowedIp(''));
		$conf->global->DOCSTUDIO_WOPI_ALLOWED_IPS = $saved;
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
			$this->assertFalse(DocStudioWopi::isValidContent('csv', $docx), 'A package is not a CSV');
			@unlink($docx);
		}
		@unlink($fake);
	}

	/**
	 * CSV and TXT written back by the office server: plain text only
	 *
	 * @return void
	 */
	public function testTextContent()
	{
		$this->assertContains('csv', DocStudioWopi::WRITABLE_EXTENSIONS);
		$this->assertContains('txt', DocStudioWopi::WRITABLE_EXTENSIONS);
		$this->assertNotContains('csv', DocStudioWopi::OFFICE_EXTENSIONS, 'CSV shown by the viewer of DocStudio, given to the office server for editing only');
		$cases = array(
			array('csv', "ref;designation;stock\r\nSW-0101;Sweat « Vague »;150\r\n", true, 'UTF-8 with separators and CRLF'),
			array('csv', "ref\tqty\n\"A;1\";2\n", true, 'Tabs and quotes'),
			array('txt', "Ligne 1\nD\xE9but en Windows-1252\f\n", true, 'Single byte encoding, form feed'),
			array('txt', "\xEF\xBB\xBFTexte avec BOM\n", true, 'UTF-8 BOM'),
			array('csv', "a,b\n\x00c,d\n", false, 'NUL byte'),
			array('txt', "\xFF\xFEt\x00e\x00x\x00t\x00", false, 'UTF-16'),
			array('txt', "texte\x1B[31m rouge\n", false, 'Control character'),
			array('csv', "PK\x03\x04rest of a zip", false, 'ZIP package'),
			array('txt', "%PDF-1.4\n%%EOF", false, 'PDF'),
			array('txt', "\x89PNG\r\n\x1A\n", false, 'PNG'),
			array('csv', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1", false, 'Old Office (OLE)'),
		);
		foreach ($cases as $case) {
			list($ext, $content, $expected, $label) = $case;
			$file = tempnam(sys_get_temp_dir(), 'dst');
			file_put_contents($file, $content);
			$this->assertSame($expected, DocStudioWopi::isValidContent($ext, $file), $label);
			@unlink($file);
		}
		// Longer than one chunk: a NUL byte after the first megabyte is still found
		$file = tempnam(sys_get_temp_dir(), 'dst');
		file_put_contents($file, str_repeat("a;b;c\n", 200000)."\x00");
		$this->assertFalse(DocStudioWopi::isValidContent('csv', $file), 'NUL byte after 1 MB');
		file_put_contents($file, str_repeat("a;b;c\n", 200000));
		$this->assertTrue(DocStudioWopi::isValidContent('csv', $file), 'Large CSV');
		@unlink($file);
	}
}
