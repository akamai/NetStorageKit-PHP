# Akamai NetStorage for PHP

This library provides the following NetStorage tools
 
- a [FlySystem](http://flysystem.thephpleague.com) adapter for NetStorage FileStore (`\Akamai\NetStorage\FileStoreAdapter`)
- a request signer (`\Akamai\NetStorage\Authentication`)
- a [Guzzle](http://guzzlephp.org) middleware for transparently signing requests to the API (`\Akamai\NetStorage\Handler\Authentication`)

## To Do:

- [ ] A PHP streams layer, so you can use `netstorage.fs://path` with _any_ built-in I/O functions (e.g. `fopen`, `fread()`, and `fputs`, or `file_get_contents()` and `file_put_contents()`)
- [ ] A FlySystem adapter for NetStorage ObjectStore

## Installation

Installation is done via `composer`:

```
$ composer require akamai-open/netstorage
```

## Using the Request Signer

```php
$signer = new Akamai\NetStorage\Authentication();
$signer->setKey($key, $keyName);
$signer->setPath('/' .$cpCode. '/path/to/file');
$signer->setAction('upload');
$headers = $signer->createAuthHeaders();

/*
Return:
[
    'X-Akamai-ACS-Auth-Data' => $authData,
    'X-Akamai-ACS-Auth-Sign' => $signature
];
*/
```

## Using the Guzzle Middleware

To use the middle, add it to the handler stack in the `\GuzzleHttp\Client` or `\Akamai\Open\EdgeGrid\Client` options:

```php
$signer = new \Akamai\NetStorage\Authentication();
$signer->setKey($key, $keyName);

$handler = new \Akamai\NetStorage\Handler\Authentication();
$handler->setSigner($signer);

$stack = \GuzzleHttp\HandlerStack::create();
$stack->push($handler, 'netstorage-handler');

$client = new \Akamai\Open\EdgeGrid\Client([
    'base_uri' => $host,
    'handler' => $stack
]);

// Upload a file:
// Request signature is added transparently
// All parent directories must exist (e.g. /path/to)
$client->put('/' . $cpCode . '/path/to/file', [
    'headers' => [
        'X-Akamai-ACS-Action' => 'version=1&upload&sha1=' .sha1($fileContents)
    ],
    'body' => $fileContents
]);
```

### Using the FlySystem Adapter

The simplest way to interact with NetStorage FileStore is using the `\Akamai\NetStorage\FileStoreAdapter` for [FlySystem](http://flysystem.thephpleague.com).

```php
$signer = new \Akamai\NetStorage\Authentication();
$signer->setKey($key, $keyName);

$handler = new \Akamai\NetStorage\Handler\Authentication();
$handler->setSigner($signer);

$stack = \GuzzleHttp\HandlerStack::create();
$stack->push($handler, 'netstorage-handler');

$client = new \Akamai\Open\EdgeGrid\Client([
    'base_uri' => $host,
    'handler' => $stack
]);

$adapter = new \Akamai\NetStorage\FileStoreAdapter($client, $cpCode);
$fs = new \League\Flysystem\Filesystem($adapter);

// Upload a file:
// cpCode, action, content signature, and request signature is added transparently
// Additionally, all required sub-directories are created transparently
$fs->write('/path/to/file', $fileContents);
```
