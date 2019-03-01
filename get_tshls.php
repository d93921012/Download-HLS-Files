<?php
/*
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
$GLOBALS['root_url'] = $root_url;

//get stream file with highest bandwith
$streamFile = getStreamFile($url);

if (streamHasChanged($streamFile)) {
    echo "Playlist has been changed, delete cached files. \n";
    shell_exec("rm -r files");
}

//get array of all links to *.ts files
$playlist = getPlaylist($streamFile);

//make new directory
if (!is_dir('files')) {
    mkdir('files');
}

echo "Download all files from array\n";
downloadSegmentFiles($playlist);
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
    $root_url = $arr['scheme'].'://'.$arr['host'];

    $rpos = strrpos($arr['path'], '/');
    $root_path = $root_url.substr($arr['path'], 0, $rpos);
    $GLOBALS['root_path'] = $root_path;

    return $root_url;
}

/*
 * Input: url string
 * Output: content string
 */
function getUrlFile($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

/*
 * input: url string
 * output: playlist string
 */
function getStreamFile($masterUrl)
{
    echo "Get high bandwidth stream file\n";
    $curl_res = getUrlFile($masterUrl);
    $result = explode("#", $curl_res);

    $isVariantPlaylist = false;
    for ($i = 0; $i < count($result); $i++) {
        if (strpos($result[$i], 'EXT-X-STREAM-INF') !== false) {
            $isVariantPlaylist = true;
            break;
        }
    }

    // 若為 VariantPlaylist，取得第一個 playlist
    if ($isVariantPlaylist == true) {
        $result = explode("\n", $result[$i]);

        if (isset($result[1])) {
            $sUrl = $result[1];
            if (strpos($sUrl, 'http') !== 0) {
                $sUrl = $GLOBALS['root_url'].'/'.$sUrl;
            }
            $curl_res = getUrlFile($sUrl);
        } else {
            echo "Data error!\n";
            exit;
        }
    }

    return $curl_res;
}

/*
 * 判斷 playlist 是否已改變
 */
function streamHasChanged($streamFile)
{
    $flg = false;

    if (!file_exists('playlist.m3u8')) {
        $flg = true;
    } else {
        $chk1 = md5_file('playlist.m3u8');
        $chk2 = md5($streamFile);
        if ($chk1 != $chk2) {
            $flg = true;
        }
    }

    if ($flg == true) {
        file_put_contents('playlist.m3u8', $streamFile);
    }

    return $flg;
}

/*
 * input: string
 * output: array
 */
function getPlaylist($plstr) {
    echo "Get playlist \n";

    $list_raw = explode("\n", $plstr);

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

// download all files from array, name with 3 leading zeros
// if file is longer than 166.5 minutes, adjust str_pad params
// 已修改為加上 4 個 0
function downloadSegmentFiles($list)
{
    // 一邊下載，同時組合，可以一邊看
    file_put_contents('movie_tmp.ts', '');

    // 平行下載的連線數
    $parallel_no = 5;
    
    $n = 1;
    for ($ii=0; $ii < ceil(count($list)/$parallel_no); $ii++) {

        $ts_lst = [];

        for ($jj=0; $jj < $parallel_no; $jj++) {
            $idx = $ii * $parallel_no + $jj;

            if ($idx < count($list)) {
                $key = $list[$idx];
                $_n = $n+$jj;
                $number = str_pad($_n, 5, "0", STR_PAD_LEFT);
                echo($_n.": ".$key."\n");

                $fn = "files/part_".$number.".ts";

                if (strpos($key, '/') === 0) {
                    $key = $GLOBALS['root_url'].'/'.$key;
                } else if (strpos($key, 'http') !== 0) {
                    $key = $GLOBALS['root_path'].'/'.$key;
                }

                $ts_lst[] = [$key, $fn];
            }
        }


        $tries = 0;

        $tsf = curlGetFiles($ts_lst);
        while ($tsf == false && $tries < 16) {
            echo "Try again later ... \n";
            sleep(3);
            $tsf = curlGetFiles($ts_lst);
            $tries++;
        }
        if (!$tsf) {
            echo "Failed to download ts file\n";
            exit;
        }

        // file_put_contents('tmp.ts', $tsf);
        // rename('tmp.ts', $fn);
        echo "download ok!\n";

        foreach ($ts_lst as $ts) {
            $fn = $ts[1];
            $shellScript = "cat ".$fn." >> movie_tmp.ts";
            shell_exec($shellScript);
        }

        $n += $parallel_no;
    }

}

function curlGetFiles(Array $ts_lst)
{
    $flg = true;

    $ch_lst = [];
    $mh = curl_multi_init();

    foreach ($ts_lst as $par) {
        $fn = $par[1];

        if (file_exists($fn) && filesize($fn) > 0) {
            echo "{$fn} file exist, skip \n";
            continue;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $par[0]);
        curl_setopt($ch, CURLOPT_HEADER,0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT,10);

        curl_multi_add_handle($mh, $ch);
        $ch_lst[] = ['ch' => $ch, 'fn' => $fn];
    }

    $running = null;

    // execute the handles
    do {
        $mrc = curl_multi_exec($mh, $running);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);

    while ($running && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do {
                $mrc = curl_multi_exec($mh, $running);
            } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }

    // $info = curl_multi_info_read($mh);
    // if (false !== $info) {
    //     var_dump($info);
    // }
    // get content and remove handles

    for ($i = 0; $i < count($ch_lst); $i++) {
        $ch = $ch_lst[$i]['ch'];
        $fn = $ch_lst[$i]['fn'];

        if(curl_errno($ch)) {
            echo "Download error: '{$fn}', error: " . curl_error($ch)."\n";
            $flg = false;
        } else {
            $resultStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($resultStatus == '200') {
                $raw = curl_multi_getcontent($ch);
                $fp = fopen($fn,'w');
                fwrite($fp, $raw);
                fclose($fp);
            } else {
                echo "Download error: '{$fn}', status: {$resultStatus}\n";
                $flg = false;
            }

        }

        // close the handle
        curl_multi_remove_handle($mh, $ch);
    }

    curl_multi_close($mh);

    return $flg;
}

function mergeFiles($dirName)
{
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

    file_put_contents('movie.ts', '');

    // join all parts
    foreach ($fileList as $ff) {
        $shellScript = "cat ".$ff." >> movie.ts";
        shell_exec($shellScript);
    }

    shell_exec("ffmpeg -i movie.ts -c copy movie.mp4");
//    shell_exec("rm -r files");
}
