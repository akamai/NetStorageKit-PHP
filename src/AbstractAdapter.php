<?php
/**
 * Created by PhpStorm.
 * User: dshafik
 * Date: 2019-05-05
 * Time: 03:43
 */

namespace Akamai\NetStorage;

use GuzzleHttp\Exception\RequestException;
use League\Flysystem\Adapter\AbstractAdapter as FlySystemAbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Config;
use League\Flysystem\FileExistsException;

abstract class AbstractAdapter extends FlySystemAbstractAdapter
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

    public function setPathPrefix($prefix)
    {
        try {
            if ($prefix != '') {
                $this->createDir($prefix, new Config());
            }
        } catch (FileExistsException $e) {
            // ignore
        }

        parent::setPathPrefix($prefix);
    }

    /**
     * @param string $path
     * @return string
     */
    public function getPathPrefix()
    {
        $prefix = '/' . $this->cpCode . '/';
        if ($this->pathPrefix != "") {
            $prefix .= ltrim($this->pathPrefix, '\\/');
        }


        return $prefix;
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
            if ($meta['type'] === 'dir') {
                $action = 'rmdir';
            }

            $this->httpClient->post($this->applyPathPrefix($path), [
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
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        try {
            return $this->getMetadata($path);
        } catch (RequestException $e) {
            return false;
        }
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
        try {
            return $this->getMetadata($path);
        } catch (RequestException $e) {
            if ($e->getCode() === 404 || $e->getCode() == 403) {
                return false;
            }

            throw $e;
        }
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
     * @param string                   $path
     * @param string                   $contents
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     * @throws \League\Flysystem\FileExistsException
     */
    public function update($path, $contents, \League\Flysystem\Config $config)
    {
        $this->delete($path);

        return $this->write($path, $contents, $config);
    }

    /**
     * Update a file using a stream.
     *
     * @param string                   $path
     * @param resource                 $resource
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     * @throws \League\Flysystem\FileExistsException
     */
    public function updateStream($path, $resource, \League\Flysystem\Config $config)
    {
        $this->delete($path);

        return $this->writeStream($path, $resource, $config);
    }

    /**
     * Write a new file.
     *
     * @param string                   $path
     * @param string|resource          $contents
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     * @throws \League\Flysystem\FileExistsException
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
                    'Content-Length' => is_string($contents) ? strlen($contents) : fstat($contents)['size'],
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
     * @param string                   $path
     * @param resource                 $resource
     * @param \League\Flysystem\Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     * @throws \League\Flysystem\FileExistsException
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
     * @return void
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \League\Flysystem\FileExistsException
     */
    protected function ensurePath($path)
    {
        $path = $this->applyPathPrefix($path);

        /* Check full path exists */
        $segments = [];
        $checkPath = $path;
        while ($checkPath = dirname($checkPath)) {
            if ($checkPath . '/' === $this->getPathPrefix() || empty(ltrim($checkPath, '\\/.')) || $this->has($checkPath)) {
                break;
            }

            $segments[] = $checkPath;
        }

        // Create paths that do not exist yet
        if (count($segments)) {
            foreach (array_reverse($segments) as $segment) {
                $segment = substr($segment, strlen($this->getPathPrefix()));
                try {
                    $this->createDir($segment, new \League\Flysystem\Config());
                } catch (FileExistsException $e) {
                    continue;
                }
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
        $meta = [
            'type' => (string) $file['type'],
            'path' => (string) $baseDir . '/' . (string) $file['name'],
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

        if (!isset($meta['mimetype']) && $meta['type'] !== 'dir') {
            $meta['mimetype'] = \League\Flysystem\Util\MimeType::detectByFileExtension(
                pathinfo($file['name'], PATHINFO_EXTENSION)
            );
        }

        if (isset($meta['path'])) {
            $meta['path'] = substr($meta['path'], strlen($this->getPathPrefix())-1);
        }

        return $meta;
    }
}