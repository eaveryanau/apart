<?php

/**
 * @param $url
 * @return array
 */
function getUrl($url):array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($output, true);

    return [
        'aparts' => $data['apartments'],
        'total' => $data['page']['last']
    ];
}

/**
 * @param $apartInfo
 * @param $tg
 * @param bool $update
 * @param int $oldAmount
 * @return bool
 */
function sendMessage($apartInfo, $tg, $update = false, $oldAmount = 0):bool
{
    // TODO improve text
    $agency = !$apartInfo['contact']['owner'] ? 'Agency ' : '';
    $amount = $update ? '<b>Up:</b>%20' . $oldAmount . '%20->%20<b>' . (int)$apartInfo['price']['amount'] . '</b>' : '<b>' . (int)$apartInfo['price']['amount'] . '</b>';
    $baseUrl = 'https://api.telegram.org/bot' . $tg . '/sendMessage?chat_id=@aveaparts&parse_mode=HTML&text=<a%20href="' . $apartInfo['url'] . '">' . $agency . $amount . '(' . $apartInfo['price']['currency'] . ')%20-%20' . $apartInfo['location']['user_address'] . '</a>';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $res = curl_exec($ch);
    curl_close($ch);
    print "\n\n";
    print $baseUrl;
    print_r($res);
    return true;
}

/**
 * @param $parseMode
 * @return array
 */
function getApartsFromOnliner($parseMode):array
{
    $i = 1;
    $aparts = [];

    do {
        $onlinerUrl = 'https://ak.api.onliner.by/search/apartments?rent_type%5B%5D=2_rooms&rent_type%5B%5D=3_rooms&rent_type%5B%5D=4_rooms&rent_type%5B%5D=5_rooms&rent_type%5B%5D=6_rooms&price%5Bmin%5D=50&price%5Bmax%5D=460&currency=usd&bounds%5Blb%5D%5Blat%5D=53.77955794100295&bounds%5Blb%5D%5Blong%5D=27.39097595214844&bounds%5Brt%5D%5Blat%5D=54.016645360195085&bounds%5Brt%5D%5Blong%5D=27.734298706054688&page=' . $i . '&v=0.8131840960715253';
        $onlineInfo = getUrl($onlinerUrl);
        $aparts = array_merge($aparts, $onlineInfo['aparts']);
        if ($parseMode === 0) {
            break;
        }
        $i++;
    } while ($i <= $onlineInfo['total']);

    return $aparts;
}

$config = parse_ini_file('.env', true);
$token = $config['tg_token'];

$aparts = getApartsFromOnliner($config['parse_mode']);

$host = "host = " . $config['DB_HOST'];
$port = "port = " . $config['DB_PORT'];
$dbname = "dbname = " . $config['DB_NAME'];
$credentials = "user = " . $config['DB_USER'] . " password=" . $config['DB_PASSWORD'];

$db = pg_connect("$host $port $dbname $credentials");
if (!$db) {
    print "Error : Unable to open database\n";
} else {
    print "Opened database successfully\n";
}

$result = pg_query($db, "SELECT * FROM aparts");
if (!$result) {
    print "Произошла ошибка.\n";
    exit;
}

$arrFromDB = pg_fetch_all($result);


$totalCount = 0;
foreach ($aparts as $apartFromOnliner) {

    print $apartFromOnliner['id'] . "\n";
    $newApart = true;
    foreach ($arrFromDB as $item) {
        if ($apartFromOnliner['id'] == $item['apart_id']) {
            print "into arr \n";
            if ((int)$apartFromOnliner['price']['amount'] !== (int)$item['amount']) {

                $res = pg_update($db, 'aparts', [
                    'apart_id' => $apartFromOnliner['id'],
                    'created_at' => strtotime($apartFromOnliner['created_at']),
                    'last_time_up' => strtotime($apartFromOnliner['last_time_up']),
                    'amount' => (int)$apartFromOnliner['price']['amount']
                ], ['apart_id' => $apartFromOnliner['id']]);
                if ($res) {
                    print "sendMessage Update\n";
                    sendMessage($apartFromOnliner, $token,true, $item['amount']);
                } else {
                    print pg_last_error();
                    die('error update');
                }
            }
            $newApart = false;
            break;
        }
    }

    if ($newApart) {
        $res = pg_insert($db, 'aparts',
            [
                'apart_id' => $apartFromOnliner['id'],
                'created_at' => strtotime($apartFromOnliner['created_at']),
                'last_time_up' => strtotime($apartFromOnliner['last_time_up']),
                'amount' => (int)$apartFromOnliner['price']['amount']
            ]
        );
        if ($res) {
            print "sendMessage Insert\n";
            sendMessage($apartFromOnliner, $token);
        } else {
            var_dump($apartFromOnliner);
            print pg_last_error();
            die('error insert');
        }
    }
}
