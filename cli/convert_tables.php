#!/usr/bin/php -q
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2018 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset($_SERVER['argv'][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die('<br><strong>This script is only meant to run at the command line.</strong>');
}

$no_http_headers = true;

include('../include/global.php');

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

global $debug;

$innodb      = false;
$utf8        = false;
$debug       = false;
$size        = 1000000;
$rebuild     = false;
$table_name  = '';
$skip_tables = array();

if (sizeof($parms)) {
	foreach($parms as $parameter) {
		if (strpos($parameter, '=')) {
			list($arg, $value) = explode('=', $parameter);
		} else {
			$arg = $parameter;
			$value = '';
		}

		switch ($arg) {
			case '-d':
			case '--debug':
				$debug = true;
				break;
			case '-r':
			case '--rebuild':
				$rebuild = true;
				break;
			case '-s':
			case '--size':
				$size = $value;
				break;
			case '-t':
			case '--table':
				$table_name = $value;
				break;
			case '-i':
			case '--innodb':
				$innodb = true;
				break;
			case '-n':
			case '--skip-innodb':
				$skip_tables = explode(' ', $value);
				break;
			case '-u':
			case '--utf8':
				$utf8 = true;
				break;
			case '--version':
			case '-V':
			case '-v':
				display_version();
				exit;
			case '--help':
			case '-H':
			case '-h':
				display_help();
				exit;
			default:
				print 'ERROR: Invalid Parameter ' . $parameter . "\n\n";
				display_help();
				exit;
		}
	}
}

if (sizeof($skip_tables) && $table_name != '') {
	print "ERROR: You can not specify a single table and skip tables at the same time.\n\n";
	display_help();
	exit;
}

if (!($innodb || $utf8)) {
	print "ERROR: Must select either UTF8 or InnoDB conversion.\n\n";
	display_help();
	exit;
}

if (sizeof($skip_tables)) {
	foreach($skip_tables as $table) {
		if (!db_table_exists($table)) {
			print "ERROR: Skip Table $table does not Exist.  Can not continue.\n\n";
			display_help();
			exit;
		}
	}
}

$convert = $innodb ? 'InnoDB' : '';
if ($utf8) {
	$convert .= (strlen($convert) ? ' and ' : '') . ' utf8';
}

echo "Converting Database Tables to $convert with less than '$size' Records\n";

if ($innodb) {
	$engines = db_fetch_assoc('SHOW ENGINES');

	foreach($engines as $engine) {
		if (strtolower($engine['Engine']) == 'innodb' && strtolower($engine['Support'] == 'off')) {
			echo "InnoDB Engine is not enabled\n";
			exit;
		}
	}

	$file_per_table = db_fetch_row("show global variables like 'innodb_file_per_table'");

	if (strtolower($file_per_table['Value']) != 'on') {
		echo 'innodb_file_per_table not enabled';
		exit;
	}
}

if (strlen($table_name)) {
	$tables = db_fetch_assoc('SHOW TABLE STATUS LIKE \''.$table_name .'\'');
} else {
	$tables = db_fetch_assoc('SHOW TABLE STATUS');
}

if (sizeof($tables)) {
	foreach($tables AS $table) {
		$canConvert = $rebuild;
		$canInnoDB  = false;
		if (!$canConvert && $innodb) {
			$canConvert = $table['Engine'] == 'MyISAM';
			$canInnoDB  = true;
		}

		if (in_array($table['Name'], $skip_tables)) {
			$canInnoDB = false;
		}

		if (!$canConvert && $utf8) {
			$canConvert = $table['Collation'] != 'utf8mb4_unicode_ci';
		}

		if ($canConvert) {
			if ($table['Rows'] < $size) {
				echo "Converting Table -> '" . $table['Name'] . "'";

				$sql = '';
				if ($utf8) {
					$sql .= ' CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
				}

				if ($innodb && $canInnoDB) {
					$sql .= (strlen($sql) ? ',' : '') . ' ENGINE=Innodb';
				}

				$status = db_execute('ALTER TABLE `' . $table['Name'] . '`' . $sql);
				echo ($status == 0 ? ' Failed' : ' Successful') . "\n";
			} else {
				echo "Skipping Table -> '" . $table['Name'] . " too many rows '" . $table['Rows'] . "'\n";
			}
		} else {
			echo "Skipping Table -> '" . $table['Name'] . "'\n";
		}
	}
}

/*  display_version - displays version information */
function display_version() {
	$version = get_cacti_cli_version();
	echo "Cacti Database Conversion Utility, Version $version, " . COPYRIGHT_YEARS . "\n";
}

/*	display_help - displays the usage of the function */
function display_help () {
	display_version();

	echo "\nusage: convert_tables.php [--debug] [--innodb] [--utf8] [--table=N] [--size=N] [--rebuild]\n\n";
	echo "A utility to convert a Cacti Database from MyISAM to the InnoDB table format.\n";
	echo "MEMORY tables are not converted to InnoDB in this process.\n\n";
	echo "Required (one or more):\n";
	echo "-i | --innodb  - Convert any MyISAM tables to InnoDB\n";
	echo "-u | --utf8    - Convert any non-UTF8 tables to utf8mb4_unicode_ci\n\n";
	echo "Optional:\n";
	echo "-t | --table=S - The name of a single table to change\n";
	echo "-n | --skip-innodb=\"table1 table2 ...\" - Skip converting tables to InnoDB\n";
	echo "-s | --size=N  - The largest table size in records to convert.  Default is 1,000,000 rows.\n";
	echo "-r | --rebuild - Will compress/optimize existing InnoDB tables if found\n";
	echo "-d | --debug   - Display verbose output during execution\n\n";
}
