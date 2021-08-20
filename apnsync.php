<?php

#####
# Pozor, v tomto skriptu nemodifikovat sheet v docs, protoze jinak se to zacykli 
# (docs zavolaji tento skript pri zmene sheetu a ten ho pak zmeni a tak porad dokola!)
#####

error_reporting(E_ALL);

require 'vendor/autoload.php';
require 'private/config.php';

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\ErrorHandler;


$sheetId = '1ugrIgECXTwqXbIlqE7pf6CNZb0edUrkIWuzH6EnjK_Y';
$worksheetGid = 3; // gid=? in URL when the sheet is selected
$timestampFile = '/tmp/mob-last-timestamp';
putenv('GOOGLE_APPLICATION_CREDENTIALS=private/service-account.json');


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

$client = new Google\Client();
$client->useApplicationDefaultCredentials();
$client->setScopes([Google\Service\Sheets::SPREADSHEETS, Google\Service\Drive::DRIVE]);
$client->setAccessType('offline');

if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithAssertion();
}

$spreadsheetService = new Google\Service\Sheets($client);
$spreadsheet = $spreadsheetService->spreadsheets->get($sheetId);

$service = new Google\Service\Drive($client);

$sheetFile = $service->files->get($sheetId, array(
    'fields' => 'id, name, modifiedTime',
    // uncomment the following if you are working with team drive
    //'supportsTeamDrives' => true
));

// test timestamp update
$lastTimestamp = file_get_contents($timestampFile);
$timestamp = $sheetFile->getModifiedTime();

$log->debug("Timestamps: $lastTimestamp $timestamp");
if (!$lastTimestamp || $timestamp > $lastTimestamp) {
    file_put_contents($timestampFile, $timestamp);

    $sheetRows = array();

    $worksheetFeed = $spreadsheet->getSheets();

    foreach ($worksheetFeed as $worksheet) {
        // var_dump($worksheet->getProperties()->sheetId." ".$worksheet->getProperties()->title);
        if ($worksheet->getProperties()->sheetId == $worksheetGid) {
            $log->debug("Sheet found {$worksheet->getProperties()->title}\n");
            $response = $spreadsheetService->spreadsheets_values->get($sheetId, $worksheet->getProperties()->title);
            $values = $response->getValues(); // array of arrays
            $columnNameToIndex = array();
            foreach ($values[0] as $i => $columnName) { // column names in first row
                $columnNameToIndex[strtolower($columnName)] = $i;
            }
            for ($i = 1; $i < count($values); $i++) { // data in next rows
                $sheetRec = array();
                $sheetRec['msisdn'] = trim($values[$i][$columnNameToIndex['msisdn']]);
                $sheetRec['uid'] = trim($values[$i][$columnNameToIndex['uid']]);
                $sheetRec['fup'] = trim($values[$i][$columnNameToIndex['fup']]);
                if (preg_match("/^[0-9]{12}$/i", $sheetRec['msisdn']) && preg_match("/^[0-9]+$/i", $sheetRec['uid'])) {
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

        $result = $database->query('SELECT uid, msisdn, fup, ip FROM mob_db');
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
                $database->query('UPDATE mob_db SET fup = ? WHERE msisdn = ?', '', $msisdn);
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
