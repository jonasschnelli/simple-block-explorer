<?php
############################
# CONFIG SECTION           #
############################
error_reporting(E_ALL);
ini_set('display_errors', 0);
$time_start = microtime(true); 

  $MAIN_ENDPOINT = "http://127.0.0.1:8332/";
  $TEST_ENDPOINT = "http://127.0.0.1:18332/";
  define("BASE_URL", "/explorer/"); #set to absolute URL, use "/" if in root directory
  define("USE_MOD_REWRITE", true);
	define("RPC_USER", "bitcoin");
	define("RPC_PASS", "CHANGEME");
	define("BLOCK_TXDETAILS", true); #blocks load faster without txdetails
	define("TXHISTORY_MAX_BLOCKS_PER_CALL", 15); #how many blocks to process per call loop
	
  // enable mod rewrite mode to have nice URLs like /block/<hash> instead of index.php?block=<hash>
  $FOOTER = '<a href="https://github.com/jonasschnelli/simple-block-explorer">Simple Block Explorer</a> for Bitcoin Core';
  $TITLE = "Block Explorer";
###### END CONFIG SECTION

  $HTMLTITLE = $TITLE;
  abstract class ViewType { const Overview = 0; const Block = 1; const Transaction = 2; const NotFound = 3; const NodeInfo = 4; const AddrSearch = 5; const TxHistory = 6;};
  function clean_hex($in) { return htmlentities(strip_tags(preg_replace("/[^a-fA-F0-9]/", "", $in))); }
	function clean_search($in) { return htmlentities(strip_tags(preg_replace("/[^a-zA-Z0-9]/", "", $in))); }
	function clean_num($in) { return htmlentities(strip_tags(preg_replace("/[^0-9]/", "", $in))); }
  $ENDPOINT = isset($_REQUEST['testnet']) ? $TEST_ENDPOINT : $MAIN_ENDPOINT;
  $LINKADD = isset($_REQUEST['testnet']) ? (USE_MOD_REWRITE ? "testnet/" : "?testnet=1&") : ( USE_MOD_REWRITE ? "":"?");
  $NOW = time();
  $VIEWTYPE = ViewType::Overview;
	if(isset($_REQUEST['txhistory'])) {
		// try to scan all relevant blocks for a given address (in multiple steps due to execution timeout)
		// generates a transaction list with received and spent transactions
		
		// get raw post data sent through AJAX
		$json_in = file_get_contents('php://input');
		$data = json_decode($json_in, true);
		$addr = clean_search($_REQUEST['txhistory']);
		$unspents = array();
		$received = array();
		$spent = array();
		$txlist = array();
		$txhistoryneedle = $addr;

		$processed = 0;
		if (isset($data)) {
			// TODO, sanitize txlist
			if (isset($data["unspents"])) {
				$unspents = $data["unspents"];
			}
			if (isset($data["txlist"])) {
				$txlist = $data["txlist"];
			}
			if (isset($data["remainingblocks"])) {
				$blocks = $data["remainingblocks"];
			}
		}

		// fetch the whole blocks and check for spent and received transaction
		while (count($blocks) > 0 && $processed < TXHISTORY_MAX_BLOCKS_PER_CALL) {
			// only process TXHISTORY_MAX_BLOCKS_PER_CALL per call
			
			// get the next blockhash to process
			$blockhash = array_shift($blocks);
			$blockhash = rtrim($blockhash);
			if (strlen($blockhash) != 64) { continue; }
			
			// fetch the block over RPC with transaction data
			$block = rpcFetch("getblock", '["'.chop($blockhash).'", 2]');
			foreach($block->result->tx as $tx) {
				if (count($unspents) > 0) { // only check for spent transactions if we have unspents
					foreach($tx->vin as $in) {
						if (isset($in->txid) && isset($unspents[$in->txid.$in->vout])) {
							// hit spent
							$spent = array("txid" => $tx->txid, "n" => $in->vout, "value" => $unspents[$in->txid.$in->vout], "tx" => $tx);
							array_push($txlist, array("type" => "out", "time" => $block->result->mediantime, "addr" => $addr, "txid" => $tx->txid, "n" => $in->vout, "value" => $unspents[$in->txid.$in->vout], "tx" => ""));
							unset($unspents[$in->txid.$in->vout]);
						}
					}
				}
				foreach($tx->vout as $out) {
					if (isset($out->scriptPubKey->address) && $out->scriptPubKey->address == $addr) {
						// hit received
						$received = array("txid" => $tx->txid, "n" => $out->n, "value" => $out->value, "tx" => $tx);
						array_push($txlist, array("type" => "in", "time" => $block->result->mediantime, "addr" => $out->scriptPubKey->address, "txid" => $tx->txid, "n" => $out->n, "value" => $out->value, "tx" => ""));
						
						// store the value and txid in the unspent array
						$unspents[$tx->txid.$out->n] = $out->value;
					}
				}
			}
			$processed++;
		}

		// calculate the total unspent balance
		$txhistorybalance = 0;
		foreach($unspents as $k => $v) {
			$txhistorybalance += $v;
		}
		
		// construct data set for hits
		$json_txlist = json_encode(array("totalblocks" => $data['totalblocks'], "remainingcount" => count($blocks), "remainingblocks" => $blocks, "txlist" => $txlist, "unspents" => $unspents));
		$remainingblocks = count($blocks);
		$VIEWTYPE = ViewType::TxHistory;
		
		if (count($blocks) == 0) {
			// no more blocks, return html list
			
			echo "<h2>Transaction History (only confirmed transactions)</h2>\n";
			echo "<div>Balance: ".btcamount($txhistorybalance)."</div>\n";
      echo '<ul class="list-group list-numbered my-2">'."\n";
			foreach($txlist as $tx) {
        echo '<li class="list-group-item d-flex justify-content-between lh-condensed">';
				echo '	<div class="text-truncate">';
				echo date("Y-m-d H:i:s", $tx['time']); echo '&nbsp;<span class="badge bg-';
				
				if ($tx['type'] == "in") { echo "success "; } else { echo "warning "; }
				echo '">';
				if ($tx['type'] == "in") { echo "received "; } else { echo "spent "; }
				echo '</span> ';
				echo $tx['txid'];
				echo '	</div>';
				echo '	<div><strong>'.btcamount($tx['value']).'</strong></div>';
				echo '</li>';
			}
			echo "</ul>\n";
			
			die();
		}
		echo $json_txlist; die();
	}
  if(isset($_REQUEST['search'])) {
    $search = clean_search($_REQUEST['search']);
		//check if it is an address
		if(preg_match("/^(bc1|BC1|[13mt])[a-zA-HJ-NP-Z0-9]{25,75}$/", $search)) {
			$ADDRESSSEARCH = $search;
			if (isset($_REQUEST['utxolookupstatus'])) {
				// check if scan active (AJAX)
				$data = rpcFetch("scantxoutset", '["status"]');
				if(!is_null($data->result)) {
					echo $data->result->progress;
				}
				die(); //end the programm as we want an AJAX response
			}
			else if (isset($_REQUEST['utxolookup'])) {
				// check if scan active
				$data = rpcFetch("scantxoutset", '["status"]');
				if(is_null($data->result)) {
					$data = rpcFetch("scantxoutset", '["start", ["addr('.$search.')"]]');
					if(!is_null($data->result)) {
						// ajax response for the scantxoutset report
						echo "<hr /><div class=\"totalamount\"><b>Total unspent BTC:</b> ".$data->result->total_amount."</div><br>\n";
						echo '<h4>UTXOs (unspent transaction outputs)</h4>'."\n";
						echo '<table class="table table-bordered table-hover table-striped utxos">'."\n";
						echo "<tr><th>Amount</th><th>TXID</th><th>Vout</th></tr>\n";
						foreach($data->result->unspents as $utxo) {
							echo "<tr>\n";
							echo "<td>".btcamount($utxo->amount)."</td>\n";
							echo "<td><a href=\"".txlink($utxo->txid, $utxo->vout)."\">".$utxo->txid."</a></td>\n";
							echo "<td>".$utxo->vout."</td>\n";
							echo "</tr>\n";
						}
						echo "</table>\n";
					}
				} else {
					echo "A scan is currently running (done ".$data->result->progress."%). Please try again later. No concurrent scans are allowed.";
				}
				die();				
			}
			else if (isset($_REQUEST['scanfiltersstatus'])) {
				// check if scan active
				$data = rpcFetch("scanblocks", '["status"]');
				if(!is_null($data->result)) {
					echo $data->result->progress;
				}
				die();				
			}
			else if (isset($_REQUEST['scanfilters'])) {
				// check if scan active
				$data = rpcFetch("scanblocks", '["status"]');
				if(is_null($data->result)) {
					// no scan present
					$data = rpcFetch("scanblocks", '["start", ["addr('.$search.')"], '.(isset($_REQUEST['startheight']) ? clean_num($_REQUEST['startheight']) : 0 ).']');
					if(!is_null($data->result)) {
						// ajax report for the blockfilter scan result
						$textblock = "{\"totalblocks\":".count($data->result->relevant_blocks).", \"remainingblocks\":[";
						foreach($data->result->relevant_blocks as $block) {
							$textblock .= "\"".$block."\",\n";
						}
						$textblock = rtrim($textblock, ",\n");
						$textblock .= "]}";
						// build the HTML response for the blockfilter scan results including a form to build the transaction history
						echo "<hr />
							Found ".count($data->result->relevant_blocks)." relevant blocks <a href=\"#\" data-bs-toggle=\"collapse\" data-bs-target=\"#collapseRelevantBlocks\" aria-expanded=\"false\" aria-controls=\"collapseRelevantBlocks\">(Show details)</a><br />
						<!-- <form action=\"".BASE_URL."?txhistory=".urlencode($search)."\" method=\"post\"> -->
							<textarea id=\"relevantblocks\" name=\"relevantblocks\" style=\"display:none;\">".$textblock."</textarea>
						  <button type=\"button\" id=\"buildtxhistory\" class=\"btn btn-primary\">Build transaction history (may take a while)</button>
						<!-- </form> -->
							<div class=\"collapse\" id=\"collapseRelevantBlocks\">
							  <div class=\"\">
								<br />
								<div class=\"scanfilterinfo\">Relevant blocks from height <b>".$data->result->from_height."</b> to height <b>".$data->result->to_height."</b></div><br>
								<h4>Relevant blocks (".count($data->result->relevant_blocks)." relevant blocks)</h4>
								<table class=\"table table-bordered table-hover table-striped relevantblocks\">
								<tr><th>Blockhash</th></tr>
								";
						foreach($data->result->relevant_blocks as $block) {
							echo "<tr>\n";
							echo "<td><a href=\"".blocklink($block)."\">".$block."</a></td>\n";
							echo "</tr>\n";
						}
						echo "</table>\n</div>\n</div>";
					}
				} else {
					echo "A scan is currently running (done ".$data->result->progress."%). Please try again later. No concurrent scans are allowed.";
				}
				die();				
			}

			$VIEWTYPE = ViewType::AddrSearch;
		}
		else {
			// not an address
	    if (is_numeric($search)) {
	      $blockhash = getBlockHashByHeight($search);
	      if (strlen($blockhash) == 64) { $search = $blockhash; }
	    }
			if (BLOCK_TXDETAILS) {
				$_REQUEST['txdetails'] = 1;
			}
	    $block = getBlockJSON($search, true, isset($_REQUEST['txdetails']));
	    $VIEWTYPE = ViewType::Block;
	    if (isset($block->height)) { $HTMLTITLE = $TITLE.", height ".$block->height; }
	    if (!$block) {
	      $tx = getTxJSON($search);
	      if (!$tx) {
	        $VIEWTYPE = ViewType::NotFound;
	      }
				else {
					if(!isset($tx->confirmations)) { 
						$tx->confirmations = 0;
						// check mempool
						$data = rpcFetch("getmempoolentry", '["'.$tx->txid.'"]')->result;
						$tx->mempool = $data;
						
						if(isset($_REQUEST['mempooltxdetails'])) {
							$cnt = 0;
							$total_in = 0;
							foreach($tx->vin as $txin) {
								$rawtx = rpcFetch("getrawtransaction", '["'.$txin->txid.'", 3]');
								$tx->vin[$cnt]->prevout = $rawtx->result->vout[$txin->vout];
								$total_in += $tx->vin[$cnt]->prevout->value;
								$cnt++;
							}
							$tx->total_in = $total_in;
						}
					}
		      $VIEWTYPE = ViewType::Transaction;
				}
	    }
		}
  } else if(isset($_REQUEST['block'])) {
		if (BLOCK_TXDETAILS) {
			$_REQUEST['txdetails'] = 1;
		}
    $VIEWTYPE = ViewType::Block;
    $block = getBlockJSON(clean_hex($_REQUEST['block']), true, isset($_REQUEST['txdetails']));
    if (isset($block->height)) { $HTMLTITLE = $TITLE.", height ".$block->height; }
  } else if(isset($_REQUEST['tx'])) {
    $VIEWTYPE = ViewType::Transaction;
    $tx = getTxJSON(clean_hex($_REQUEST['tx']));
  } else if(isset($_REQUEST['nodeinfo'])) {
    $VIEWTYPE = ViewType::NodeInfo;
  }
  if ($VIEWTYPE == ViewType::Overview) {
		$data = rpcFetch("getblockchaininfo")->result;
    $blocks = $data->blocks;
    $bestblockhash = $data->bestblockhash;

    $lastblocks = getLastBlocks($bestblockhash);
  }
  function getLastBlocks($tophash, $blk_count = 12) {
    global $NOW;
    $lastblocks = array();
    $hash = $tophash;
    for ($i=0;$i<$blk_count;$i++) {
      array_push($lastblocks, getBlockJSON($hash));
      $hash = $lastblocks[count($lastblocks)-1]->previousblockhash;
      $age_in_s = $NOW - $lastblocks[count($lastblocks)-1]->time;
      if ($age_in_s > 3600) {
        $lastblocks[count($lastblocks)-1]->age = gmdate("G", $age_in_s)." hours, ".gmdate("i", $age_in_s)." min";
      }
      else if ($age_in_s > 60) {
        $lastblocks[count($lastblocks)-1]->age = gmdate("i", $age_in_s)." mins, ".gmdate("s", $age_in_s)." secs";
      }
      else {
        $lastblocks[count($lastblocks)-1]->age = "New block";
      }
    }
    return $lastblocks;
  }
	function rpcFetch($method, $json_params = "[]") {
		global $ENDPOINT;
		$opts = array('http' =>
		    array(
		        'method'  => 'POST',
		        'header'  => 'Content-type: text/plain',
		        'content' => '{"jsonrpc":"1.0","method":"'.$method.'","params":'.$json_params.'}'
		    )
		);
		$context = stream_context_create($opts);
		$url = $ENDPOINT;
		$url = str_replace("://", "://".RPC_USER.":".RPC_PASS."@", $url);
		$response = file_get_contents($url,  false, $context);
		return json_decode($response);
	}
  function getUTXOJSON($hash, $n) {
		$gettxout = rpcFetch("gettxout", '["'.$hash.'", '.$n.']')->result;
		return $gettxout;
  }
  function getBlockHeader($hash) {
		$block = rpcFetch("getblockheader", '["'.$hash.'"]');
		if (isset($block->result)) {
			$block = $block->result;
		}
    if(!$block) {
      return;
    }
    return $block;
  }
  function getBlockHashByHeight($height) {
    # requires Core 0.18
		$blockhash = rpcFetch("getblockhash", '['.$height.']')->result;
    return $blockhash;
  }
  function getBlockJSON($hash, $stats = false, $withtxdetails = false) {
		$block = rpcFetch("getblock", '["'.$hash.'", '.($withtxdetails ? "2" : "1").']');
		if (isset($block->result)) {
			$block = $block->result;
		}
    if (!$block) {
      $block = getBlockHeader($hash);
    }
		if ($stats) {
			$stats = rpcFetch("getblockstats", '["'.$hash.'"]');
			if (isset($stats->result)) {
				$block->stats = $stats->result;
			}
		}
    return $block;
  }
  function getTxJSON($hash) {
    global $VIEWTYPE;
		$rawtx = rpcFetch("getrawtransaction", '["'.$hash.'", 3]');
		if(isset($rawtx->result)) {
			$json = $rawtx->result;
		}
    if (!isset($json)) { $VIEWTYPE = ViewType::NotFound; return; }
    if (isset($json->blockhash)) { $json->blockheader = getBlockHeader($json->blockhash); }
    $missing_prevout = false;
    if (!$missing_prevout) {
      $total_in = 0;
      foreach($json->vin as &$vin) {
        if (isset($vin->prevout)) {
          $total_in += $vin->prevout->value;
        }
      }
      $json->total_in = $total_in;
    }
    $total_out = 0;
    foreach($json->vout as &$vout) {
      $total_out += $vout->value;
      $utxo = getUTXOJSON($json->txid, $vout->n);
      if (is_object($utxo)) {
        $vout->is_unspent = true;
      }
    }
    $json->total_out = $total_out;
    if (!$missing_prevout) { $json->fee = round($total_in-$total_out, 8); }
    if ($total_in == 0) {
      $json->fee = 0;
    }
    return $json;
  }
  function txlink($hash, $vout="") {
    global $LINKADD;
    if (USE_MOD_REWRITE) { return BASE_URL.$LINKADD."tx/".$hash.(strlen($vout) > 0 ? "/n/".$vout : ""); }
    return "index.php".$LINKADD."tx=".$hash.(strlen($vout) > 0 ? "&n=".$vout : "");
  }
  function blocklink($hash, $txid="") {
    global $LINKADD;
    if (strlen($txid) > 0) {
      if (USE_MOD_REWRITE) { return BASE_URL.$LINKADD."block/".$hash."/txid/".$txid; }
      return BASE_URL."index.php".$LINKADD."block=".$hash."&txid=".$txid;
    }
    if (USE_MOD_REWRITE) { return BASE_URL.$LINKADD."block/".$hash; }
    return BASE_URL."index.php".$LINKADD."block=".$hash;
  }
  function addresslink($addr) {
    global $LINKADD;
    if (strlen($addr) > 0) {
      if (USE_MOD_REWRITE) { return BASE_URL.$LINKADD."?&search=".$addr; }
      return BASE_URL."index.php".$LINKADD."&search=".$addr;
    }
  }
  function overviewlink($testnet=0) {
    global $LINKADD;
    if ($testnet) {
      if (USE_MOD_REWRITE) { return BASE_URL."testnet/"; }
      return BASE_URL."index.php".$LINKADD."testnet=1";
    }
    return BASE_URL;
  }
  function nodeInfolink() {
    global $LINKADD;
    if (USE_MOD_REWRITE) { return BASE_URL.$LINKADD."nodeinfo"; }
    return BASE_URL."index.php".$LINKADD."nodeinfo=1";
  }
  function homelink() {
    global $LINKADD;
    if (USE_MOD_REWRITE) { return BASE_URL; }
    return BASE_URL."index.php";
  }
  function btcamount($amount) {
    return number_format($amount, 8, ".", "'")." BTC";
  }
	function shortHash($hash) {
		if(strlen($hash) < 12) {
			return $hash;
		}
		return substr($hash, 0, 6)."...".substr($hash, strlen($hash)-12, 12);
	}
	function shortAddr($addr) {
		if (strlen($addr) <= 36) return $addr;
		return substr($addr, 0, 25)."...".substr($addr, strlen($addr)-8, 8);
	}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title><?php echo $HTMLTITLE; ?></title>
  <meta name="author" content="">
  <meta name="description" content="">
