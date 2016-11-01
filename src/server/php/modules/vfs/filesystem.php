<?php namespace OSjs\VFS;
/*!
 * OS.js - JavaScript Operating System
 *
 * Copyright (c) 2011-2016, Anders Evenrud <andersevenrud@gmail.com>
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met: 
 * 
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer. 
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution. 
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @author  Anders Evenrud <andersevenrud@gmail.com>
 * @licence Simplified BSD License
 */

use OSjs\Instance;
use OSjs\Request;
use OSjs\Utils;

use Exception;

abstract class Filesystem
{
  const TRANSPORT = 'filesystem';

  final protected static function getRealPath($path) {
    $parts = explode(':', $path, 2);
    $protocol = $parts[0];
    $mounts = (array) (INSTANCE::getConfig()->vfs->mounts ?: []);

    $replacements = [
      '%UID%' => isset($_SESSION['username']) ? $_SESSION['username'] : -1,
      '%USERNAME' => isset($_SESSION['username']) ? $_SESSION['username'] : '',
      '%DROOT%' => DIR_ROOT,
      '%DIST%' => Instance::getDist(),
      '%MOUNTPOINT%' => $protocol
    ];

    $root = null;
    if ( isset($mounts[$protocol]) ) {
      $root = $mounts[$protocol];
    } else if ( isset($mounts['*']) ){
      $root = $mounts['*'];
    }

    if ( !is_string($root) ) {
      $root = $root->destination;
    }

    $path = preg_replace('/^(.*):\/\//', $root, $path);

    return str_replace(array_keys($replacements), array_values($replacements), $path);
  }

  final public static function exists(Request $request, Array $arguments = []) {
    return file_exists(self::getRealPath($arguments['path']));
  }

  final public static function read(Request $request, Array $arguments = []) {
    $path = self::getRealPath($arguments['path']);
    $mime = Utils::getMIME($path);

    if ( !isset($arguments["raw"]) ) {
      // NOTE: This is pretty much deprecated ?!?!
      print "data:{$mime};base64,";
      while( !feof($handle) ) {
        $plain = fread($handle, 57 * 143);
        $encoded = base64_encode($plain);
        $encoded = chunk_split($encoded, 76, '');
        echo $encoded;
        ob_flush();
        flush();
      }
    } else {
      $request->respond()->file($path, $mime);
    }
  }

  final public static function upload(Request $request, Array $arguments = []) {
    throw new Exception('Not implemented');
  }

  final public static function write(Request $request, Array $arguments = []) {
    $data = $arguments['data'];
    if ( empty($arguments['raw']) || $arguments['raw'] === false ) {
      $data = base64_decode(substr($data, strpos($data, ',') + 1));
    }
    return file_put_contents(self::getRealPath($arguments['path']), $data) !== false;
  }

  final public static function delete(Request $request, Array $arguments = []) {
    return unlink(self::getRealPath($arguments['path']));
  }

  final public static function copy(Request $request, Array $arguments = []) {
    $src = self::getRealPath($arguments['src']);
    $dest = self::getRealPath($arguments['dest']);
    return copy($src, $dest);
  }

  final public static function move(Request $request, Array $arguments = []) {
    $src = self::getRealPath($arguments['src']);
    $dest = self::getRealPath($arguments['dest']);
    return rename($src, $dest);
  }

  final public static function mkdir(Request $request, Array $arguments = []) {
    return mkdir(self::getRealPath($arguments['path']));
  }

  final public static function find(Request $request, Array $arguments = []) {
    throw new Exception('Not implemented');
  }

  final public static function fileinfo(Request $request, Array $arguments = []) {
    throw new Exception('Not implemented');
  }

  final public static function scandir(Request $request, Array $arguments = []) {
    throw new Exception('Not implemented');
  }

  final public static function freeSpace(Request $request, Array $arguments = []) {
    throw new Exception('Not implemented');
  }
}

\OSjs\Instance::registerVFS('OSjs\VFS\Filesystem');
