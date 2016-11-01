<?php namespace OSjs;
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

use OSjs\Request;
use OSjs\Responder;

use Exception;

/**
 * OS.js Server Instance
 */
class Instance
{
  protected static $DIST = 'dist-dev';
  protected static $CONFIG = [];
  protected static $PACKAGES = [];
  protected static $API = [];
  protected static $VFS = [];

  /////////////////////////////////////////////////////////////////////////////
  // LOADERS
  /////////////////////////////////////////////////////////////////////////////

  /**
   * Loads configuration files
   */
  final protected static function _loadConfiguration() {
    self::$CONFIG = json_decode(file_get_contents(DIR_SERVER . '/settings.json'));
    self::$PACKAGES = json_decode(file_get_contents(DIR_SERVER . '/packages.json'), true);
  }

  /**
   * Loads API methods
   */
  final protected static function _loadAPI() {
    $path = DIR_SELF . '/modules/api/';
    foreach ( scandir($path) as $file ) {
      if ( substr($file, 0, 1) !== '.' ) {
        require($path . $file);
      }
    }
  }

  /**
   * Loads Authenticator
   */
  final protected static function _loadAuth() {
    $name = self::$CONFIG->http->authenticator;
    $path = DIR_SELF . '/modules/auth/' . $name . '.php';
    require($path);
  }

  /**
   * Loads Storage
   */
  final protected static function _loadStorage() {
    $name = self::$CONFIG->http->storage;
    $path = DIR_SELF . '/modules/storage/' . $name . '.php';
    require($path);
  }

  /**
   * Loads VFS Transports
   */
  final protected static function _loadVFS() {
    $path = DIR_SELF . '/modules/vfs/';
    foreach ( scandir($path) as $file ) {
      if ( substr($file, 0, 1) !== '.' ) {
        require($path . $file);
      }
    }
  }

  /////////////////////////////////////////////////////////////////////////////
  // GETTERS
  /////////////////////////////////////////////////////////////////////////////

  /**
   * Get Loaded configuration
   */
  final public static function getConfig() {
    return self::$CONFIG;
  }

  /**
   * Get packages manifest
   */
  final public static function getPackages() {
    return self::$PACKAGES[self::$DIST];
  }

  /**
   * Get current dist
   */
  final public static function getDist() {
    return self::$DIST;
  }

  /////////////////////////////////////////////////////////////////////////////
  // REGISTRATORS
  /////////////////////////////////////////////////////////////////////////////

  /**
   * Adds API methods to the registry
   */
  final public static function registerAPI($className) {
    foreach ( get_class_methods($className) as $methodName ) {
      self::$API[$methodName] = $className;
    }
  }

  /**
   * Adds VFS transports to the registry
   */
  final public static function registerVFS($className) {
    self::$VFS[] = $className;
  }

  /////////////////////////////////////////////////////////////////////////////
  // MISC
  /////////////////////////////////////////////////////////////////////////////

  /**
   * Get Transport VFS module from given path
   */
  final public static function getTransportFromPath($args) {
    $checks = ['path', 'src'];
    $mounts = (array) (self::$CONFIG->vfs->mounts ?: []);
    $path = null;

    foreach ( $checks as $c ) {
      if ( isset($args[$c]) ) {
        $path = $args[$c];
        break;
      }
    }

    if ( $path !== null ) {
      $parts = explode(':', $path, 2);
      $protocol = $parts[0];
      $transport = 'filesystem';

      if ( isset($mounts[$protocol]) ) {
        if ( is_array($mounts[$protocol]) && isset($mounts[$protocol]['transport']) ) {
          $transport = $mounts[$protocol]['transport'];
        }
      }

      foreach ( self::$VFS as $className ) {
        if ( $className::TRANSPORT === $transport ) {
          return $className;
        }
      }
    }

    return null;
  }

  /////////////////////////////////////////////////////////////////////////////
  // APP
  /////////////////////////////////////////////////////////////////////////////

  /**
   * Shutdown handler
   */
  final public static function shutdown() {
  }

  /**
   * Startup handler
   */
  final public static function run() {
    date_default_timezone_set('Europe/Oslo');
    register_shutdown_function(Array('\\OSjs\\Instance', 'shutdown'));

    define('DIR_ROOT', realpath(__DIR__ . '/../../../../'));
    define('DIR_SERVER', realpath(__DIR__ . '/../../'));
    define('DIR_SELF', realpath(__DIR__ . '/../'));

    try {
      self::_loadConfiguration();
      self::_loadAuth();
      self::_loadStorage();
      self::_loadAPI();
      self::_loadVFS();
    } catch ( Exception $e ) {
      (new Responder())->error('Failed to initialize');
    }

    define('DIR_DIST', DIR_ROOT . '/' . self::$DIST);
    define('DIR_PACKAGES', DIR_ROOT . '/src/packages');

    session_start();

    $request = new Request();

    if ( $request->isfs ) {
      if ( $request->method === 'GET' ) {
        $endpoint = 'read';
        $args = [
          'path' => preg_replace('/(^get\/)?/', '', $request->endpoint),
          'raw' => true
        ];
      } else {
        $endpoint = $request->endpoint;
        $args = $request->data;
      }

      try {
        $transport = self::getTransportFromPath($args);
        if ( is_callable($transport, $request->endpoint) ) {
          $result = call_user_func_array([$transport, $endpoint], [$request, $args]);
          $request->respond()->json([
            'error' => null,
            'result' => $result
          ]);
        } else {
          $request->respond()->json([
            'error' => 'No such VFS method',
            'result' => null
          ], 500);
        }
      } catch ( Exception $e ) {
        $request->respond()->json([
          'error' => $e->getMessage(),
          'result' => null
        ], 500);
      }
    } else if ( $request->isapi && $request->method === 'POST' ) {
      if ( isset(self::$API[$request->endpoint]) ) {
        try {
          $result = call_user_func_array([self::$API[$request->endpoint], $request->endpoint], [$request]);

          $request->respond()->json([
            'error' => null,
            'result' => $result
          ]);
        } catch ( Exception $e ) {
          $request->respond()->json([
            'error' => $e->getMessage(),
            'result' => null
          ], 500);
        }
      } else {
        $request->respond()->json([
          'error' => 'No such API method',
          'result' => null
        ], 500);
      }
    } else {
      $request->respond()->file(DIR_DIST . $request->url);
    }

    $request->respond()->error('File not found', 404);
  }

}