<!-- <?php if($VIEWTYPE == ViewType::Overview): ?>  <meta http-equiv="refresh" content="60"><?php endif;?> -->

<!-- IE Edge Meta Tag -->
<meta http-equiv="X-UA-Compatible" content="IE=edge">

<!-- Viewport -->
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

<!--<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">-->

<style>
tr.highlight {background-color: powderblue;}

.masthead {
  margin-bottom: 10rem;
  padding-bottom: 2rem;
}

.masthead-brand {
  margin-bottom: 0;
  font-size:3rem;
  font-family: 'Helvetica Neue Light', 'HelveticaNeue-UltraLight', 'Helvetica Neue UltraLight', 'Helvetica Neue', Arial, Helvetica, sans-serif;
}

.nav-masthead .nav-link {
  padding: .25rem 0;
  font-weight: 700;
  color: rgba(40,40,40, .5);
  background-color: transparent;
  border-bottom: .25rem solid transparent;
}

.nav-masthead .nav-link:hover,
.nav-masthead .nav-link:focus {
  border-bottom-color: rgba(0,0,0, .25);
}

.nav-masthead .nav-link + .nav-link {
  margin-left: 1rem;
}

.nav-masthead .active {
  color: #111;
  border-bottom-color: #111;
}

@media (min-width: 48em) {
  .masthead-brand {
    float: left;
  }
  .nav-masthead {
    float: right;
  }
}

