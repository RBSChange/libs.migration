<?php
class c_ChangeMigrationScript
{
	const REMOTE_REPOSITORY = 'http://update.rbschange.fr';
	
	static $fromRelease = '3.5.4';
	
	static $toRelease = '3.5.5';
	
	static $patchs = array(
		"migrateChangeXml",
	
		"updateChangeProperties", 
	
		"cleanBuildAndCache", 
	
		"updateDependencies", 
		
		"updateAutoload", 
		
		"initPojectGenericFiles",
		
		"buildProject",

		"framework 0355", // [FIX #56098] Mauvaise gestion des permissions dans l'indexation front
		
		"form 0350", // [FIX #50818] Les conditions d'activation des champs ne tiennent pas compte de la traduction (champ vrai/faux)
			
		"clearAll",
		
		"compileAll",
		
		"filalizeMigration"	
		);
	
	/**
	 * @return boolean
	 */
	public function downloadFramework()
	{
		$repositoryPath = $this->getProjectProperty('LOCAL_REPOSITORY');
		$fPath = '/framework/framework-' . self::$toRelease;
		if (is_dir($repositoryPath . $fPath))
		{
			return true;
		}
		$zipFilePath = $repositoryPath . '/tmp/framework-' . self::$toRelease .'.zip';
		if (!is_dir(dirname($zipFilePath)) && !mkdir(dirname($zipFilePath), 0777, true))
		{
			$this->log('Unable to create: '. dirname($zipFilePath). PHP_EOL, 'error');
			return false;
		}
		
		$url = self::REMOTE_REPOSITORY . $fPath . '.zip';
		$this->log('Download framework from : '. $url. PHP_EOL);
		
		$result = $this->getRemoteFile($url,  $zipFilePath);
		if ($result !== true )
		{
			
			$this->log('Unable to download framework: '. $result[0]. ', '.$result[1]. PHP_EOL, 'error');
			return false;
		}
		
		try 
		{
			$this->unzip($zipFilePath, dirname($repositoryPath . $fPath));
		}
		catch (Exception $e)
		{
			$this->log('Unable to unzip framework: '. $e->getMessage(). PHP_EOL, 'error');
			return false;
		}
		
		clearstatcache();
		if (!is_dir($repositoryPath . $fPath))
		{
			$this->log('Directory not found: '. $repositoryPath . $fPath. PHP_EOL, 'error');
			return false;
		}
		return true;
	}
	
	public function buildModulesVersion()
	{
		$repositoryPath = $this->getProjectProperty('LOCAL_REPOSITORY');
		$modules = array();
		$paths = glob(WEBEDIT_HOME . '/modules/*/change.xml');
		if (is_array($paths))
		{
			$doc = new DOMDocument('1.0', 'UTF-8');
			foreach ($paths as $path)
			{
				$moduleName = basename(dirname($path));
				if ($doc->load($path))
				{
					$nl = $doc->getElementsByTagName('version');
					if ($nl->length)
					{
						$version = $nl->item(0)->textContent;
						$repoPath = $repositoryPath. '/modules/' . $moduleName . '/' . $moduleName .'-' . $version .'/change.xml';
						$repo = (file_exists($repoPath) && realpath($repoPath) == realpath($path));
						$modules[$moduleName] = array('repo' => $repo, 'version' => $version);
					}
				}
			}
		}
		return $modules;
	}
		
	public function migrateChangeXml()
	{
		$this->loadChangeXML();
		$this->changeXML->save(WEBEDIT_HOME . '/change.' .self::$fromRelease .'.xml');
		$targets = $this->getReleaseModules(self::$toRelease);
		
		$nodes = $this->changeXML->getElementsByTagName('framework');
		$node = $nodes->item(0);
		$t = $targets['framework'];
		$this->log('framework ' .$node->textContent . ' -> ' . $t . PHP_EOL);		
		while ($node->hasChildNodes()){$node->removeChild($node->lastChild);}
		$node->appendChild($this->changeXML->createTextNode($t));
		
		foreach ($this->changeXML->getElementsByTagName('module') as $node)
		{
			$parts = explode('-', $node->textContent);
			if (count($parts) != 2) {continue;}
			list ($modulename, $version) = $parts;
			if (isset($targets[$modulename]))
			{
				$t = $targets[$modulename];
				$this->log($modulename . ' ' . $version . ' -> ' . $t . PHP_EOL);
				while ($node->hasChildNodes()) {$node->removeChild($node->lastChild);}
				$node->appendChild($this->changeXML->createTextNode($modulename . '-' . $t));
			}
		}
			
		$this->changeXML->save(WEBEDIT_HOME . '/change.xml');
	}
	
