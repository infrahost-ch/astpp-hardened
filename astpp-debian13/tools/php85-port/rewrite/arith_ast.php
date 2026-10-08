<?php
/*
 * ASTPP PHP 8.5 port - arithmetic with PHP 7 semantics.
 *
 * PHP 8 throws for arithmetic that PHP 7 only warned about:
 *   '' + 1, 'abc' * 2, -''   TypeError "Unsupported operand types"   (PHP 7: string -> 0)
 *   $x / 0                   DivisionByZeroError                    (PHP 7: INF / -INF / NAN)
 *
 * AST based rewrite (nikic/php-parser, Debian package php-parser), formatting preserved:
 *   operand of + - * % ** (binary/unary) that is not provably numeric -> astpp_n(operand)
 *   a / b  with b not a non-zero number literal                         -> astpp_div(a, b)
 *   $v OP= e                                                            -> $v = astpp_n($v) OP astpp_n(e)
 * astpp_n()/astpp_div() are in application/php7compat.php (verified against PHP 7.4).
 *
 * Every rewritten file is verified: it is parsed again, the helpers are removed and the
 * resulting AST must be identical to the original AST (compound assignments normalised
 * on both sides). Files failing verification are left untouched.
 *
 *   php8.5 arith_ast.php [--dry-run] [--verbose] [--diff] files...
 */
require '/usr/share/php/PhpParser/autoload.php';

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Scalar;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

$dry = false; $verbose = false; $showdiff = false; $files = array();
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--dry-run') $dry = true;
    elseif ($a === '--verbose') $verbose = true;
    elseif ($a === '--diff') $showdiff = true;
    else $files[] = $a;
}

const NUMFUNCS = array('count', 'sizeof', 'strlen', 'mb_strlen', 'time', 'mktime', 'gmmktime', 'strtotime', 'intval', 'floatval',
    'doubleval', 'round', 'ceil', 'floor', 'abs', 'rand', 'mt_rand', 'random_int', 'array_sum', 'array_product', 'strpos', 'stripos',
    'strrpos', 'substr_count', 'ord', 'pow', 'sqrt', 'log', 'log10', 'exp', 'fmod', 'intdiv', 'filesize', 'memory_get_usage', 'pi',
    'hexdec', 'octdec', 'bindec', 'crc32', 'ip2long', 'deg2rad', 'rad2deg', 'sin', 'cos', 'tan', 'atan', 'atan2', 'is_numeric',
    'cal_days_in_month', 'getrandmax', 'mt_getrandmax', 'version_compare', 'strcmp', 'strcasecmp', 'strncmp', 'strnatcmp',
    'preg_match', 'preg_match_all', 'in_array', 'array_key_exists', 'checkdate', 'is_null', 'is_array', 'is_string', 'is_int',
    'is_float', 'is_bool', 'is_object', 'file_exists', 'is_file', 'is_dir', 'func_num_args', 'mb_substr_count', 'fdiv',
    'astpp_n', 'astpp_div', 'astpp_count', 'astpp_round', 'astpp_ceil', 'astpp_floor', 'astpp_abs', 'astpp_in_array',
    'astpp_array_key_exists', 'astpp_eq0', 'astpp_lt', 'astpp_le', 'astpp_gt', 'astpp_ge', 'astpp_loose_eq');

