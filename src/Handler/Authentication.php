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
namespace Akamai\NetStorage\Handler;

use Akamai\NetStorage\Authentication as Signer;
use Psr\Http\Message\RequestInterface;

class Authentication
{
    /**
     * @var \Akamai\NetStorage\Authentication
     */
    protected $signer;

    public function setSigner(\Akamai\NetStorage\Authentication $auth = null)
    {
        $this->signer = $auth;
        if ($this->signer === null) {
            $this->signer = new Signer();
        }
    }
    
    public function __invoke(callable $handler)
    {
        return function (
            RequestInterface $request,
            array $config
        ) use ($handler) {
            $action = $request->getHeader('X-Akamai-ACS-Action');

            if (sizeof($action) === 0) {
                return $handler($request, $config);
            }
            
            if (!$this->signer) {
                throw new \Exception("You must call setSigner before trying to sign a request");
            }

            $this->signer->setPath($request->getUri()->getPath())
                ->setAction($action[0]);
            
            foreach ($this->signer->createAuthHeaders() as $header => $value) {
                $request = $request->withHeader($header, $value);
            }

            return $handler($request, $config);
        };
    }
}
