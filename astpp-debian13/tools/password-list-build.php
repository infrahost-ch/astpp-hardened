<?php
/*
 * Builds the list of common passwords for the portal password rule (audit N13,
 * astpp/web_interface/astpp/application/libraries/astpp/password_policy_list.txt).
 *
 * Usage (sources from SecLists, https://github.com/danielmiessler/SecLists, MIT License):
 *   php8.5 password-list-build.php 10k-most-common.txt 100k-most-used-passwords-NCSC.txt > list.txt
 * then put the header lines (# ...) of the current list in front and replace the file.
 *
 * Kept: lowercase, unique, valid UTF-8 without control characters; entries of 12 or more
 * characters (the rule needs 12, shorter entries can only match as a word) and entries of 4 or
 * more characters that start and end with a letter (for the check of a listed word with
 * digits/symbols before or after it, e.g. "Sunshine2024!").
 */
$out = array();
foreach (array_slice($argv, 1) as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        fwrite(STDERR, "cannot read $file\n");
        exit(1);
    }
    foreach ($lines as $line) {
        $line = mb_strtolower(trim($line, "\r\n"), 'UTF-8');
        if ($line === '' || $line[0] === '#' || ! mb_check_encoding($line, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $line)) {
            continue;
        }
        $length = mb_strlen($line, 'UTF-8');
        if ($length >= 12 || ($length >= 4 && preg_match('/^\p{L}.*\p{L}$/u', $line))) {
            $out[$line] = true;
        }
    }
}
$out = array_keys($out);
sort($out, SORT_STRING);
fwrite(STDERR, count($out) . " entries\n");
echo implode("\n", $out), "\n";
