#!/usr/bin/perl -pi
# PHP 8: each() was removed.
#   while (list($k, $v) = each(X))  -> foreach (X as $k => $v)
#   while (list($k) = each(X))      -> foreach (array_keys(X) as $k)
#   list($a, $b) = each(X);         -> $a = key(X); $b = current(X);
s{while\s*\(\s*list\(\s*(\$\w+)\s*,\s*(\$\w+)\s*\)\s*=\s*each\(\s*([^()]+?)\s*\)\s*\)}{foreach ($3 as $1 => $2)}g;
s{while\s*\(\s*list\(\s*(\$\w+)\s*\)\s*=\s*each\(\s*([^()]+?)\s*\)\s*\)}{foreach (array_keys($2) as $1)}g;
s{list\(\s*(\$\w+)\s*,\s*(\$\w+)\s*\)\s*=\s*each\(\s*([^()]+?)\s*\)\s*;}{$1 = key($3); $2 = current($3); // PHP8: was each()}g;