.list-group-item.active a {
  color: white;
}

.small_vin {
	font-size: 65%;
}

.innercontent {
	font-size: 90%;
}

</style>
</head>
<body class="bg-light">
	<nav class="navbar bg-dark shadow navbar-expand-lg sticky-top bg-body-tertiary" data-bs-theme="dark">
	  <div class="container-fluid">
				<div id="logo">
				<a class="navbar-brand" href="<?php echo homelink(); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="currentColor" class="bitcoinlogo me-2" viewBox="0 0 64 64">
						<g transform="translate(0.00630876,-0.00301984)">
						<path fill="#FFF" d="m63.033,39.744c-4.274,17.143-21.637,27.576-38.782,23.301-17.138-4.274-27.571-21.638-23.295-38.78,4.272-17.145,21.635-27.579,38.775-23.305,17.144,4.274,27.576,21.64,23.302,38.784z"/>
						<path fill="#000" d="m46.103,27.444c0.637-4.258-2.605-6.547-7.038-8.074l1.438-5.768-3.511-0.875-1.4,5.616c-0.923-0.23-1.871-0.447-2.813-0.662l1.41-5.653-3.509-0.875-1.439,5.766c-0.764-0.174-1.514-0.346-2.242-0.527l0.004-0.018-4.842-1.209-0.934,3.75s2.605,0.597,2.55,0.634c1.422,0.355,1.679,1.296,1.636,2.042l-1.638,6.571c0.098,0.025,0.225,0.061,0.365,0.117-0.117-0.029-0.242-0.061-0.371-0.092l-2.296,9.205c-0.174,0.432-0.615,1.08-1.609,0.834,0.035,0.051-2.552-0.637-2.552-0.637l-1.743,4.019,4.569,1.139c0.85,0.213,1.683,0.436,2.503,0.646l-1.453,5.834,3.507,0.875,1.439-5.772c0.958,0.26,1.888,0.5,2.798,0.726l-1.434,5.745,3.511,0.875,1.453-5.823c5.987,1.133,10.489,0.676,12.384-4.739,1.527-4.36-0.076-6.875-3.226-8.515,2.294-0.529,4.022-2.038,4.483-5.155zm-8.022,11.249c-1.085,4.36-8.426,2.003-10.806,1.412l1.928-7.729c2.38,0.594,10.012,1.77,8.878,6.317zm1.086-11.312c-0.99,3.966-7.1,1.951-9.082,1.457l1.748-7.01c1.982,0.494,8.365,1.416,7.334,5.553z"/>
						</g>
					</svg>
					<?php echo $TITLE; ?></a>
				</div>
	    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
	      <span class="navbar-toggler-icon"></span>
	    </button>
	    <div class="collapse navbar-collapse" id="navbarSupportedContent">
	      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
	        <li class="nav-item">
						<a class="nav-link <?php if($ENDPOINT == $MAIN_ENDPOINT) echo "active"; ?>" href="<?php echo overviewlink(); ?>" aria-current="page" >Bitcoin</a>
	        </li>
	        <li class="nav-item">
						<a class="nav-link <?php if($ENDPOINT == $TEST_ENDPOINT) echo "active"; ?>" href="<?php echo overviewlink(true); ?>" aria-current="page" >Testnet</a>
	        </li>
	        <li class="nav-item">
						<a class="nav-link <?php if($VIEWTYPE == ViewType::NodeInfo) echo "active"; ?>" href="<?php echo nodeInfolink(); ?>" aria-current="page" >Node-Info</a>
	        </li>
	      </ul>

	      <ul class="navbar-nav flex-row flex-wrap ms-md-auto">
					<li class="nav-item pe-2">
			      <form class="d-flex" role="search" action="<?php echo BASE_URL.$LINKADD; ?>">
			        <input class="form-control me-2" name="search" type="search" placeholder="Search hash/address" aria-label="Search">
			        <button class="btn btn-outline-success" type="submit">Search</button>
			      </form>
					</li>
					<li class="nav-item py-2 py-lg-1 col-12 col-lg-auto">
            <div class="vr d-none d-lg-flex h-100 mx-lg-2 text-white"></div>
            <hr class="d-lg-none my-2 text-white-50">
          </li>
	        <li class="nav-item py-1 px-0 px-lg-2">
						<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="#fff" class="bi bi-github" viewBox="0 0 16 16">
						  <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
						</svg>
	        </li>
	      </ul>
	    </div>
	  </div>
	</nav>

