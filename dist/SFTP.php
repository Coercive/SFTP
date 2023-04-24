<?php
namespace Coercive\Utility\SFTP;

use Exception;
use function ssh2_connect;
use function ssh2_sftp;
use function ssh2_auth_password;
use function ssh2_auth_pubkey_file;
use function ssh2_disconnect;

/**
 * SFtp utility
 *
 * @package Coercive\Utility\SFTP
 * @link https://github.com/Coercive/SFTP
 *
 * @author Anthony Moral <contact@coercive.fr>
 * @copyright 2023
 * @license MIT
 */
class SFTP
{
	/** @var resource */
	private $ssh = null;

	/** @var resource|null */
	private $sftp = null;

	/** @var string Prefix for autoname tmp download file */
	private string $prefix = '';

	/** @var string */
	private string $host;

	/** @var int */
	private int $port;

	/** @var string */
	private string $username = '';

	/** @var string */
	private string $password = '';

	/** @var string */
	private string $publicKey = '';

	/** @var string */
	private string $privateKey = '';

	/** @var string|null */
	private ? string $passphrase = null;

	/**
	 * @param string $path
	 * @return string
	 */
	private function getSftpPath(string $path): string
	{
		return 'ssh2.sftp://' . $this->sftp . $path;
	}

	/**
	 * SFtp constructor.
	 *
	 * @param string $host
	 * @param int $port [optional]
	 * @return void
	 * @throws Exception
	 */
	public function __construct(string $host, int $port = 22)
	{
		$this->host = $host;
		$this->port = $port;
	}

	/**
	 * SSH Authenticate
	 *
	 * @param string $username
	 * @param string $password
	 * @return SFtp
	 * @throws Exception
	 */
	public function login(string $username, string $password): SFTP
	{
		$this->username = $username;
		$this->password = $password;
		return $this;
	}

	/**
	 * SSH Authenticate
	 *
	 * @param string $username
	 * @param string $publicKey
	 * @param string $privateKey
	 * @param string|null $passphrase [optional]
	 * @return $this
	 * @throws Exception
	 */
	public function loginWithKey(string $username, string $publicKey, string $privateKey, ? string $passphrase = null): SFTP
	{
		$this->username = $username;
		$this->publicKey = $publicKey;
		$this->privateKey = $privateKey;
		$this->passphrase = $passphrase;
		return $this;
	}

	/**
	 * Prefix for autoname tmp download file
	 *
	 * @param string $prefix
	 * @return $this
	 */
	public function setTmpPrefix(string $prefix): SFTP
	{
		$this->prefix = $prefix;
		return $this;
	}

	/**
	 * @return $this
	 * @throws Exception
	 */
	public function connect(): SFTP
	{
		if($this->ssh || $this->sftp) {
			throw new Exception("Already connected.");
		}

		# SSH Connect
		$this->ssh = ssh2_connect($this->host, $this->port);
		if (!$this->ssh) {
			throw new Exception("Can't connect to {$this->host}:{$this->port}");
		}

		# SSH Authenticate via KEY
		if($this->username && $this->publicKey && $this->privateKey) {
			if (!ssh2_auth_pubkey_file($this->ssh, $this->username, $this->publicKey, $this->privateKey, $this->passphrase)) {
				throw new Exception("Could not authenticate with given keys or passphrase for user {$this->username}");
			}
		}

		# SSH Authenticate : basic id/pw auth
		elseif($this->username && $this->password) {
			if (!ssh2_auth_password($this->ssh, $this->username, $this->password)) {
				throw new Exception("Can't authenticate with user {$this->username}");
			}
		}

		# Initialize SFTP subsystem
		$this->sftp = ssh2_sftp($this->ssh);
		if (!$this->sftp) {
			throw new Exception("Can't initialize SFTP subsystem.");
		}

		return $this;
	}

	/**
	 * SFtp destructor.
	 *
	 * @return void
	 */
	public function __destruct()
	{
		$this->disconnect();
	}

	/**
	 * Close SFtp handler
	 *
	 * @return void
	 */
	public function disconnect()
	{
		if($this->sftp && is_resource($this->sftp)) {
			ssh2_disconnect($this->sftp);
		}
		unset($this->sftp);
		$this->sftp = null;

		if($this->ssh && is_resource($this->ssh)) {
			ssh2_disconnect($this->ssh);
		}
		unset($this->ssh);
		$this->ssh = null;
	}

	/**
	 * @param string $remote
	 * @param int $permissions [optional]
	 * @param bool $recursive [optional]
	 * @return $this
	 * @throws Exception
	 */
	public function mkdir(string $remote, int $permissions = 0777, bool $recursive = false): SFTP
	{
		# Verify connection
		if(!$this->sftp) {
			throw new Exception("SFTP stream is closed.");
		}

		# Verify dest
		$dir = $this->getSftpPath($remote);
		if(is_dir($dir) ) {
			throw new Exception("Directory already exist: $dir.");
		}

		# Create directory
		if (!mkdir($dir, $permissions, $recursive)) {
			throw new Exception("Could not create directory: $dir.");
		}

		return $this;
	}

