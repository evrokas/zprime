<?php
/* The Update button: the git runner on real (temporary) repositories, then the
 * HTTP endpoint with its gates (feature switch, sign-in, CSRF, owner only). */

/** The git runner itself, no web server involved. */
function zprime_functional_git_pull(TestRunner $runner, GitSandbox $git): void {
    $runner->add('pull when nothing is new reports success and the same commit', function () use ($git) {
        $r = zprime_git_pull($git->server);
        assert_true($r['ok'], json_encode($r));
        assert_equal($r['before'], $r['after']);
        assert_equal([], $r['changed']);
        assert_contains('Already up to date', $r['output']);
    });

    $runner->add('pull brings in a new commit and lists the changed files', function () use ($git) {
        $new = $git->pushFile('web/new_feature.txt', "hello\n", 'add feature');
        $before = $git->serverHead();
        $r = zprime_git_pull($git->server);
        assert_true($r['ok'], json_encode($r));
        assert_equal($before, $r['before']);
        assert_equal($new, $r['after']);
        assert_equal($new, $git->serverHead(), 'the checkout was not updated');
        assert_equal("hello\n", $git->serverFile('web/new_feature.txt'));
        assert_equal(['web/new_feature.txt'], $r['changed']);
        assert_equal([], $r['hints']);
    });

    $runner->add('a changed table definition tells the owner to run update.sh', function () use ($git) {
        $git->pushFile('web/classes/yaml/dash_links.yaml', "table:\n  name: dash_links\n", 'schema change');
        $git->pushFile('config/site.info.yaml.in', "x: 1\n", 'new setting');
        $r = zprime_git_pull($git->server);
        assert_true($r['ok']);
        $hints = implode(' | ', $r['hints']);
        assert_contains('fw/bin/update.sh', $hints);
        assert_contains('site.info.yaml', $hints);
    });

    $runner->add('a checkout that has diverged is refused and left as it was', function () use ($git) {
        $git->pushFile('upstream.txt', "from origin\n", 'upstream');
        // a local commit on the "server" that origin does not have
        file_put_contents($git->server . '/local.txt', "local work\n");
        $git->git($git->server, ['add', '-A']);
        $git->git($git->server, ['commit', '-q', '-m', 'local only']);
        $head = $git->serverHead();

        $r = zprime_git_pull($git->server);
        assert_false($r['ok'], 'a diverged pull was accepted');
        assert_not_null($r['error']);
        assert_equal($head, $git->serverHead(), 'the local commit was rewritten');
        assert_equal("local work\n", $git->serverFile('local.txt'));
        assert_equal(null, $git->serverFile('upstream.txt'), 'the upstream file was merged in');
        assert_not_equal('', $r['output'], 'git said nothing about why it failed');

        // put the sandbox back in sync for the later tests
        $git->git($git->server, ['reset', '-q', '--hard', 'origin/main']);
    });

    $runner->add('uncommitted local changes that would be overwritten are not lost', function () use ($git) {
        $git->pushFile('README', "second\n", 'readme');
        file_put_contents($git->server . '/README', "my local edit\n");
        $r = zprime_git_pull($git->server);
        assert_false($r['ok']);
        assert_equal("my local edit\n", $git->serverFile('README'), 'a local edit was overwritten');
        $git->git($git->server, ['checkout', '-q', '--', 'README']);
        assert_true(zprime_git_pull($git->server)['ok'], 'a clean pull did not work afterwards');
    });

    $runner->add('an unreachable remote fails fast instead of hanging or prompting', function () use ($git) {
        $git->git($git->server, ['remote', 'set-url', 'origin', '/nonexistent/path/repo.git']);
        $t = microtime(true);
        $r = zprime_git_pull($git->server, 20);
        assert_false($r['ok']);
        assert_true(microtime(true) - $t < 15, 'the pull took too long');
        $git->git($git->server, ['remote', 'set-url', 'origin', $git->origin]);
    });

    $runner->add('a folder that is not a git checkout is reported clearly', function () {
        $dir = sys_get_temp_dir() . '/zprime_not_git_' . getmypid();
        @mkdir($dir);
        $r = zprime_git_pull($dir);
        @rmdir($dir);
        assert_false($r['ok']);
        assert_contains('not a git checkout', $r['error']);
        $r = zprime_git_pull('/nonexistent/dir/for/zprime');
        assert_false($r['ok']);
    });

    $runner->add('two updates at once: the second is turned away', function () use ($git) {
        $lock = fopen(sys_get_temp_dir() . '/zprime-git-pull-' . md5($git->server) . '.lock', 'c');
        flock($lock, LOCK_EX);
        $r = zprime_git_pull($git->server);
        flock($lock, LOCK_UN);
        fclose($lock);
        assert_false($r['ok']);
        assert_contains('already running', $r['error']);
    });
}

