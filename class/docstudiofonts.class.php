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
 * \file    htdocs/docstudio/class/docstudiofonts.class.php
 * \ingroup docstudio
 * \brief   Fonts of the editors: the 3 standard fonts of PDF, and fonts uploaded by the administrator
 *
 * Uploaded fonts are stored in documents/docstudio/fonts/<slug>/ (TrueType or OpenType only, the formats that can be
 * embedded in a PDF), served to the editors by ajax/font.php. Nothing is ever fetched from outside (GDPR).
 */


/**
 * Fonts of DocStudio
 */
class DocStudioFonts
{
	/** Max size of a font file, in bytes */
	const MAX_UPLOAD = 10485760;

	/** @var string Error of the last call */
	public static $error = '';

	/**
	 * The 3 standard fonts of PDF: no file, always available, Latin characters only (WinAnsi)
	 *
	 * @return array<string,array{key:string,label:string,css:string,standard:string,standardBold:string}>
	 */
	public static function standardFonts()
	{
		return array(
			'std-helvetica' => array('key' => 'std-helvetica', 'label' => 'Helvetica', 'css' => 'Helvetica, Arial, sans-serif', 'standard' => 'Helvetica', 'standardBold' => 'HelveticaBold'),
			'std-times' => array('key' => 'std-times', 'label' => 'Times', 'css' => '"Times New Roman", Times, serif', 'standard' => 'TimesRoman', 'standardBold' => 'TimesRomanBold'),
			'std-courier' => array('key' => 'std-courier', 'label' => 'Courier', 'css' => '"Courier New", Courier, monospace', 'standard' => 'Courier', 'standardBold' => 'CourierBold'),
		);
	}

	/**
	 * @return string Directory of the uploaded fonts of the current entity
	 */
	public static function getDir()
	{
		global $conf;

		$entity = (int) $conf->entity;

		return DOL_DATA_ROOT.($entity > 1 ? '/'.$entity : '').'/docstudio/fonts';
	}

	/**
	 * Uploaded fonts
	 *
	 * @return array<string,array{slug:string,label:string,faces:array<int,string>}> By slug, sorted by label; faces = weight => file
	 */
	public static function listInstalled()
	{
		$fonts = array();
		$dir = self::getDir();
		if (!is_dir($dir)) {
			return $fonts;
		}
		foreach (scandir($dir) as $slug) {
			if (!preg_match('/^u-[a-z0-9-]{1,60}$/', $slug) || !is_dir($dir.'/'.$slug)) {
				continue;
			}
			$meta = json_decode((string) @file_get_contents($dir.'/'.$slug.'/font.json'), true);
			if (!is_array($meta) || empty($meta['faces'][400]) || ($meta['slug'] ?? '') !== $slug) {
				continue;
			}
			$faces = array();
			foreach ($meta['faces'] as $weight => $file) {
				if (in_array((int) $weight, array(400, 700), true) && preg_match('/^(400|700)\.(ttf|otf)$/', (string) $file) && is_file($dir.'/'.$slug.'/'.$file)) {
					$faces[(int) $weight] = (string) $file;
				}
			}
			if (isset($faces[400])) {
				$fonts[$slug] = array('slug' => $slug, 'label' => dol_string_nohtmltag((string) $meta['label']), 'faces' => $faces);
			}
		}
		uasort($fonts, function ($a, $b) {
			return strcasecmp($a['label'], $b['label']);
		});

		return $fonts;
	}

	/**
	 * Fonts given to the editors: the standard ones then the uploaded ones (with the URL of their files)
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function forEditor()
	{
		$list = array_values(self::standardFonts());
		$url = dol_buildpath('/docstudio/ajax/font.php', 1);
		foreach (self::listInstalled() as $slug => $font) {
			$list[] = array(
				'key' => $slug,
				'label' => $font['label'],
				'css' => '"DocStudio-'.$slug.'", sans-serif',
				'family' => 'DocStudio-'.$slug,
				'url' => $url.'?font='.rawurlencode($slug).'&weight=400',
				'urlBold' => isset($font['faces'][700]) ? $url.'?font='.rawurlencode($slug).'&weight=700' : '',
			);
		}

		return $list;
	}

	/**
	 * Full path of a face of an uploaded font
	 *
	 * @param string $slug   Slug asked by the browser
	 * @param int    $weight 400 or 700
	 * @return string        '' if not a font
	 */
	public static function getFilePath($slug, $weight)
	{
		$fonts = self::listInstalled();
		if (!isset($fonts[$slug]['faces'][(int) $weight])) {
			return '';
		}

		return self::getDir().'/'.$slug.'/'.$fonts[$slug]['faces'][(int) $weight];
	}

