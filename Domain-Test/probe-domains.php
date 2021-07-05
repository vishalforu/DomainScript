<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);


//$input_file="whitelist_input.csv";
//$output_file="whitelist_output.csv";


if (in_array('--help', $argv) or in_array('-h', $argv)) {
	echo "Commands:
--in             File path for input csv
--out            File path for output csv
-v or --verbose  Extra information in console
-d or --debug    Debug detail in console
--redo-ok        If output file has good results, they will be skipped. Force a retry with this switch
--redo-bad       If output file has bad DNS results, they will be skipped. Force a retry with this switch
-h or --help     Show this message\n\n";
	exit(0);
}

$i = @array_search('--in', $argv);
if ($i !== false and $i + 1 < sizeof($argv)) {
	$input_file = $argv[$i+1];
} else{
	die("Specify input file with --in\n");
}
if (!is_file($input_file)) die("$input_file not found\n");
if (!is_readable($input_file)) die("$input_file is not readable\n");

$i = @array_search('--out', $argv);
if ($i !== false and $i + 1 < sizeof($argv)) {
	$output_file = $argv[$i+1];
} else{
	die("Specify output file with --out\n");
}

$verbose = (in_array('-v', $argv) or in_array('--verbose', $argv));
$debug = (in_array('-d', $argv) or in_array('--debug', $argv));
$redo_ok = in_array('--redo-ok', $argv);
$redo_bad = in_array('--redo-bad', $argv);

$sets = array();
$master_stats = array();
$set_size = 20;
$max_loops = 2;

$good_domains = array();
$bad_dns = array();
if (is_file($output_file)) {
	// read in existing data
	$in = fopen($output_file, 'r');
	while (($line = fgetcsv($in)) !== false) {
		if ($line[0] == 'Original Domain') continue;
		if (!$redo_ok and $line[3] == 200) {
			// time, final, code, code-text
			$good_domains[$line[0]] = [$line[1], $line[2], $line[3], $line[4]];
		}
		elseif (!$redo_bad and $line[3] == 0) {
			$bad_dns[$line[0]] = [$line[1], $line[2], $line[3], $line[4]];
		}
	}
	if ($good_domains) {
		$master_stats[200] = sizeof($good_domains);
	}
	if ($bad_dns) {
		$master_stats[0] = sizeof($bad_dns);
	}
}

// load manually-checked domains for testing
//$known_good = array();
//$known = fopen('./known.csv', 'r');
//$set = array();
//while (($line = fgetcsv($known)) !== false) {
//	$known_good[$line[0]] = $line[1];
//}
//fclose($known);

// divide bad or unfinished domains into sets of limited amount
$in = fopen($input_file, 'r');
$set = array();
while (($line = fgetcsv($in)) !== false) {
	if (array_key_exists($line[0], $good_domains)) continue;
	if (array_key_exists($line[0], $bad_dns)) continue;
	$set[] = $line[0];
	if (sizeof($set) == $set_size) {
		$sets[] = $set;
		$set = array();
	}
}
if ($set) $sets[] = $set;
$total = (sizeof($sets) - 1) * $set_size + sizeof(end($sets));
fclose($in);
if ($verbose) {
	if (!$redo_bad) echo "bad dns:         " . sizeof($bad_dns) . "\n";
	if (!$redo_ok) echo "good domains:    " . sizeof($good_domains) . "\n";
	echo "pending domains: $total\n";
}

// start output file fresh
$out = fopen($output_file, 'w');
fputcsv($out, array(
	'Original Domain',
	'Time',
	'Final Domain',
	'HTTP Code',
	'Response'
));
// write known results into output
foreach ($good_domains as $domain => $result) {
	fputcsv($out, array(
		$domain,
		$result[0], $result[1], $result[2], $result[3]
	));
}
foreach ($bad_dns as $domain => $result) {
	fputcsv($out, array(
		$domain,
		$result[0], $result[1], $result[2], $result[3]
	));
}
fclose($out);

// work
$start = time();
foreach ($sets as $set_index => $set) {
	$t_init = time();
	$output = array();
	if ($verbose) echo "Set $set_index: " . date('r') . "\n";
	if ($debug) echo join(', ', $set) . "\n";
	$stats = probe($set, $output_file);
	if ($verbose) echo "  time: " . (time() - $t_init) . " seconds\n";

	arsort($stats);
	foreach ($stats as $key => $value) {
		if (!array_key_exists($key, $master_stats)) $master_stats[$key] = 0;
		$master_stats[$key] += $value;
	}

	arsort($master_stats);
	$log = array();
	foreach ($master_stats as $key => $value) {
		$log[] = "$key: $value";
	}
	if ($verbose) {
		if ($verbose) echo '  ' . join("\t", $log) . "\n";
	}
}
echo 'Total time: ' . number_format(time() - $start) . " seconds\n";

