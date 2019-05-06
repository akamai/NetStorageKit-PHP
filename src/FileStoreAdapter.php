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

use League\Flysystem\AdapterInterface;
use League\Flysystem\FileExistsException;

class FileStoreAdapter extends AbstractAdapter implements AdapterInterface
{
    /**
     * Create a directory.
     *
     * @param string                   $dirname directory name
     * @param \League\Flysystem\Config $config
     *
     * @return array|false
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws FileExistsException
     */
    public function createDir($dirname, \League\Flysystem\Config $config)
    {
        try {
            if ($dirname === '') {
                throw new FileExistsException($dirname . '/', 400);
            }

            $this->ensurePath($dirname);

            $response = $this->httpClient->put($this->applyPathPrefix($dirname), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('mkdir'),
                ]
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getCode() == 409) {
                throw new FileExistsException($dirname, 409, $e);
            }

            throw $e;
        }

        return $this->getMetadata($dirname);
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
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $response = $this->httpClient->get($this->applyPathPrefix($directory), [
            'headers' => [
                'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('dir')
            ]
        ]);
        $xml = simplexml_load_string((string) $response->getBody());

        $baseDir = (string) $xml['directory'];
        $dir = [];
        foreach ($xml->file as $file) {
            $meta = $this->handleFileMetaData($baseDir, $file);
            $dir[$meta['path']] = $meta;
            if ($meta['type'] === 'dir') {
                if($recursive) {
                    $dir[$meta['path']]['children'] = $this->listContents($meta['path'], $recursive);
                } else {
                    $dir[$meta['path']]['children'] = [];
                }
            }
        }

        return $dir;
    }
}
