<?php
/**
 *
 * Original Author: Davey Shafik <dshafik@akamai.com>
 *
 * For more information visit https://developer.akamai.com
 *
 * Copyright 2014 Akamai Technologies, Inc. All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Akamai\NetStorage;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\AdapterInterface;

class FileStoreAdapter extends AbstractAdapter implements AdapterInterface
{
    use NotSupportingVisibilityTrait, StreamedCopyTrait;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * @var string CPCode
     */
    protected $cpCode;

    public function __construct(\GuzzleHttp\Client $client, $cpCode)
    {
        $this->httpClient = $client;

        $this->cpCode = ltrim($cpCode, '\\/');
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param \League\Flysystem\Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, \League\Flysystem\Config $config)
    {
        try {
            $this->ensurePath($dirname);

            $response = $this->httpClient->put($this->applyPathPrefix($dirname), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('mkdir'),
                ]
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getCode() == 409) {
                throw new \League\Flysystem\FileExistsException($dirname, 409, $e);
            }

            throw $e;
        }

        return $this->getMetadata($dirname);
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        try {
            $action = 'delete';

            $meta = $this->getMetadata($path);
            if ($meta['type'] == 'dir') {
                $action = 'rmdir';
            }

            $this->httpClient->put($this->applyPathPrefix($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue($action),
                ]
            ]);

            return true;
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            return false;
        }
    }

    /**
     * Delete a directory.
     *
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        if (!$this->has($dirname)) {
            return true;
        }

        $dir = $this->listContents($dirname, true);
        foreach ($dir as $file) {
            if (isset($file['children'])) {
                $this->deleteDir($file['path']);
            }

            if (!$this->delete($file['path'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        try {
            $response = $this->httpClient->get($this->applyPathPrefix($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('stat')
                ]
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->getCode() != 404) {
                throw $e;
            }

            return false;
        }

        $xml = simplexml_load_string((string) $response->getBody());

        $meta = $this->handleFileMetaData($xml['directory'], (sizeof($xml->file) > 0) ? $xml->file : null);

        return $meta;
    }

    /**
     * Get the mimetype of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        $response = $this->httpClient->head($this->applyPathPrefix($path), [
            'headers' => [
                'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('download')
            ]
        ]);

        $mimetype = $response->getHeader('Content-Type')[0];

        return [
            'mimetype' => $mimetype
        ];
    }

    /**
     * @param string $path
     * @return string
     */
    public function getPathPrefix()
    {
        return '/' . $this->cpCode . '/'. $this->pathPrefix;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Get the timestamp of a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * Check whether a file exists.
     *
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $response = $this->httpClient->get(rtrim($this->applyPathPrefix($directory), '\\/'), [
            'headers' => [
                'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('dir')
            ]
        ]);
        $xml = simplexml_load_string((string) $response->getBody());

        $baseDir = (string) $xml['directory'];
        $dir = [];
        foreach ($xml->file as $file) {
            $meta = $this->handleFileMetaData($directory, $file);
            $dir[$meta['path']] = $meta;
            if ($meta['type'] == 'dir') {
                if($recursive) {
                    $dir[$meta['path']]['children'] = $this->listContents($meta['path'], $recursive);
                } else {
                    $dir[$meta['path']]['children'] = [];
                }
            }
        }

        return $dir;
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $response = $this->httpClient->get($this->applyPathPrefix($path), [
            'headers' => [
                'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('download'),
            ]
        ]);

        return [
            'contents' => (string) $response->getBody()
        ];
    }

    /**
     * Read a file as a stream.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $response = $this->httpClient->get($this->applyPathPrefix($path), [
            'headers' => [
                'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('download'),
            ]
        ]);

        $stream = \GuzzleHttp\Psr7\StreamWrapper::getResource($response->getBody());
        fseek($stream, 0);

        return [
            'stream' => $stream
        ];
    }

    /**
     * Rename a file.
     *
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        try {
            $this->httpClient->post($this->applyPathPrefix($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('rename', [
                        'destination' => $this->applyPathPrefix($newpath)
                    ]),
                ]
            ]);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, \League\Flysystem\Config $config)
    {
        $this->delete($path);

        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, \League\Flysystem\Config $config)
    {
        $this->delete($path);

        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $contents, \League\Flysystem\Config $config)
    {
        try {
            $this->ensurePath($path);

            // Upload the file
            $this->httpClient->put($this->applyPathPrefix($path), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue(
                        'upload',
                        (is_string($contents)) ? ['sha1' => sha1($contents)] : null
                    ),
                ],
                'body' => $contents,
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            return false;
        }

        $meta = $this->getMetadata($path);
        if (is_string($contents)) {
            $meta['contents'] = $contents;
        }

        return $meta;
    }

    /**
     * Write a new file using a stream.
     *
     * @param string $path
     * @param resource $resource
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, \League\Flysystem\Config $config)
    {
        $meta = $this->write($path, $resource, $config);
        
        if($meta === false) {
            return false;
        }
        
        $meta['stream'] = $resource;
        return $meta;
    }

    /**
     * @param $path
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function ensurePath($path)
    {
        /* Check full path exists */
        $segments = [];
        $checkPath = $path;
        while ($checkPath = dirname($checkPath)) {
            if (empty(ltrim($checkPath, '\\/.')) || $this->has($checkPath)) {
                break;
            }

            $segments[] = $checkPath;
        }

        // Create paths that do not exist yet
        if (sizeof($segments)) {
            foreach (array_reverse($segments) as $segment) {
                $this->createDir($segment, new \League\Flysystem\Config());
            }
        }
    }

    /**
     * @param $action
     * @param array|null $options
     * @return string
     */
    protected function getAcsActionHeaderValue($action, array $options = null)
    {
        $header = 'version=1&action=' . rawurlencode($action);
        $header .= ($options !== null ? '&' . http_build_query($options) : '');
        if (in_array($action, ['dir', 'download', 'du', 'stat'])) {
            $header .= '&format=xml';
        }

        return $header;
    }

    /**
     * @param $baseDir
     * @param $file
     * @return array
     */
    protected function handleFileMetaData($baseDir, $file = null)
    {
        if($baseDir == '') {
            $metaPath = (string) $file['name'];
        } else {
            $metaPath = (string) $baseDir . '/' . (string) $file['name'];
        }
        
        $meta = [
            'type' => (string) $file['type'],
            'path' => $metaPath,
            'visibility' => 'public',
            'timestamp' => (string) $file['mtime'],
        ];

        $attributes = $file->attributes();
        if ($attributes != null) {
            foreach ($attributes as $attr => $value) {
                $attr = (string) $attr;
                if (!isset($meta[$attr])) {
                    $meta[(string) $attr] = (string) $value;
                }
            }
        }

        if (!isset($meta['mimetype']) && $meta['type'] != 'dir') {
            $meta['mimetype'] = \League\Flysystem\Util\MimeType::detectByFileExtension(
                pathinfo($file['name'], PATHINFO_EXTENSION)
            );
        }

        return $meta;
    }
}
