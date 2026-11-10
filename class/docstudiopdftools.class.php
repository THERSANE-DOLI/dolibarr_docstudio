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
 * \file    htdocs/docstudio/class/docstudiopdftools.class.php
 * \ingroup docstudio
 * \brief   PDF tools run by the server: Ghostscript on the Dolibarr server, Stirling PDF (optional server).
 *
 * Compression: Ghostscript first (images resampled and encoded again in JPEG, the text stays text), Stirling PDF when
 * Ghostscript is not set or fails. The original is kept when the result is not smaller.
 *
 * Redaction is not done here: the zone redaction of Stirling PDF (1.3 and 2.x) only draws boxes over the text, which
 * stays in the file (measured 2026-11-10). DocStudio keeps its own redaction (pages made images, then checked).
 */

/**
 * Ghostscript and Stirling PDF for the editors of DocStudio
 */
class DocStudioPdfTools
{
	/**
	 * Levels of compression: resolution and JPEG quality for Ghostscript, optimizeLevel for Stirling PDF
	 * (Stirling has nothing between lossless and 150 dpi: light falls back to lossless).
	 */
	const LEVELS = array(
		'light' => array('dpi' => 200, 'jpeg' => 85, 'stirling' => 2),
		'medium' => array('dpi' => 150, 'jpeg' => 80, 'stirling' => 6),
		'strong' => array('dpi' => 100, 'jpeg' => 70, 'stirling' => 8),
	);

	/** @var string */
	public $error = '';

	/** @var string What made the result: 'ghostscript', 'stirling' or 'none' (original kept) */
	public $usedTool = '';

	/** @var string[] Work files to remove */
	protected $workFiles = array();

	/**
	 * @return bool A Stirling PDF server is set
	 */
	public static function hasStirling()
	{
		return self::getStirlingUrl() !== '';
	}

	/**
	 * @return bool Ghostscript is set (its path is not checked here)
	 */
	public static function hasGhostscript()
	{
		return trim(getDolGlobalString('DOCSTUDIO_GHOSTSCRIPT_PATH')) !== '' && function_exists('exec');
	}

	/**
	 * @return bool The editors may offer the compression
	 */
	public static function canCompress()
	{
		return self::hasGhostscript() || self::hasStirling();
	}

	/**
	 * @return string
	 */
	public static function getStirlingUrl()
	{
		$url = trim(getDolGlobalString('DOCSTUDIO_STIRLING_URL'));

		return preg_match('/^https?:\/\/[^\s]+$/i', $url) ? rtrim($url, '/') : '';
	}

	/**
	 * @return string API key of Stirling PDF (stored encrypted), never given to the browser
	 */
	protected static function getStirlingKey()
	{
		$key = getDolGlobalString('DOCSTUDIO_STIRLING_API_KEY');

		return $key !== '' ? (string) dolDecrypt($key) : '';
	}

	/**
	 * @return int Seconds
	 */
	protected static function getTimeout()
	{
		return max(10, getDolGlobalInt('DOCSTUDIO_STIRLING_TIMEOUT', 120));
	}

	/**
	 * Version of Stirling PDF, for the test of the setup
	 *
	 * @return string Version, '' with $this->error when it cannot be reached
	 */
	public function getStirlingVersion()
	{
		$url = self::getStirlingUrl();
		if ($url === '') {
			$this->error = 'Not set';
			return '';
		}
		$ch = curl_init($url.'/api/v1/info/status');
		$headers = array('Accept: application/json');
		$key = self::getStirlingKey();
		if ($key !== '') {
			$headers[] = 'X-API-KEY: '.$key;
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 10);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		$body = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		if ($body === false) {
			$this->error = $curlError;
			return '';
		}
		$json = json_decode((string) $body, true);
		if ($code !== 200 || !is_array($json) || empty($json['version'])) {
			$this->error = 'HTTP '.$code;
			return '';
		}

		return (string) $json['version'];
	}

	/**
	 * Version of Ghostscript, for the test of the setup
	 *
	 * @return string Version, '' with $this->error when it does not run
	 */
	public function getGhostscriptVersion()
	{
		if (!self::hasGhostscript()) {
			$this->error = 'Not set';
			return '';
		}
		$output = array();
		$code = -1;
		exec(escapeshellarg(self::getGhostscriptBin()).' --version 2>&1', $output, $code);
		$version = trim(implode(' ', $output));
		if ($code !== 0 || !preg_match('/^\d+(\.\d+)+$/', $version)) {
			$this->error = dol_trunc($version, 200);
			return '';
		}

		return $version;
	}

	/**
	 * @return string
	 */
	protected static function getGhostscriptBin()
	{
		return trim(getDolGlobalString('DOCSTUDIO_GHOSTSCRIPT_PATH'));
	}

	/**
	 * Compress a PDF
	 *
	 * @param string $file  PDF to compress (not changed)
	 * @param string $level Key of LEVELS
	 * @return string|false Path of the result (may be $file when nothing is gained), false with $this->error
	 */
	public function compress($file, $level)
	{
		if (!isset(self::LEVELS[$level])) {
			$this->error = 'Unknown level';
			return false;
		}
		$params = self::LEVELS[$level];
		$result = false;
		if (self::hasGhostscript()) {
			$result = $this->compressWithGhostscript($file, $params['dpi'], $params['jpeg']);
			$this->usedTool = 'ghostscript';
		}
		if ($result === false && self::hasStirling()) {
			$result = $this->compressWithStirling($file, $params['stirling']);
			$this->usedTool = 'stirling';
		}
		if ($result === false) {
			if ($this->error === '') {
				$this->error = 'No tool';
			}
			return false;
		}
		clearstatcache();
		if (filesize($result) >= filesize($file)) {
			$this->usedTool = 'none';
			return $file;
		}

		return $result;
	}