	/**
	 * @param string $local
	 * @param string $remote
	 * @return $this
	 * @throws Exception
	 */
	public function upload(string $local, string $remote): SFTP
	{
		# Verify connection
		if(!$this->sftp) {
			throw new Exception("SFtp stream is closed.");
		}

		# Verify source file
		if(!is_file($local) || !is_readable($local)) {
			throw new Exception("Source file is not readable.");
		}

		# Try open stream
		$stream = fopen($this->getSftpPath($remote), 'w');
		if (!$stream) {
			throw new Exception("Can't open stream for $remote.");
		}

		# Read data from source
		$data = file_get_contents($local);
		if (false === $data) {
			throw new Exception("Can't read data from $local.");
		}

		# Send data to remote
		$write = fwrite($stream, $data);
		fclose($stream);
		if (false === $write) {
			throw new Exception("Could not send data from file: $local.");
		}

		return $this;
	}

	/**
	 * @param string $remote
	 * @param string|null $local [optional] Empty = new file
	 * @return $this
	 * @throws Exception
	 */
	public function download(string $remote, ? string &$local = null): SFTP
	{
		# Get file data
		$data = $this->read($remote);

		# Generate temp file if needed
		if(!$local) {
			$local = tempnam(sys_get_temp_dir(), $this->prefix);
		}

		# Write data in targeted file
		if (false === file_put_contents($local, $data, LOCK_EX)) {
			throw new Exception("Could not write data into file: $local.");
		}

		return $this;
	}

	/**
	 * @param string $remote
	 * @return string
	 * @throws Exception
	 */
	public function read(string $remote): string
	{
		# Verify connection
		if(!$this->sftp) {
			throw new Exception("SFtp stream is closed.");
		}

		# Try open stream
		$stream = fopen($this->getSftpPath($remote), 'r');
		if (!$stream) {
			throw new Exception("Can't open read stream for $remote.");
		}

		# Reading content
		$contents = stream_get_contents($stream);
		fclose($stream);
		if(false === $contents) {
			throw new Exception("Can't read file $remote.");
		}
		return $contents;
	}

	/**
	 * @param string $remote
	 * @param string $data
	 * @return $this
	 * @throws Exception
	 */
	public function write(string $remote, string $data): SFTP
	{
		# Verify connection
		if(!$this->sftp) {
			throw new Exception("SFtp stream is closed.");
		}

		# Try open stream
		$stream = fopen($this->getSftpPath($remote), 'w');
		if (!$stream) {
			throw new Exception("Can't open write stream for $remote.");
		}

		# Send data
		$bytes = fwrite($stream, $data);
		fclose($stream);
		if (false === $bytes) {
			throw new Exception("Could not write data into file $remote.");
		}

		return $this;
	}

	/**
	 * @param string $remote
	 * @return $this
	 * @throws Exception
	 */
	public function delete(string $remote): SFTP
	{
		if(!$this->sftp) {
			throw new Exception("SFtp stream is closed.");
		}
		unlink($this->getSftpPath($remote));
		return $this;
	}

	/**
	 * @param string $remote
	 * @return int
	 * @throws Exception
	 */
	public function filesize(string $remote): int
	{
		if(!$this->sftp) {
			throw new Exception("SFtp stream is closed.");
		}
		$path = $this->getSftpPath($remote);
		if(!is_file($path)) {
			throw new Exception("Can't read file $remote.");
		}
		$size = filesize($path);
		if(false === $size) {
			throw new Exception("Can't filesize $remote.");
		}
		return $size;
	}

	/**
	 * @param string $dirpath [optional]
	 * @param bool $recursive [optional]
	 * @return array
	 * @throws Exception
	 */
	public function list(string $dirpath = '/', bool $recursive = false): array
	{
		if(!$this->sftp) {
			throw new Exception("SFtp stream is closed.");
		}

		$list = [];
		$dir = $this->getSftpPath($dirpath);
		if (is_dir($dir)) {

			$stream = opendir($dir);
			if(!$stream) {
				throw new Exception("Can't open directory for $dirpath.");
			}

			while (false !== ($file = readdir($stream))) {
				if (!in_array($file, ['.','..'], true)) {
					$type = is_dir("$dir/$file") ? 'directory' : 'file';
					$list[] = [
						'type' => $type,
						'parent' => $dirpath,
						'name' => $file,
						'filepath' => "$dirpath/$file",
					];
					if($recursive && $type === 'directory') {
						$sublist = $this->list("$dirpath/$file");
						foreach($sublist as $sub) {
							$list[] = $sub;
						}
					}
				}
			}

			closedir($stream);
		}
		return $list;
	}
}