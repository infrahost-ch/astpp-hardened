#!/usr/bin/perl -pi
# PHP 8.2+: creating dynamic properties is deprecated (an error in PHP 9).
# CodeIgniter 2 and ASTPP rely on them everywhere ($this->CI, $this->load, ...).
# Mark every class with #[\AllowDynamicProperties] (inherited by child classes,
# a plain comment for PHP < 8). Keeps the line ending style (LF / CRLF) of the file.
if (/^((?:abstract\s+|final\s+)?class\s+\w+)/ && $prev !~ /AllowDynamicProperties/) {
	my $eol = /\r\n$/ ? "\r\n" : "\n";
	$_ = "#[\\AllowDynamicProperties]" . $eol . $_;
}
$prev = $_;