/** The button and endpoint over HTTP. $on has the feature switched on and pointed at the sandbox; $off does not. */
function zprime_functional_update_http(TestRunner $runner, string $onUrl, string $offUrl, GitSandbox $git): void {
    $owner = new TestHttpClient($onUrl);
    $user = new TestHttpClient($onUrl);
    TestFixtures::loginAs($owner, TestFixtures::USER_OWNER, TestFixtures::PASS_OWNER);
    TestFixtures::loginAs($user, TestFixtures::USER_A, TestFixtures::PASS_A);
    $token = function (TestHttpClient $c): string { return TestFixtures::csrfFrom($c->get('/')['body']); };
    $post = function (TestHttpClient $c, bool $withToken = true) use ($token): array {
        $res = $c->postWithHeaders('/system/update', [], $withToken ? ['X-CSRF-Token' => $token($c)] : []);
        $res['json'] = json_decode($res['body'], true);
        return $res;
    };

    $runner->add('the owner sees the Update button and its dialog', function () use ($owner) {
        $html = $owner->get('/')['body'];
        assert_contains('data-git-pull', $html);
        assert_contains('id="update-dialog"', $html);
        assert_contains('data-update-url="/system/update"', $html);
    });

    $runner->add('an ordinary account does not see the button', function () use ($user) {
        $html = $user->get('/')['body'];
        assert_not_contains('data-git-pull', $html);
        assert_not_contains('update-dialog', $html);
    });

    $runner->add('an ordinary account cannot run an update', function () use ($post, $user, $git) {
        $before = $git->serverHead();
        $git->pushFile('blocked.txt', "x\n", 'should not arrive');
        $res = $post($user);
        assert_equal(403, $res['status']);
        assert_false($res['json']['ok']);
        assert_equal($before, $git->serverHead(), 'a non-owner updated the checkout');
        assert_equal(null, $git->serverFile('blocked.txt'));
    });

    $runner->add('an update without the CSRF token is refused', function () use ($post, $owner, $git) {
        $before = $git->serverHead();
        $res = $post($owner, false);
        assert_equal(403, $res['status']);
        assert_equal($before, $git->serverHead());
    });

    $runner->add('signed out, the update route is refused', function () use ($onUrl) {
        $res = (new TestHttpClient($onUrl))->post('/system/update', []);
        assert_contains('/login', (string)$res['location']);
    });

    $runner->add('the owner can update, and gets the changed files and a hint', function () use ($post, $owner, $git) {
        $git->pushFile('web/classes/yaml/another.yaml', "table:\n  name: another\n", 'new table');
        $res = $post($owner);
        assert_equal(200, $res['status']);
        assert_true($res['json']['ok'], json_encode($res['json']));
        assert_true($res['json']['updated']);
        assert_contains('web/classes/yaml/another.yaml', implode(',', $res['json']['changed']));
        assert_contains('fw/bin/update.sh', implode(' ', $res['json']['hints']));
        assert_equal($git->git($git->dev, ['rev-parse', 'HEAD']), $git->serverHead(), 'the checkout did not reach the latest commit');
    });

    $runner->add('updating again says it is already up to date', function () use ($post, $owner) {
        $res = $post($owner);
        assert_true($res['json']['ok']);
        assert_false($res['json']['updated']);
    });

    $runner->add('a failed pull is reported as a failure with git\'s own message', function () use ($post, $owner, $git) {
        $git->git($git->server, ['remote', 'set-url', 'origin', '/nonexistent/path/repo.git']);
        $res = $post($owner);
        $git->git($git->server, ['remote', 'set-url', 'origin', $git->origin]);
        assert_equal(200, $res['status']);
        assert_false($res['json']['ok']);
        assert_not_equal('', (string)$res['json']['error']);
        assert_not_equal('', (string)$res['json']['output']);
    });

    // --- the same install with the feature left at its default (off) ---
    $ownerOff = new TestHttpClient($offUrl);
    TestFixtures::loginAs($ownerOff, TestFixtures::USER_OWNER, TestFixtures::PASS_OWNER);

    $runner->add('with the feature off (the default), not even the owner sees a button', function () use ($ownerOff) {
        assert_not_contains('data-git-pull', $ownerOff->get('/')['body']);
    });

    $runner->add('with the feature off, the route answers 404 and changes nothing', function () use ($ownerOff, $git, $token) {
        $git->pushFile('off.txt', "x\n", 'while off');
        $before = $git->serverHead();
        $res = $ownerOff->postWithHeaders('/system/update', [], ['X-CSRF-Token' => TestFixtures::csrfFrom($ownerOff->get('/')['body'])]);
        assert_equal(404, $res['status']);
        assert_equal($before, $git->serverHead());
    });
}