// value can never be a non-numeric string -> operator behaves the same on PHP 7 and 8
function is_safe(Expr $e): bool
{
    if ($e instanceof Scalar\Int_ || $e instanceof Scalar\Float_ || $e instanceof Scalar\MagicConst\Line) return true;
    if ($e instanceof Scalar\String_) return is_numeric($e->value);
    if ($e instanceof Expr\Cast\Int_ || $e instanceof Expr\Cast\Double || $e instanceof Expr\Cast\Bool_) return true;
    if ($e instanceof Expr\ConstFetch || $e instanceof Expr\ClassConstFetch) return true;
    if ($e instanceof Expr\Array_) return true; // arrays: same behaviour (union / error) on both versions
    if ($e instanceof BinaryOp && ! ($e instanceof BinaryOp\Concat) && ! ($e instanceof BinaryOp\Coalesce)
        && ! ($e instanceof BinaryOp\BitwiseAnd) && ! ($e instanceof BinaryOp\BitwiseOr) && ! ($e instanceof BinaryOp\BitwiseXor)) return true;
    if ($e instanceof Expr\UnaryMinus || $e instanceof Expr\UnaryPlus || $e instanceof Expr\BooleanNot || $e instanceof Expr\Isset_
        || $e instanceof Expr\Empty_ || $e instanceof Expr\Instanceof_) return true;
    if ($e instanceof Expr\FuncCall && $e->name instanceof Node\Name && in_array($e->name->toLowerString(), NUMFUNCS, true)) return true;
    if ($e instanceof Expr\Assign) return is_safe($e->expr);
    return false;
}
function nonzero_literal(Expr $e): bool
{
    if ($e instanceof Expr\UnaryMinus || $e instanceof Expr\UnaryPlus) $e = $e->expr;
    if ($e instanceof Scalar\Int_ || $e instanceof Scalar\Float_) return $e->value != 0;
    if ($e instanceof Scalar\String_) return is_numeric($e->value) && $e->value + 0 != 0;
    return false;
}
function call(string $fn, Expr ...$args): Expr\FuncCall
{
    return new Expr\FuncCall(new Node\Name($fn), array_map(function ($a) { return new Node\Arg($a); }, $args));
}
function wrap(Expr $e): Expr
{
    return is_safe($e) ? $e : call('astpp_n', $e);
}
// no side effects when evaluated twice
function is_pure(Node $n): bool
{
    $ok = true;
    $t = new NodeTraverser(new class ($ok) extends NodeVisitorAbstract {
        public $ok;
        public function __construct(&$ok) { $this->ok = &$ok; }
        public function enterNode(Node $n)
        {
            if ($n instanceof Expr\FuncCall || $n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall || $n instanceof Expr\New_
                || $n instanceof Expr\PreInc || $n instanceof Expr\PostInc || $n instanceof Expr\PreDec || $n instanceof Expr\PostDec
                || $n instanceof Expr\Assign || $n instanceof Expr\AssignOp || $n instanceof Expr\AssignRef || $n instanceof Expr\Include_
                || $n instanceof Expr\Eval_ || $n instanceof Expr\ErrorSuppress || ($n instanceof Expr\ArrayDimFetch && $n->dim === null)
                || ($n instanceof Expr\Variable && ! is_string($n->name)) || $n instanceof Expr\NullsafePropertyFetch) {
                $this->ok = false;
            }
            return null;
        }
    });
    $t->traverse(array($n));
    return $ok;
}

const ARITH = array(BinaryOp\Plus::class, BinaryOp\Minus::class, BinaryOp\Mul::class, BinaryOp\Mod::class, BinaryOp\Pow::class);
const ASSIGNOPS = array(AssignOp\Plus::class => BinaryOp\Plus::class, AssignOp\Minus::class => BinaryOp\Minus::class,
    AssignOp\Mul::class => BinaryOp\Mul::class, AssignOp\Div::class => BinaryOp\Div::class, AssignOp\Mod::class => BinaryOp\Mod::class,
    AssignOp\Pow::class => BinaryOp\Pow::class);

class Rewriter extends NodeVisitorAbstract
{
    public $changes = 0;
    public $manual = array();
    public function enterNode(Node $n)
    {
        // constant expressions: no function calls allowed
        if ($n instanceof Node\Stmt\ClassConst || $n instanceof Node\Stmt\Const_ || $n instanceof Node\Param
            || $n instanceof Node\Stmt\Property || $n instanceof Node\Stmt\Static_ || $n instanceof Node\Stmt\Declare_
            || $n instanceof Node\Attribute || $n instanceof Node\Stmt\EnumCase) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }
        return null;
    }
    public function leaveNode(Node $n)
    {
        foreach (ARITH as $cls) {
            if ($n instanceof $cls) {
                $l = wrap($n->left); $r = wrap($n->right);
                if ($l !== $n->left || $r !== $n->right) {
                    $this->changes++;
                    $n->left = $l; $n->right = $r;
                }
                return null;
            }
        }
        if ($n instanceof BinaryOp\Div) {
            if (nonzero_literal($n->right)) {
                $l = wrap($n->left);
                if ($l !== $n->left) { $this->changes++; $n->left = $l; }
                return null;
            }
            $this->changes++;
            return call('astpp_div', $n->left, $n->right);
        }
        if ($n instanceof Expr\UnaryMinus || $n instanceof Expr\UnaryPlus) {
            $e = wrap($n->expr);
            if ($e !== $n->expr) { $this->changes++; $n->expr = $e; }
            return null;
        }
        if ($n instanceof BinaryOp\BitwiseAnd || $n instanceof BinaryOp\BitwiseOr || $n instanceof BinaryOp\BitwiseXor
            || $n instanceof BinaryOp\ShiftLeft || $n instanceof BinaryOp\ShiftRight) {
            if (! is_safe($n->left) || ! is_safe($n->right)) $this->manual[] = $n->getStartLine() . ' bitwise';
            return null;
        }
        foreach (ASSIGNOPS as $acls => $bcls) {
            if ($n instanceof $acls) {
                $isDiv = $bcls === BinaryOp\Div::class;
                if (is_safe($n->var) && is_safe($n->expr) && (! $isDiv || nonzero_literal($n->expr))) return null;
                if ($n->expr instanceof Expr\Array_) return null; // array union
                if (! is_pure($n->var)) { $this->manual[] = $n->getStartLine() . ' compound'; return null; }
                // deep copy for the read side; it must not carry the original position
                // attributes (the printer would try to reuse the original tokens)
                $read = (new NodeTraverser(new class extends NodeVisitorAbstract {
                    public function enterNode(Node $n) { $c = clone $n; $c->setAttributes(array()); return $c; }
                }))->traverse(array(clone $n->var))[0];
                // the right side moves into a binary operator: anything that is not atomic is
                // grouped with astpp_n() (the format preserving printer would not add the
                // parentheses, "$x -= $a + $b" must not become "$x - $a + $b")
                $atomic = $n->expr instanceof Scalar || $n->expr instanceof Expr\ConstFetch || $n->expr instanceof Expr\ClassConstFetch
                    || $n->expr instanceof Expr\Variable || $n->expr instanceof Expr\ArrayDimFetch || $n->expr instanceof Expr\PropertyFetch
                    || $n->expr instanceof Expr\StaticPropertyFetch || $n->expr instanceof Expr\FuncCall || $n->expr instanceof Expr\MethodCall
                    || $n->expr instanceof Expr\StaticCall || $n->expr instanceof Expr\Cast;
                $e = $atomic ? wrap($n->expr) : call('astpp_n', $n->expr);
                if ($isDiv) {
                    $rhs = nonzero_literal($n->expr) ? new BinaryOp\Div(wrap($read), $n->expr) : call('astpp_div', $read, $n->expr);
                } else {
                    $rhs = new $bcls(wrap($read), $e);
                }
                $this->changes++;
                return new Expr\Assign($n->var, $rhs);
            }
        }
        return null;
    }
}

