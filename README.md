Simple Block Explorer
=====================================

A trivial block explorer written in a single PHP file.

Demo
-------
https://bitcoin.jonasschnelli.ch/explorer/

Features
-------
* Only requires Bitcoin Core (no additional index)
* Does UTXO-set lookups
* Can scan UTXO-set for address balances
* Can scan blockfilters to create a transaction history of given address

Install
-------
* Place the `index.php` script into a php enabled http docs directory
* Run Bitcoin Core with txindex and blockfilters `-txindex -blockfilterindex`
* Edit the index.php config section

Nice links
-------

Use `mod_rewrite` use a propper URL scheme (/block/<hash>), etc.
Apache users can place a `.htaccess` file into the same folder as the PHP script.

```
RewriteEngine On
RewriteRule ^testnet$ index.php?testnet=1 [L,QSA]
RewriteRule ^testnet/$ index.php?testnet=1 [L,QSA]
RewriteRule ^testnet/tx/([a-fA-F0-9]*)$ index.php?testnet=1&tx=$1 [L,QSA]
RewriteRule ^testnet/tx/([a-fA-F0-9]*)/n/([a-fA-F0-9]*)$ index.php?testnet=1&tx=$1&n=$2 [L,QSA]
RewriteRule ^testnet/block/([a-fA-F0-9]*)$ index.php?testnet=1&block=$1 [L,QSA]
RewriteRule ^testnet/block/([a-fA-F0-9]*)/txid/([a-fA-F0-9]*)$ index.php?testnet=1&block=$1&txid=$2 [L,QSA]
RewriteRule ^testnet/height/([0-9]*)$ index.php?testnet=1&search=$1 [L,QSA]
RewriteRule ^tx/([a-fA-F0-9]*)$ index.php?tx=$1 [L,QSA]
RewriteRule ^tx/([a-fA-F0-9]*)/n/([0-9]*)$ index.php?tx=$1&n=$2 [L,QSA]
RewriteRule ^block/([a-fA-F0-9]*)$ index.php?block=$1 [L,QSA]
RewriteRule ^block/([a-fA-F0-9]*)/txid/([a-fA-F0-9]*)$ index.php?block=$1&txid=$2 [L,QSA]
RewriteRule ^height/([0-9]*)$ index.php?search=$1 [L,QSA]
RewriteRule ^testnet/([a-zA-Z0-9]*)$ index.php?testnet=1&$1=1 [L,QSA]
RewriteRule ^([a-zA-Z0-9]*)$ index.php?$1=1 [L,QSA]

```
