<?php

#####
# Pozor, v tomto skriptu nemodifikovat sheet v docs, protoze jinak se to zacykli 
# (docs zavolaji tento skript pri zmene sheetu a ten ho pak zmeni a tak porad dokola!)
#####

error_reporting(E_ALL);

require 'vendor/autoload.php';
require 'private/config.php';

use Google\Spreadsheet\DefaultServiceRequest;
use Google\Spreadsheet\ServiceRequestFactory;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\ErrorHandler;


$sheetId = '1ugrIgECXTwqXbIlqE7pf6CNZb0edUrkIWuzH6EnjK_Y';
$worksheetId = 'od5';
$timestampFile = '/tmp/mob-last-timestamp';
$clientEmail = '356855916299-ifck40oapgsvj6ekai0kj12t9vlr2b2s@developer.gserviceaccount.com';
$privateKey = file_get_contents('private/HKFree mobily-5d8b3e2a026b.p12');

header('Content-Type: text/plain');

$log = new Logger('apnsync');
$log->pushHandler(new RotatingFileHandler('/var/log/apnsync/apnsync.log', 300, Logger::DEBUG));
ErrorHandler::register($log); // catch exceptions by Monolog and log them

function getNewIp(&$ips, $msisdn) {
    global $log;
    if (isset($ips[$msisdn]) && $ips[$msisdn] !== '') {
        return $ips[$msisdn]; // IP already assigned
    }
    // najit prvni volnou IP
    $firstIp = '10.253.36.10'; // 10.253.36.0/22
    $lastIp = '10.253.39.254';
    for ($i = ip2long($firstIp); $i < ip2long($lastIp); $i++) {
        $used = false;
        foreach ($ips as $aMsisdn => $ip) {
            if (ip2long($ip) == $i) {
                $used = true;
                break;
            }
        }
        if (!$used) {
            $ip = long2ip($i);
            $ips[$msisdn] = $ip;
            $log->debug("MSISDN $msisdn assigned new IP $ip");
            return $ip;
        }
    }
    $log->error("MSISDN $msisdn no new IP available!");
    return ''; // zadna volna IP (asi by se stat nemelo, kdyz mame rezervu)
}

$log->info('ApnSync started');
echo "Updating APN configuration\n";

$scopes = array('https://spreadsheets.google.com/feeds');
$credentials = new Google_Auth_AssertionCredentials($clientEmail, $scopes, $privateKey, $privateKeyPassword, 'http://oauth.net/grant_type/jwt/1.0/bearer' // Default grant type
);

$client = new Google_Client();
$client->setAssertionCredentials($credentials);
if ($client->getAuth()->isAccessTokenExpired()) {
    $client->getAuth()->refreshTokenWithAssertion();
}

$tokenData = json_decode($client->getAccessToken());
$accessToken = $tokenData->access_token;

$serviceRequest = new DefaultServiceRequest($accessToken);
ServiceRequestFactory::setInstance($serviceRequest);

$spreadsheetService = new Google\Spreadsheet\SpreadsheetService();
$spreadsheetFeed = $spreadsheetService->getSpreadsheets();

#$spreadsheet = $spreadsheetFeed->getByTitle('Hello World');
$spreadsheet = $spreadsheetService->getSpreadsheetById($sheetId);