	/**
	 * Extension from the signature of a font file, '' when it is not a TrueType or OpenType font
	 *
	 * @param string $file File
	 * @return string
	 */
	public static function detectFormat($file)
	{
		$head = (string) @file_get_contents($file, false, null, 0, 4);
		if ($head === "\x00\x01\x00\x00" || $head === 'true') {
			return 'ttf';
		}
		if ($head === 'OTTO') {
			return 'otf';
		}

		return '';
	}

	/**
	 * Install an uploaded font (regular face, bold face optional)
	 *
	 * @param string              $label   Name shown in the editors
	 * @param array<string,mixed> $regular $_FILES entry of the regular face
	 * @param array<string,mixed> $bold    $_FILES entry of the bold face, may be empty
	 * @return string                      Slug, '' if refused (self::$error)
	 */
	public static function installUpload($label, $regular, $bold)
	{
		global $langs;

		$label = trim(dol_string_nohtmltag((string) $label));
		if ($label === '') {
			self::$error = $langs->transnoentitiesnoconv('ErrorFieldRequired', $langs->transnoentitiesnoconv('DocStudioFontName'));
			return '';
		}
		$files = array(400 => $regular);
		if (!empty($bold['tmp_name'])) {
			$files[700] = $bold;
		}
		$faces = array();
		foreach ($files as $weight => $upload) {
			$tmp = !empty($upload['tmp_name']) && is_uploaded_file($upload['tmp_name']) ? $upload['tmp_name'] : '';
			$ext = $tmp !== '' ? self::detectFormat($tmp) : '';
			if ($ext === '' || (int) $upload['size'] > self::MAX_UPLOAD) {
				self::$error = $langs->transnoentitiesnoconv('DocStudioFontErrorFile', (string) ($upload['name'] ?? ''), self::MAX_UPLOAD / 1048576);
				return '';
			}
			$faces[$weight] = array('file' => $weight.'.'.$ext, 'tmp' => $tmp);
		}

		$slug = 'u-'.substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(dol_string_unaccent($label))), '-'), 0, 50);
		if ($slug === 'u-') {
			$slug = 'u-font';
		}
		$base = $slug;
		$i = 2;
		while (is_dir(self::getDir().'/'.$slug)) {
			$slug = $base.'-'.$i++;
		}
		$dir = self::getDir().'/'.$slug;
		if (dol_mkdir($dir) < 0) {
			self::$error = $langs->transnoentitiesnoconv('ErrorFailToCreateDir', $dir);
			return '';
		}
		$meta = array('slug' => $slug, 'label' => $label, 'faces' => array());
		foreach ($faces as $weight => $face) {
			if (!move_uploaded_file($face['tmp'], $dir.'/'.$face['file'])) {
				self::$error = $langs->transnoentitiesnoconv('ErrorFailToCreateFile', $face['file']);
				dol_delete_dir_recursive($dir);
				return '';
			}
			dolChmod($dir.'/'.$face['file']);
			$meta['faces'][$weight] = $face['file'];
		}
		file_put_contents($dir.'/font.json', json_encode($meta));
		dolChmod($dir.'/font.json');

		return $slug;
	}

	/**
	 * Remove an uploaded font
	 *
	 * @param string $slug Slug
	 * @return bool
	 */
	public static function delete($slug)
	{
		if (!isset(self::listInstalled()[$slug])) {
			return false;
		}

		return dol_delete_dir_recursive(self::getDir().'/'.$slug) > 0;
	}
}