<div class="w-100 h-100 p-3 mx-auto flex-column innercontent">	
<div class="container mt-5">
<?php if($VIEWTYPE == ViewType::Overview): ?>
	<main class="bd-main order-1">​
  <div class="row p-0 g-0">
		<h1>Latest Blocks</h1>
    <p class="text-muted">Last <?php echo count($lastblocks); ?> blocks</p>
<?php foreach ($lastblocks as $block): ?>
				<div class="card me-2 mb-2" style="width: 18rem;">
				  <div class="card-body">
				    <h5 class="card-title"><?php echo $block->height; ?></h5>
				    <p class="card-text"><?php echo $block->hash; ?></p>
				  </div>
				  <ul class="list-group list-group-flush">
				    <li class="list-group-item"><strong><?php echo $block->nTx; ?></strong> Transactions</li>
				    <li class="list-group-item"><strong><?php if (isset($block->size)) echo number_format($block->size, 0, ".", "'"); ?></strong> bytes</li>
				    <li class="list-group-item"><strong><?php echo $block->age; ?></strong> ago</li>
				  </ul>
				  <div class="card-body">
				    <a href="<?php echo blocklink($block->hash); ?>" class="card-link stretched-link">Details</a>
				  </div>
				</div>
<?php endforeach;?>
  </div>
  </main>
