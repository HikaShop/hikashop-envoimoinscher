<?php
if(!function_exists('curl_init')) {
	throw new Exception('EnvoiMoinsCher needs the CURL PHP extension.');
}

require(dirname(__FILE__) . '/webservice.php');
require(dirname(__FILE__) . '/carrier.php');
require(dirname(__FILE__) . '/carrierslist.php');
require(dirname(__FILE__) . '/contentcategory.php');
require(dirname(__FILE__) . '/country.php');
require(dirname(__FILE__) . '/listpoints.php');
require(dirname(__FILE__) . '/orderstatus.php');
require(dirname(__FILE__) . '/parcelpoint.php');
require(dirname(__FILE__) . '/quotation.php');
require(dirname(__FILE__) . '/service.php');
require(dirname(__FILE__) . '/user.php');

