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
    //Send Photo
    $baseUrl = 'https://api.telegram.org/bot' . $tg . '/sendPhoto?chat_id=@aveaparts&disable_notification=true&photo=' . $apartInfo['photo'];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_exec($ch);
    curl_close($ch);
    sleep(3);

    //Send url
    // TODO improve text
    $agency = $apartInfo['seller']['type'] === 'agent' ? 'Agency ' : '';
    $amount = $update ? '<b>Up:</b>%20' . $oldAmount . '%20->%20<b>' . (int)$apartInfo['price']['amount'] . '</b>' : '<b>' . (int)$apartInfo['price']['amount'] . '%20('. (int)($apartInfo['price']['amount']/$apartInfo['area']['total']) .'$)</b>';

    $baseUrl = 'https://api.telegram.org/bot' . $tg . '/sendMessage?chat_id=@aveaparts&disable_web_page_preview=true&parse_mode=HTML&text='.'<a%20href="' . $apartInfo['url'] . '">' . $agency . $amount . '</a>' .urlencode( "\n"  . $apartInfo['area']['total'] . ' (' . $apartInfo['area']['living'] . ') - '.$apartInfo['number_of_rooms'] . ' ком.'."\n". $apartInfo['floor'] . '/' . $apartInfo['number_of_floors'] ."\n". $apartInfo['location']['address']);
//        '<a%20href="' . $apartInfo['url'] . '">' . $agency . $amount . '(' . $apartInfo['price']['currency'] . ')%20-%20' . $apartInfo['location']['address'] . '</a>';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $baseUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $res = curl_exec($ch);
    curl_close($ch);
    print "\n\n";
    print $baseUrl;
    sleep(3);
    return true;
}

/**
 * @param $config
 * @return array
 */
function getLoanApartsFromOnliner($config):array
{
    $i = 1;
    $aparts = [];
    $minPrice = $config['min_price'];
    $maxPrice = $config['max_price'];

    var_dump($config['parse_mode']);
    do {
        $onlinerUrl = 'https://ak.api.onliner.by/search/apartments?rent_type%5B%5D=2_rooms&rent_type%5B%5D=3_rooms&rent_type%5B%5D=4_rooms&rent_type%5B%5D=5_rooms&rent_type%5B%5D=6_rooms&price%5Bmin%5D=' . $minPrice . '&price%5Bmax%5D=' . $maxPrice . '&currency=usd&bounds%5Blb%5D%5Blat%5D=53.77955794100295&bounds%5Blb%5D%5Blong%5D=27.39097595214844&bounds%5Brt%5D%5Blat%5D=54.016645360195085&bounds%5Brt%5D%5Blong%5D=27.734298706054688&page=' . $i . '&v=0.8131840960715253';
        $onlineInfo = getUrl($onlinerUrl);
        $aparts = array_merge($aparts, $onlineInfo['aparts']);
        if ($config['parse_mode'] === 0) {
            break;
        }
        $i++;
    } while ($i <= $onlineInfo['total']);

    return $aparts;
}


/**
 * @param $config
 * @return array
 */
function getApartsFromOnliner($config):array
{
    $i = 1;
    $aparts = [];
//    $minPrice = $config['min_price'];
//    $maxPrice = $config['max_price'];

    do {
        $onlinerUrl = 'https://r.onliner.by/sdapi/pk.api/search/apartments?number_of_rooms%5B%5D=2&number_of_rooms%5B%5D=3&number_of_rooms%5B%5D=4&number_of_rooms%5B%5D=5&number_of_rooms%5B%5D=6&building_year%5Bmin%5D=1900&building_year%5Bmax%5D=2031&bounds%5Blb%5D%5Blat%5D=53.559570871691115&bounds%5Blb%5D%5Blong%5D=27.311985361170006&bounds%5Brt%5D%5Blat%5D=54.226227678552085&bounds%5Brt%5D%5Blong%5D=27.832365346925673&page=' . $i . '&v=0.9386221681958828';
        $onlineInfo = getUrl($onlinerUrl);
        $aparts = array_merge($aparts, $onlineInfo['aparts']);
        if ((int)$config['parse_mode'] === 0) {
            break;
        }
        $i++;
    } while ($i <= $onlineInfo['total']);


    return $aparts;
}


