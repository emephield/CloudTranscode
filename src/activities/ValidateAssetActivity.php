#!/usr/bin/php

<?php

/*
 *   This class validate input assets and get mime type and metadata 
 *   Using the ValidateAsset activity you can confirm your asset can be transcoded
 *
 *   Copyright (C) 2016  BFan Sports - Sport Archive Inc.
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 *   This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU General Public License for more details.
 *
 *   You should have received a copy of the GNU General Public License along
 *   with this program; if not, write to the Free Software Foundation, Inc.,
 *   51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

require_once __DIR__.'/ValidateAssetBaseInterface.php';

use SA\CpeSdk;

/*
 ***************************
 * Activity Startup SCRIPT
 ***************************
*/

// Usage
function usage()
{
    echo("Usage: php ". basename(__FILE__) . " -A <Snf ARN> -I <input> [-C <client class path>] [-N <activity name>] [-h] [-d] [-l <log path>]\n");
    echo("-h: Print this help\n");
    echo("-d: Debug mode\n");
    echo("-l <log_path>: Location where logs will be dumped in (folder).\n");
    echo("-A <activity_name>: Activity name this Poller can process. Or use 'SNF_ACTIVITY_ARN' environment variable. Command line arguments have precedence\n");
    echo("-C <client class path>: Path to the PHP file that contains the class that implements your Client Interface\n");
    echo("-N <activity name>: Override the default activity name. Useful if you want to have different client interfaces for the same activity type.\n");
    echo("-I <json string>: Json object you give as input of the function. To use it as a command line, use -I \"$(< file.json)\" replacing file.json by your input file. \n");
    exit(0);
}

// Check command line input parameters
function check_activity_arguments()
{
    // Filling the globals with input
    global $arn;
    global $logPath;
    global $debug;
    global $clientClassPath;
    global $name;
    global $input;
    
    // Handle input parameters
    if (!($options = getopt("N:A:l:C:I:hd")))
        usage();
    
    if (isset($options['h']))
        usage();

    // Debug
    if (isset($options['d']))
        $debug = true;

    if (isset($options['I']))
        $input = json_decode($options['I']);        
    else
    {
        echo "ERROR: You must provide an input'\n";
        usage();
    }


    if (isset($options['A']) && $options['A']) {
        $arn = $options['A'];
    } else if (getenv('SNF_ACTIVITY_ARN')) {
        $arn = getenv('SNF_ACTIVITY_ARN');
    } else {
        echo "ERROR: You must provide the ARN of your activity (Sfn ARN). Use option [-A <ARN>] or environment variable: 'SNF_ACTIVITY_ARN'\n";
        usage();
    }

    if (isset($options['C']) && $options['C']) {
        $clientClassPath = $options['C'];
    }

    if (isset($options['N']) && $options['N']) {
        $name = $options['N'];
    }
    
    if (isset($options['l']))
        $logPath = $options['l'];
}


/*
 * START THE SCRIPT ACTITIVY
 */

// Globals
$debug = false;
$logPath = null;
$arn;
$name = 'ValidateAsset';
$clientClassPath = null;
$input;

check_activity_arguments();

$task = [
    "input" => $input
];
$cpeLogger = new SA\CpeSdk\CpeLogger($name, $logPath);
$cpeLogger->logOut("INFO", basename(__FILE__),
                   "\033[1mStarting activity\033[0m: $name");

// We instanciate the Activity 'ValidateAsset' and give it a name for Snf
$activityPoller = new ValidateAssetActivity(
    $clientClassPath,
    [
        'arn'  => $arn,
        'name' => $name
    ],
    $debug,
    $cpeLogger);

// Initiate the polling loop and will call your `process` function upon trigger
$activityPoller->doActivityOnce($task);