	/**
	 * Images resampled to $dpi (only when 20 % above) and encoded again in JPEG, black and white scans without loss
	 *
	 * @param string $file
	 * @param int    $dpi
	 * @param int    $jpegQuality
	 * @return string|false
	 */
	protected function compressWithGhostscript($file, $dpi, $jpegQuality)
	{
		$out = $this->newWorkFile();
		if ($out === false) {
			return false;
		}
		$args = array(
			// The PDF comes from a user: no file nor device access outside of the output
			'-dSAFER', '-dBATCH', '-dNOPAUSE', '-dQUIET',
			'-sDEVICE=pdfwrite',
			'-dDetectDuplicateImages=true',
			'-dDownsampleColorImages=true', '-dColorImageDownsampleType=/Bicubic',
			'-dColorImageResolution='.((int) $dpi), '-dColorImageDownsampleThreshold=1.2',
			'-dDownsampleGrayImages=true', '-dGrayImageDownsampleType=/Bicubic',
			'-dGrayImageResolution='.((int) $dpi), '-dGrayImageDownsampleThreshold=1.2',
			'-dAutoFilterColorImages=false', '-dColorImageFilter=/DCTEncode',
			'-dAutoFilterGrayImages=false', '-dGrayImageFilter=/DCTEncode',
			'-dJPEGQ='.((int) $jpegQuality),
			'-dDownsampleMonoImages=false',
			'-sOutputFile='.$out,
			'-f', $file,
		);
		$cmd = escapeshellarg(self::getGhostscriptBin()).' '.implode(' ', array_map('escapeshellarg', $args)).' 2>&1';
		if (is_executable('/usr/bin/timeout')) {
			$cmd = '/usr/bin/timeout '.self::getTimeout().' '.$cmd;
		}
		$output = array();
		$code = -1;
		exec($cmd, $output, $code);
		clearstatcache();
		if ($code !== 0 || !is_file($out) || filesize($out) == 0) {
			// Not blocking: Stirling PDF is tried next when it is set
			dol_syslog(__METHOD__.' Ghostscript failed (code '.$code.'): '.dol_trunc(implode(' ', $output), 500), LOG_WARNING);
			$this->error = 'Ghostscript';
			return false;
		}

		return $out;
	}

	/**
	 * @param string $file
	 * @param int    $optimizeLevel
	 * @return string|false
	 */
	protected function compressWithStirling($file, $optimizeLevel)
	{
		return $this->callStirling('/api/v1/misc/compress-pdf', array(
			array('fileInput', null, $file),
			array('optimizeLevel', (string) $optimizeLevel),
			array('expectedOutputSize', ''),
			array('linearize', 'false'),
			array('normalize', 'false'),
			array('grayscale', 'false'),
		));
	}

	/**
	 * Call of Stirling PDF, the answer written in a work file
	 *
	 * @param string $endpoint
	 * @param array<int,array{0:string,1:?string,2?:string}> $parts array(name, value) or array(name, null, file path)
	 * @return string|false
	 */
	protected function callStirling($endpoint, array $parts)
	{
		$fields = array();
		foreach ($parts as $part) {
			$fields[$part[0]] = isset($part[2]) ? new CURLFile($part[2], 'application/pdf', 'document.pdf') : (string) $part[1];
		}
		$out = $this->newWorkFile();
		if ($out === false) {
			return false;
		}
		$fh = fopen($out, 'wb');
		if ($fh === false) {
			$this->error = 'Work file';
			return false;
		}
		$headers = array();
		$key = self::getStirlingKey();
		if ($key !== '') {
			$headers[] = 'X-API-KEY: '.$key;
		}
		$ch = curl_init(self::getStirlingUrl().$endpoint);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_FILE, $fh);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::getTimeout());
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		$ok = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlError = curl_error($ch);
		curl_close($ch);
		fclose($fh);
		clearstatcache();
		if ($ok === false || $code !== 200 || !is_file($out) || filesize($out) == 0) {
			$detail = $ok === false ? $curlError : 'HTTP '.$code;
			dol_syslog(__METHOD__.' Stirling PDF '.$endpoint.' failed: '.$detail, LOG_WARNING);
			$this->error = 'Stirling PDF: '.$detail;
			return false;
		}
		// A PDF is expected (an error page of a proxy would be refused here)
		$head = (string) file_get_contents($out, false, null, 0, 5);
		if ($head !== '%PDF-') {
			$this->error = 'Stirling PDF: not a PDF';
			return false;
		}

		return $out;
	}

	/**
	 * @return string|false
	 */
	protected function newWorkFile()
	{
		$dir = DOL_DATA_ROOT.'/docstudio/temp';
		if (dol_mkdir($dir) < 0) {
			$this->error = 'Work directory';
			return false;
		}
		$file = $dir.'/work-'.dol_hash(uniqid((string) mt_rand(), true), 'md5').'.pdf';
		$this->workFiles[] = $file;

		return $file;
	}

	/**
	 * Remove the work files (after the answer is sent)
	 *
	 * @return void
	 */
	public function clean()
	{
		foreach ($this->workFiles as $file) {
			if (is_file($file)) {
				@unlink($file);
			}
		}
		$this->workFiles = array();
	}
}