// test timestamp update
$lastTimestamp = file_get_contents($timestampFile);
$timestamp = $spreadsheet->getUpdated()->getTimestamp();
$log->debug("Timestamps: $lastTimestamp $timestamp");
if (!$lastTimestamp || $timestamp > $lastTimestamp) {
    file_put_contents($timestampFile, $timestamp);

    $sheetRows = array();

    $worksheetFeed = $spreadsheet->getWorksheets();

    foreach ($worksheetFeed as $worksheet) {
        if ($worksheet->getId() == "https://spreadsheets.google.com/feeds/worksheets/$sheetId/private/values/$worksheetId") {
            foreach ($worksheet->getListFeed()->getEntries() as $entry) {
                $values = $entry->getValues();
                $sheetRec = array();
                $sheetRec['msisdn'] = trim($values['msisdn']);
                $sheetRec['uid'] = trim($values['uid']);
                $sheetRec['kauce'] = trim($values['kauce']);
                $sheetRec['fup'] = trim($values['fup']);
                if (preg_match("/^[0-9]{12}$/i", $sheetRec['msisdn']) && preg_match("/^[0-9]+$/i", $sheetRec['uid']) && preg_match("/^[0-9]*$/i", $sheetRec['kauce'])) {
                    $sheetRows []= $sheetRec;
                }
            }
        }
    }

    echo count($sheetRows) . " valid records fetched from Google Docs\n";
    $log->debug(count($sheetRows) . ' valid records fetched from Google Docs');

    if (count($sheetRows) > 100) { // "heuristic" check (error proof TM :)
        print "Updating database...\n";
        $log->debug('Updating database');

        $options = [
            'driver'   => 'mysqli',
            'host'     => 'localhost',
            'username' => 'radius',
            'password' => $mysqlPassword,
            'database' => 'userdb',
        ];
        $database = new Dibi\Connection($options);

        $database->query('SET autocommit=0');
        $database->query('LOCK TABLES mob_db WRITE');

        $statsUpdate = 0;
        $statsInsert = 0;
        $statsDelete = 0;

        $result = $database->query('SELECT uid, msisdn, kauce, fup, ip FROM mob_db WHERE ip IS NOT NULL');
        $ips = array();
        foreach ($result as $row) {
            // msisdn -> ip
            $ips[$row->msisdn] = $row->ip;
        }

        // update FUP flag, UID and IP for records in Google Docs
        $msisdnsInGoogleDocs = [];
        foreach ($sheetRows as $sheetRec) {
            $msisdnsInGoogleDocs []= $sheetRec['msisdn'];
            if (array_key_exists($sheetRec['msisdn'], $ips)) {
                // MSISDN already in DB
                $statsUpdate++;
                // update FUP and UID
                $database->query('UPDATE mob_db SET fup = ?, uid = ? WHERE msisdn = ?',
                    $sheetRec['fup'],
                    $sheetRec['uid'],
                    $sheetRec['msisdn']
                );
                if ($sheetRec['fup']) {
                    // when FUP flag is active, update (set) IP if not already set (never ever replace IP by another IP!)
                    $ip = getNewIp($ips, $sheetRec['msisdn']);
                    $database->query('UPDATE mob_db SET ip = ?, tmpid = ? WHERE msisdn = ? AND ip IS NULL',
                        $ip,
                        ip2long($ip),
                        $sheetRec['msisdn']
                    );
                }
            } else {
                if ($sheetRec['fup']) {
                    // MSISDN is not present in the database -> insert when FUP flag is active
                    $statsInsert++;
                    $ip = getNewIp($ips, $sheetRec['msisdn']);
                    $database->query('INSERT INTO mob_db', [
                        'uid' => $sheetRec['uid'],
                        'msisdn' => $sheetRec['msisdn'],
                        'fup' => $sheetRec['fup'],
                        'ip' => $ip,
                        'tmpid' => ip2long($ip)
                    ]);
                }
            }
        }

        // $ips reflects MSISDNs in DB now
        // update (clear FUP flag) records missing from Google Docs but present in MySQL
        foreach ($msisdnsInGoogleDocs as $msisdn) {
            if (!array_key_exists($msisdn, $ips)) {
                // MSISDN is in DB but not in Google Docs
                $statsDelete++;
                $database->query('UPDATE mob_db SET fup = ?, WHERE msisdn = ?', '', $msisdn);
            }
        }

        $database->commit();
        $database->query('UNLOCK TABLES');

        print "Done, existing=$statsUpdate inserted=$statsInsert deleted=$statsDelete\n";
        $log->debug("Updating database done, existing=$statsUpdate inserted=$statsInsert deleted=$statsDelete");

    }

} else {
    $log->info('Sheet not updated since last run');
    print "Sheet not updated since last run.\n";
}

$log->info('ApnSync finished');
