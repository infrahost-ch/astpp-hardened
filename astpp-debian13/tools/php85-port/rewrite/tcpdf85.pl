#!/usr/bin/perl
# PHP 8 port of TCPDF 5.0.002 (byte-preserving: CRLF + ISO-8859-1 stay untouched)
#  - create_function('$matches', '<php code>')  ->  function ($matches) { <php code> }
#    (the code is a single-quoted PHP literal: \' -> ' and \\ -> \ when unwrapping)
#  - while (list($a, $b) = each($arr))  ->  foreach ($arr as $a => $b)
use strict;
use warnings;
binmode STDIN; binmode STDOUT;
local $/;
my $src = <STDIN>;
my ($cf, $ea) = (0, 0);

$src =~ s{create_function\('\$matches',\s*'((?:[^'\\]|\\.)*)'\)}{
	my $body = $1;
	$body =~ s/\\(['\\])/$1/g;
	$cf++;
	"function (\$matches) {" . $body . "}"
}gse;

$src =~ s{while\s*\(\s*list\(\s*(\$\w+)\s*,\s*(\$\w+)\s*\)\s*=\s*each\(\s*(\$[\w\[\]\->']+)\s*\)\s*\)}{
	$ea++;
	"foreach ($3 as $1 => $2)"
}ge;

print $src;
print STDERR "create_function ersetzt: $cf, each() ersetzt: $ea\n";
