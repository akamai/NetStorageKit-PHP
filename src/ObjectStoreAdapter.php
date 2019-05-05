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

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use League\Flysystem\AdapterInterface;
use League\Flysystem\FileExistsException;
use Twistor\Flysystem\Exception\NotADirectoryException;

class ObjectStoreAdapter extends AbstractAdapter implements AdapterInterface
{
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
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('stat', ['implicit' => 'yes', 'encoding' => 'utf-8'])
                ]
            ]);
        } catch (RequestException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return false;
        }

        $xml = simplexml_load_string((string) $response->getBody());

        $meta = $this->handleFileMetaData($xml['directory'], (count($xml->file) > 0) ? $xml->file : null);

        return $meta;
    }

    /**
     * Create a directory.
     *
     * @param string                   $dirname directory name
     * @param \League\Flysystem\Config $config
     *
     * @return array|false
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \League\Flysystem\FileExistsException
     */
    public function createDir($dirname, \League\Flysystem\Config $config)
    {
        try {
            if ($dirname === '') {
                throw new FileExistsException($dirname . '/', 400);
            }

            // ObjectStore returns a 200 if the directory already exists
            if ($this->has($dirname)) {
                throw new FileExistsException($dirname, 409);
            }

            $this->ensurePath($dirname);

            $response = $this->httpClient->put($this->applyPathPrefix($dirname), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('mkdir'),
                ]
            ]);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getCode() === 409) {
                throw new \League\Flysystem\FileExistsException($dirname, 409, $e);
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
     * @throws \Twistor\Flysystem\Exception\NotADirectoryException
     */
    public function deleteDir($dirname)
    {
        if (!$this->has($dirname)) {
            return true;
        }

        $dir = $this->listContents($dirname, true);
        $dir = array_reverse($dir);
        foreach ($dir as $file) {
            if (!$this->delete($file['path'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * List contents of a directory.
     *
     * @param string $directory
     * @param bool   $recursive
     * @param null   $resume
     *
     * @return array|\SimpleXMLElement
     * @throws \Twistor\Flysystem\Exception\NotADirectoryException
     */
    public function listContents($directory = '', $recursive = false, $resume = null)
    {
        try {
            $options = ['encoding' => 'utf-8'];
            if ($resume !== null) {
                $options['start'] = $resume;
            }

            $response = $this->httpClient->get($this->applyPathPrefix($directory), [
                'headers' => [
                    'X-Akamai-ACS-Action' => $this->getAcsActionHeaderValue('list', $options)
                ]
            ]);

            $body = (string) $response->getBody();
            if ($body == "deleted") {
                throw new NotADirectoryException();
            }

            $xml = simplexml_load_string((string)$response->getBody());
            if (count($xml->resume) === 1) {
                $new = $this->listContents($directory, $recursive, (string) $xml->resume['start']);
                unset($xml->resume);
                foreach ($new->file as $file) {
                    $node = $xml->addChild($file->getName(), (string) $file);
                    foreach($new->attributes() as $attr => $value) {
                        $node->addAttribute($attr, $value);
                    }
                }
            }

            if ($resume !== null) {
                return $xml;
            }
        } catch (RequestException $e) {
            if ($e->getCode() !== 412) {
                throw new NotADirectoryException();
            }
        }

        $baseDir = (string) $xml['directory'];
        $dir = [];
        foreach ($xml->file as $file) {
            $meta = $this->handleFileMetaData($baseDir, $file);
            $dir[$meta['path']] = $meta;
//            if ($recursive && $meta['type'] === 'dir') {
//                $dir[$meta['path']]['children'] = $this->listContents($meta['path'], $recursive);
//            }
        }

        return $dir;
    }
}
