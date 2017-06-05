<?php

namespace Bin;

/**
 * Bin webdav client.
 */
class Bin extends Core\Module
{
	private $url      = null;
	private $username = null;
	private $password = null;

	/* resize values for images when */
	private $maxWidth  = 0;
	private $maxHeight = 0;

	/* crop image to these values when they are more than 0 */
	private $cropWidth  = 0;
	private $cropHeight = 0;

	public function __construct()
	{
		parent::__construct();

		$this->url      = $this->getModuleValue('webdav', 'url');
		$this->username = $this->getModuleValue('webdav', 'username');
		$this->password = $this->getModuleValue('webdav', 'password');
	}

	public function setMaxWidth($value)
	{
		$value = intval($value);
		if ($value >= 0)
		{
			$this->maxWidth = $value;
		}
	}

	public function setMaxHeight($value)
	{
		$value = intval($value);
		if ($value >= 0)
		{
			$this->maxHeight = $value;
		}
	}

	public function setCropWidth($value)
	{
		$value = intval($value);
		if ($value >= 0)
		{
			$this->cropWidth = $value;
		}
	}

	public function setCropHeight($value)
	{
		$value = intval($value);
		if ($value >= 0)
		{
			$this->cropHeight = $value;
		}
	}

	/**
	 * Echo contents to output buffer if possible.
	 *
	 * @param  string $key         Key to data.
	 * @param  bool   $set_headers Auto setup headers (mime-type etc).
	 * @return bool   true if ok, false on failures.
	 */
	public function readfile($key, $set_headers = true)
	{
		$filename = $this->cacheFile($key);

		if (is_file($filename))
		{
			/* do possible resize if type is image */
			$filename = $this->resizeImage($filename);
			/* do possible crop if type is image */
			$filename = $this->cropImage($filename);

			if ($set_headers)
			{
				header('Content-Type: ' . mime_content_type($filename));
			}

			return @readfile($filename);
		}

		return false;
	}

	public function read($key, &$content)
	{
		$filename = $this->cacheFile($key);

		if (is_file($filename))
		{
			$content = @file_get_contents($filename);
			if ($content === false)
			{
				return false;
			}
			return true;
		}

		return false;
	}

	public function save($key, $content, $hashCheck = true)
	{
		if (!$this->cacheCheck($key, $filepath, $filename))
		{
			/* create cache directory */
			@mkdir($filepath, 0700, true);
		}

		/* create remote directory */
		$this->createDirectory(dirname($key));

		/* write content to cache file */
		$file = $filepath . '/' . $filename;
		$r    = file_put_contents($file, $content);
		if (!$r)
		{
			$this->kernel->log(LOG_ERR, 'failed to cache contents for key ' . $key . ' to cache ' . $file);
			return false;
		}

		/* open file for reading */
		$filesize = filesize($file);
		$f        = fopen($file, 'r');
		if (!$f)
		{
			$this->kernel->log(LOG_ERR, 'failed to open file for reading for key ' . $key . ' from cache file ' . $file);
			return false;
		}

		/* setup curl and execute request */
		$curl = $this->getCurl($key);
		curl_setopt($curl, CURLOPT_PUT, 1);
		curl_setopt($curl, CURLOPT_INFILE, $f);
		curl_setopt($curl, CURLOPT_INFILESIZE, $filesize);
		curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		/* done */
		curl_close($curl);
		fclose($f);

		/* check result code for failures */
		if ($code >= 400)
		{
			$this->kernel->log(LOG_ERR, 'file content save to webdav failed with code ' . $code . ' (key: ' . $key . ')');
			unlink($file);
			return false;
		}

		return true;
	}

	public function upload($path)
	{
		if (count($_FILES) < 1)
		{
			return false;
		}

		$upload = array_shift($_FILES);
		if ($upload['error'])
		{
			return false;
		}

		/* create filename and get cache info */
		$key = $path . '/' . rawurlencode($upload['name']);
		// $key = $path . '/' . Validate::generateKey() . '_' . rawurlencode($upload['name']);
		if (!$this->cacheCheck($key, $filepath, $filename))
		{
			/* create cache directory */
			@mkdir($filepath, 0700, true);
		}

		/* create remote directory */
		$this->createDirectory($path);

		$file = $filepath . '/' . $filename;
		if (!@move_uploaded_file($upload['tmp_name'], $file))
		{
			$this->kernel->log(LOG_ERR, 'failed to move uploaded file ' . $key . ' to cache ' . $file);
			return false;
		}

		/* open file for reading */
		$filesize = filesize($file);
		$f        = fopen($file, 'r');
		if (!$f)
		{
			$this->kernel->log(LOG_ERR, 'failed to open cache file ' . $file);
			return false;
		}

		/* setup curl and execute request */
		$curl = $this->getCurl($key);
		curl_setopt($curl, CURLOPT_PUT, 1);
		curl_setopt($curl, CURLOPT_INFILE, $f);
		curl_setopt($curl, CURLOPT_INFILESIZE, $filesize);
		curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		/* done */
		curl_close($curl);
		fclose($f);

		/* check result code for failures */
		if ($code >= 400)
		{
			$this->kernel->log(LOG_ERR, 'failed to upload file ' . $key . ' to webdav ' . $this->url . ', return code ' . $code);
			unlink($file);
			return false;
		}

		return false;
	}

