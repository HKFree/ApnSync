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


$sheetId = '1ugrIgECXTwqXbIlqE7pf6CNZb0edUrkIWuzH6EnjK_Y';
$worksheetId = 'od5';
$timestampFile = '/tmp/mob-last-timestamp';
$client_email = '356855916299-ifck40oapgsvj6ekai0kj12t9vlr2b2s@developer.gserviceaccount.com';
$private_key = file_get_contents('private/HKFree mobily-5d8b3e2a026b.p12');

function getIp($ips, $msisdn) {
    if ($ips[$msisdn]) {
	// uz ma IP
	$ip = $ips[$msisdn];
	print "MSISDN $msisdn already has IP $ip\n";
	return $ip;
    } else {
	// najit prvni volnou IP
	$firstIp = '10.253.36.10'; // 10.253.36.0/22
	$lastIp = '10.253.39.254';
	for ($i = ip2long($firstIp); $i < ip2long($lastIp); $i++) {
	    $used = false;
	    foreach ($ips as $msisdn => $ip) {
		if (ip2long($ip) == $i) {
		    $used = true;
		    break;
		}
	    }
	    if (!$used) {
		$ip = long2ip($i);
		print "MSISDN $msisdn assigned new IP $ip\n";
		return $ip;
	    }
	}
	print "MSISDN $msisdn no new IP available!\n";
	return ''; // zadna volna IP (asi by se stat nemelo, kdyz mame rezervu)
    }
}

$scopes = array('https://spreadsheets.google.com/feeds');
$credentials = new Google_Auth_AssertionCredentials(
            $client_email,
            $scopes,
            $private_key,
            $privateKeyPassword,
            'http://oauth.net/grant_type/jwt/1.0/bearer' // Default grant type
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
print "Timestamps:\n$lastTimestamp\n$timestamp\n";
if ($timestamp > $lastTimestamp || $lastTimestamp == '') {
    file_put_contents($timestampFile, $timestamp);

    $output = array();

    $worksheetFeed = $spreadsheet->getWorksheets();

    foreach ($worksheetFeed as $worksheet) {
	if ($worksheet->getId() == "https://spreadsheets.google.com/feeds/worksheets/$sheetId/private/values/$worksheetId") {
    	    foreach ($worksheet->getListFeed()->getEntries() as $entry) {
    		$values = $entry->getValues();
    		$rec = array();
		$rec['msisdn'] = trim($values['msisdn']);
		$rec['uid'] = trim($values['uid']);
		$rec['kauce'] = trim($values['kauce']);
		$rec['fup'] = trim($values['fup']);
		if (preg_match("/^[0-9]{12}$/i",$rec['msisdn']) &&
		    preg_match("/^[0-9]+$/i",$rec['uid']) &&
		    preg_match("/^[0-9]*$/i",$rec['kauce'])
		    ) {
			array_push($output, $rec);
		}
    	    }
	}
    }

    print "Valid records: ".count($output)."\n";

    if (count($output) > 100) { // "heuristic" check (error proof TM :)
	print "Re-inserting data...\n";

	$dbh = new PDO("mysql:host=db;dbname=userdb", "userdb-mobilis-w", $mysqlPassword);
	$dbh->exec("LOCK TABLES mob_db WRITE;");
	
	$sthsel = $dbh->prepare("SELECT uid, msisdn, kauce, fup, ip FROM mob_db WHERE ip IS NOT NULL");
	$sthsel->execute();
	$result = $sthsel->fetchAll();
	$ips = array();
	foreach ($result as $row) {
	    // msisdn -> ip
	    $ips[$row[1]] = $row[4];
	}
	
	var_dump($ips);
	
	
	$dbh->exec("DELETE FROM mob_db;");
	
	$sth = $dbh->prepare ("INSERT INTO mob_db (uid, msisdn, kauce, fup, ip, tmpid)
					VALUES (:uid, :msisdn, :kauce, :fup, :ip, :tmpid)");
	foreach ($output as $rec) {
	    $sth->bindValue (":uid", $rec['uid'], PDO::PARAM_INT);
	    $sth->bindValue (":msisdn", $rec['msisdn']);
	    $sth->bindValue (":kauce", $rec['kauce'], PDO::PARAM_INT);
	    $sth->bindValue (":fup", $rec['fup']);
	    if ($rec['fup']) {
		$ip = getIp($ips, $rec['msisdn']); // prirazeni existujici nebo nove IP
		$ips[$rec['msisdn']] = $ip; // pokud je IP nove prirazena, musime si ji alokovat i v asoc. poli
	    } else {
		$ip = $ips[$rec['msisdn']]; // pokud nema uz FUP (APN), porad muze mit historicky prirazenou IP, uz mu ji nechame
	    }
	    $sth->bindValue (":ip", $ip);
	    if ($ip) {
		$sth->bindValue (":tmpid", ip2long($ip), PDO::PARAM_INT);
	    } else {
		$sth->bindValue(":tmpid", null, PDO::PARAM_INT);
	    }
	    $sth->execute ();
	}

	$dbh->exec("UNLOCK TABLES;");

	print "Done.\n";
	
	syslog(LOG_INFO, "Mobily: Sync from Google Docs to userdb - ".count($output)." records re-inserted.");
	
    }
    
} else {
    print "No update since last run.\n";
}