<?php elseif($VIEWTYPE == ViewType::AddrSearch):
	$addrdata = rpcFetch("validateaddress", "[\"".$ADDRESSSEARCH."\"]")->result;
	 ?>
	<div class="row">
		<h2>Address Info</h2>
	</div>
	<?php if ($addrdata->isvalid): ?>
	<div class="row mb-4">
		<div class="col">
			<ol class="list-group mb-2">
				<li class="list-group-item d-flex justify-content-between align-items-start">
					<div class="ms-2 me-auto">
						<div class="fw-bold">Address</div>
						<?php echo $addrdata->address;?>
					</div>
	  		</li>
				<?php if(isset($addrdata->witness_version)): ?>
				<li class="list-group-item d-flex justify-content-between align-items-start">
					<div class="ms-2 me-auto">
						<div class="fw-bold">Witness Version</div>
						<?php echo $addrdata->witness_version; ?>
					</div>
	  		</li>
				<li class="list-group-item d-flex justify-content-between align-items-start">
					<div class="ms-2 me-auto">
						<div class="fw-bold">Witness Program</div>
						<?php echo $addrdata->witness_program; ?>
					</div>
	  		</li>
				<?php endif;?>
			</ol>
		</div>
		<div class="col">
			<ol class="list-group mb-2">
				<li class="list-group-item d-flex justify-content-between align-items-start">
					<div class="ms-2 me-auto">
						<div class="fw-bold">scriptPubKey</div>
						<?php echo $addrdata->scriptPubKey; ?>
					</div>
	  		</li>
			</ol>
		</div>
	</div>
	
	<div class="row mb-4">
		<div class="col">
			<nav aria-label="breadcrumb" class="mt-2">
			  <ol class="list-group mb-2">
			    <li class="list-group-item d-flex justify-content-between align-items-start" id="utxolookup"><a href="javascript:;">Scan UTXO Set (total balance, all unspent outputs)</a></li>
					<li class="list-group-item d-flex justify-content-between align-items-start" id="scanfilters"><a href="javascript:;">Scan Blockfilters (all relevant blocks including spent outputs)</a></li>
			  </ol>
			</nav>
			<div class="progress invisible" id="loadingbardiv" style="width: 25rem;">
			  <div id="loadingbar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%">0%</div>
			</div>
			<div id="result">
			</div>
			<div id="result2">
			</div>
		</div>
	</div>
	
	<?php else: ?>
	<div class="row mb-4">
		<div class="col">
			Invalid Address <?php if (isset($addrdata->error)) echo " (Error: ".$addrdata->error.")"; ?>
		</div>
	</div>		
	<?php endif; ?>
	<script>
		// ajax utxoset and blockfilter scan
		document.addEventListener("DOMContentLoaded", () => {
			
		var scan_ongoing = false;
			
	  $("#utxolookup").click(function(){
			if (scan_ongoing == true) { return; }
			scan_ongoing = true;
			var refreshIntervalId = 0;
			$("#loadingbardiv").removeClass("invisible");
			$("#loadingbar").attr('aria-valuenow', 0).css('width', 0+'%');
			$( "#result" ).html( "Scanning UTXO Set... (can take a while)" );
			$.get( "?search=<?php echo $ADDRESSSEARCH; ?>&utxolookup=1", function( data ) {
			  $( "#result" ).html( data );
				clearInterval(refreshIntervalId);
				$("#loadingbardiv").addClass("invisible");
				scan_ongoing = false;
			});
			
			refreshIntervalId = setInterval(function() {
				$.get( "?search=<?php echo $ADDRESSSEARCH; ?>&utxolookupstatus=1", function( data ) {
					if (data == "") {
						clearInterval(refreshIntervalId);
						$("#loadingbardiv").addClass("invisible");
						scan_ongoing = false;
					}
					else {
						$( "#result" ).html( "Scanning UTXO Set... "+data+"%" );
						$("#loadingbar").attr('aria-valuenow', data).css('width', data+'%');
						$("#loadingbar").html(data+'%');
					}
				});
			}, 1000);
	  });
		
	  $("#scanfilters").click(function(){
			if (scan_ongoing == true) { return; }
			scan_ongoing = true;
			
			var refreshIntervalId = 0;
			$("#loadingbardiv").removeClass("invisible");
			$("#loadingbar").attr('aria-valuenow', 0).css('width', 0+'%');
			$( "#result" ).html( "Scanning Blockfilters... (can take a while)" );
			$.get( "?search=<?php echo $ADDRESSSEARCH; ?>&scanfilters=1&startheight=1", function( data ) {
			  $( "#result" ).html( data );
				clearInterval(refreshIntervalId);
				$("#loadingbardiv").addClass("invisible");
				scan_ongoing = false;
			});
			
			refreshIntervalId = setInterval(function() {
				$.get( "?search=<?php echo $ADDRESSSEARCH; ?>&scanfiltersstatus=1", function( data ) {
					if (data == "") {
						clearInterval(refreshIntervalId);
						scan_ongoing = false;
						$("#loadingbardiv").addClass("invisible");
					}
					else {
						$( "#result" ).html( "Scanning Blockfilters... "+data+"%" );
						$("#loadingbar").attr('aria-valuenow', data).css('width', data+'%');
						$("#loadingbar").html(data+'%');
					}
				});
			}, 1000);
	  });
		
		
		$(document).on("click", "#buildtxhistory", function() {
			if (scan_ongoing == true) { return; }
			scan_ongoing = true;
			
			$("#loadingbardiv").removeClass("invisible");
			$("#loadingbar").attr('aria-valuenow', 0).css('width', 0+'%');
			$( "#result2" ).html( "<hr />Building Transaction History... (can take a while)" );
			
			var postdata = $("#relevantblocks").val();
			
			var func = function( data ) {
				var do_continue = false;
				try {
					// decode JSON
					var json = $.parseJSON(data);
					var progress = (100 / json.totalblocks * (json.totalblocks - json.remainingcount)).toFixed(0);
					$("#loadingbar").attr('aria-valuenow', data).css('width', progress+'%');
					$("#loadingbar").html(progress+'%');
					$( "#result2" ).html( "<hr />Building Transaction History... ("+json.remainingcount+" blocks remaining)" );
					do_continue = true;
				}
				catch (e) {
					// its html, must be the result
					$("#loadingbardiv").addClass("invisible");
					$("#buildtxhistory").addClass("invisible");
					$( "#result2" ).html( "<hr />"+data );
					scan_ongoing = false;
				}
				if (do_continue == true) {
					// process the next blocks
					$.post( "?txhistory=<?php echo $ADDRESSSEARCH; ?>", data, func);
				}
			};
			
			$.post( "?txhistory=<?php echo $ADDRESSSEARCH; ?>", postdata, func);
			
			// refreshIntervalId = setInterval(function() {
			// 	$.get( "?search=<?php echo $ADDRESSSEARCH; ?>&scanfiltersstatus=1", function( data ) {
			// 		if (data == "") {
			// 			clearInterval(refreshIntervalId);
			// 			$("#loadingbardiv").addClass("invisible");
			// 		}
			// 		else {
			// 			$( "#result" ).html( "Scanning Blockfilters... "+data+"%" );
			// 			$("#loadingbar").attr('aria-valuenow', data).css('width', data+'%');
			// 			$("#loadingbar").html(data+'%');
			// 		}
			// 	});
			// }, 1000);
	  });
		
	});
	</script>
	<?php ?>