	public function getFiles($parent)
	{
		$files    = array();
		$url_path = parse_url($this->url, PHP_URL_PATH);

		$curl = $this->getCurl($parent);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
		$data = curl_exec($curl);
		if ($data === false)
		{
			$this->setError('Curl call failed, reason: ' . curl_error($curl));
			curl_close($curl);
			return false;
		}
		curl_close($curl);

		$xml = new DOMDocument();
		if ($xml->loadXML($data) === false)
		{
			$this->setError('Failed to parse XML response.');
			return false;
		}
		$xpath   = new DOMXPath($xml);
		$entries = $xpath->query('//d:multistatus/d:response');

		foreach ($entries as $entry)
		{
			/* get full file path */
			$href = $xpath->query('d:href', $entry);
			if ($href->length !== 1)
			{
				continue;
			}

			/* remove start of url from path */
			$path = substr($href->item(0)->textContent, strlen($url_path));
			if ($path[0] == '/')
			{
				$path = substr($path, 1);
			}
			$name = basename($path);

			/* skip "parent" */
			$rest = substr($path, strlen($parent));
			if ($rest == '/' || $rest == '')
			{
				continue;
			}

			/* is this a directory? */
			$isdir       = false;
			$tcollection = $xpath->query('d:propstat/d:prop/d:resourcetype/d:collection', $entry);
			if ($tcollection->length === 1)
			{
				$isdir = true;
			}

			/* get modification time */
			$modified = 0;
			$tmod     = $xpath->query('d:propstat/d:prop/d:getlastmodified', $entry);
			if ($tmod->length === 1)
			{
				$modified = strtotime($tmod->item(0)->textContent);
			}

			/* get content type */
			$mimetype = 'application/plain';
			$ttype    = $xpath->query('d:propstat/d:prop/d:getcontenttype', $entry);
			if ($ttype->length === 1)
			{
				$mimetype = $ttype->item(0)->textContent;
			}

			/* get size */
			$size  = 0;
			$tsize = $xpath->query('d:propstat/d:prop/d:getcontentlength', $entry);
			if ($tsize->length === 1)
			{
				$size = intval($tsize->item(0)->textContent);
			}

			$files[$name] = array(
				'name'     => $name,
				'key'      => $path,
				'type'     => $isdir ? 'dir' : 'file',
				'size'     => $size,
				'modified' => $modified,
				'mimetype' => $mimetype,
			);
		}

		ksort($files, SORT_NATURAL);

		return $files;
	}

	private function cacheFile($path)
	{
		if (!$this->cacheCheck($path, $filepath, $filename))
		{
			/* create cache directory */
			@mkdir($filepath, 0700, true);
			$this->kernel->log(LOG_DEBUG, 'File not found from cache: ' . $path);
		}
		else
		{
			$this->kernel->log(LOG_DEBUG, 'File found from cache: ' . $path);
			return $filepath . '/' . $filename;
		}

		/* open file */
		$file = $filepath . '/' . $filename;
		$f    = fopen($file, 'w');
		if (!$f)
		{
			return false;
		}

		/* setup curl and execute request */
		$curl = $this->getCurl($path);
		curl_setopt($curl, CURLOPT_FILE, $f);
		curl_setopt($curl, CURLOPT_HEADER, 0);
		curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		/* done */
		curl_close($curl);
		fclose($f);

		/* check result code for failures */
		if ($code >= 400)
		{
			unlink($file);
			return false;
		}

		return $file;
	}

	private function cacheCheck($path, &$filepath = false, &$filename = false)
	{
		if (substr($path, -1) == '/')
		{
			$path = substr($path, 0, -1);
		}

		/* setup file hash and path */
		$filename = md5($path);
		$filepath = $this->kernel->expand('{path:cache}/bin/' . $filename[0] . '/' . $filename[1]);
		$file     = $filepath . '/' . $filename;

		/* check if file is already in cache */
		if (is_file($file))
		{
			$this->kernel->log(LOG_DEBUG, 'Check for cached file modification time: ' . $path);

			$modified = $this->cacheGet('webdav_modified_' . $filename);

			if ($modified === null)
			{
				/* fetch file modification time from webdav */
				$url_path = parse_url($this->url, PHP_URL_PATH);
				$curl     = $this->getCurl($path);
				curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PROPFIND');
				$data = curl_exec($curl);
				if ($data === false)
				{
					$this->setError('Curl call failed, reason: ' . curl_error($curl));
					curl_close($curl);
					return false;
				}
				curl_close($curl);

				/* parse xml response */
				$xml = new DOMDocument();
				if ($xml->loadXML($data) === false)
				{
					$this->setError('Failed to parse XML response.');
					return false;
				}
				$xpath = new DOMXPath($xml);

				/* get modification time */
				$tmod = $xpath->query('//d:multistatus/d:response/d:propstat/d:prop/d:getlastmodified');
				if ($tmod->length === 1)
				{
					$modified = strtotime($tmod->item(0)->textContent);
				}
				else
				{
					/* was this a directory? */
					return false;
				}

				$this->cacheSet('webdav_modified_' . $filename, $modified, 30);
			}

			$cache_modified = filemtime($file);
			$this->kernel->log(LOG_DEBUG, 'File modified: ' . $modified . ', cache modified: ' . $cache_modified . ' (' . ($modified > $cache_modified ? 'refresh' : 'ok') . ')');

			if ($modified > $cache_modified)
			{
				/* cache is older than real file */
				unlink($file);
				return false;
			}

			return true;
		}

		$this->kernel->log(LOG_DEBUG, 'Cache check return false: ' . $path);
		return false;
	}

