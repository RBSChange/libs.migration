<?php
require_once dirname(__FILE__) .'/pclzip.lib.php';

interface migration_Zipper
{
	/**
	 * @param String $zipPath
	 */
	function __construct($zipPath);

	function close();

	/**
	 * @param String $path
	 * @param String[] $entries
	 */
	function extractTo($path, $entries = null);
}


if (!defined('PCLZIP_TEMPORARY_DIR'))
{
	$tmpPath = dirname(__FILE__) . DIRECTORY_SEPARATOR . uniqid('zip');
	if (!is_dir($tmpPath) && !mkdir($tmpPath, 0777, true))
	{
		throw new Exception("Could not create ". $tmpPath);
	}
	define('PCLZIP_TEMPORARY_DIR', $tmpPath);
}

class migration_PclZip implements migration_Zipper
{
	/**
	 * @var PclZip
	 */
	private $zip;
	/**
	 * @var String
	 */
	private $zipPath;

	/**
	 * @param string $zipPath
	 */
	function __construct($zipPath)
	{
		$this->zip = new PclZip($zipPath);
		$this->zipPath = $zipPath;
	}

	function close()
	{
		$this->zip = null;
	}

	/**
	 * @param string $path
	 * @param string[] $entries
	 */
	function extractTo($path, $entries = null)
	{
		if ($entries === null)
		{
			if ($this->zip->extract(PCLZIP_OPT_PATH, $path) == 0)
			{
				throw new Exception("Could not extract ".$this->zipPath." to $path: ".$this->zip->errorInfo(true));
			}
		}
		else
		{
			if ($this->zip->extract(PCLZIP_OPT_PATH, $path, PCLZIP_OPT_BY_NAME, $entries) == 0)
			{
				throw new Exception("Could not extract ".$this->zipPath." to $path: ".$this->zip->errorInfo(true));
			}
		}
	}

	function __destruct()
	{
		if ($this->zip !== null)
		{
			$this->close();
		}
	}
}

class migration_Zip
{
	/**
	 * @var String
	 */
	private static $driverClassName;

	/**
	 * @return migration_Zipper
	 */
	private static function getInstance($zipPath)
	{
		if (self::$driverClassName === null)
		{
			self::$driverClassName = "migration_PclZip";
		}
		return new self::$driverClassName($zipPath);
	}

	/**
	 * @param String $zipPath
	 * @param String $dest
	 * @param String[] $entries
	 */
	static function unzip($zipPath, $dest, $entries = null)
	{
		$zip = self::getInstance($zipPath);
		$zip->extractTo($dest, $entries);
		$zip->close();
	}
}