<?php elseif($VIEWTYPE == ViewType::NodeInfo): ?>
	<?php
		$networkinfo = rpcFetch("getnetworkinfo")->result;
		$blockchaininfo = rpcFetch("getblockchaininfo")->result;
	?>
	<table class="table">
	  <tbody>
	    <tr>
	      <th scope="row">Chain</th>
	      <td><?php echo $blockchaininfo->chain; ?></td>
	    </tr>
	    <tr>
	      <th scope="row">Version</th>
	      <td><?php echo $networkinfo->subversion; ?></td>
	    </tr>
	    <tr>
	      <th scope="row">Connections</th>
	      <td><?php echo $networkinfo->connections; ?></td>
	    </tr>
	    <tr>
	      <th scope="row">Blocks</th>
	      <td><?php echo $blockchaininfo->blocks; ?></td>
	    </tr>
	    <tr>
	      <th scope="row">Difficulty</th>
	      <td><?php echo $blockchaininfo->difficulty; ?></td>
	    </tr>
	  </tbody>
	</table>
<?php elseif($VIEWTYPE == ViewType::Block): ?>
  <div class="row">
    <h2>Block Details</h2>
  </div>
  <div class="row mb-4">
    <div class="col">
			<ol class="list-group mb-2">
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Height</div>
			      <?php echo $block->height; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Hash</div>
			      <span><?php echo shortHash($block->hash); ?></span>
						<a href="#" data-bs-toggle="collapse" data-bs-target="#collapseBlockHash" aria-expanded="false" aria-controls="collapseBlockHash">(Show full hash)</a>
						<div class="collapse" id="collapseBlockHash">
						  <div class="" style="font-size:.7em">
						    <?php echo $block->hash; ?>
						  </div>
						</div>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Time</div>
			      <?php echo date("Y-m-d H:i:s", $block->time); ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Total amount in all outputs</div>
						<?php echo btcamount($block->stats->total_out/100000000.0); ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Total fees</div>
						<?php echo btcamount($block->stats->totalfee/100000000); ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Total fees & blockreward</div>
						<?php echo btcamount(($block->stats->totalfee+$block->stats->subsidy)/100000000); ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Previous Block</div>
						<?php if(isset($block->previousblockhash)): ?>
			      <span><a href="<?php echo blocklink($block->previousblockhash); ?>"><?php echo shortHash($block->previousblockhash); ?></a></span>
						<a href="#" data-bs-toggle="collapse" data-bs-target="#collapsePrevBlockHash" aria-expanded="false" aria-controls="collapsePrevBlockHash">(Show full hash)</a>
						<div class="collapse" id="collapsePrevBlockHash">
						  <div class="" style="font-size:.7em">
						    <?php echo $block->previousblockhash; ?>
						  </div>
						</div>
						<?php endif; ?>
			    </div>
			  </li>
			</ol>
		</div>
    <div class="col">
			<ol class="list-group">
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Size in Bytes</div>
			      <?php if (isset($block->size)) echo number_format($block->size, 0, ".", "'"); else echo "N/A"; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Weight in Bytes</div>
			      <?php if (isset($block->weight)) echo number_format($block->weight, 0, ".", "'"); else echo "N/A"; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Transactions</div>
						<?php echo $block->nTx; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Total inputs/outputs</div>
						<?php echo $block->stats->ins."/".$block->stats->outs; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Feerange</div>
						<?php echo $block->stats->minfeerate; ?>-<?php echo $block->stats->maxfeerate; ?> sats/vB
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Medianfee</div>
						<?php echo $block->stats->medianfee; ?> sats
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Next Block</div>
						<?php if(isset($block->nextblockhash)): ?>
			      <span><a href="<?php echo blocklink($block->nextblockhash); ?>"><?php echo shortHash($block->nextblockhash); ?></a></span>
						<a href="#" data-bs-toggle="collapse" data-bs-target="#collapseNextBlockHash" aria-expanded="false" aria-controls="collapseNextBlockHash">(Show full hash)</a>
						<div class="collapse" id="collapseNextBlockHash">
						  <div class="" style="font-size:.7em">
						    <?php echo $block->nextblockhash; ?>
						  </div>
						</div>
						<?php endif; ?>
			    </div>
			  </li>
			</ol>
		</div>
	</div>
  <div class="row mb-4">
    <div class="col-md-12">
      <h4>Transactions (<?php echo $block->nTx; ?>)</h4>