function sellApartsFromOnliner($config, $token){
    $aparts = getApartsFromOnliner($config);

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

    $result = pg_query($db, "SELECT * FROM new_aparts");
    if (!$result) {
        print "Произошла ошибка.\n";
        exit;
    }

    $arrFromDB = pg_fetch_all($result);

    foreach ($aparts as $apartFromOnliner) {

        print $apartFromOnliner['id'] . "\n";
        $newApart = true;
        foreach ($arrFromDB as $item) {
            if ($apartFromOnliner['id'] == $item['id']) {
                print "into arr \n";
                if ((int)$apartFromOnliner['price']['converted']['USD']['amount'] !== (int)$item['price']) {

                    $res = pg_update($db, 'new_aparts', [
                        'id' => (int)$apartFromOnliner['id'],
                        'author_id' => (int)$apartFromOnliner['author_id'],
                        'address' => $apartFromOnliner['location']['address'],
                        'created_at' => strtotime($apartFromOnliner['created_at']),
                        'last_time_up' => strtotime($apartFromOnliner['last_time_up']),
                        'price' => (int)$apartFromOnliner['price']['converted']['USD']['amount'],
                        'currency' => $apartFromOnliner['price']['currency'] === 'USD' ? 0 : 1,
                        'photo' => $apartFromOnliner['photo'],
                        'resale' => $apartFromOnliner['resale'],
                        'rooms' => $apartFromOnliner['number_of_rooms'],
                        'floor' => $apartFromOnliner['floor'],
                        'all_floors' => $apartFromOnliner['number_of_floors'],
                        'url' => $apartFromOnliner['url'],
                        'area_all' => $apartFromOnliner['area']['total'],
                        'area_living' => $apartFromOnliner['area']['living'],
                        'area_kitchen' => $apartFromOnliner['area']['kitchen'],
                        'is_agent' => $apartFromOnliner['seller']['type'] === 'agent',
                    ], ['id' => $apartFromOnliner['id']]);
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
            $res = pg_insert($db, 'new_aparts',
                             [
                                 'id' => (int)$apartFromOnliner['id'],
                                 'author_id' => (int)$apartFromOnliner['author_id'],
                                 'address' => $apartFromOnliner['location']['address'],
                                 'created_at' => strtotime($apartFromOnliner['created_at']),
                                 'last_time_up' => strtotime($apartFromOnliner['last_time_up']),
                                 'price' => (int)$apartFromOnliner['price']['converted']['USD']['amount'],
                                 'currency' => $apartFromOnliner['price']['currency'] === 'USD' ? 0 : 1,
                                 'photo' => $apartFromOnliner['photo'],
                                 'resale' => $apartFromOnliner['resale'],
                                 'rooms' => $apartFromOnliner['number_of_rooms'],
                                 'floor' => $apartFromOnliner['floor'],
                                 'all_floors' => $apartFromOnliner['number_of_floors'],
                                 'url' => $apartFromOnliner['url'],
                                 'area_all' => $apartFromOnliner['area']['total'],
                                 'area_living' => $apartFromOnliner['area']['living'],
                                 'area_kitchen' => $apartFromOnliner['area']['kitchen'],
                                 'is_agent' => $apartFromOnliner['seller']['type'] === 'agent',
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

}

function loanApartsFromOnliner($config, $token) {
    $aparts = getLoanApartsFromOnliner($config);

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
}






$config = parse_ini_file('.env', true);
$token = $config['tg_token'];

//loanApartsFromOnliner($config, $token);
sellApartsFromOnliner($config, $token);