	public function updateChangeProperties()
	{
		$repositoryPath = $this->getProjectProperty('LOCAL_REPOSITORY');
		$localPath = $repositoryPath . '/framework/framework-' . self::$toRelease;
		
		$projectPath = WEBEDIT_HOME.'/framework';
		@unlink($projectPath);
		
		$this->log('Link Framework to:' . $localPath. PHP_EOL);
		symlink($localPath, $projectPath);
		
		$this->log('Update Remote repository to:' . self::REMOTE_REPOSITORY. PHP_EOL);
		$this->updateProjectProperties('REMOTE_REPOSITORIES', self::REMOTE_REPOSITORY);
		$this->saveProjectProperties();
	}
		
	public function cleanBuildAndCache()
	{	
		@unlink(WEBEDIT_HOME . "/.computedChangeComponents.ser");
		$this->rmdir(WEBEDIT_HOME . "/cache/" . $this->getProfile());	
		$this->rmdir(WEBEDIT_HOME . "/cache/autoload");
		$this->rmdir(WEBEDIT_HOME . "/cache/www");
	}
	
	public function updateDependencies()
	{
		$this->executeTask("update-dependencies");
	}
	
	public function updateAutoload()
	{
		$this->executeTask("update-autoload", array('--clear'));
	}
	
	public function initPojectGenericFiles()
	{
		$this->executeTask("compile-config", array('--clear'));
		
		$this->executeTask("apply-project-policy");
		
		$this->executeTask("init-webapp");		
	}
	
	public function buildProject()
	{
		
		$this->executeTask("compile-config");
		
		$this->executeTask("compile-documents");
		
		$this->executeTask("generate-database");
		
		$this->executeTask("compile-db-schema");
	}
	
	public function filalizeMigration()
	{
		$this->log('Migration completly exectued.');
	}

	public function clearAll()
	{
		$this->executeTask("clear-all");
	}

	public function compileAll()
	{
		$this->executeTask("compile-all");
	}	
	//TOOL FUNCTIONS

	/**
	 * @var array
	 */
	protected $projectProperties;
	
	/**
	 * @var string
	 */
	protected $profile;
	
	/**
	 * @var DOMDocument
	 */
	protected $changeXML;
	
	/**
	 * @param string $module
	 * @param string $number
	 */
	protected function applyPatch($module, $number)
	{
		if ($module === 'framework' || file_exists(WEBEDIT_HOME . '/modules/' . $module . '/patch/' . $number . '/install.php'))
		{
			$this->executeTask('apply-patch', array($module, $number));
		}
		else
		{
			$this->log("Patch not found ". $module. " ". $number. PHP_EOL, 'info');
		}
	}	
	
	/**
	 * @param String $task
	 * @param String[] $args
	 * @return string
	 */
	protected function executeTask($task, $args = array(), $log = true)
	{
		$phpCli = $this->getProjectProperty('PHP_CLI_PATH');
		if ($phpCli === null) {$phpCli = "php";}
		if (!empty($phpCli)) {$phpCli .= ' ';}
		
		$cmd = $phpCli . WEBEDIT_HOME . "/framework/bin/change.php $task " . implode(" ", $args);
		$result = $this->exec($cmd);
		if ($log) {$this->log($result);}
		return $result;
	}
	
