<?php
/**
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Benjamin Liles <benliles@arch.tamu.edu>
 * @author Christian Berendt <berendt@b1-systems.de>
 * @author Daniel Tosello <tosello.daniel@gmail.com>
 * @author Felix Moeller <mail@felixmoeller.de>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Martin Mattel <martin.mattel@diemattels.at>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Philipp Kapfer <philipp.kapfer@gmx.at>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Tim Dettrick <t.dettrick@uq.edu.au>
 * @author Vincent Petry <pvince81@owncloud.com>
 * @author Mateo Boudet <mateo.boudet@irisa.fr>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OCA\ExternalIrods\Storage;

use Guzzle\Http\Exception\ClientErrorResponseException;
use GuzzleHttp\Psr7\Stream;
use Icewind\Streams\IteratorDirectory;
use \RODSAccount;
use \ProdsDir;
use \ProdsFile;

//Autoload this in composer

class Irods extends \OCP\Files\Storage\StorageAdapter {

	/**
	 * @var Service./
	 */
	private $connection;
	/**
	 * @var Container
	 */
    private $homeDir;

    private $streamUrl;

	private $params;
	/**
	 * @var array
	 */
	private static $tmpFiles = [];

	private $objectCache;

	/**
	 * @param string $path
	 */
	private function normalizePath($path) {
        if (! $path || $path === "."){
            return $this->basepath . "/";
        }
        if (strpos($path, $this->basepath) !== false) {
            $path = rtrim($path, '/');
            return $path;
        }
		$path = rtrim($path, '/');
        return $this->basepath . "/" . $path;

	}

	const SUBCONTAINER_FILE = '.subcontainers';

	public function __construct($params) {
// Sha of the home?
		$this->id = 'irods::' . $params['user'] ;
        $this->basepath = '/BDRZone/home/mboudet';
		$this->params = $params;
		$this->objectCache = new \OC\Cache\CappedMemoryCache();
        $this->objectCache->clear();
	}

    public function file_exists($path) {
        $path = $this->normalizePath($path);
        $stream = $this->getStreamUrl() . $path;
        return file_exists($stream);
    }

	public function stat($path) {
		$path = $this->normalizePath($path);
        if ($this->file_exists($path)){
            $stream = $this->getStreamUrl() . $path;
            $stat = stat($stream);
            return $stat;
        }
        return false;

	}

	public function filetype($path) {
        if ($this->file_exists($path)){
            $path = $this->normalizePath($path);
            $stream = $this->getStreamUrl() . $path;
            return filetype($stream);
	    }
        return false;
    }
    private function getHome(){
        \OCP\Util::writeLog('files_external', 'Called getHome', \OCP\Util::WARN);
        if (!is_null($this->homeDir)) {
            return $this->homeDir;
        }
        $this->homeDir = $this->getConnection()->getUserHomeDir();
        return $this->homeDir;
    }

	public function getId() {
		return $this->id;
	}

	public function getConnection() {
		if (!is_null($this->connection)) {
			return $this->connection;
		}
		$connection = new RODSAccount($this->params['url'],$this->params['port'], $this->params['user'], $this->params['password']);
		$this->connection = $connection;
		return $this->connection;
	}

    private function getStreamUrl(){
        $this->streamUrl = "rods://" . $this->params['user'] . ":" . $this->params['password'] . "@" . $this->params['url'] . ":" . $this->params['port'];
        return $this->streamUrl;
    }

    public function mkdir($path){
        $path = $this->normalizePath($path);
        $stream = $this->getStreamUrl() . $path;
        return mkdir($stream);
    }

    public function rmdir($path){
        $path = $this->normalizePath($path);
        $stream = $this->getStreamUrl() . $path;
        rmdir($stream);
        return true;
    }

    public function unlink($path){
        $path = $this->normalizePath($path);
        $stream = $this->getStreamUrl() . $path;
        if ( $this->filetype($path) === 'dir'){
            rmdir($path);
            return true;
        }
        if ( $this->filetype($path) === 'file'){
            unlink($stream);
            return true;
        }
    }

    public function fopen($path, $mode){
        $path = $this->normalizePath($path);
        $stream = $this->getStreamUrl() . $path;
        $fh = fopen($stream, $mode);
        if ($fh) {
            stream_set_chunk_size($fh, 1024*1024*10);
        }
        return $fh;
    }

    public function opendir($path){
        $path = $this->normalizePath($path);
        $stream = $this->getStreamUrl() . $path;
        $dh = opendir($stream);
        return $dh;
    }

    public function touch($path, $mtime = null){
        $path = $this->normalizePath($path);
        $stream = $this->getStreamUrl() . $path;
        if (! is_null($mtime)) {
            return false;
        }

        if (! $this->file_exists($stream)) {
            file_put_contents($stream, '');
            return true;
        }
        return false;
    }

    public function rename($sourcePath, $targetPath){
        //rename fails on streams, have to create manually
        $sourcePath = $this->normalizePath($sourcePath);
        $targetPath = $this->normalizePath($targetPath);
        $filetype = $this->filetype($sourcePath);
        $connection = $this->getConnection();
        if($filetype === 'dir'){
            $dir = new ProdsDir($connection, $sourcePath);
            $dir->rename($targetPath);
            return true;
        } else if ($filetype === 'file'){
            $file = new ProdsFile($connection, $sourcePath);
            $file->rename($targetPath);
            return true;
        }
        return false;
    }


    public function hasUpdated($path,$time) {
// Irods does not update mtime for collections..
        $filetype = $this->filetype($path);
        $path = $this->normalizePath($path);
        if ( $filetype == 'dir') {
            $actualTime=$this->collectionMTime($path);
            return $actualTime>$time;
        }
        $actualTime=$this->filemtime($path);
        return $actualTime>$time;
    }


    private function collectionMTime($path) {
        $dh = $this->opendir($path);
        $lastCTime = $this->filemtime($path);
        if(is_resource($dh)) {
            while (($file = readdir($dh)) !== false) {
                if ($file != '.' and $file != '..') {
                    $time = $this->filemtime($path . '/' . $file);
                    if ($time > $lastCTime) {
                        $lastCTime = $time;
                    }
                }
            }
        }
        return $lastCTime;
    }



	public static function checkDependencies() {
		return true;
	}

}
