<?php
/*
Made by Kudusch (blog.kudusch.de, kudusch.de, @Kudusch)

---------

DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
                    Version 2, December 2004

Copyright (C) 2004 Sam Hocevar <sam@hocevar.net>
Everyone is permitted to copy and distribute verbatim or modified
copies of this license document, and changing it is allowed as long
as the name is changed.

DO WHAT THE FUCK YOU WANT TO PUBLIC LICENSE
TERMS AND CONDITIONS FOR COPYING, DISTRIBUTION AND MODIFICATION

0. You just DO WHAT THE FUCK YOU WANT TO.

---------

# How to use

- go to http://mediathek.daserste.de, open with iPad User Agent
- navigate to the desired program, get link to the master.m3u8 file from source (in the <video>-tag)
- run the script with url to the master.m3u8 file as argument (e.g. php get_tshls 'http://http://hls.daserste.de/i/videoportal/Film/c_380000/386059/ios.smil/master.m3u8'
- wait for the script to download and merge all media files; every 10 second part is about 3 MB (when it's done, it will output the runtime)
- if necessary, convert the *.ts file with a media converter eg. handbrake to a *.mp4 file
- enjoy

*/
error_reporting(E_ALL);
ini_set('display_errors', 1);
//runtime
$startTime = microtime(true);

//get url from input
$url = $argv[1];
$root_url = getRootUrl($url);
//get stream with highest bandwith
$streamUrl = getHighBandwidthStream($url);
//get array of all links to *.ts files
$list = getHlsFiles($root_url.'/'.$streamUrl);

//make new directory
if (!is_dir('files')) {
    mkdir('files');
}

//download all files from array, name with 3 leading zeros
//if file is longer than 166.5 minutes, adjust str_pad params
echo "Download all files from array\n";
//var_dump($list);

$n = 1;
foreach ($list as $key) {
    $number = str_pad($n, 4, "0", STR_PAD_LEFT);
    echo($n.": ".$key."\n");
    $fn = "files/part.".$number.".ts";
    if (file_exists($fn) && filesize($fn) > 0) {
        echo "file exist, skip \n";
    } else {
        $tries = 0;
        $tsf = @fopen($root_url.'/'.$key, 'r');
        while ($tsf == false && $tries < 3) {
            echo "Try again later ... \n";
            sleep(3);
            $tsf = @fopen($root_url.'/'.$key, 'r');
            $tries++;
        }
        if (!$tsf) {
            echo "Failed to download ts file\n";
            exit;
        }
        file_put_contents('tmp.ts', $tsf);
        rename('tmp.ts', $fn);
        echo "download ok!\n";
    }
    $n++;
}

//merge files and delte parts
sleep(1);
mergeFiles('files');

//echo part numbers and runtime for debugging
echo("\nRun in ".(microtime(true)-$startTime)." seconds.");

/*
 * Input: string
 * Output: string
 */
function getRootUrl($masterUrl)
{
    $arr = parse_url($masterUrl);
//    var_dump($arr);
    $root_url = $arr['scheme'].'://'.$arr['host'];
    var_dump($root_url);
    return $root_url;
}

/*
 * input: string
 * output: string
 */
function getHighBandwidthStream($masterUrl) {
    echo "Get content of master.m3u8\n";
    $ch = curl_init($masterUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    $curl_res = curl_exec($ch);
    curl_close($ch);

    //return link to second last stream (https://developer.apple.com/library/ios/documentation/networkinginternet/conceptual/streamingmediaguide/FrequentlyAskedQuestions/FrequentlyAskedQuestions.html#//apple_ref/doc/uid/TP40008332-CH103-SW1)
//    var_dump($curl_res);

    $result = explode("#", $curl_res);
//    var_dump($result);
    $flg = false;
    for ($i = 0; $i < count($result); $i++) {
//        array_shift($result);
        if (strpos($result[$i], 'EXT-X-STREAM-INF') !== false) {
            $flg = true;
            break;
        }
    }
//    var_dump($result);

    if ($flg == false) {
        echo "Data error, without STREAM-INF!\n";
        exit;
    }

    $result = explode("\n", $result[$i]);
//    var_dump($result);

    if (!isset($result[1])) {
        echo "Data error!\n";
        exit;
    }
    $str = $result[1];
    echo "HLS url: {$str} \n";
    return $str;
}

/*
 * input: string
 * output: array
 */
function getHlsFiles($streamUrl) {
    echo "Get content of *.m3u8 file \n";
//    var_dump($streamUrl);
    $ch = curl_init($streamUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    $raw = curl_exec($ch);
    curl_close($ch);

//    var_dump($raw);
    //remove comments and unnecessary data
    $list_raw = explode("\n", $raw);

    /*
    for ($i = 0; $i < 5; $i++) {
        array_shift($list_raw);
    }

    for ($i = 0; $i < 2; $i++) {
        array_pop($list_raw);
    }

    var_dump($list_raw);
    //extract file links
    $list = array();
    $i = 1;
    foreach ($list_raw as $key) {
        if($i % 2 == 0) {
            array_push($list, $key);
        }
        $i++;
    }
    */
    $list = [];

    while (count($list_raw) > 0) {
        $txt = array_shift($list_raw);
        if (strpos($txt,'#EXTINF:') !== false) {
            $furl = array_shift($list_raw);
            if ($furl != '') {
                $list[] = $furl;
            }
        }
    }

    //return array
    return $list;
}

function mergeFiles($dirName) {
    //get all *.ts files in directory
    $fileList = [];

    if ($handle = opendir($dirName)) {
        while (false !== ($file = readdir($handle))) {
            if (strpos($file, ".ts") !== false) {
                $fileList[] = "files/".$file;
            }
        }
        closedir($handle);
    }

    asort($fileList);
//    var_dump($fileList);
//    exit;

    shell_exec("rm movie.ts");
    //join and remove parts
//    echo "filelist: {$fileList}\n";
    foreach ($fileList as $ff) {
        $shellScript = "cat ".$ff." >> movie.ts";
        shell_exec($shellScript);
    }

//    shell_exec("rm -r files");
}
