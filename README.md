# HKFree ApnSync

A tool syncing APN server configuration (MSISDNs allowed and their IPs) from Google Docs to APN's MySQL.

The `apnsync.php` script is called (via HTTP) from Google Docs automatically after the sheet is edited. 
The script runs on the APN server and updates the local MySQL database according to the data fetched from Google Docs.

## Installation

PHP 5.6 or newer is required. `curl` and `mysqli` PHP extensions are required.

Create Service Account in Google Cloud https://cloud.google.com/iam/docs/service-accounts and download the key (service-account.json). Grant sheet's read permissions to the Service Account's email in Google Sheets. 

```bash
git clone https://github.com/HKFree/ApnSync.git
cd ApnSync
composer install
cp private/config.example.php private/config.php  
vi private/config.php
cp somewhere/service-account.json private/
```
 


