<?php
if (!defined('FORUM_ROOT'))
	define('FORUM_ROOT', '../');

function backupDB( $date )
{
	global $db_name, $db_type, $forum_db, $db_prefix, $ext_info;
	
	// Increase time limit... in case its a big file or something.
	set_time_limit(99999999);
	define('N', "\r\n");
	
	//Tables of PunBB core 
	$punbb_arr = array('bans', 'categories',  'censoring', 'config', 'forum_perms', 'forums', 'groups', 'online', 'posts', 'ranks', 'reports', 'subscriptions', 'topics', 'users', 'extension_hooks', 'extensions');
	$dump = '-- SQL dump commenced at '.format_time(time()).N.'-- Generated by PunBB Backup Plugin'.N.'-- Dumping database `'.$db_name.'` - Database type: '.$db_type.N;
	$wrep = ';#%%';
	$rep = ';#%';
	switch ($db_type)
	{
		case 'mysql':
		case 'mysqli':
			$query = 'SHOW TABLES';
			break;
		case 'sqlite':
			$query = "SELECT * FROM sqlite_master WHERE type='table'";
			$wrep = ';--%%';
			$rep = ';--%';
		break;
		case 'pgsql':
			$query = "SELECT tablename FROM pg_tables WHERE (tableowner = current_user) AND ((tablename NOT Like('sql_%'))AND(tablename NOT Like('pg_%')))";
			$wrep = ';--%%';
			$rep = ';--%';
		break;
		default:
			message($lang_backup['Not supported DB']);
		break;
	}
	$tables = $forum_db->query($query);
	
	while($tmp_tab = $forum_db->fetch_row($tables))
	{
		$tab = $tmp_tab[0];
		if ($db_prefix != '' && strpos($db_prefix, $tab) != 0)
			continue;
		if ($db_type == 'mysql' || $db_type == 'mysqli'|| $db_type == 'pgsql')
		{
			$output = '';
			$result = $forum_db->query('SHOW CREATE TABLE `'.$tab.'`');
			while ($ins = $forum_db->fetch_row($result))
			{
				unset($ins[0]);
				$output .= implode(N,$ins);
			}
			$dump .= N.N.'-- '.N.'-- Table structure for '.$tab.N.'-- '.N.N;
			$dump .= 'DROP TABLE IF EXISTS `'.$tab.'`'.$wrep.N;
			$dump .= $output.$wrep;
		} 
		else if ($db_type == 'sqlite')
		{
			$table[0] = $table[1];
			$dump .= N.N.'-- '.N.'-- Table structure for '.$table[0].N.'-- '.N.N;
			$dump .= 'DROP TABLE IF EXISTS '.$table[0].$wrep.N;
			$dump .= str_replace("\t", '', $table[4]);
		}
		$result = $forum_db->query('SELECT * FROM '.$tab.' WHERE 1=1');
		$table_rows = $forum_db->num_rows($result);
		if ($table_rows > 0)
		{
			$dump .= N.N.'-- '.N.'-- Table data for '.$tab.N.'-- '.N;
			while($row = $forum_db->fetch_row($result))
			{
				$dump .= N.'INSERT INTO '.$tab.' VALUES (';
				$cols = count($row);
				$j = 0;
				foreach($row as $data)
				{
					$j++;
					while (strpos($data,$wrep)!== false)
						$data = str_replace($wrep,$rep, $data);
					if (!isset($data)) 
					{ 
						if ($j != $cols)
							$dump .= "NULL,";
						else
							$dump .= "NULL"; 
					}
					else if ($data != "")
						$dump .= '\''.$forum_db->escape($data).'\'' . ($j != $cols ? ', ' : '');
					else if ($data == "")
					{
						if ($j != $cols)
							$dump .= "'',";
						else
							$dump .= "''"; 
					}
				}
				$dump .= ')'.$wrep;
			}
		}
	}
	$dump .= N.'-- SQL dump concluded at '.format_time(time());

	$filepath = $ext_info['path'].'/dumps/';
	if (!file_exists($filepath))
		mkdir($filepath);

	$filename = $db_name.$date.'.sql';
	$file = fopen($filepath.$filename, "w+");
	if (!$file)
		message($lang_backup['Wrong filepath']);
	else
		fputs ( $file, $dump);		
	
	fclose ($file);
	add_to_log('backupDB', $filename);	
	return $filename;
}

