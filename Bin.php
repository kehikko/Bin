<?php

namespace Bin;

class Bin extends \Core\Module
{
    private $root        = null;
    private $access_file = '.access';

    public function __construct()
    {
        parent::__construct();
        $this->root = $this->kernel->expand('{path:data}') . '/bin';
    }

    /**
     * Echo contents to output buffer if possible.
     *
     * @param  string $key         Key to data.
     * @param  bool   $set_headers Auto setup headers (mime-type etc).
     * @param  bool   $allow_cache Whether or not to allow browser side to cache this file, $set_headers must be also true for this to take effect. This also means that If-Modified-Since in request headers takes effect.
     * @return bool   true if ok, false on failures.
     */
    public function readfile($key, $set_headers = false, $allow_cache = false)
    {
        $key  = rtrim($key, '/');
        $file = $this->getDataDir($key, true);
        if (is_file($file)) {
            if ($set_headers) {
                if ($this->setHeaders($file, $allow_cache)) {
                    return true;
                }
            }
            $r = @readfile($file);
            return $r !== false;
        }
        return false;
    }

    public function setHeaders($file, $allow_cache = false)
    {
        if ($allow_cache) {
            header_remove('Cache-Control');
            header_remove('Pragma');
            header_remove('Expires');

            $request_headers = apache_request_headers();
            $modified        = filemtime($file);
            if (isset($request_headers['If-Modified-Since'])) {
                $t = strtotime($request_headers['If-Modified-Since']);
                if ($t !== false && $t >= $modified) {
                    http_response_code(304);
                    return true;
                }
            }

            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        } else {
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('Expires: Fri, 1 Oct 1982 00:00:00 GMT');
        }

        header('Content-Type: ' . mime_content_type($file));
        return false;
    }

    public function read($key, &$content)
    {
        $key  = rtrim($key, '/');
        $file = $this->getDataDir($key, true, false);
        if (is_file($file)) {
            $content = @file_get_contents($file);
            if ($content === false) {
                return false;
            }
            return true;
        }
        return false;
    }

    public function getFilepath($key)
    {
        $key = rtrim($key, '/');
        return $this->getDataDir($key, true, false);
    }

    public function save($key, $content)
    {
        $file = $this->getDataDir($key, true);
        if (@file_put_contents($file, $content) === false) {
            return false;
        }
        return true;
    }

    public function upload($key, &$files = null)
    {
        if (count($_FILES) < 1) {
            kernel::msg('error', 'No file given for upload.');
            return false;
        }

        $dir = $this->getDataDir($key);
        if (!is_dir($dir)) {
            kernel::msg('error', 'File parent directory does not exist for uloaded file: ' . $dir);
            return false;
        }

        foreach ($_FILES as $filedl) {
            $error = false;
            if ($filedl['error']) {
                kernel::msg('error', 'File upload failed: ' . $filedl['name']);
                $error = true;
            } else {
                $filename = $dir . '/' . $filedl['name'];
                if (!@move_uploaded_file($filedl['tmp_name'], $filename)) {
                    kernel::msg('error', 'Moving uploaded file failed, destination: ' . $filename);
                    $error = true;
                }
            }
            $files[] = array(
                'name'  => $filedl['name'],
                'error' => $error,
            );
        }

        return true;
    }

    protected function getDataDir($key, $as_file = false, $create_dir = true)
    {
        $this->checkAccess($key, true);

        $path = $this->root . '/' . $key;

        /* remove all "/.." for security */
        $path = str_replace('/..', '', $path);

        if ($as_file) {
            $path = dirname($path);
        }

        /* creata directory if needed */
        if ($create_dir && !file_exists($path)) {
            if (!@mkdir($path, 0700, true)) {
                throw new \Exception('unable to create data directory for module Bin');
            }
        }

        return $as_file ? $path . '/' . basename($key) : $path;
    }

    public function getFiles($path, $full_paths = false)
    {
        $dir = $this->getDataDir($path, false, false);
        if (is_dir($dir)) {
            $all   = scandir($dir);
            $files = array();
            foreach ($all as $filename) {
                if ($filename == '.' || $filename == '..') {
                    continue;
                }
                $filepath = rtrim($dir, '/') . '/' . ltrim($filename, '/');
                $key      = substr($filepath, strlen($dir) - strlen($path));
                $isdir    = false;
                /* is this a directory? */
                if (is_dir($filepath)) {
                    $isdir = true;
                }

                $files[$filename] = array(
                    'name'     => $filename,
                    'path'     => dirname($key),
                    'key'      => $key,
                    'type'     => $isdir ? 'dir' : 'file',
                    'size'     => filesize($filepath),
                    'modified' => filemtime($filepath),
                    'mimetype' => mime_content_type($filepath),
                );
            }
            ksort($files, SORT_NATURAL);
            return $files;
        }

        return array();
    }

