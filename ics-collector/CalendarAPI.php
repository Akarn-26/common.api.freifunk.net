<?php
require_once 'lib/class.iCalReader.php';
/*
 * Supported HTTP methods 
 */
$supportedMethods = ['GET'];
/*
 * Mandatory parameters fields
 */
$mandatory = ['source'];
/**
 * If not specified, the parameters will take these default values
 */
$defaultValues = array
(
	'format' => 'json',
);
/**
 * Ics properties of a VEVENT that will be converted & included in json result (if exist)
 * $icsKey => [$jsonKey, $include]
 * $icsKey : ics property name
 * $jsonKey : json key name (null <=> copy ics property name as json key, lower case)
 * $include : boolean indicating that if the field should be included by default in the result
 */
$jsonEventFields = array
(
	'DTSTART' => ['start', true],
	'DTEND' => ['end', true],
	'SUMMARY' => [null, true],
	'DESCRIPTION' => [null, true],
	'DTSTAMP' => ['stamp', false],
	'CREATED' => [null, false],
	'LAST_MODIFIED' => [null, false],
	'LOCATION' => [null, true],
	'GEO' => ['geolocation', false]
);
foreach ($jsonEventFields as $key => &$value) {
	if ($value[0] === null) {
		$value[0] = strtolower($key);
	}
}
/**
 * Supported sets of values for some parameters
 */
$supportedValues = array
(
	'format' => ['ics', 'json'],
	'fields' => array_map(function($v) { return $v[0]; }, $jsonEventFields)
);
/**
 * Supported formats (in regexp) for some parameters
 */
$supportedFormat = array 
(
	'from' => "/^[1-2][0-9]{3}-[0-1][0-9]-[0-3][0-9]$/", // date format, e.g. 1997-12-31 
	'to' => "/^[1-2][0-9]{3}-[0-1][0-9]-[0-3][0-9]$/"
);
/**
 * Request validations
 */
// Check HTTP method
$httpMethod = $_SERVER['REQUEST_METHOD'];
if (!in_array($httpMethod, $supportedMethods)) {
	throwAPIError('Unsupported HTTP method : ' . $httpMethod);
}
$parameters = getRequestParameters($httpMethod);
// Check required parameters
foreach ($mandatory as $value) {
	if (!array_key_exists($value, $parameters)) {
		throwAPIError('Missing required parameter : ' . $value);
	}
}

// Check parameters with limited support values
foreach ($supportedValues as $key => $value) {
	if (array_key_exists($key, $parameters)) {
		if (!in_array($parameters[$key], $value)) {
			throwAPIError('Value not supported for parameter \'' . $key . '\' : ' . $parameters[$key]);
		}
	}
}
// Check parameter with constrained format
foreach ($supportedFormat as $key => $value) {
	if (array_key_exists($key, $parameters)) {
		if (!preg_match($value, $parameters[$key])) {
			throwAPIError('Format not supported for parameter \'' . $key . '\' : ' . $parameters[$key]);
		}
	}
}
/*
 * Prepare/convert parameter options
 */
// Assign default values for some unspecified parameters
foreach ($defaultValues as $key => $value) {
	if (!array_key_exists($key, $parameters)) {
		$parameters[$key] = $value;
	}
}
// source can have multiple values, separated by comma
$sources = explode(',', $parameters['source']);
/*
 * Constructing response
 */
$parsedIcs = new Ical('data/ffMerged.ics');
foreach ($parsedIcs->cal['VEVENT'] as $key => $value) {
	// this filter is to skip all events that don't match the criteria
    // and shouldn't be added to the output result
	if (!in_array('all', $sources)) {
		if (!array_key_exists('X-WR-SOURCE', $value) || !in_array($value['X-WR-SOURCE'], $sources)) {
			unset($parsedIcs->cal['VEVENT'][$key]);
			continue;
		}
	}
	if (array_key_exists('from', $parameters)) {
		$from = new DateTime($parameters['from']);
		$eventStart = new DateTime($value['DTSTART']['value']);
		if ($eventStart < $from) {
			unset($parsedIcs->cal['VEVENT'][$key]);
			continue;
		}
	}
	if (array_key_exists('to', $parameters)) {
		$to = new DateTime($parameters['to']);
		$eventStart = new DateTime($value['DTSTART']['value']);
		if ($eventStart > $to) {
			unset($parsedIcs->cal['VEVENT'][$key]);
			continue;
		}
	}
}

if ($parameters['format'] == 'json') {
	header('Content-type: application/json; charset=UTF-8');
	$jsonResult = array();
	foreach ($parsedIcs->cal['VEVENT'] as $key => $value) {
		$event = array();
		foreach ($value as $propertyKey => $propertyValue) {
			if (isRequiredField($propertyKey, $jsonEventFields)) {
				$event[$jsonEventFields[$propertyKey][0]] = is_array($propertyValue) ? $propertyValue['value'] : $propertyValue;
			}
		}
		$jsonResult[$key] = $event;
	}
	if (count($jsonResult) === 0) {
		throwAPIError('No result found');
	}
	echo json_encode($jsonResult);
} else {
	header('Content-type: text/ics; charset=UTF-8');
	var_dump($parsedIcs);
}

function throwAPIError($errorMsg) {
	echo '{ "error": "' . $errorMsg . '"}';
	die;
}

function getRequestParameters($httpMethod) {
	return $httpMethod === 'GET' ? $_GET :
           ($httpMethod === 'POST' ? $_POST :
           null);
}

function isRequiredField($propertyKey, $jsonEventFields) {
	return array_key_exists($propertyKey, $jsonEventFields) && isDefaultJSONField($propertyKey, $jsonEventFields);
}

function isDefaultJSONField($icsKey, $jsonEventFields) {
	return $jsonEventFields[$icsKey][1];
}
