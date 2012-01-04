<?php
class c_ChangeMigrationScript
{
	static $fromRelease = '3.5.2';
	
	static $toRelease = '3.5.3';
	
	static $patchs = array(
		"migrateChangeXml",
	
		"updateChangeProperties", 
	
		"cleanBuildAndCache", 
	
		"buildProject",

		"fixPatch",

		"productreturns 0354", // [FIX #47736] Add "accounting" status in return statuses list. Add query folder base on "accounting" status.
		"twitterconnect 0350", // [FIX #46695] Fix period on periodic planners.
	
		"filalizeMigration"
		);
		
	public function migrateChangeXml()
	{
		$this->loadChangeXML();
		$this->changeXML->save(WEBEDIT_HOME . '/change.' .self::$fromRelease .'.xml');
		$targets = $this->getReleaseModules(self::$toRelease);
		
		$nodes = $this->changeXML->getElementsByTagName('framework');
		$node = $nodes->item(0);
		$t = $targets['framework'];
		$this->log('framework ' .$node->textContent . ' -> ' . implode('-', $t) . PHP_EOL);
		
		while ($node->hasChildNodes()){$node->removeChild($node->lastChild);}
		$node->appendChild($this->changeXML->createTextNode($t[0]));
		if (count($t) == 2)
		{
			$node->setAttribute('hotfixes', $t[1]);
		}
		elseif ($node->hasAttribute('hotfixes'))
		{
			$node->removeAttribute('hotfixes');
		}
		
		foreach ($this->changeXML->getElementsByTagName('module') as $node)
		{
			$parts = explode('-', $node->textContent);
			if (count($parts) == 2 && isset($targets[$parts[0]]))
			{
				$t = $targets[$parts[0]];
				$this->log($parts[0] . ' ' . $parts[1] . ' -> ' . implode('-', $t) . PHP_EOL);
				while ($node->hasChildNodes()) {$node->removeChild($node->lastChild);}
				$node->appendChild($this->changeXML->createTextNode($parts[0] . '-' . $t[0]));
				if (count($t) == 2)
				{
					$node->setAttribute('hotfixes', $t[1]);
				}
				elseif ($node->hasAttribute('hotfixes'))
				{
					$node->removeAttribute('hotfixes');
				}
			}
		}
			
		$this->changeXML->save(WEBEDIT_HOME . '/change.xml');
	}
	
	public function updateChangeProperties()
	{
		//$this->updateProjectProperties('DEVELOPMENT_MODE', 'true');
		//$this->saveProjectProperties();
	}
		
	public function cleanBuildAndCache()
	{
			
		@unlink(WEBEDIT_HOME . "/.computedChangeComponents.ser");
		$this->rmdir(WEBEDIT_HOME . "/build");
		$this->rmdir(WEBEDIT_HOME . "/cache/" . $this->getProfile());
		$this->rmdir(WEBEDIT_HOME . "/cache/www");
	}
	
	public function buildProject()
	{
		$this->executeTask("update-dependencies");
		
		$this->executeTask("compile-config", array('--clear'));
		
		$this->executeTask("apply-project-policy");
		
		$this->executeTask("init-webapp");
		
		$this->executeTask("compile-config");
		
		$this->executeTask("compile-documents");
		
		$this->executeTask("generate-database");
		
		$this->executeTask("compile-db-schema");
	}
	
	public function fixPatch()
	{
		
		
	}
	
	public function filalizeMigration()
	{
		$this->executeTask("clear-all");
		
		$this->executeTask("compile-blocks");
		
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
		
		if (file_exists($filepath) && self::normalizePath($filepath) != readlink($filepath))
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
			CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5,
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
		return file_exists(WEBEDIT_HOME . '/modules/' . $moduleName);
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
			$result['framework'] = array($node->getAttribute('version'));
			$hotFixes = $xpath->query('hotfix', $node);
			if ($hotFixes->length > 0)
			{
				$result['framework'][] = $hotFixes->item($hotFixes->length - 1)->getAttribute('number');
			}
		}
		
		foreach ($xpath->query('//module') as $node) 
		{
			$result[$node->getAttribute('name')] = array($node->getAttribute('version'));
			$hotFixes = $xpath->query('hotfix', $node);
			if ($hotFixes->length > 0)
			{
				$result[$node->getAttribute('name')][] = $hotFixes->item($hotFixes->length - 1)->getAttribute('number');
			}
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
			if ($this->checkExecution())
			{
				return true;
			}
		}
		return false;
	}
	
	protected function checkRelease()
	{
		$string = $this->getProjectProperty("REMOTE_REPOSITORIES");
		if (empty($string)) {$string = "http://osrepo.rbschange.fr";}		
		$remoteRepositories = array();
		foreach (explode(",", $string) as $repoUrl) 
		{
			$repoUrl = trim($repoUrl);
			if (!empty($repoUrl)) {$remoteRepositories[] = $repoUrl;}
		}
		$fromName = 'release-' . self::$fromRelease . '.xml';
		$doc = $this->getRelaseDocument($remoteRepositories, $fromName);
		if ($doc)
		{
			$doc->save(WEBEDIT_HOME . '/migration/' . $fromName);
		}
		else
		{
			$this->log('Unable to download ' . $fromName, 'error');
			return false;
		}
		
		$toName = 'release-' . self::$toRelease . '.xml';
		$doc = $this->getRelaseDocument($remoteRepositories, $toName);
		if ($doc)
		{
			$doc->save(WEBEDIT_HOME . '/migration/' . $toName);
		}
		else
		{
			$this->log('Unable to download ' . $toName, 'error');
			return false;
		}
		
		return true;
	}
	
	/**
	 * @param string[] $repositories
	 * @param string $name
	 * @return DOMDocument || null
	 */
	protected function getRelaseDocument($repositories, $name)
	{
		foreach ($repositories as $repoUrl) 
		{
			$url = $repoUrl . '/' . $name;
			$data = ''; $errno = false;
			$this->wget($url, $data, $errno);
			if ($errno === false && !empty($data))
			{
				$doc = new DOMDocument('1.0', 'UTF-8');
				if ($doc->loadXML($data))
				{
					return $doc;
				}
			}
		}
		return null;
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
	
	public function log($message, $level = "info")
	{
		if ($level === 'warn' || $level === 'error')
		{
			echo strtoupper($level) , ': ', $message;
		}
		else
		{
			echo $message;
		}
	}
}

if (!defined('WEBEDIT_HOME'))
{
	define("WEBEDIT_HOME", getcwd());
	$migration = new c_ChangeMigrationScript();
	if ($migration->check())
	{
		$migration->main();
	}
}