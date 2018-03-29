# HKFree ApnSync

A tool syncing APN server configuration (MSISDNs allowed and their IPs) from Google Docs to APN's MySQL.

The `apnsync.php` script is called (via HTTP) from Google Docs automatically after the sheet is edited. 
The script runs on the APN server and updates the local MySQL database according to the data fetched from Google Docs.

## Installation

```bash
git clone https://github.com/HKFree/ApnSync.git
cd ApnSync
composer install
cp private/config.example.php private/config.php  
vi private/config.php
cp "somewhere/HKFree mobily-5d8b3e2a026b.p12" private/
```
 


