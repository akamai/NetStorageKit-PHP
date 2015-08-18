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

use Akamai\Open\EdgeGrid\Authentication\Nonce;
use Akamai\Open\EdgeGrid\Authentication\Timestamp;

class Authentication
{
    /**
     * @var string Signing key
     */
    protected $key;

    /**
     * @var Key name
     */
    protected $key_name;

    /**
     * @var string reserved, always 0.0.0.0
     */
    protected $reserved = "0.0.0.0";

    /**
     * @var \Akamai\Open\EdgeGrid\Authentication\Timestamp
     */
    protected $timestamp;

    /**
     * @var \Akamai\Open\EdgeGrid\Authentication\Nonce
     */
    protected $nonce;

    protected $path;
    
    protected $action;
    
    public function createAuthHeaders()
    {
        $auth_data = implode(", ", [
            5,
            $this->reserved,
            $this->reserved,
            ($this->timestamp instanceof Timestamp)
                ? (new \DateTime((string) $this->timestamp))->format('U')
                : (new \DateTime())->format('U'),
            ($this->nonce instanceof Nonce) ? (string) $this->nonce : new Nonce(),
            $this->key_name
        ]);
        
        return [
            'X-Akamai-ACS-Auth-Data' => $auth_data,
            'X-Akamai-ACS-Auth-Sign' => $this->signRequest($auth_data)
        ];
    }

    /**
     * Set the auth key and key name
     *
     * @param $key
     * @param $name
     * @return $this
     */
    public function setKey($key, $name)
    {
        $this->key = $key;
        $this->key_name = $name;
        return $this;
    }

    /**
     * @param Timestamp $timestamp
     * @return $this
     */
    public function setTimestamp($timestamp = null)
    {
        $this->timestamp = $timestamp;
        if ($timestamp === null) {
            $this->timestamp = new Timestamp();
        }
        return $this;
    }

    /**
     * @param Nonce $nonce
     * @return $this
     */
    public function setNonce($nonce = null)
    {
        $this->nonce = $nonce;
        if ($nonce === null) {
            $this->nonce = new Nonce();
        }
        return $this;
    }

    /**
     * Set request path
     *
     * @param mixed $path
     * @return $this
     */
    public function setPath($path)
    {
        $url = parse_url($path);
        $this->path = $url['path'];
        
        return $this;
    }

    /**
     * @param string $action
     * @return $this
     */
    public function setAction($action)
    {
        $this->action = $action;
        return $this;
    }

    /**
     * Returns a signature for the request
     *
     * @param string $auth_header
     * @return string
     */
    protected function signRequest($auth_header)
    {
        return $this->makeBase64HmacSha256(
            $this->makeDataToSign($auth_header)
        );
    }

    /**
     * Returns Base64 encoded HMAC-SHA256 Hash
     *
     * @param string $data
     * @param string $key
     * @return string
     */
    protected function makeBase64HmacSha256($data)
    {
        $hash = base64_encode(hash_hmac('sha256', (string) $data, $this->key, true));
        return $hash;
    }
    
    /**
     * Returns a string with all data that will be signed
     *
     * @param string $auth_data_header
     * @return string
     */
    protected function makeDataToSign($auth_data_header)
    {
        return implode('', [
            $auth_data_header,
            $this->path,
            "\n",
            "x-akamai-acs-action:" . trim($this->action),
            "\n"
        ]);
    }
}
