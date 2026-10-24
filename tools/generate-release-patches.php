<?php
declare(strict_types=1);

// Maintainer tool: derive version-pinned upstream updates and the small guard delta.
$root = dirname(__DIR__);
$git = getenv('PHP_GIT') ?: throw new RuntimeException('Set PHP_GIT to a php-src Git checkout');
$pairs = ['8.2' => ['8.2.34', '8.2.35'], '8.3' => ['8.3.35', '8.3.36'],
    '8.4' => ['8.4.26', '8.4.27'], '8.5' => ['8.5.11', '8.5.12']];
function command(array $args, ?string $cwd = null, array $allowed = [0]): string {
    $p = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes, $cwd);
    if (!is_resource($p)) { throw new RuntimeException('Cannot start command'); }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $code = proc_close($p);
    if (!in_array($code, $allowed, true)) { throw new RuntimeException('Command failed: ' . implode(' ', $args)); }
    return $out;
}
function replace_once(string $text, string $from, string $to): string {
    if (substr_count($text, $from) !== 1) { throw new RuntimeException('Non-unique patch anchor: ' . $from); }
    return str_replace($from, $to, $text);
}
$scratch = $root . '/work/patch-generation';
if (!is_dir($scratch)) { mkdir($scratch, 0755, true); }
foreach ($pairs as $branch => [$affected, $fixed]) {
    $base = trim(command(['git', '-C', $git, 'rev-parse', "php-$affected^{commit}"]));
    $official = trim(command(['git', '-C', $git, 'rev-parse', "php-$fixed^{commit}"]));
    $dir = "$root/releases/$branch";
    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $upstream = command(['git', '-C', $git, 'diff', '--binary', '--no-ext-diff', $base, $official, '--']);
    file_put_contents("$dir/upstream-release.patch", $upstream);
    $files = [];
    foreach (['Zend/zend_alloc.h', 'Zend/zend_alloc.c', 'main/main.c'] as $file) {
        $before = command(['git', '-C', $git, 'show', "$official:$file"]);
        $after = $before;
        if ($file === 'Zend/zend_alloc.h') {
            $after = replace_once($after, "BEGIN_EXTERN_C()\n", <<<'C'
BEGIN_EXTERN_C()

/* Additional lifecycle guard; the official OOM/state fixes are prerequisites. */
#if defined(ZTS) || defined(ZEND_WIN32)
# error "The experimental lifecycle guard requires a POSIX NTS build"
#endif
ZEND_API ZEND_NORETURN void zend_oom_guard_retire(void);
ZEND_API void zend_oom_guard_check_request_state(void);

C);
        } elseif ($file === 'Zend/zend_alloc.c') {
            $needle = "zend_out_of_memory(void)\n{\n";
            $after = replace_once($after, $needle, $needle . "\tzend_oom_guard_retire();\n");
            $needle = 'static ZEND_COLD void zend_mm_delayed_verror(';
            $after = replace_once($after, $needle, <<<'C'
ZEND_API ZEND_NORETURN void zend_oom_guard_retire(void)
{
	/* No allocation, formatting, refcount changes, or application callbacks. */
	_exit(137);
}

C
                . $needle);
        } else {
            $old = <<<'C'
static void clear_last_error(void) {
	if (PG(last_error_message)) {
		zend_string_release(PG(last_error_message));
		PG(last_error_message) = NULL;
	}
	if (PG(last_error_file)) {
		zend_string_release(PG(last_error_file));
		PG(last_error_file) = NULL;
	}
}
C;
            $new = <<<'C'
static void clear_last_error(void) {
	/* Unpublish both owned references before either release can run. */
	zend_string *message = PG(last_error_message);
	zend_string *file = PG(last_error_file);
	PG(last_error_message) = NULL;
	PG(last_error_file) = NULL;
	if (message) {
		zend_string_release(message);
	}
	if (file) {
		zend_string_release(file);
	}
}

ZEND_API void zend_oom_guard_check_request_state(void)
{
	if (PG(last_error_message) != NULL || PG(last_error_file) != NULL
#if PHP_VERSION_ID >= 80500
			|| !Z_ISUNDEF(EG(last_fatal_error_backtrace))
#endif
	) {
		zend_oom_guard_retire();
	}
}
C;
            $after = replace_once($after, $old, $new);
            $after = replace_once($after, "\tclear_last_error();\n\tzend_try {\n\t\tshutdown_memory_manager",
                "\tclear_last_error();\n\tzend_oom_guard_check_request_state();\n\tzend_try {\n\t\tshutdown_memory_manager");
        }
        foreach (['a' => $before, 'b' => $after] as $side => $content) {
            $path = "$scratch/$branch/$side/$file";
            if (!is_dir(dirname($path))) { mkdir(dirname($path), 0755, true); }
            file_put_contents($path, $content);
        }
        $files[$file] = ['official_sha256' => hash('sha256', $before), 'candidate_sha256' => hash('sha256', $after)];
    }
    $patch = command(['git', 'diff', '--no-index', '--binary', '--', 'a', 'b'], "$scratch/$branch", [1]);
    $patch = str_replace(['a/a/', 'b/b/'], ['a/', 'b/'], $patch);
    preg_match_all('/^diff --git a\/(\S+) b\/\1$/m', $patch, $changed);
    $names = $changed[1]; $expected = array_keys($files); sort($names); sort($expected);
    if ($names !== $expected) { throw new RuntimeException('Unexpected files in guard generation directory'); }
    file_put_contents("$dir/lifecycle-guard.patch", $patch);
    foreach (['LICENSE' => 'PHP-LICENSE', 'Zend/LICENSE' => 'ZEND-LICENSE'] as $source => $name) {
        file_put_contents("$dir/$name", command(['git', '-C', $git, 'show', "$official:$source"]));
    }
    $manifest = ['schema' => 'php-release-guard/1', 'branch' => $branch, 'affected_version' => $affected,
        'official_version' => $fixed, 'affected_revision' => $base, 'official_revision' => $official,
        'official_tree' => trim(command(['git', '-C', $git, 'rev-parse', "$official^{tree}"])),
        'upstream_patch_sha256' => hash('sha256', $upstream), 'guard_patch_sha256' => hash('sha256', $patch),
        'guard_files' => $files, 'kind' => 'complete-official-release-update-plus-additive-guard',
        'upstream_security_commits' => array_values(array_filter(explode("\n", trim(command(['git', '-C', $git,
            'log', '--format=%H', "$base..$official", '--grep=Avoid state corruption via bailout'])))))];
    file_put_contents("$dir/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    echo "$branch: $affected -> $fixed + lifecycle guard\n";
}
