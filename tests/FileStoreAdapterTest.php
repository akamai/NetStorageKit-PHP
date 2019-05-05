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
namespace Akamai\NetStorage\Tests;

use League\Flysystem\FileExistsException;

class FileStoreAdapterTest extends \PHPUnit_Framework_TestCase
{
    protected $key = "netstorage-key";

    protected $keyName = 'key-name';

    protected $host = 'testing.akamaihd.net.example.org';

    protected $cpCode = '123456';

    /**
     * @var \Akamai\NetStorage\FileStoreAdapter
     */
    protected $fs;

    public function __construct($name = null, array $data = array(), $dataName = '')
    {
        // Override credential properties above here to generate new fixtures

        parent::__construct($name, $data, $dataName);
    }

    public function setUp()
    {
        if (!file_exists(__DIR__ . '/fixtures/file-store')) {
            mkdir(__DIR__ . '/fixtures/file-store', 0777, true);
        }

        $handler = new \Akamai\NetStorage\Handler\Authentication();
        $handler->setSigner((new \Akamai\NetStorage\Authentication())->setKey($this->key, $this->keyName));

        $stack = \Dshafik\GuzzleHttp\VcrHandler::turnOn(
            __DIR__ . '/fixtures/file-store/' . lcfirst(substr($this->getName(), 4)) . '.json'
        );
        $stack->push($handler, 'netstorage-handler');

        $client = new \Akamai\Open\EdgeGrid\Client([
            'base_uri' => $this->host,
            'handler' => $stack
        ]);
        $this->adapter = new \Akamai\NetStorage\FileStoreAdapter($client, $this->cpCode);
        $this->adapter->setPathPrefix('/test');
        $this->fs = new \League\Flysystem\Filesystem($this->adapter);
    }

    public function tearDown()
    {
        try {
            $this->adapter->setPathPrefix('');
            $this->fs->deleteDir('/test');
        } catch (\Exception $e) {

        }
    }

    public function testCreateDir()
    {
        $temp = uniqid();
        try {
            $this->assertTrue($this->fs->createDir('/' . $temp));
        } finally {
            $this->assertTrue($this->fs->deleteDir('/' . $temp));
        }
    }

    /**
     * @expectedException \League\Flysystem\FileExistsException
     * @expectedExceptionMessageRegExp /^File already exists at path: (.*?)$/
     */
    public function testCreateDirExists()
    {
        $temp = uniqid();

        try {
            $this->assertTrue($this->fs->createDir('/' . $temp));
            $this->fs->createDir('/' . $temp);
        } finally {
            $this->assertTrue($this->fs->deleteDir('/' . $temp));
        }
    }

    /**
     * @expectedException \League\Flysystem\FileExistsException
     * @expectedExceptionCode 400
     */
    public function testCreateDirInvalid()
    {
        $this->fs->createDir('/');
    }

    public function testDelete()
    {
        $this->assertTrue($this->fs->write('/test/HelloWorld.txt', "Hello World"));
        $this->assertTrue($this->fs->delete('/test/HelloWorld.txt'));
    }

    public function testDeleteDir()
    {
        $temp = uniqid();

        $this->assertTrue($this->fs->write('/test/nested/subdir/' . $temp . '/example.txt', __METHOD__));
        $this->assertSame(__METHOD__, $this->fs->read('/test/nested/subdir/' . $temp . '/example.txt'));
        $this->assertTrue($this->fs->deleteDir('/test'));
    }

    /**
     * @expectedException \League\Flysystem\FileNotFoundException
     * @expectedExceptionMessage File not found at path: test/non-existent
     */
    public function testDeleteNonExistent()
    {
        $this->fs->delete('/test/non-existent');
    }

    public function testGetMetadata()
    {
        $this->fs->write('/test/image.jpg', "Not really a JPEG");
        $this->assertSame("Not really a JPEG", $this->fs->read('/test/image.jpg'));
        $meta = $this->fs->getMetadata('/test/image.jpg');

        $expected = [
            'type' => 'file',
            'path' => '/test/image.jpg',
            'visibility' => 'public',
            'name' => 'image.jpg',
            'size' => '17',
            'md5' => '4fd61fa9838732f3d114536fdb28aa2f',
            'mimetype' => 'image/jpeg',
        ];

        $this->assertArrayPartial($expected, $meta);
    }

    public function testGetMetadataDir()
    {
        $this->testWrite();
        $meta = $this->fs->getMetadata('/test');

        $expected = [
            'type' => 'dir',
            'path' => '/test',
            'visibility' => 'public',
            'name' => 'test',
        ];

        $this->assertArrayPartial($expected, $meta);
    }