	private function createDirectory($path)
	{
		$curl = $this->getCurl($path);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'MKCOL');
		curl_exec($curl);
		curl_close($curl);
	}

	private function getCurl($path)
	{
		$url  = $this->url . '/' . $path;
		$curl = curl_init();

		curl_setopt($curl, CURLOPT_URL, $url);

		curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
		curl_setopt($curl, CURLOPT_USERPWD, $this->username . ':' . $this->password);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

		return $curl;
	}

	private function resizeImage($filename_original)
	{
		$mimetype = mime_content_type($filename_original);

		/* no resize if not image or resize not requested */
		if (strpos($mimetype, 'image') !== 0)
		{
			return $filename_original;
		}
		if ($this->maxWidth < 1 && $this->maxHeight < 1)
		{
			return $filename_original;
		}

		$name_resized = 'cache_resized_image_' . $this->maxWidth . '*' . $this->maxHeight . '/' . $filename_original;

		/* check cache */
		if ($this->cacheCheck($name_resized, $filepath, $filename))
		{
			return $filepath . '/' . $filename;
		}

		$file                 = $filepath . '/' . $filename;
		list($width, $height) = getimagesize($filename_original);
		$this->kernel->log(LOG_DEBUG, 'resize image file ' . $file . ', not found from cache');

		/* calculate new width and height */
		$new_width  = $width;
		$new_height = $height;
		if ($this->maxWidth < $new_width && $this->maxWidth > 0)
		{
			$new_width  = $this->maxWidth;
			$new_height = $height * $new_width / $width;
		}
		if ($this->maxHeight < $new_height && $this->maxHeight > 0)
		{
			$new_height = $this->maxHeight;
			$new_width  = $width * $new_height / $height;
		}

		/* if size not changed */
		if ($new_width == $width && $new_height == $height)
		{
			return $filename_original;
		}

		/* create cache directory */
		if (!is_dir($filepath))
		{
			/* create cache directory */
			@mkdir($filepath, 0700, true);
		}

		/* get type */
		list($image, $type) = explode('/', $mimetype);

		/* resize */
		$imagick = new Imagick($filename_original);
		$imagick->resizeImage($new_width, $new_height, Imagick::FILTER_LANCZOS, 1.0);
		$imagick->writeImage($type . ':' . $file);
		$imagick->clear();
		$imagick->destroy();

		return $file;
	}

	private function cropImage($filename_original)
	{
		$mimetype = mime_content_type($filename_original);

		/* no resize if not image or resize not requested */
		if (strpos($mimetype, 'image') !== 0)
		{
			return $filename_original;
		}
		if ($this->cropWidth < 1 && $this->cropHeight < 1)
		{
			return $filename_original;
		}

		$name_resized = 'cache_cropped_image_' . $this->cropWidth . '*' . $this->cropHeight . '/' . $filename_original;

		/* check cache */
		if ($this->cacheCheck($name_resized, $filepath, $filename))
		{
			$this->kernel->log(LOG_DEBUG, 'Cropped image file found from cache: ' . $file);
			return $filepath . '/' . $filename;
		}

		$file                 = $filepath . '/' . $filename;
		list($width, $height) = getimagesize($filename_original);
		$this->kernel->log(LOG_DEBUG, 'Cropped image file not found from cache: ' . $file);

		/* calculate new width and height */
		$x = 0;
		$y = 0;
		if (($this->cropWidth / $this->cropHeight) > ($width / $height))
		{
			$n      = $width / $this->cropWidth * $this->cropHeight;
			$y      = ($height - $n) / 2;
			$height = $n;
		}
		else
		{
			$n     = $height / $this->cropHeight * $this->cropWidth;
			$x     = ($width - $n) / 2;
			$width = $n;
		}

		/* create cache directory */
		if (!is_dir($filepath))
		{
			/* create cache directory */
			@mkdir($filepath, 0700, true);
		}

		/* get type */
		list($image, $type) = explode('/', $mimetype);

		/* resize */
		$imagick = new Imagick($filename_original);
		$imagick->cropImage($width, $height, $x, $y);
		$imagick->resizeImage($this->cropWidth, $this->cropHeight, Imagick::FILTER_LANCZOS, 1.0);
		$imagick->writeImage($type . ':' . $file);
		$imagick->clear();
		$imagick->destroy();

		return $file;
	}
}
