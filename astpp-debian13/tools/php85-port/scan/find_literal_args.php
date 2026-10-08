<?php
/*
 * PHP 8: an internal function parameter of type int/float/bool given a NON-numeric
 * string throws TypeError (PHP 7: warning, NULL returned / value 0).
 * Finds calls with a string literal in such a position (types via Reflection).
 */
require '/usr/share/php/PhpParser/autoload.php';
use PhpParser\Node;
$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
class V extends PhpParser\NodeVisitorAbstract {
    public $file;
    public function enterNode(Node $n) {
        if (! ($n instanceof Node\Expr\FuncCall) || ! ($n->name instanceof Node\Name)) return null;
        $fn = $n->name->toLowerString();
        if (! function_exists($fn)) return null;
        $rf = new ReflectionFunction($fn);
        if (! $rf->isInternal()) return null;
        $params = $rf->getParameters();
        foreach ($n->args as $i => $a) {
            if (! ($a instanceof Node\Arg) || $a->unpack || $a->name) continue;
            if (! isset($params[$i])) { if ($params && end($params)->isVariadic()) $p = end($params); else continue; } else $p = $params[$i];
            $t = $p->getType();
            if (! $t) continue;
            $names = $t instanceof ReflectionUnionType ? array_map(function ($x) { return $x->getName(); }, $t->getTypes()) : array($t->getName());
            if (array_intersect($names, array('string', 'mixed', 'array', 'callable', 'object'))) continue;
            if (! array_intersect($names, array('int', 'float', 'bool'))) continue;
            $v = $a->value;
            if ($v instanceof Node\Scalar\String_ && ! is_numeric($v->value) && ! (in_array('bool', $names, true))) {
                echo "{$this->file}:{$n->getStartLine()}\t$fn() arg " . ($i + 1) . " (" . implode('|', $names) . ") = '" . substr($v->value, 0, 40) . "'\n";
            }
        }
        return null;
    }
}
$v = new V();
$tr = new PhpParser\NodeTraverser($v);
foreach (array_slice($argv, 1) as $f) {
    try { $ast = $parser->parse(file_get_contents($f)); } catch (Throwable $e) { continue; }
    $v->file = $f;
    $tr->traverse($ast);
}