    /**
     * @expectedException \League\Flysystem\FileNotFoundException
     * @expectedExceptionMessage File not found at path: test/non-existent
     */
    public function testGetMetadataNonExistent()
    {
        $this->fs->getMetadata('/test/non-existent');
    }

    public function testGetMimetype()
    {
        try {
            $this->testWrite();
        } catch (FileExistsException $e) { }
        $this->assertSame('text/plain', $this->fs->getMimetype('/test/example.txt'));

        $this->fs->write('/test/image.jpg', "Not really a JPEG");
        $this->assertSame('image/jpeg', $this->fs->getMimetype('/test/image.jpg'));
    }

    /**
     * @expectedException \GuzzleHttp\Exception\ClientException
     * @expectedExceptionCode 412
     */
    public function testGetMimetypeDirectory()
    {
        $temp = uniqid();
        try {
            $this->assertTrue($this->fs->createDir('/' . $temp));
            $this->fs->getMimetype('/' . $temp);
        } finally {
            $this->assertTrue($this->fs->deleteDir('/' . $temp));
        }
    }

    /**
     * @expectedException \League\Flysystem\FileNotFoundException
     * @expectedExceptionMessage File not found at path: test/non-existent
     */
    public function testGetMimetypeNonExistent()
    {
        $this->fs->getMimetype('/test/non-existent');
    }

    public function testGetSize()
    {
        try {
            $this->testWrite();
        } catch (FileExistsException $e) { }
        $this->assertSame(strlen(self::class . '::' . 'testWrite'), $this->fs->getSize('/test/example.txt'));
    }

    public function testGetSizeDirectory()
    {
        $this->assertFalse($this->fs->getSize('/test'));
    }

    public function testGetSizeNonExistent()
    {
        $this->assertFalse($this->fs->getSize('/test/non-existent'));
    }

    public function testGetTimestamp()
    {
        try {
            $this->testWrite();
        } catch (FileExistsException $e) { }
        $this->assertEquals("1557054190", $this->fs->getTimestamp('/test/example.txt'), "Fixture has changed, check the expected value!");
    }

    public function testGetTimestampDirectory()
    {
        $this->assertSame("1557054546", $this->fs->getTimestamp('/test'), "Fixture has changed, check the expected value!");
    }

    /**
     * @expectedException \League\Flysystem\FileNotFoundException
     * @expectedExceptionMessage File not found at path: test/non-existent
     */
    public function testGetTimestampNonExistent()
    {
        $this->assertFalse($this->fs->getTimestamp('/test/non-existent'));
    }

    public function testRead()
    {
        $file = uniqid();
        $this->fs->write('/test/' . $file, __METHOD__);
        $this->assertSame(__METHOD__, $this->fs->read('/test/' . $file));
    }

    /**
     * @expectedException \League\Flysystem\FileNotFoundException
     * @expectedExceptionMessage File not found at path: test/non-existent
     */
    public function testReadNonExistent()
    {
        $this->fs->read('/test/non-existent');
    }

    public function testReadStream()
    {
        $file = uniqid();
        $this->fs->write('/test/' . $file, __METHOD__);
        $this->assertSame(__METHOD__, stream_get_contents($this->fs->readStream('/test/' . $file)));
    }

    /**
     * @expectedException \League\Flysystem\FileNotFoundException
     * @expectedExceptionMessage File not found at path: test/non-existent
     */
    public function testReadStreamNonExistent()
    {
        $this->fs->readStream('/test/non-existent');
    }

    public function testRename()
    {
        try {
            $this->testWrite();
        } catch (FileExistsException $e) { }
        $this->fs->rename('/test/example.txt', '/test/example-2.txt');
        $this->assertSame(strlen(self::class . '::' . 'testWrite'), $this->fs->getSize('/test/example-2.txt'));
    }

    /**
     * @expectedException \League\Flysystem\FileExistsException
     * @expectedExceptionMessage File already exists at path: test/example-2.txt
     */
    public function testRenameExisting()
    {
        try {
            $this->testWrite();
        } catch (FileExistsException $e) { }
        $this->fs->write('/test/example-2.txt', 'Foo');
        $this->fs->rename('/test/example.txt', '/test/example-2.txt');
    }

    public function testRenameNonExistent()
    {
        try {
            $this->fs->rename( '/test/non-existent', '/test/non-existent-2.txt' );
        } catch (\Exception $e) {
            $this->assertInstanceOf(\League\Flysystem\FileNotFoundException::class, $e);
            $this->assertEquals("File not found at path: test/non-existent", $e->getMessage());
            $this->assertFalse($this->fs->has('/test/non-existent-2'));
        }
    }