	/**
	 * @param string $cmd
	 */
	private function exec($cmd)
	{
		$descriptorspec = array(
			0 => array('pipe', 'r'), // stdin
			1 => array('pipe', 'w'), // stdout
			2 => array('pipe', 'w') // stderr
		);
		
		$proc = proc_open($cmd, $descriptorspec, $pipes, WEBEDIT_HOME);
		if (!is_resource($proc))
		{
			$this->log("Can not execute $cmd" . PHP_EOL, 'error');
			return null;
		}
		
		$output = array();
		
		stream_set_blocking($pipes[2], 0);
		fclose($pipes[0]);		
		while (!feof($pipes[1]))
		{
			$s = fread($pipes[1], 512);
			if ($s === false)
			{
				$this->log("Error while executing $cmd: could not read further execution result" . PHP_EOL, 'error');
				break;
			}
			$output[] = $s;
		}

		$retVal = proc_close($proc);
		if (0 != $retVal)
		{
			$this->log("Could not execute $cmd (exit code $retVal)" . PHP_EOL, 'error');
		}
		return implode('', $output);
	}
	
	/**
	 * @param string $directoryPath
	 * @param boolean $onlyContent
	 */
	protected function rmdir($directoryPath, $onlyContent = false)
	{
		if (is_dir($directoryPath))
		{
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directoryPath, RecursiveDirectoryIterator::KEY_AS_PATHNAME), RecursiveIteratorIterator::CHILD_FIRST) as $file => $info)
			{
				$fileName = $info->getFilename();
				if ($fileName === '.' || $fileName === '..')
				{
					continue;
				}
				if ($info->isFile() || $this->isLink($info->getPathname()))
				{
					unlink($file);
				}
				else if ($info->isDir())
				{
					rmdir($file);
				}
			}
			if (!$onlyContent)
			{
				rmdir($directoryPath);
			}
		}
	}
	
	/**
	 * @param string $filepath
	 * @return boolean
	 */
	protected function isLink($filepath)
	{
		if (DIRECTORY_SEPARATOR === '/')
		{
			return is_link($filepath);
		}
		else
		{
			$filepath = str_replace('/', DIRECTORY_SEPARATOR, $filepath);
		}
		
		if (file_exists($filepath) && $filepath != readlink($filepath))
		{
			return true;
		}
		return false;
	}	
	
	/**
	 * @param string $url
	 * @param string $curldata
	 * @param string || false $err
	 */
	protected function wget($url, &$curldata, &$err)
	{
		$cr = curl_init();
		$options = array(CURLOPT_RETURNTRANSFER => true, 
			CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 5,
		 	CURLOPT_FOLLOWLOCATION => false, CURLOPT_URL => $url, CURLOPT_POSTFIELDS => null, 
		 	CURLOPT_POST => false, CURLOPT_SSL_VERIFYPEER =>false);
		curl_setopt_array( $cr, $options );
		$curldata = curl_exec( $cr );
		$errNumber = curl_errno( $cr );
		if ($errNumber !== 0)
		{
			$err = curl_error($cr) . ' (code: ' . $errNumber . ')';
		}
		else
		{
			$err = false;
		}
		curl_close( $cr );
	}
	
	/**
	 * @return String
	 */
	protected function getProfile()
	{
		return trim(file_get_contents(WEBEDIT_HOME . "/profile"));
	}
	
	/**
	 * @return boolean
	 */
	protected function loadChangeXML()
	{
		$this->changeXML = new DOMDocument('1.0', 'UTF-8');
		if (!$this->changeXML->load(WEBEDIT_HOME . '/change.xml'))
		{
			$this->log('Unable to load :' . WEBEDIT_HOME . '/change.xml' . PHP_EOL, 'error');
			$this->changeXML = false;
			return false;
		}
		
		$framework = $this->changeXML->getElementsByTagName('framework');
		if ($framework->length != 1)
		{
			$this->log('Unable to read framework version.' . PHP_EOL, 'error');
			$this->changeXML = false;
			return false;
		}
		return true;
	}
	
	/**
	 * @return string;
	 */
	protected function getCurrentRelease()
	{
		if ($this->changeXML === null) {$this->loadChangeXML();}
		
		if ($this->changeXML)
		{
			return $this->changeXML->getElementsByTagName('framework')->item(0)->textContent;
		}
		return null;
	}
	
	/**
	 * @param string $moduleName
	 * @return boolean
	 */
	protected function moduleExist($moduleName)
	{
		return file_exists(WEBEDIT_HOME . '/modules/' . $moduleName . '/change.xml');
	}
	
	/**
	 * @param string $moduleName
	 * @param string $version
	 * @return boolean
	 */
	protected function checkModuleVersion($moduleName, $version = null)
	{
		if ($version === null) {$version = self::$toRelease;}
		
		if ($this->changeXML === null) {$this->loadChangeXML();}
		if ($this->changeXML)
		{
			$modules = $this->changeXML->getElementsByTagName('module');
			foreach ($modules as $module)
			{
				if ($module->textContent == $moduleName . '-' . $version)
				{
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	 * @return boolean
	 */
	protected function loadProjectProperties()
	{
		$changePropertiesPath = WEBEDIT_HOME . '/change.properties';
		if (is_readable($changePropertiesPath))
		{
			$this->projectProperties = file($changePropertiesPath);	
			return true;
		}
		$this->log('Unable to load : ' . $changePropertiesPath . PHP_EOL, 'error');
		$this->projectProperties = array();
		return false;
	}
	
	/**
	 * @param string $name
	 * @param string $value
	 */
	protected function updateProjectProperties($name, $value)
	{
		foreach ($this->projectProperties as $index => $line)
		{
			if (strpos($line, $name) === 0)
			{
				$this->projectProperties[$index] = $name . '=' . $value . PHP_EOL;
				return;
			}
		}
		$this->projectProperties[] = PHP_EOL . $name . '=' . $value . PHP_EOL;
	}
	
	protected function getProjectProperty($name)
	{
		foreach ($this->projectProperties as $index => $line)
		{
			if (strpos($line, $name) === 0)
			{
				$lineParts = explode("=", $line);
				if (count($lineParts) > 1)
				{
					$valueParts = explode("#", $lineParts[1]);
					return trim($valueParts[0]);
				}
				return null;
			}
		}
		return null;
	}
	
	protected function saveProjectProperties()
	{
		file_put_contents(WEBEDIT_HOME . '/change.properties', 
				implode('', $this->projectProperties));
	}
	
	/**
	 * @param string $releaseVersion
	 * @return array
	 */
	protected function getReleaseModules($releaseVersion)
	{
		$result = array();
		$filename = WEBEDIT_HOME . '/migration/release-' . $releaseVersion . '.xml';
		$doc = new DOMDocument('1.0', 'UTF-8');
		$doc->load($filename);
		$xpath = new DOMXPath($doc);
		
		foreach ($xpath->query('//change-lib[@name="framework"]') as $node) 
		{
			$result['framework'] = $node->getAttribute('version');
		}
		
		foreach ($xpath->query('//module') as $node) 
		{
			$result[$node->getAttribute('name')] = $node->getAttribute('version');
		}
		return $result;
	}	
	
	public function main()
	{
		$this->profile = $this->getProfile();	
		$this->loadProjectProperties();	
		foreach (self::$patchs as $patch)
		{
			if (strpos($patch, ' '))
			{
				list ($module, $number) = explode(' ', $patch);
				$this->applyPatch($module, $number);
			}
			else
			{
				$this->{$patch}();
			}
		}
	}
	
	public function executeStep($stepIndex)
	{
		if (isset(self::$patchs[$stepIndex]))
		{
			$this->profile = $this->getProfile();
			$this->loadProjectProperties();	
						
			$patch = self::$patchs[$stepIndex];			
			if (strpos($patch, ' '))
			{
				list ($module, $number) = explode(' ', $patch);
				$this->applyPatch($module, $number);
			}
			else
			{
				$this->{$patch}();
			}
			return true;
		}
		else
		{
			$this->log('Step '. $stepIndex. ' not found' . PHP_EOL, 'error');
		}
		return false;
	}
	
	public function check()
	{		
		$profilePath = WEBEDIT_HOME . "/profile";
		if (!file_exists($profilePath))
		{
			$this->log('Profile file not found'. PHP_EOL, 'error');
			return false;
		}
		$this->profile = $this->getProfile();
		
		if (!$this->loadChangeXML())
		{
			return false;
		}

		if ($this->getCurrentRelease() != self::$fromRelease)
		{
			$this->log('Invalid current release: ' . $this->getCurrentRelease() . PHP_EOL, 'error');
			return false;
		}

		if (!$this->loadProjectProperties())
		{
			return false;
		}
		
		if (!isset($_SERVER['REMOTE_ADDR']) && $this->getProjectProperty('PHP_CLI_PATH') === null)
		{
			$this->log('Add constant PHP_CLI_PATH in your change.properties config file. (ex: PHP_CLI_PATH=php)' . PHP_EOL, 'error');
			return false;
		}
					
		if ($this->checkRelease())
		{
			if (!$this->downloadFramework())
			{
				$this->log('Framework ' . self::$toRelease . ' not present localy' . PHP_EOL, 'error');
				return false;
			}
			
			if (!$this->checkLicense())
			{
				return false;
			}
			
			
			if ($this->checkExecution())
			{
				return true;
			}
		}
		return false;
	}
	
	protected function checkRelease()
	{
		$toName = 'release-' . self::$toRelease . '.xml';
		$destFile = WEBEDIT_HOME . '/migration/' . $toName;
		$url = self::REMOTE_REPOSITORY . '/' . $toName;
		$result = $this->getRemoteFile($url, $destFile);
		if ($result !== true)
		{
			$this->log('Unable to download ' . $toName, 'error: ' . implode(', ', $result), 'error');
			return false;
		}
		return true;
	}
	
	protected function checkLicense()
	{
		$modules = $this->buildModulesVersion();
		$target = $this->getReleaseModules(self::$toRelease);
		foreach ($modules as $moduleName => $info)
		{
			if ($info['repo'])
			{
				if (isset($target[$moduleName]))
				{
					$modules[$moduleName]['version'] = $target[$moduleName];
				}
				else
				{
					$this->log('Unable to upgrade ' . $moduleName . '-' . $info['version'] . PHP_EOL, 'error');
				}
			}
		}
		
		$destFile = WEBEDIT_HOME . '/migration/license.xml';
		$url = self::REMOTE_REPOSITORY . '/license.xml';
		$result = $this->getRemoteFile($url, $destFile, array('modules' => $modules));
		if ($result === true)
		{
			$doc = new DOMDocument('1.0', 'UTF8');
			if ($doc->load($destFile))
			{
				if ($doc->documentElement->hasAttribute('error'))
				{
					$this->log('Error in license file: ' . $destFile . ' , ' . $doc->documentElement->getAttribute('status') . PHP_EOL, 'error');
					foreach ($doc->getElementsByTagName('module') as $me)
					{
						/* @var $me DOMElement */
						$this->log('Invalid license for ' . $me->getAttribute('name') . ': ' . $doc->documentElement->getAttribute('status') . PHP_EOL, 'error');
					}
					return false;
				}
				
				return true;
			}
			else
			{
				$this->log('Invalid xml license file: ' . $destFile . PHP_EOL, 'error');
			}
		}
		$this->log('Unable to check license: ' . implode(', ', $result), 'error');
		return false;
	}
	
	protected function checkExecution()
	{
		if (!is_readable(WEBEDIT_HOME . '/modules/updater/change.xml'))
		{
			return false;
		}
		$result = $this->executeTask("updater.migrate", array('--check'));
		return (strpos($result, 'CHECK SUCCESS') !== false);
	}
	
	/**
	 * @var string
	 */
	private $logFilePath;
	
	protected function logFile($message, $level)
	{
		if ($this->logFilePath === null)
		{
			$this->logFilePath = WEBEDIT_HOME . '/log/updater/updater.log';
			if (!is_dir(dirname($this->logFilePath)))
			{
				mkdir(dirname($this->logFilePath), 0777, true);
			}
		}
		error_log(date('Y-m-d H:i:s'). ' - ' . strtoupper($level) . ' - '. $message, 3, $this->logFilePath);
	}
	
	public function log($message, $level = "info")
	{
		$this->logFile($message, $level);
		if ($level === 'warn' || $level === 'error')
		{
			echo strtoupper($level) , ': ', $message;
		}
		else
		{
			echo $message;
		}
	}
	
	protected $instanceProjectKey = null;
	
	/**
	 * Part of Bootstrap
	 * @return string
	 */
	public function getInstanceProjectKey()
	{
		if ($this->instanceProjectKey === null)
		{
			$license = $this->getProjectProperty("PROJECT_LICENSE");
			if (empty($license)) {$license = "OS";}
			
			$mode = ($this->getProjectProperty("DEVELOPMENT_MODE") === 'true') ? "DEV" : "PROD";
			
			$version = self::$toRelease;
			$profile = '-';
			$pId = '-';
			$fqdn = '-';
				
			$profilePath = WEBEDIT_HOME . '/profile';
			if (is_readable($profilePath))
			{
				$profile = trim(file_get_contents($profilePath));
				$configPath = WEBEDIT_HOME . '/config/project.' . $profile . '.xml';
				if (is_readable($configPath))
				{
					$changeXMLDoc = new DOMDocument('1.0', 'UTF8');
					if ($changeXMLDoc->load($configPath))
					{
						$xpath = new DOMXpath($changeXMLDoc);
						$nl = $xpath->query('defines/define[@name="PROJECT_ID"]');
						if ($nl->length) {$pId = $nl->item(0)->textContent;}
						
						$nl = $xpath->query('config/general/entry[@name="server-fqdn"]');
						if ($nl->length) {$fqdn = $nl->item(0)->textContent;}
					}
				}
			}
			$this->instanceProjectKey = 'Change/' . $version . ';License/' . $license . ';Profile/' . $profile . ';PId/' . $pId . ';DevMode/' . $mode . ';FQDN/' . $fqdn;
		}
		return $this->instanceProjectKey;
	}
	
	/**
	 * Part of Bootstrap
	 * @return string|null
	 */
	protected function getProxy()
	{
		return $this->getProjectProperty("PROXY");
	}

	/**
	 * Part of Bootstrap
	 */
	protected $remoteError = true;
	
	/**
	 * Part of Bootstrap
	 * @param string $url
	 * @param string $destFile
	 * @param array $postDataArray
	 * @return true array
	 */
	protected function getRemoteFile($url, $destFile, $postDataArray = null)
	{
		$this->remoteError = true;
		$fp = fopen($destFile, "wb");
		if ($fp === false)
		{
			$this->remoteError = array(-1, 'Fopen error for filename ', $destFile);
			return $this->remoteError;
		}
	
		$ch = curl_init($url);
		if ($ch == false)
		{
			$this->remoteError = array(-2, 'Curl_init error for url ' . $url);
			return $this->remoteError;
		}
		
		$userAgent = $this->getInstanceProjectKey();

		curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_COOKIEFILE, '');
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 600);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
	
		if (is_array($postDataArray) && count($postDataArray))
		{
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postDataArray, null , '&'));
			curl_setopt($ch, CURLOPT_POST, true);
		}
	
		$proxy = $this->getProxy();
		if ($proxy !== null)
		{
			curl_setopt($ch, CURLOPT_PROXY, $proxy);
		}
	
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
		if (curl_exec($ch) === false)
		{
			$this->remoteError = array(curl_errno($ch), curl_error($ch));
			fclose($fp);
			unlink($destFile);
			curl_close($ch);
			return $this->remoteError;
		}
	
		fclose($fp);
		$info = curl_getinfo($ch);
		curl_close($ch);
		if ($info["http_code"] != "200")
		{
			unlink($destFile);
			$this->remoteError = array($info["http_code"], "Could not download " . $url . ": bad http status (" . $info["http_code"] . ")");
			return $this->remoteError;
		}
	
		return $this->remoteError;
	}
	
	/**
	 * Part of Bootstrap
	 * @param string $zipFilePath
	 * @param string $targetDir
	 */
	protected function unzip($zipFilePath, $targetDir)
	{
		if (class_exists('ZipArchive', false))
		{
			$zipArch = new ZipArchive();
			$res = $zipArch->open($zipFilePath);
			if ($res === TRUE) 
			{
				$this->log('Use ZipArchive for unzip: '. $zipFilePath . PHP_EOL);
				$zipArch->extractTo($targetDir);
				$zipArch->close();
				return;
			}
			else
			{
				$this->log('Error on use ZipArchive : ' . $res, 'error');
			}
		}
		
		$this->log('USE Soft php unzip', 'warn');
		require_once dirname(__FILE__) .'/Zip.php';
		migration_Zip::unzip($zipFilePath, $targetDir);
	}
}

if (!defined('WEBEDIT_HOME'))
{
	define("WEBEDIT_HOME", getcwd());
	$migration = new c_ChangeMigrationScript();
	if ($migration->check())
	{
		echo 'Checkink OK', PHP_EOL;
		$migration->main();
	}
}