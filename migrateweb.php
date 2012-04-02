<?php
header('Last-Modified' .': '. gmdate('D, d M Y H:i:s') . ' GMT');
header('Expires' .': '. 'Mon, 26 Jul 1997 05:00:00 GMT');
header('Cache-Control' .': '. 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
header('Pragma' .': '. 'no-cache');
ignore_user_abort(true);
set_time_limit(0);
umask(0002);
session_start();

define("WEBEDIT_HOME", dirname(dirname(realpath(__FILE__))));
chdir(WEBEDIT_HOME);

require_once WEBEDIT_HOME . '/migration/migrate.php';

class c_ChangeMigrationHTTPScript extends c_ChangeMigrationScript
{
	public function executeTask($task, $args = array(), $log = true)
	{
		$https = isset($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] != "off";
		$srcUrl = "http".(($https) ? "s" : "")."://".$_SERVER["HTTP_HOST"]."/migration/migrateweb.php";
		$params = http_build_query(array('cmd' => $task . ' ' . implode(' ', $args)));
		$curldata = null;
		$errno = 0;
		$this->wget($srcUrl . '?' . $params, $curldata, $errno);
		if ($errno !== false)
		{
			$this->log($errno, 'error');
		}
		if ($log) {echo $curldata;}
		return $curldata;
	}
	
	public function log($message, $level = 'info')
	{
		$this->logFile($message, $level);
		echo 'MSGTYPE:', $level, ':', $message;
	}
	
	function convertLogToArray($string)
	{
		$lines = array();
		if (empty($string)) {return $string;}
		
		foreach (explode(PHP_EOL, $string) as $line) 
		{
			if (empty($line)) {continue;}
			if (preg_match('/^MSGTYPE:([a-z]+):/', $line, $match))
			{
				switch ($match[1])
				{
					case 'warn':
						$lines[] = array('warn', substr($line, strlen($match[0])));
						break;
					case 'error':
						$lines[] = array('error', substr($line, strlen($match[0])));
						break;
					default:
						$lines[] = array('info', substr($line, strlen($match[0])));
				}
			}
			else
			{
				$lines[] = array('info', $line);
			}
		}
		return $lines;
	}
}

if (isset($_GET['execStep']))
{
	header('Content-Type: application/json; charset=utf-8');
	
	ob_start();
	$migration = new c_ChangeMigrationHTTPScript();
	$migration->executeStep($_GET['execStep']);
	$string = ob_get_clean();
	$lines = $migration->convertLogToArray($string);
	echo json_encode(array('logs' => $lines));
}
elseif (isset($_GET['check']))
{
	header('Content-Type: application/json; charset=utf-8');
	ob_start();
	$migration = new c_ChangeMigrationHTTPScript();
	$checked = $migration->check();
	$string = ob_get_clean();
	$lines = $migration->convertLogToArray($string);
	echo json_encode(array('logs' => $lines, 'checked' => $checked));
}
elseif (isset($_GET['cmd']))
{
	
	$profile = @file_get_contents(WEBEDIT_HOME . DIRECTORY_SEPARATOR . 'profile');
	define('PROFILE', trim($profile));
	define('FRAMEWORK_HOME', WEBEDIT_HOME . DIRECTORY_SEPARATOR . 'framework');
	define('AG_CACHE_DIR', WEBEDIT_HOME . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . PROFILE);
		
	require_once FRAMEWORK_HOME . '/bin/bootstrap.php';
	$bootStrap = new c_ChangeBootStrap(WEBEDIT_HOME);
	$bootStrap->setAutoloadPath(WEBEDIT_HOME."/cache/autoload");
	$argv = explode(' ', $_GET['cmd']);
	
	class c_ChangeBootStrapScript extends c_Changescript
	{
		protected function echoMessage($message, $color = null)
		{
			if ($color == 31)
			{
				echo 'MSGTYPE:error:' , $message;
			}
			elseif ($color == 35)
			{
				echo 'MSGTYPE:warn:' , $message;
			}
			else
			{
				echo 'MSGTYPE:info:', $message;
			}
		}
	}

	$script = new c_ChangeBootStrapScript(__FILE__, FRAMEWORK_HOME, 'change');
	require_once FRAMEWORK_HOME . '/bin/change_script.inc';
}
else
{
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('steps' => c_ChangeMigrationScript::$patchs));
}