<?php if(isset($block->tx)): ?>
	
	<?php if(isset($_REQUEST['txdetails'])): ?>
		      <ul class="list-group list-numbered">
		<?php $i = 0; foreach($block->tx as $tx): $i++;
			$total = 0;
			foreach($tx->vout as $out) {
				$total += $out->value;
			}
						?>
		        <li class="list-group-item d-flex justify-content-between lh-condensed <?php if(isset($_REQUEST['txid']) && $tx->txid == $_REQUEST['txid']) echo "active"; ?>"><div class="text-truncate"><?php echo $i; ?>.&nbsp;<a href="<?php echo txlink($tx->txid); ?>"><?php echo $tx->txid; ?></a></div><div><?php if(isset($tx->fee)): ?><span class="badge bg-success me-1"><?php echo round($tx->fee*100000000/$tx->vsize); ?> sat/vB</span><?php endif; ?><span class="badge bg-dark me-1"><?php echo count($tx->vin)."/".count($tx->vout).""; ?></span><span class="badge bg-primary me-1"><?php echo btcamount($total); ?></span></div>
		        </li>
		<?php endforeach; ?>
		      </ul>
	<?php else: ?>
		      <ul class="list-group list-group-numbered">
		<?php $i = 0; foreach($block->tx as $tx): $i++?>
		        <li class="list-group-item d-flex lh-condensed <?php if(isset($_REQUEST['txid']) && $tx == $_REQUEST['txid']) echo "active"; ?>">
		          &nbsp;&nbsp;<div class="text-truncate"><a href="<?php echo txlink($tx); ?>"><?php echo $tx; ?></a></div>
		        </li>
		<?php endforeach; ?>
		      </ul>
	<?php endif;?>
<?php else:?>
<span class="badge badge-warning">Block data not available</span>
<?php endif; ?>
    </div>
  </div>
<?php elseif($VIEWTYPE == ViewType::Transaction): ?>
  <div class="row">
    <div class="col">
			<h2>Transaction Details<span class="badge <?php if ($tx->confirmations >= 6) echo "bg-success"; elseif($tx->confirmations > 0) { echo "bg-secondary"; } else { echo "bg-danger"; } ?> align-middle ms-4 mb-1" style="font-size: 0.8rem;"><?php echo ($tx->confirmations > 0) ? $tx->confirmations." Confirmations" : "mempool"; ?></span></h2>
		</div>
  </div>
  <div class="row mb-4">
    <div class="col">
			<ol class="list-group mb-2">
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
						<?php if(isset($tx->mempool)): ?>
				      <div class="fw-bold">Time entered mempool</div>
							<?php echo date("Y-m-d H:i:s", $tx->mempool->time); ?>
						<?php else:?>
			      <div class="fw-bold">Time</div>
						<?php echo date("Y-m-d H:i:s", $tx->time); ?>
						<?php endif;?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">TX ID</div>
			      <span><?php echo shortHash($tx->txid); ?></span>
						<a href="#" data-bs-toggle="collapse" data-bs-target="#collapseExample" aria-expanded="false" aria-controls="collapseExample">(Show full txid)</a>
						<div class="collapse" id="collapseExample">
						  <div class="" style="font-size:.7em">
						    <?php echo $tx->txid; ?>
						  </div>
						</div>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Block</div>
						<?php if(isset($tx->mempool)) { echo "mempool"; } ?>
			      <?php if(isset($tx->blockhash)): ?>
							<span><a href="<?php echo blocklink($tx->blockhash, $tx->txid); ?>"><?php echo shortHash($tx->blockhash); ?></a></span> <?php if(isset($tx->blockheader)) echo " / Height: <a href=\"".blocklink($tx->blockhash, $tx->txid)."\">".$tx->blockheader->height."</a>"; else echo "-"; ?>
						<?php endif; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Confirmations</div>
						<?php echo $tx->confirmations; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Fee</div>
						<?php echo btcamount($tx->fee); ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Total inputs (<?php echo count($tx->vin); ?>)</div>
						<?php echo btcamount($tx->total_in); ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Total outputs (<?php echo count($tx->vout); ?>)</div>
						<?php echo btcamount($tx->total_out); ?>
			    </div>
			  </li>
			</ol>
		</div>
    <div class="col">
			<ol class="list-group">
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Feerate</div>
						<?php echo round((isset($tx->mempool) ? $tx->mempool->fees->base : $tx->fee)*100000000/$tx->vsize, 2); ?> sats/vB
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Size</div>
						<?php echo $tx->size; ?> Bytes
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Virtual Size</div>
						<?php echo $tx->vsize; ?> vBytes
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Weight</div>
						<?php echo $tx->weight; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Locktime</div>
						<?php echo $tx->locktime; ?>
			    </div>
			  </li>
			  <li class="list-group-item d-flex justify-content-between align-items-start">
			    <div class="ms-2 me-auto">
			      <div class="fw-bold">Version</div>
						<?php echo $tx->version; ?>
			    </div>
			  </li>
			</ol>
		</div>
	</div>
	
  <div class="row">
    <div class="col">
			<h2>Inputs / Outputs</h2>
		</div>
  </div>
	<div class="row mb-4 row-cols-1 row-cols-sm-2 row-cols-md-2">
	  <div class="col">
			<ol class="list-group">