function revertDB( $filepath )
{
	global $forum_db, $db_type, $lang_backup;

	if (empty($filepath))
		return $lang_backup['No filename'];
	if (!file_exists($filepath))
		return $lang_backup['No file'];
	if (!is_readable($filepath))
		return $lang_backup['Not readable file'];
	$file = @fopen($filepath, 'r');
	if (!$file)
		return $lang_backup['Wrong filename'];
	else
	{
		$buff = fread ($file, filesize($filepath));
		$query = explode(";#%%", $buff);
		for ($i = 0; $i < count($query) - 1; $i++)
		{
			switch ($db_type)
			{
				case 'mysql':
				case 'mysqli':
					$forum_db->query($query[$i]) or die (mysql_error());
				break;
				case 'sqlite':
					$forum_db->query($query[$i]) or die (sqlite_error_string(sqlite_last_error($db)));
				break;
				case 'pgsql':
					$forum_db->query($query[$i]) or die(pg_last_error());
				break;
				default:
					return $lang_backup['Not supported DB'];
				break;
			}
		}
	}
	fclose ($file);
	
	if (!add_to_log('revertDB', $filepath))
		return $lang_backup['Log error'];
	return true;
}
function arr_concat($arr1, $arr2)
{
	foreach ($arr2 as $value)
		$arr1[] = $value;
	return $arr2;
}
function revertFiles($filename)
{
	global $ext_info, $lang_backup;

	$newpath = realpath( $filename ).'/';
	$oldpath = realpath( FORUM_ROOT ).'/';	
	$errors = array();
	$dump_list = get_list($newpath);

	foreach ($dump_list as $file)
	{
		$new_file = $newpath.$file;
		$old_file = $oldpath.$file;
		if ( is_dir(realpath($new_file)) )
		{
			if ( checkdelTree($old_file) )
			{
				$errors[] = sprintf($lang_backup['Permission error'], $old_file);
				continue;
			}			
			$del_errors = array();

			if ($file != 'extensions')
			{
				deltree( $old_file,  $del_errors);
				dircopy( $new_file.'/', $old_file.'/' );
				$errors = arr_concat($errors, $del_errors);
			}
			else
			{
				$ext_list = get_list( $new_file.'/' );
				for ($ext_num = 0; $ext_num < count( $ext_list ); $ext_num++)
				{
					//We don't revert pun_admin_backup
					if ($ext_list[$ext_num] == $ext_info['id'])
						continue;

					if (is_dir($new_file.'/'.$ext_list[$ext_num]))
					{
						deltree($old_file.'/'.$ext_list[$ext_num], $del_errors);
						dircopy($new_file.'/'.$ext_list[$ext_num], $old_file.'/'.$ext_list[$ext_num]);
					}
					else
					{
						if (@!unlink($old_file.'/'.$ext_list[$ext_num]))
							$errors[] = sprintf($lang_backup['Permission error file'], $old_file.'/'.$ext_list[$ext_num]);
						else
							copy($new_file.'/'.$ext_list[$ext_num], $old_file.'/'.$ext_list[$ext_num]);
					}
					$errors = arr_concat($errors, $del_errors);
				}
			}
		}
		else
		{
			if ( !copy($new_file, $old_file) )
				$errors[] = sprintf($lang_backup['Copy error'], $new_file);
		}
	}

	if (!add_to_log('revertFiles', $filename, $errors))
		$errors[] = $lang_beckup['Log error'];
	return $errors;
}

function dircopy($src_dir, $dst_dir)
{
	static $src_tree;
	static $dst_tree;
	$num = 0;
	if (($slash = substr($src_dir, -1)) == "\\" || $slash == "/")
		$src_dir = substr($src_dir, 0, strlen($src_dir) - 1);
	if (($slash = substr($dst_dir, -1)) == "\\" || $slash == "/")
		$dst_dir = substr($dst_dir, 0, strlen($dst_dir) - 1);

	$src_tree = get_dir_tree($src_dir);
	$src_changed = true;
	if (!isset($dst_tree) || $src_changed)
		$dst_tree = get_dir_tree($dst_dir);
	if (!is_dir($dst_dir))
		mkdir($dst_dir, 0777, true);

	foreach ($src_tree as $file => $src_mtime)
	{
		if (!isset($dst_tree[$file]) && $src_mtime === false) 
			mkdir("$dst_dir/$file",0777, true);
		else if (!isset($dst_tree[$file]) && $src_mtime || isset($dst_tree[$file]) && $src_mtime > $dst_tree[$file])  
		{
			if (copy("$src_dir/$file", "$dst_dir/$file"))
			{
				if (!touch("$dst_dir/$file", $src_mtime))
					echo "$dst_dir/$file";
				$num++;
			}
		}
	}

	return $num;
}


