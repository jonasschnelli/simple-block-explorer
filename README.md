Dumb Block Explorer
=====================================

A trivial block explorer written in a single PHP file.

Demo
-------
https://bitcointools.jonasschnelli.ch/explorer/

Features
-------
* Works wirth Bitcoin Core with enabled REST interface (-rest)
* Works with or without txindex
* Works with pruning
* Does UTXO lookups

Install
-------
* Place the `index.php` script into a php enabled http docs directory
* Run Bitcoin Core with rest and txindex (optional) `-txindex -rest`
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
RewriteRule ^tx/([a-fA-F0-9]*)$ index.php?tx=$1 [L,QSA]
RewriteRule ^tx/([a-fA-F0-9]*)/n/([0-9]*)$ index.php?tx=$1&n=$2 [L,QSA]
RewriteRule ^block/([a-fA-F0-9]*)$ index.php?block=$1 [L,QSA]
```