    public function stat($key)
    {
        $filepath = $this->getDataDir($key, true, false);
        if (file_exists($filepath)) {
            $isdir = false;
            if (is_dir($filepath)) {
                $isdir = true;
            }
            $stat = array(
                'name'     => basename($filepath),
                'path'     => dirname($key),
                'key'      => $key,
                'type'     => $isdir ? 'dir' : 'file',
                'size'     => filesize($filepath),
                'modified' => filemtime($filepath),
                'mimetype' => mime_content_type($filepath),
            );
            return $stat;
        }

        return false;
    }

    public function delete($key)
    {
        $path = $this->getDataDir($key, false, false);
        if (!file_exists($path)) {
            kernel::msg('error', 'Trying to delete file that does not exist, key: ' . $key);
            return false;
        }

        $trash_key  = '__trash__/' . basename($path) . '_' . time() . '_' . uniqid();
        $trash_path = $this->getDataDir($trash_key, true, true);

        $r = @rename($path, $trash_path);
        if (!$r) {
            kernel::msg('error', 'Failed to delete file, key: ' . $key);
            return false;
        }

        return true;
    }

    public function folderCreate($key)
    {
        $this->getDataDir($key, false, true);
        return true;
    }

    /**
     * Export folder to an archive file.
     *
     * @param  string $key         Path to folder to export.
     * @param  string $format      Format of the archive, default is zip.
     * @param  string $echo        Echo archive contents to output (default: false).
     * @param  bool   $set_headers Auto setup headers (mime-type etc). $echo must be set fir this to take effect.
     * @return mixed  Archive file or false on failures. Archive is a temporary file and will be removed after request is complete.
     */
    public function export($key, $format = 'zip', $echo = false, $set_headers = false)
    {
        if ($format != 'zip') {
            kernel::msg('error', 'Invalid export format: ' . $format);
            return false;
        }

        $dir = $this->getDataDir($key, false, false);
        if (!is_dir($dir)) {
            $this->setError('Folder does not exist.');
            $this->kernel->log(LOG_ERR, 'Folder does not exist, key: ' . $key);
            return false;
        }

        $archive  = $this->kernel->createTempFile('.' . $format);
        $can_pipe = false;

        if ($format == 'zip') {
            $cmd = 'cd ' . escapeshellarg(dirname($dir)) . ' && zip -q -r ';
            if ($echo) {
                $can_pipe = true;
                $cmd .= '-';
            } else {
                $cmd .= escapeshellarg($archive);
            }
            $cmd .= ' ' . escapeshellarg(basename($dir));
        }

        if ($echo) {
            if ($set_headers) {
                header('Content-Disposition: inline; filename="' . basename($dir) . '.' . $format . '"');
                if ($format == 'zip') {
                    header('Content-Type: application/zip');
                }
            }

            if ($can_pipe) {
                passthru($cmd, $r);
                if ($r === 0) {
                    return true;
                }
            } else {
                exec($cmd, $output, $r);
            }
        } else {
            exec($cmd, $output, $r);
        }

        if ($r !== 0) {
            $this->setError('Creating archive failed.');
            $this->kernel->log(LOG_ERR, 'Creating archive failed, cmd: "' . $cmd . '", key: ' . $key);
            return false;
        } else if ($echo && !$can_pipe) {
            readfile($archive);
        }

        return $archive;
    }

    public function checkAccess($key, $throw_exception = false)
    {
        if ($this->kernel->session->authorize('role:admin')) {
            return true;
        }

        $path  = $this->root;
        $parts = explode('/', $key);
        array_unshift($parts, null);

        foreach ($parts as $part) {
            if ($part !== null && strlen($part) < 1) {
                continue;
            }
            $path = realpath($path . '/' . $part);
            if (!$path) {
                break;
            }
            if (!is_dir($path)) {
                break;
            }
            $access_file = $path . '/' . $this->access_file;
            if (!file_exists($access_file)) {
                continue;
            }
            $accesses = kernel::yaml_read($access_file);
            if (!is_array($accesses)) {
                continue;
            }
            $has_access = false;
            foreach ($accesses as $access) {
                if ($this->kernel->session->authorize($access)) {
                    $has_access = true;
                    break;
                }
            }
            if (!$has_access) {
                if ($throw_exception) {
                    throw new \Exception('Access denied.');
                }
                return false;
            }
        }

        return true;
    }

    public function hashCalculate($key, $algo = false)
    {
        if (!$algo) {
            $algo = $this->getModuleValue('hash');
            if (!$algo) {
                $algo = 'sha256';
            }
        }
        $file = $this->getFilepath($key);
        if (!is_file($file)) {
            throw new \Exception('File not found.');
        }
        return hash_file($algo, $file);
    }

    public function hashVerify($key, $hash, $algo = false)
    {
        return $this->hashCalculate($key, $algo) == $hash;
    }
}
