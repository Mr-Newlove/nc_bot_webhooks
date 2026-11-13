<?php
// Standalone unit test for TalkService::mapSlackContent +
// TalkService::convertSlackMrkdwn — the pure Slack payload → Talk message
// mapping (Block Kit blocks, legacy attachments, mrkdwn conversion).
//
// No Nextcloud container needed: both functions are public static and take
// only scalars/arrays, so loading TalkService.php does not resolve the
// OCP/OCA classes it references in property types and method signatures
// (PHP checks those lazily, only when a property is assigned or a method is
// called — neither happens here).
//
// Run:  php test/test_map_slack.php
// Exits non-zero if any check fails.

use OCA\Ncbotwebhooks\Service\TalkService;

$base = dirname(__DIR__);
require $base . '/lib/Service/TalkService.php';

$failures = 0;
function check(string $name, bool $cond): void {
    global $failures;
    echo '  ' . ($cond ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$cond) {
        $failures++;
    }
}

// --- convertSlackMrkdwn -------------------------------------------------

check('empty string → empty', TalkService::convertSlackMrkdwn('') === '');

// TrueNAS html-escape round trip (html.escape(quote=False) reverses exactly)
check('unescape &amp; / &lt; / &gt;', TalkService::convertSlackMrkdwn('Pool &amp; volume &lt;da-0&gt;') === 'Pool & volume <da-0>');
check('unescape numeric entity &#39;', TalkService::convertSlackMrkdwn("Fish &#39;s disk") === "Fish 's disk");

// Link unwrapping
check('labeled link <url|label>', TalkService::convertSlackMrkdwn('See <https://example.com|Example>') === 'See Example (https://example.com)');
check('bare link <url>', TalkService::convertSlackMrkdwn('<https://example.com/a?x=1&y=2>') === 'https://example.com/a?x=1&y=2');

// Mention dropping
check('drop <@U..> mention', TalkService::convertSlackMrkdwn('Hello <@U012AB3CD> world') === 'Hello world');
check('drop <@W..> mention at end', TalkService::convertSlackMrkdwn('ping <@W0123456>') === 'ping');

// Passthrough: Slack *bold* / _italic_ are NOT converted (Talk markdown is
// limited; partial conversion would mangle more than it helps)
check('passthrough *bold* _italic_', TalkService::convertSlackMrkdwn('*bold* and _italic_') === '*bold* and _italic_');
check('plain text untouched', TalkService::convertSlackMrkdwn("line one\nline two") === "line one\nline two");

// --- mapSlackContent ----------------------------------------------------

// TrueNAS primary use case: single {"text": "..."} payload
$r = TalkService::mapSlackContent(['text' => "TrueNAS @ host\n\nThis is a test alert"]);
check('TrueNAS text-only payload', $r === ['message' => "TrueNAS @ host\n\nThis is a test alert", 'imageUrls' => []]);

// TrueNAS text with entity-escaped content
$r = TalkService::mapSlackContent(['text' => 'Pool &amp; disk failure']);
check('TrueNAS text entities unescaped', $r['message'] === 'Pool & disk failure');

// Block Kit: section text + section fields
$r = TalkService::mapSlackContent(['blocks' => [
    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'Review summary']],
    ['type' => 'section', 'fields' => [
        ['type' => 'mrkdwn', 'text' => "*Rating*\n1.0"],
        ['type' => 'mrkdwn', 'text' => "*Guest*\nDanny"],
    ]],
]]);
check('blocks section text + fields', $r === [
    'message' => "Review summary\n\n*Rating*\n1.0\n*Guest*\nDanny",
    'imageUrls' => [],
]);

// Block Kit: header + divider + image block
$r = TalkService::mapSlackContent(['blocks' => [
    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => 'Alert']],
    ['type' => 'divider'],
    ['type' => 'image', 'image_url' => 'https://img.example/x.png', 'alt_text' => 'x'],
]]);
check('header + divider + image block', $r === [
    'message' => "Alert\n\n---",
    'imageUrls' => ['https://img.example/x.png'],
]);

// Section image accessory
$r = TalkService::mapSlackContent(['blocks' => [
    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'hi'], 'accessory' => [
        'type' => 'image', 'image_url' => 'https://a.example/b.png', 'alt_text' => 'b',
    ]],
]]);
check('section image accessory', $r === ['message' => 'hi', 'imageUrls' => ['https://a.example/b.png']]);

// Legacy attachment: pretext, title, text, fields, image_url
$r = TalkService::mapSlackContent(['attachments' => [
    ['pretext' => 'pre', 'title' => 'Title &amp; More', 'text' => 'Body text',
     'fields' => [
         ['title' => 'F1', 'value' => 'V1'],
         ['title' => 'F2', 'value' => 'V2'],
     ],
     'image_url' => 'https://img.example/att.png'],
]]);
check('legacy attachment full', $r === [
    'message' => "pre\n**Title & More**\nBody text\nF1: V1\nF2: V2",
    'imageUrls' => ['https://img.example/att.png'],
]);

// Legacy attachment: thumb_url used when image_url absent
$r = TalkService::mapSlackContent(['attachments' => [
    ['title' => 'T', 'thumb_url' => 'https://img.example/t.png'],
]]);
check('attachment thumb_url fallback', $r['imageUrls'] === ['https://img.example/t.png']);

// Slack display semantics: blocks with visible text win; top-level text is
// the notification fallback and is dropped
$r = TalkService::mapSlackContent([
    'text' => 'fallback only',
    'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => 'real content']]],
]);
check('text dropped when blocks render text', $r['message'] === 'real content');

// Image-only blocks: no visible text from blocks, so top-level text becomes
// the body and the image is kept
$r = TalkService::mapSlackContent([
    'text' => 'caption',
    'blocks' => [['type' => 'image', 'image_url' => 'https://img.example/c.png']],
]);
check('image-only blocks keep text', $r === [
    'message' => 'caption',
    'imageUrls' => ['https://img.example/c.png'],
]);

// username / icon_emoji / icon_url / thread_ts are ignored: none of them is
// content, and icon_url is not fetched (Talk has no per-message avatar)
$r = TalkService::mapSlackContent([
    'username' => 'Bot', 'icon_emoji' => ':robot:',
    'icon_url' => 'https://img.example/icon.png', 'thread_ts' => '123.456',
]);
check('identity fields ignored', $r === ['message' => '', 'imageUrls' => []]);

// Unknown block types are skipped; text still used as body
$r = TalkService::mapSlackContent([
    'text' => 'still here',
    'blocks' => [['type' => 'actions', 'elements' => [['type' => 'button', 'url' => 'https://x']]]],
]);
check('unknown block types skipped', $r['message'] === 'still here');

// Empty payload
check('empty payload', TalkService::mapSlackContent([]) === ['message' => '', 'imageUrls' => []]);

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s) failed\n";
    exit(1);
}
echo "OK: all checks passed\n";
exit(0);