arsort($master_stats);
echo "Changed domains:\t" . number_format($master_stats['changed']) . "\n";
unset($master_stats['changed']);
if (array_key_exists(0, $master_stats)) {
	echo "No server response:\t" . number_format($master_stats[0]) . "\n";
	unset($master_stats[0]);
}
foreach ($master_stats as $status => $count) {
	echo "HTTP Status $status:\t" . number_format($count) . "\n";
}

function probe($domains, $output_file) {
	global $verbose;
	global $debug;
	global $max_loops;
	global $known_good;

	// prepare
	$curls = array();
	$stats = array('changed' => 0);
	$mh = curl_multi_init();
	curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 25);
	//curl_multi_setopt($mh, CURLMOPT_MAX_TOTAL_CONNECTIONS, 15);
	$out = fopen($output_file, 'a');

	// create multiple curl handles
	foreach ($domains as $domain) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		curl_setopt($ch, CURLOPT_URL, "http://$domain");
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			// 'accept-encoding: gzip, deflate, br',
			'accept-language: en-US,en;q=0.9',
			'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/72.0.3626.109 Safari/537.36'
		));

		$curls[] = $ch;
		curl_multi_add_handle($mh, end($curls));
	}

	// run curls
	$active = null;
	do {
		$mrc = curl_multi_exec($mh, $active);
	} while ($mrc == CURLM_CALL_MULTI_PERFORM);
	while ($active && $mrc == CURLM_OK) {
		if (curl_multi_select($mh) != -1) {
			do {
				$mrc = curl_multi_exec($mh, $active);
			} while ($mrc == CURLM_CALL_MULTI_PERFORM);
		}
	}

	// process results
	foreach ($curls as $i => $ch) {
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$content = curl_multi_getcontent($ch);
		if ($debug) echo "  $http_code: " . $domains[$i] . ' -> ' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) . ' -> ' . clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)) . "\n";

		// process redirects from META or redirects to dead domains
		$loop = 1;
		$previous_domain = '';
		while (
			(
				preg_match('/^\d+\.\d+\.\d+\.\d+$/', clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL))) and
				($match = array(null, 'www.' . clean_up_domain($domains[$i]))) and
				($logic_rule = 1)
			) or
			(
				preg_match('/refresh.*content="\d+.*url=(https?:\/\/.*)\/.*"/miU', $content, $match) and
				clean_up_domain($domains[$i]) != clean_up_domain($match[1]) and
				($logic_rule = 2)
			) or
			(
				in_array($http_code, array(301, 302, 303)) and
				preg_match('`^http://.*/wp-signup\.php\?new=(.*)$`', curl_getinfo($ch, CURLINFO_EFFECTIVE_URL), $match) and
				($match[1] = 'www.' . clean_up_domain($match[1])) and
				($logic_rule = 3)
			) or
			(
				in_array($http_code, array(301, 302, 303)) and
				clean_up_domain($domains[$i]) != clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)) and
				($match = array(null, curl_getinfo($ch, CURLINFO_EFFECTIVE_URL))) and
				($logic_rule = 4)
			) or
			(
				$http_code != 200 and
				clean_up_domain($domains[$i]) == clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)) and
				($match = array(null, 'www.' . clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)))) and
				($logic_rule = 5)
			) or
			(
				preg_match('/<TITLE> Server Down for Maintenance <\/TITLE>/miU', $content, $match) and
				($match = array(null, 'www.' . clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)))) and
				($logic_rule = 6)
			) or
			(
				$http_code == 200 and
				preg_match('`coolRedirect\("(.*)"\)`iU', $content, $match) and
				($match[1] = 'www.' . clean_up_domain($match[1])) and
				($logic_rule = 7)
			)
		) {
			$match[1] = urldecode($match[1]);
			if ($debug) echo "    from $logic_rule, try $match[1]...";
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($ch, CURLOPT_DNS_CACHE_TIMEOUT, 3);
			curl_setopt($ch, CURLOPT_TIMEOUT, 10);
			curl_setopt($ch, CURLOPT_URL, $match[1]);
			curl_setopt($ch, CURLOPT_HEADER, true);
			curl_setopt($ch, CURLOPT_AUTOREFERER, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array(
				'accept-encoding: gzip, deflate, br',
				'accept-language: en-US,en;q=0.9',
				'User-Agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/72.0.3626.109 Safari/537.36'
			));

			$content = curl_exec($ch);
			$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			if ($debug) echo " $http_code\n";

			if (in_array($http_code, array(301, 302, 303)) and (
					$previous_domain == clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)) or
					clean_up_domain($domains[$i]) == clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL))
				)
			) {
				$http_code = 200;
				break;
			}

			if ($loop == $max_loops) {
				break;
			}

			$loop++;
			$previous_domain = clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL));
		}

		$line = array(
			clean_up_domain($domains[$i]),
			date('c'),
			clean_up_domain(curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)),
			$http_code,
			_statustext($http_code)
		);

		// override for misconfigured servers or unusual redirection methods
		if ($domains[$i] == 'tpgnc.com') {
			$line[2] = 'theperformancegroup.net';
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}
		elseif ($domains[$i] == 'vha.com') {
			$line[2] = 'vizientinc.com';
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}
		elseif ($domains[$i] == 'xata.com') {
			$line[2] = 'omnitracs.com';
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}
		elseif ($domains[$i] == 'phoenixwm.com') {
			$line[2] = 'nsre.com';
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}
		elseif ($domains[$i] == 'sbliusa.com') {
			$line[2] = 'prosperitylife.com';
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}
		elseif ($domains[$i] == 'scif.com') {
			$line[2] = 'statefundca.com';
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}
		elseif ($domains[$i] == 'stvincenthealth.com') {
			$line[2] = 'chistvincent.com';
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}
		elseif (in_array($domains[$i], array(
			'southhooklng.com',
			'axelon.com',
			'imcg.com',
			'transelec.com'
		))) {
			$line[2] = $domains[$i];
			$line[3] = '200';
			$line[4] = 'OK';
			$http_code = 200;
		}

		if (!array_key_exists($http_code, $stats)) {
			$stats[$http_code] = 0;
		}
		$stats[$http_code]++;

		if ($http_code == 0) {
			$dns_result = @dns_get_record($domains[$i], DNS_A);
			if ($dns_result === false) {
				$line[2] = '';
				$line[3] = 'DNS';
				$line[4] = 'DNS lookup failed';
			} else {
				$line[2] = '';
				$line[3] = 'NONE';
				$line[4] = 'Server did not respond';
			}
		}

		if ($line[0] != $line[2]) {
			$stats['changed']++;
		}

		if (array_key_exists($line[0], $known_good) and $line[2] != $known_good[$line[0]]) {
			echo "for $line[0], $line[2] does not match known-good result\n";
			echo "  $line[2] != " . $known_good[$line[0]] . "\n";
			fclose($out);
			exit(0);
		}

		fputcsv($out, $line);

		curl_multi_remove_handle($mh, $curls[$i]);
		curl_close($curls[$i]);
	}

	fclose($out);

	return $stats;
}