function get_dir_tree($dir, $root = true)
{
	static $tree;
	static $base_dir_length;
	global $ext_info;

	if ($root)
	{
		$tree = array();
		$base_dir_length = strlen($dir) + 1;
	}
	if (is_file($dir))
	{
		$tree[substr($dir, $base_dir_length)] = filemtime($dir);
	}
	else if (is_dir($dir) && $dir_handler = dir($dir))
	{
		if (!$root)
			$tree[substr($dir, $base_dir_length)] = false;

		while (($file = $dir_handler->read()) !== false)
		{
			if (($file != '.') && ($file != '..') && (strpos($dir.'/'.$file, '/extensions/'.$ext_info['id'].'/dumps') === false))
				get_dir_tree($dir.'/'.$file, false);
		}
		$dir_handler->close();
	}

	if ($root)
		return $tree;
}


function get_list($dir)
{
	$dump_list = array();
	if (is_dir($dir) && $dir_handler = dir($dir))
	{
		while (($file = $dir_handler->read()) !== false)
			if ((strpos($file, '.') !== 0) && (strpos($file, 'log.txt') === false))
				$dump_list[]=$file;
	}
	return $dump_list;
}

function add_to_log($action, $name, $errors = array())
{
	global $ext_info, $lang_backup;

	$data = date('_Ymd-His');
	$mess = '';
	switch ($action)
	{
		case 'backupDB':
			$mess = $data.'//--Created Data Base Dump--'.$name.'--'."\n";			
			break;
		case 'revertDB':
			$mess = $data.'//--Reverted Data Base Dump--'.$name.'--'.( (empty($errors)) ? ('') : ('Errors:'.implode(' ', $errors)) )."\n";
			break;
		case 'backupFiles':
			$mess = $data.'//--Created Files Dump--'.$name.'--'."\n";
			break;
		case 'revertFiles':
			$mess = $data.'//--Reverted Files Dump--'.$name.'--'."\n";
			break;
		default:
			$mess = 'Wrong parametr'."\n";
			break;
	}
	$filepath = $ext_info['path'].'/dumps/log.txt';

	$file = fopen($filepath, 'ab');
	if (!$file)
		return $lang_backup['Wrong filepath'];
	else
		fputs ( $file, $mess);
	fclose ($file);
	return true;
}

function scanEntireDir($dir)
{
	$dh = opendir($dir);

	while (false !== ($filename = readdir($dh)))
		$files[] = $filename;

	closedir($dh);
	return $files;
}

function delTree($f, &$errors)
{
	global $lang_backup;

	//If we want to remove directory	
	if (is_dir($f))	
	{
		$error = false;
		if (!is_writable($f))
		{
			$errors[] = sprintf($lang_backup['Permission error'], $f);
			$error = true;
			return;			
		}			
		//Check and remove all entire directores
		foreach (scanEntireDir($f) as $item)
		{
			if ( (!strcmp($item, '.')) || (!strcmp($item, '..')) )
				continue;
			if ( !checkdelTree(realpath($f.'/'.$item)) )
			{
				$error = true;
				$errors[] = sprintf($lang_backup['Permission error'], $f.'/'.$item);
			}
			else
				delTree(realpath($f.'/'.$item), $errors);
		}		
		//Remove current directory
		if (!$error)
			rmdir($f);
	}
	else
	{
		if ( !is_writable($f) )
			$errors[] = sprintf($lang_backup['Permission error file'], $f);
		else
			unlink($f);
	}
}

function checkdelTree( $f )
{
	if (!is_writable($f))
		return false;
	if (!is_dir($f))
		return true;

	foreach (scanEntireDir($f) as $item)
	{
		if ((!strcmp($item, '.')) || (!strcmp($item, '..')))
			continue;
		if (!checkdelTree(realpath($f . '/' . $item)))
			return false;	
	}
	return true;
}
	
function sendFile( $filename )
{
	global $lang_backup;

	header('Content-Type: application/x-force-download;');
	header('Content-Disposition: attachment; filename='.basename($filename));
	header('Content-Transfer-Encoding binary');
	header('Content-Length '.filesize($filename));
	readfile($filename) or die($lang_backup['File not found']);
}

?>