// verification: remove the helpers again / normalise compound assignments
class Unwrap extends NodeVisitorAbstract
{
    public function leaveNode(Node $n)
    {
        if ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name) {
            $f = $n->name->toLowerString();
            if ($f === 'astpp_n' && count($n->args) === 1) return $n->args[0]->value;
            if ($f === 'astpp_div' && count($n->args) === 2) return new BinaryOp\Div($n->args[0]->value, $n->args[1]->value);
        }
        foreach (ASSIGNOPS as $acls => $bcls) {
            if ($n instanceof $acls) return new Expr\Assign($n->var, new $bcls(clone $n->var, $n->expr));
        }
        return null;
    }
}
function strip_attrs(array $nodes): string
{
    $d = new PhpParser\NodeDumper(array('dumpComments' => false, 'dumpPositions' => false));
    return $d->dump($nodes);
}

$parser = (new ParserFactory())->createForNewestSupportedVersion();
$printer = new PhpParser\PrettyPrinter\Standard();
$tot = array('files' => 0, 'changes' => 0, 'manual' => 0, 'failed' => 0, 'parse_error' => 0);
foreach ($files as $file) {
    if (strpos($file, 'php7compat') !== false) continue;
    $code = file_get_contents($file);
    try {
        $old = $parser->parse($code);
        $tokens = $parser->getTokens();
    } catch (PhpParser\Error $e) {
        fwrite(STDERR, "PARSE ERROR $file: " . $e->getMessage() . "\n"); $tot['parse_error']++; continue;
    }
    $new = (new NodeTraverser(new NodeVisitor\CloningVisitor()))->traverse($old);
    $rw = new Rewriter();
    $new = (new NodeTraverser($rw))->traverse($new);
    if ($rw->manual) {
        $tot['manual'] += count($rw->manual);
        echo "MANUAL $file: " . implode(', ', $rw->manual) . "\n";
    }
    if (! $rw->changes) continue;
    $out = $printer->printFormatPreserving($new, $old, $tokens);
    // verify
    try {
        $re = $parser->parse($out);
    } catch (PhpParser\Error $e) {
        echo "VERIFY FAILED (parse) $file: " . $e->getMessage() . "\n"; $tot['failed']++; continue;
    }
    $a = (new NodeTraverser(new Unwrap()))->traverse((new NodeTraverser(new NodeVisitor\CloningVisitor()))->traverse($old));
    $b = (new NodeTraverser(new Unwrap()))->traverse($re);
    if (strip_attrs($a) !== strip_attrs($b)) {
        echo "VERIFY FAILED (ast) $file\n"; $tot['failed']++;
        if ($verbose) {
            $fa = tempnam('/tmp', 'va'); $fb = tempnam('/tmp', 'vb');
            file_put_contents($fa, strip_attrs($a)); file_put_contents($fb, strip_attrs($b));
            echo shell_exec('diff ' . escapeshellarg($fa) . ' ' . escapeshellarg($fb) . ' | head -30');
            unlink($fa); unlink($fb);
        }
        continue;
    }
    $tot['files']++; $tot['changes'] += $rw->changes;
    if ($verbose) echo "$file: {$rw->changes}\n";
    if ($showdiff) {
        $fa = tempnam('/tmp', 'da'); file_put_contents($fa, $out);
        echo shell_exec('diff -u ' . escapeshellarg($file) . ' ' . escapeshellarg($fa));
        unlink($fa);
    }
    if (! $dry) file_put_contents($file, $out);
}
foreach ($tot as $k => $v) echo "$k: $v\n";