    public function testUpdate()
    {
        $file = uniqid();
        $this->assertTrue($this->fs->write('/test/' . $file, 'Hello World'));
        $this->assertTrue($this->fs->update('/test/' . $file, 'Goodbye Moon'));
        $this->assertSame('Goodbye Moon', $this->fs->read('/test/' . $file));
    }

    public function testUpdateNonExistent()
    {
        try {
            $this->fs->update( '/test/non-existent', __METHOD__ );
        } catch (\Exception $e) {
                $this->assertInstanceOf(\League\Flysystem\FileNotFoundException::class, $e);
                $this->assertEquals("File not found at path: test/non-existent", $e->getMessage());

                // Make sure it didn't write the file
                try {
                    $this->testReadNonExistent();
                } catch (\Exception $e) {
                    $this->assertInstanceOf(\League\Flysystem\FileNotFoundException::class, $e);
                    $this->assertSame('File not found at path: test/non-existent', $e->getMessage());
                }
        }
    }

    public function testUpdateStream()
    {
        $fp = fopen('php://memory', 'w+');
        fputs($fp, 'Goodbye Moon');
        fseek($fp, 0);

        $file = uniqid();
        $this->assertTrue($this->fs->write('/test/' . $file, 'Hello World'));
        $this->assertTrue($this->fs->updateStream('/test/' . $file, $fp));
        $this->assertSame('Goodbye Moon', $this->fs->read('/test/' . $file));
    }

    public function testWrite()
    {
        $this->assertTrue($this->fs->write('/test/example.txt', __METHOD__));
        $this->assertSame(__METHOD__, $this->fs->read('/test/example.txt'));
    }

    /**
     * @expectedException \League\Flysystem\FileExistsException
     * @expectedExceptionMessage File already exists at path: test/example.txt
     */
    public function testWriteExistingFile()
    {
        $this->testWrite();
        try {
            $this->fs->write('/test/example.txt', __METHOD__);
        } finally {
            $this->assertSame(
                self::class . '::' . 'testWrite',
                $this->fs->read('/test/example.txt')
            );
        }
    }


    public function testWriteNonExistentSubDir()
    {
        $temp = uniqid();

        $this->assertTrue($this->fs->write('/test/nested/subdir/' . $temp . '/example.txt', __METHOD__));
        $this->assertSame(__METHOD__, $this->fs->read('/test/nested/subdir/' . $temp . '/example.txt'));
    }

    public function testWriteStream()
    {
        $fp = fopen('php://memory', 'w+');
        fputs($fp, __METHOD__);
        fseek($fp, 0);

        $this->assertTrue($this->fs->writeStream('/test/example.txt', $fp));
        $this->assertSame(__METHOD__, $this->fs->read('/test/example.txt'));
    }

    /**
     * @expectedException \League\Flysystem\FileExistsException
     * @expectedExceptionMessage File already exists at path: test/example.txt
     */
    public function testWriteStreamExistingFile()
    {
        $this->testWriteStream();

        $fp = fopen('php://memory', 'w+');
        fputs($fp, __METHOD__);
        fseek($fp, 0);
        try {
            $this->fs->writeStream('/test/example.txt', $fp);
        } finally {
            $this->assertSame(
                self::class . '::' . 'testWriteStream',
                $this->fs->read('/test/example.txt')
            );
        }
    }

    public function testCopyFile()
    {
        try {
            $this->testWrite();
        } catch (FileExistsException $e) { }
        $this->fs->copy('/test/example.txt', '/test/copy.txt');
        $this->assertSame(
            self::class . '::' . 'testWrite',
            $this->fs->read('/test/example.txt')
        );
        $this->assertSame(
            self::class . '::' . 'testWrite',
            $this->fs->read('/test/copy.txt')
        );
    }

    /**
     * @expectedException \League\Flysystem\FileExistsException
     * @expectedExceptionMessage File already exists at path: test/copy.txt
     */
    public function testCopyFileExisting()
    {
        try {
            $this->testWrite();
        } catch (FileExistsException $e) { }
        $this->fs->copy('/test/example.txt', '/test/copy.txt');
        $this->fs->copy('/test/example.txt', '/test/copy.txt');
    }

    public function assertArrayPartial($subset, $array, $strict = false, $message = '')
    {
        foreach ($subset as $key => $value) {
            $this->assertArrayHasKey($key, $array);
            $this->assertEquals($value, $array[$key]);
        }
    }
}