function clean_up_domain($domain, $keep_www = false) {
	$output = stripslashes(trim(strtolower($domain)));

	$output = preg_replace('/^https?:\/\//', '', $output);
	if (!$keep_www) $output = preg_replace('/^w+\d*\./', '', $output);
	$output = preg_replace('/^([^\/]*)\/.*/', '$1', $output);
	$output = preg_replace('/:\d+/', '', $output);

	return $output;
}

function _statustext($code = 0) {
	// https://gist.github.com/danmatthews/1379769

	// List of HTTP status codes.
	$statuslist = array(
		'100' => 'Continue',
		'101' => 'Switching Protocols',
		'200' => 'OK',
		'201' => 'Created',
		'202' => 'Accepted',
		'203' => 'Non-Authoritative Information',
		'204' => 'No Content',
		'205' => 'Reset Content',
		'206' => 'Partial Content',
		'300' => 'Multiple Choices',
		'301' => 'Moved Permanently',
		'302' => 'Found',
		'303' => 'See Other',
		'304' => 'Not Modified',
		'305' => 'Use Proxy',
		'400' => 'Bad Request',
		'401' => 'Unauthorized',
		'402' => 'Payment Required',
		'403' => 'Forbidden',
		'404' => 'Not Found',
		'405' => 'Method Not Allowed',
		'406' => 'Not Acceptable',
		'407' => 'Proxy Authentication Required',
		'408' => 'Request Timeout',
		'409' => 'Conflict',
		'410' => 'Gone',
		'411' => 'Length Required',
		'412' => 'Precondition Failed',
		'413' => 'Request Entity Too Large',
		'414' => 'Request-URI Too Long',
		'415' => 'Unsupported Media Type',
		'416' => 'Requested Range Not Satisfiable',
		'417' => 'Expectation Failed',
		'500' => 'Internal Server Error',
		'501' => 'Not Implemented',
		'502' => 'Bad Gateway',
		'503' => 'Service Unavailable',
		'504' => 'Gateway Timeout',
		'505' => 'HTTP Version Not Supported'
	);

	// Caste the status code to a string.
	$code = (string)$code;

	// Determine if it exists in the array.
	if(array_key_exists($code, $statuslist) ) {
		// Return the status text
		return $statuslist[$code];

	} else {
		// If it doesn't exists, degrade by returning the code.
		return $code;

	}
}

?>
