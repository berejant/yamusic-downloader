<?php

// плейлист для скачивания
$playlist_url = substr($_SERVER['PATH_INFO'], 1);


require_once __DIR__ . '/vendor/autoload.php';

define('MP3_DIR', __DIR__ . '/mp3/');

set_time_limit(0);
ini_set('memory_limit','256M');

/*
 * Записывает мета-данные о треке полученные из Яндекса в id3-теги mp3-файла
 * @param string $filepath Путь к файлу с треком
 * @param array $track массив о треке, полученных от Яндекса
 * @throws Exception
 */
function writeId3Tags ($filepath, $track)
{
    $album = $track['albums'][0];

    // Определяем информацию
    $tags = array(
        'name' => $track['title'],

        # @todo Обрабатывать несколько исполнителей в массиве $track['artists']
        'artists' => $track['artists'][0]['name'],

        'album'   => $album['title'],
        'year'    => $album['year'],
        'comment' => '',
        'genre'   => $album['genre'],
        'track'   => null,
    );

    foreach($tags as &$value) {
        if(is_string($value)) {
            $value = iconv('utf-8', 'windows-1251//ignore', $value);
        }
    }
    unset($value);

    $id3 = new MP3_Id;
    $result = $id3->read($filepath);

    // Ошибка "Tag not found" игнорируется
    if (PEAR::isError($result) && $result->getCode() !== PEAR_MP3_ID_TNF) {
        throw new Exception($result->getMessage());
    }

    $id3->setTag($tags);

    // Записываем информацию в тег
    $result = $id3->write();
    if (PEAR::isError($result)) {
        throw new Exception ($result->getMessage());
    }

    return true;
}


$http = new Http;

if(!preg_match( '/users\/(.*)\/playlists\/(.*)(?:$|\\/)/isuU', $playlist_url, $match ) ) {
    exit('Wrong url');
}

$owner = $match[1];
$playlist_id = $match[2];

$response = $http->get( 'http://music.yandex.ru/handlers/playlist.jsx?kinds=' . $playlist_id . '&owner=' . $owner );

$playlist = json_decode( $response, true );

if(!$playlist) {
    echo 'Playlist not found';
    exit;
}

$playlist_title = $playlist['playlist']['title'];
$playlist_title = str_replace("\'","_", $playlist_title);

$tracks = $playlist['playlist']['tracks'];

$denied = array ('\\','/',':','?','*','<','>','|','"');

$playlist_title = str_ireplace($denied,'_', $playlist_title);

$playlist_dir = MP3_DIR . $playlist_title;

echo $playlist_dir, '<br/>';

if ( !file_exists( $playlist_dir ) && !is_dir( $playlist_dir ) ) {
    mkdir( $playlist_dir );
}

$index = 1;
echo '<table border="1" cellspacing="0">';

$totalDuration = 0;

$http->timeout = 60;

// скачивай трек за треком
foreach ( $tracks as $track ) {
    $artist =  $track['artists'][0]['name'];
    $title = $track['title'];
    $mp3_name =  $artist . ' - ' . $title . '.mp3';

    echo '<tr><td>', $index, '</td><td>', $mp3_name, '</td>', PHP_EOL;
    flush();

    $response = $http->get( 'http://storage.mds.yandex.net/download-info/' . $track['storageDir'] . '/2?format=json' );

    if($http->info['http_code'] !== 200) {
        echo '<td>Bad http code: ',  $http->info['http_code'], '</td><td></td></tr>';
        continue;
    }

    $json = json_decode( $response, true );

    if(!$json) {
        echo '<td>Error: bad json</td><td></td></tr>';
        continue;
    }

    $host = $json['host'];
    $ts   = $json['ts'];
    $path = $json['path'];
    $s    = $json['s'];

    $n = md5( 'XGRlBW9FXlekgbPrRHuSiA' . substr( $path, 1 ) . $s );

    $mp3_url = 'http://' . $host . '/get-mp3/' . $n . '/' . $ts . $path;
    $mp3_name = str_ireplace($denied, '_', $mp3_name);

    $filename = MP3_DIR . $playlist_title . '/' . $mp3_name;
    $exists = file_exists($filename) && filesize($filename);

    if ( !$exists )  {
        $response = $http->get( $mp3_url );
        if($response && file_put_contents( $filename, $response )) {
            echo '<td>downloaded</td>';
        } else {
            echo '<td style="color:red;">failed</td>';
        }
        unset($response);
    } else {
        echo '<td>exists</td>';
    };

    if( file_exists($filename) ) {
        try {
            writeId3Tags ($filename, $track);
            echo '<td>Write ID3 success</td>';
        } catch (Exception $e) {
            echo '<td>Write ID3 failed:', $e->getMessage(), '</td>';
        }
    }
    echo '</tr>';
    $index++;
    $totalDuration += $track['duration'];
 };

echo '</table>', PHP_EOL;

// определяем общую длительность звучания
$totalDuration = floor($totalDuration/1000);

$totalDuration = array( 'seconds' => $totalDuration );

$totalDuration['minute']  = floor($totalDuration['seconds'] / 60);
$totalDuration['hours']   = floor($totalDuration['minute'] / 60);
$totalDuration['seconds'] -= $totalDuration['minute'] * 60;
$totalDuration['minute']  -= $totalDuration['hours'] * 60;
$totalDuration['text']    = '';

// генерируем текст с общей длительностью
if($totalDuration['hours']) {
	$totalDuration['text'] .= $totalDuration['hours'] . ' ';

	if( $totalDuration['hours']%10 == 1 && $totalDuration['hours'] != 11) {
		$totalDuration['text'] .= 'час';
	} elseif(  $totalDuration['hours']%10 >= 2 && $totalDuration['hours']%10 <= 4 && ($totalDuration['hours'] < 10 || $totalDuration['hours'] > 20)) {
		$totalDuration['text'] .= 'часа';
	} else {
		$totalDuration['text'] .= 'часов';
	}
}

if($totalDuration['minute'])
{
	if($totalDuration['text'])
	{
		$totalDuration['text'] .= ' ';
		if(!$totalDuration['seconds'])
		{
			$totalDuration['text'] .= 'и ';
		}
	}

	$totalDuration['text'] .= $totalDuration['minute'] . ' ';

	if( $totalDuration['minute']%10 == 1 && $totalDuration['minute'] != 11) {
		$totalDuration['text'] .= 'минуту';
	} elseif(  $totalDuration['minute']%10 >= 2 && $totalDuration['minute']%10 <= 4 && ($totalDuration['minute'] < 10 || $totalDuration['minute'] > 20)) {
		$totalDuration['text'] .= 'минуты';
	} else {
		$totalDuration['text'] .= 'минут';
	}
}


if($totalDuration['seconds'])
{
	if($totalDuration['text'])
	{
		$totalDuration['text'] .= ' и ';
	}

	$totalDuration['text'] .= $totalDuration['seconds'] . ' ';

	if( $totalDuration['seconds']%10 == 1 && $totalDuration['seconds'] != 11) {
		$totalDuration['text'] .= 'секунуду';
	} elseif(  $totalDuration['seconds']%10 >= 2 && $totalDuration['seconds']%10 <= 4 && ($totalDuration['seconds'] < 10 || $totalDuration['seconds'] > 20)) {
		$totalDuration['text'] .= 'секунды';
	} else {
		$totalDuration['text'] .= 'секунд';
	}
}

if($totalDuration['text']) {
	echo 'Длительность: ', $totalDuration['text'], '.<br />', PHP_EOL;
}
