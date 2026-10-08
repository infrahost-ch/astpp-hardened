#!/usr/bin/perl -pi
# PHP 8: "$str{$i}" string/array offset syntax was removed -> "$str[$i]".
# Matches a variable (optionally ->prop and [..] parts) directly followed by {expr}.
# Does not touch "{$var}" interpolation or code blocks ("if (...) {").
s/(\$[A-Za-z_]\w*(?:->\w+)*(?:\[[^\]\n]*\])*)\{([^{}\s][^{}\n]*)\}/$1\[$2\]/g;