<?php foreach($tx->vin as $vin): ?>
        <li class="list-group-item d-flex justify-content-between align-items-start">
<?php if (isset($vin->prevout)):?>
            <div class="text-truncate"><a href="<?php echo txlink($vin->txid, $vin->vout); ?>"><?php echo shortAddr($vin->prevout->scriptPubKey->address); ?><br><span class="small_vin">txid: <?php echo shortHash($vin->txid)." vout:".$vin->vout; ?></span></a></div><span class="text-muted"><?php echo btcamount($vin->prevout->value); ?></span>
<?php elseif(isset($vin->txid)): ?>
            <div class="text-truncate"><a href="<?php echo txlink($vin->txid, $vin->vout); ?>"><?php echo $vin->txid; ?></a></div>
<?php else: ?>
            <div class="text-truncate">Coinbase</div>
<?php endif; ?>
        </li>
<?php endforeach;?>
			</ol>
		</div>
	  <div class="col">
			<ol class="list-group">
<?php foreach($tx->vout as $vout): ?>
        <li class="list-group-item d-flex align-items-start<?php if (isset($_REQUEST['n']) && $_REQUEST['n'] == $vout->n) echo " active"; ?>">
						<div class="text-truncate"><h6><?php if (isset($vout->scriptPubKey->address)) echo "<a href=\"".addresslink($vout->scriptPubKey->address)."\">".shortAddr($vout->scriptPubKey->address)."</a>"; ?></h6><span class="text-muted<?php if (!isset($vout->is_unspent)) echo " spent"; ?>"><?php if ($vout->scriptPubKey->type != "nulldata") { echo btcamount($vout->value); } else { echo "<span style=\"max-width: 200px; font-size:0.7em;color:black !important;\">".$vout->scriptPubKey->asm."</span>"; }; ?></span>
						<br>
						<span class="badge bg-primary me-1"><?php echo $vout->scriptPubKey->type; ?></span><?php if (isset($vout->is_unspent) || !isset($tx->blockhash)): ?><span class="badge bg-success">unspent</span><?php else: ?><span class="badge bg-warning">spent</span><?php endif; ?>
						
						</div>
        </li>
<?php endforeach;?>
			</ol>
		</div>
	</div>
		<button type="button" class="btn btn-primary"  data-bs-toggle="collapse" data-bs-target="#collapseJSON" aria-expanded="false" aria-controls="collapseJSON">Show RAW JSON</button>
		<div class="collapse mt-2" id="collapseJSON">
	        <textarea name="rawjson" style="width:100%;height:15em;">
	    <?php echo json_encode($tx, JSON_PRETTY_PRINT); ?>
	        </textarea>
		</div>
<?php elseif($VIEWTYPE == ViewType::TxHistory): ?>
  <div class="row">
    <div class="col">
			<h2>Transaction History (only confirmed transactions)</h2>
			<h4><?php echo $txhistoryneedle; ?></h4>
			<div>Total transactions found: <?php echo count($txlist); ?></div>
			<?php if($remainingblocks > 0): ?><div>Remaining Blocks to process: <?php echo $remainingblocks; ?></div><?php endif; ?>
			
			<?php if($remainingblocks == 0): ?>
			<div>Balance: <?php echo btcamount($txhistorybalance); ?></div>
      <ul class="list-group list-numbered my-2">
				<?php foreach($txlist as $tx): ?>
        <li class="list-group-item d-flex justify-content-between lh-condensed">
					<div class="text-truncate">
						<?php echo date("Y-m-d H:i:s", $tx['time']); ?>&nbsp;<span class="badge bg-<?php if ($tx['type'] == "in") { echo "success "; } else { echo "warning "; } ?>"><?php if ($tx['type'] == "in") { echo "received "; } else { echo "spent "; } ?></span>
						<?php echo $tx['txid']; ?>
					</div>
					<div><strong><?php echo btcamount($tx['value']); ?></strong></div>
				</li>
				<?php endforeach;?>
			</ul>
		<?php endif; ?>
		<?php if($remainingblocks > 0): ?>
			<form action="<?php echo BASE_URL."?txhistory=".urlencode($txhistoryneedle); ?>" method="post">
				<textarea id="dataform" name="data" style="display:none;"><?php echo $json_txlist; ?></textarea>
			  <button type="submit" id="submitbutton" class="btn btn-primary">continue build</button>
			</form>
	<script>
		document.addEventListener("DOMContentLoaded", () => {
			refreshIntervalId = setInterval(function() {
				clearInterval(refreshIntervalId);
				$('#submitbutton').click();
			}, 100);
		});
	</script>
	<?php endif; ?>
		</div>
	</div>
<?php elseif($VIEWTYPE == ViewType::NotFound): ?>
  <h3>Object not found</h3>
<?php endif; ?>
<footer class="row mt-2 pt-2">
  <div class="inner">
	<div class="text-success">
	  <hr>
	</div>
    <p><?php echo $FOOTER; ?></p>
  </div>
</footer>
</div>
</div>
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.min.js" integrity="sha384-BBtl+eGJRgqQAUMxJ7pMwbEyER4l1g+O15P+16Ep7Q9Q+zqX6gSbd85u4mG4QzX+" crossorigin="anonymous"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="   crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/clipboard.js/2.0.0/clipboard.min.js"></script>
<script>
    var clipboard = new ClipboardJS('.copyicon');
		var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
		var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
		  return new bootstrap.Tooltip(tooltipTriggerEl)
		})
</script>
<!-- execution time: <?php echo (microtime(true) - $time_start); ?>s -->
</body>
</html>