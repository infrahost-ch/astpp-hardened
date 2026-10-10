# Parsedown 1.7.4

Markdown renderer for the API documentation in the portal (modules/apikeys).

- Source: https://github.com/erusev/parsedown, tag 1.7.4 (commit 211b53c1bf6019ee9fbde367ce0e06cad7098e99)
- License: MIT, see LICENSE.txt
- Verified: git blob of Parsedown.php as released = 1b9d6d5bc2c044a2e1115dda3f001874f5891789
- Local change (PHP 8.4+): blockSetextHeader() and blockTable() declare `?array $Block = null`
  instead of the implicitly nullable `array $Block = null` (deprecated since PHP 8.4).
