<?php
############################
# CONFIG SECTION           #
############################
error_reporting(E_ALL);
ini_set('display_errors', 1);
  $MAIN_ENDPOINT = "http://127.0.0.1:8332/";
  $TEST_ENDPOINT = "http://127.0.0.1:18332/";
  define("BASE_URL", "/explorer/");
  define("USE_MOD_REWRITE", true);
  // enable mod rewrite mode to have nice URLs like /block/<hash> instead of index.php?block=<hash>
  $FOOTER = '<a href="https://github.com/jonasschnelli/dumbblockexplorer">Dumb Block Explorer</a> for Bitcoin Core';
  $TITLE = "Dumb Block Explorer";
###### END CONFIG SECTION
  $HTMLTITLE = $TITLE;
  abstract class ViewType { const Overview = 0; const Block = 1; const Transaction = 2; const NotFound = 3; };
  function clean($in) { return htmlentities(strip_tags(preg_replace("/[^a-fA-F0-9]/", "", $in))); }
  $ENDPOINT = isset($_REQUEST['testnet']) ? $TEST_ENDPOINT : $MAIN_ENDPOINT;
  $LINKADD = isset($_REQUEST['testnet']) ? (USE_MOD_REWRITE ? "testnet/" : "?testnet=1&") : ( USE_MOD_REWRITE ? "":"?");
  $NOW = time();
  $VIEWTYPE = ViewType::Overview;
  if(isset($_REQUEST['search'])) {
    $search = clean($_REQUEST['search']);
    $block = getBlockJSON($search);
    $VIEWTYPE = ViewType::Block;
    if (isset($block->height)) { $HTMLTITLE = $TITLE.", height ".$block->height; }
    if (!$block) {
      $tx = getTxJSON($search);
      $VIEWTYPE = ViewType::Transaction;
      if (!$tx) {
        $VIEWTYPE = ViewType::NotFound;
      }
    }
  } else if(isset($_REQUEST['block'])) {
    $VIEWTYPE = ViewType::Block;
    $block = getBlockJSON(clean($_REQUEST['block']));
    if (isset($block->height)) { $HTMLTITLE = $TITLE.", height ".$block->height; }
  } else if(isset($_REQUEST['tx'])) {
    $VIEWTYPE = ViewType::Transaction;
    $tx = getTxJSON(clean($_REQUEST['tx']));
  }
  if ($VIEWTYPE == ViewType::Overview) {
    $data = httpFetch("rest/chaininfo.json");
    $blocks = $data->blocks;
    $bestblockhash = $data->bestblockhash;

    $lastblocks = getLastBlocks($bestblockhash);
  }
  function getLastBlocks($tophash, $blk_count = 10) {
    global $NOW;
    $lastblocks = array();
    $hash = $tophash;
    for ($i=0;$i<$blk_count;$i++) {
      array_push($lastblocks, getBlockJSON($hash));
      $hash = $lastblocks[count($lastblocks)-1]->previousblockhash;
      $age_in_s = $NOW - $lastblocks[count($lastblocks)-1]->mediantime;
      if ($age_in_s > 3600) {
        $lastblocks[count($lastblocks)-1]->age = gmdate("G", $age_in_s)." hours, ".gmdate("i", $age_in_s)." min";
      }
      else if ($age_in_s > 60) {
        $lastblocks[count($lastblocks)-1]->age = gmdate("i", $age_in_s)." mins, ".gmdate("s", $age_in_s)." secs";
      }
    }
    return $lastblocks;
  }
  function httpFetch($url) {
    global $ENDPOINT;
    $data = @file_get_contents($ENDPOINT.$url);
    return json_decode($data);
  }
  function getUTXOJSON($hash, $n) {
    return httpFetch("rest/getutxos/".$hash."-".$n.".json");
  }
  function getBlockHeader($hash) {
    $blocks = httpFetch("rest/headers/1/$hash.json");
    if($blocks) {
      return $blocks[0];
    }
    return;
  }
  function getBlockJSON($hash) {
    $block = httpFetch("rest/block/notxdetails/$hash.json");
    if (!$block) {
      $block = getBlockHeader($hash);
    }
    return $block;
  }
  function getTxJSON($hash) {
    global $VIEWTYPE;
    $json = httpFetch("rest/tx/$hash.json");
    if (!$json) { $VIEWTYPE = ViewType::NotFound; return; }
    if (isset($json->blockhash)) { $json->blockheader = getBlockHeader($json->blockhash); }
    $missing_prevout = false;
    foreach($json->vin as &$vin) {
      if ($vin->txid) {
        $prevout = httpFetch("rest/tx/".$vin->txid.".json");
        if(isset($prevout)) {
          $vin->prevout = $prevout->vout[$vin->vout];
        } else { $missing_prevout = true; }
      }
    }
    if (!$missing_prevout) {
      $total_in = 0;
      foreach($json->vin as &$vin) {
        $total_in += $vin->prevout->value;
      }
      $json->total_in = $total_in;
    }
    $total_out = 0;
    foreach($json->vout as &$vout) {
      $total_out += $vout->value;
      $utxo = getUTXOJSON($json->txid, $vout->n);
      if (is_object($utxo) && $utxo->bitmap == "1") {
        $vout->is_unspent = true;
      }
    }
    $json->total_out = $total_out;
    if (!$missing_prevout) { $json->fee = round($total_in-$total_out, 8); }
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
  function overviewlink($testnet=0) {
    global $LINKADD;
    if ($testnet) {
      if (USE_MOD_REWRITE) { return BASE_URL."testnet/"; }
      return BASE_URL."index.php".$LINKADD."testnet=1";
    }
    return BASE_URL;
  }
  function btcamount($amount) {
    return number_format($amount, 8, ".", "'")." BTC";
  }
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <title><?php echo $HTMLTITLE; ?></title>
  <meta name="author" content="">
  <meta name="description" content="">

<!-- IE Edge Meta Tag -->
<meta http-equiv="X-UA-Compatible" content="IE=edge">

<!-- Viewport -->
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">

<!-- Minified CSS -->
<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/css/bootstrap.min.css" integrity="sha384-MCw98/SFnGE8fJT3GXwEOngsV7Zt27NXFoaoApmYm81iuXoPkFOJwJ8ERdknLPMO" crossorigin="anonymous">

<!-- Optional Theme -->
<script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js" integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js" integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy" crossorigin="anonymous"></script>


<style>
  body {
    font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
  }
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

span.text-muted.spent {
  color: #f66 !important;
}

</style>
</head>
<body class="bg-light">
  
<div class="w-100 h-100 p-3 mx-auto flex-column">
  <header class="masthead mb-auto">
    <div class="inner">
      <h3 class="masthead-brand"><?php echo $TITLE; ?></h3>
      <nav class="nav nav-masthead justify-content-center">
        <form class="form-inline" action="">
          <input class="form-control mr-sm-2" name="search" type="search" placeholder="Search hash" aria-label="Search">
        </form>
<?php if(isset($TEST_ENDPOINT) && strlen($TEST_ENDPOINT) > 0): ?>
        <a class="nav-link <?php if($ENDPOINT == $TEST_ENDPOINT) echo "active"; ?>" href="<?php echo overviewlink(true); ?>">Testnet</a>
        <a class="nav-link <?php if($ENDPOINT == $MAIN_ENDPOINT) echo "active"; ?>" href="<?php echo overviewlink(); ?>">Mainnet</a>
<?php endif;?>
      </nav>
    </div>
  </header>

<div class="container mt-5">
<?php if($VIEWTYPE == ViewType::Overview): ?>
  <div class="row">
    <p class="text-muted">Last <?php echo count($lastblocks); ?> blocks</p>
    <table class="table table-bordered table-hover table-striped">
      <thead>
        <tr>
          <th scope="col">Height</th>
          <th scope="col">Hash</th>
          <th scope="col">txns</th>
          <th scope="col">Size</th>
          <th scope="col">Age</th>
        </tr>
      </thead>
      <tbody>
<?php foreach ($lastblocks as $block): ?>
        <tr>
          <td><?php echo $block->height; ?></td>
          <td class="text-truncate"><a href="<?php echo blocklink($block->hash); ?>"><?php echo $block->hash; ?></a></td>
          <td><?php echo $block->nTx; ?></td>
          <td><?php if (isset($block->size)) echo number_format($block->size, 0, ".", "'"); ?></td>
          <td><?php echo $block->age; ?></td>
        </tr>
<?php endforeach;?>
      </tbody>
    </table>
  </div>
<?php elseif($VIEWTYPE == ViewType::Block): ?>
  <div class="row">
    <h2>Block Details</h2>
  </div>
  <div class="row">
    <div class="col-md-8">
      <table class="table table-bordered table-hover table-striped bg-white">
        <tbody>
        <tr>
          <th>Heigt</th>
          <td><?php echo $block->height; ?></td>
        </tr>
        <tr>
          <th>Hash</th>
          <td><?php echo $block->hash; ?></td>
        </tr>
        <tr>
          <th>Time</th>
          <td><?php echo date("Y-m-d H:i:s", $block->mediantime); ?></td>
        </tr>
        <tr>
          <th>Transactions</th>
          <td><?php echo $block->nTx; ?></td>
        </tr>
        <tr>
          <th>Size</th>
          <td><?php if (isset($block->size)) echo number_format($block->size, 0, ".", "'"); else echo "N/A"; ?></td>
        </tr>
        <tr>
          <th>Weight</th>
          <td><?php if (isset($block->weight)) echo number_format($block->weight, 0, ".", "'"); else echo "N/A"; ?></td>
        </tr>
        <tr>
          <th>Previous block</th>
          <td><a href="<?php echo blocklink($block->previousblockhash); ?>"><?php echo $block->previousblockhash; ?></a></td>
        </tr>
        <tr>
          <th>Next block</th>
          <td><?php if(isset($block->nextblockhash)): ?><a href="<?php echo blocklink($block->nextblockhash); ?>"><?php echo $block->nextblockhash; ?></a><?php endif; ?></td>
        </tr>
        </tbody>
      </table>
    </div>
    <div class="col-md-8">
      <h4>Transactions</h4>
<?php if(isset($block->tx)): ?>
      <ul class="list-group">
<?php $i = 0; foreach($block->tx as $tx): $i++?>
        <li class="list-group-item d-flex justify-content-between lh-condensed <?php if($tx == $_REQUEST['txid']) echo "active"; ?>">
          <div><a href="<?php echo txlink($tx); ?>"><?php echo $tx; ?></a></div>
          <span class="text-muted"><?php echo $i; ?></span>
        </li>
<?php endforeach;
else:?>
<span class="badge badge-warning">Block data not available</span>
<?php endif; ?>
      </ul>
    </div>
  </div>
<?php elseif($VIEWTYPE == ViewType::Transaction): ?>
  <div class="row">
    <h2>Transaction Details</h2>
  </div>
  <div class="row">
    <table class="table table-bordered table-hover table-striped bg-white">
      <tbody>
      <tr>
        <th>TXID</th>
        <td><?php echo $tx->txid; ?></td>
      </tr>
      <tr>
        <th>Hash</th>
        <td><?php echo $tx->hash; ?></td>
      </tr>
      <tr>
        <th>Confirmation</th>
        <td><?php if(isset($tx->blockheader)) echo $tx->blockheader->confirmations; else echo "0"; ?></td>
      </tr>
      <tr>
        <th>In Block</th>
        <td><?php if(isset($tx->blockhash)): ?><a href="<?php echo blocklink($tx->blockhash, $tx->txid); ?>"><?php echo $tx->blockhash; ?></a><?php else: ?><span class="badge badge-primary">Mempool</span><?php endif; ?></td>
      </tr>
      <tr>
        <th>Size</th>
        <td><?php echo $tx->size; ?></td>
      </tr>
      <tr>
        <th>VSize</th>
        <td><?php echo $tx->vsize; ?></td>
      </tr>
      <tr>
        <th>Weight</th>
        <td><?php echo $tx->weight; ?></td>
      </tr>
      <tr>
        <th>Total Inputs</th>
        <td><?php if (isset($tx->total_in)) echo btcamount($tx->total_in); else echo "N/A"; ?></td>
      </tr>
      <tr>
        <th>Total Outputs</th>
        <td><?php if (isset($tx->total_out)) echo btcamount($tx->total_out); else echo "N/A"; ?></td>
      </tr>
      <tr>
        <th>Fee</th>
        <td><?php if (isset($tx->fee)) echo btcamount($tx->fee); else echo "N/A"; ?></td>
      </tr>
      <tr>
        <th>Feerate</th>
        <td><?php if (isset($tx->fee)) echo $tx->fee*100000000/$tx->vsize; else echo "N/A"; ?></td>
      </tr>
      </tbody>
    </table>
  </div>
  <div class="row">
    <div class="col">
      <h4>Inputs</h4>
      <ul class="list-group">
<?php foreach($tx->vin as $vin): ?>
        <li class="list-group-item d-flex justify-content-between lh-condensed">
<?php if (isset($vin->prevout)):?>
            <div><a href="<?php echo txlink($vin->txid, $vin->vout); ?>"><?php echo $vin->prevout->scriptPubKey->addresses[0]; ?></a></div><span class="text-muted"><?php echo btcamount($vin->prevout->value); ?></span>
<?php else: ?>
            <div><a href="<?php echo txlink($vin->txid, $vin->vout); ?>"><?php echo $vin->txid; ?></a></div>
<?php endif; ?>
        </li>
<?php endforeach;?>
      </ul>
    </div>
    <div class="col">
      <h4>Outputs</h4>
      <ul class="list-group">
<?php foreach($tx->vout as $vout): ?>
        <li class="list-group-item lh-condensed<?php if (isset($_REQUEST['n']) && $_REQUEST['n'] == $vout->n) echo " active"; ?>">
          <span class="badge badge-primary"><?php echo $vout->scriptPubKey->type; ?></span> <?php if (isset($vout->is_unspent)): ?><span class="badge badge-success">unspent</span><?php else: ?><span class="badge badge-warning">spent</span><?php endif; ?><div><h6><?php if (isset($vout->scriptPubKey->addresses)) echo $vout->scriptPubKey->addresses[0]; ?></h6><span class="text-muted<?php if (!isset($vout->is_unspent)) echo " spent"; ?>"><?php echo btcamount($vout->value); ?></span></div>
        </li>
<?php endforeach;?>
      </ul>
    </div>
  </div>
  <div class="row mt-2">
    <p>
    <button type="button" class="btn btn-primary" data-toggle="collapse" href="#collapse_json" role="button" aria-expanded="false" aria-controls="collapse_json">
      Show RAW Json
    </button>
    </p>
  </div>
  <div class="row collapse" id="collapse_json">
        <h4>RAW Json</h4>
        <textarea name="rawjson" style="width:100%;height:10em;">
    <?php echo json_encode($tx, JSON_PRETTY_PRINT); ?>
        </textarea>
  </div>
<?php elseif($VIEWTYPE == ViewType::NotFound): ?>
  <h3>Object not found</h3>
<?php endif; ?>
<?php if($VIEWTYPE != ViewType::Overview): ?>
<nav aria-label="breadcrumb" class="row mt-2">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?php echo overviewlink(); ?>">« Overview</a></li>
  </ol>
</nav>
<?php endif; ?>
<footer class="row mt-2 pt-2 border-top">
  <div class="inner">
    <p><?php echo $FOOTER; ?></p>
  </div>
</footer>
</div>
</div>
<!-- jQuery first, then Popper.js, then Bootstrap JS -->
  <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js" integrity="sha384-q8i/X+965DzO0rT7abK41JStQIAqVgRVzpbzo5smXKp4YfRvH+8abtTE1Pi6jizo" crossorigin="anonymous"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.3/umd/popper.min.js" integrity="sha384-ZMP7rVo3mIykV+2+9J3UJ46jBk0WLaUAdn689aCwoqbBJiSnjAK/l8WvCWPIPm49" crossorigin="anonymous"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.1.3/js/bootstrap.min.js" integrity="sha384-ChfqqxuZUCnJSK3+MXmPNIyE6ZbWh2IMqE241rYiqJxyMiZ6OW/JmZQ5stwEULTy" crossorigin="anonymous"></script>
</body>
</html>