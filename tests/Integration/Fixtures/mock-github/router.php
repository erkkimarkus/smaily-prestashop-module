<?php
/**
 * A mock of GitHub's "latest release" endpoint for `php -S`.
 *
 * State lives in the JSON file that GITHUB_MOCK_STATE names: the answer to give (status, body, and
 * a delay in seconds before it) and every request received, with all its headers. GithubMockServer
 * writes and reads it.
 *
 * - GET /repos/sendsmaily/smaily-prestashop-module/releases/latest  the answer set in the state.
 * - Anything else: HTTP 404.
 */
declare(strict_types=1);

$stateFile = (string) getenv('GITHUB_MOCK_STATE');
$state = is_file($stateFile) ? (array) json_decode((string) file_get_contents($stateFile), true) : [];

$state['requests'][] = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => getallheaders(),
    'body' => (string) file_get_contents('php://input'),
];
file_put_contents($stateFile, json_encode($state));

$answer = $state['answer'] ?? ['status' => 404, 'body' => '{"message":"Not Found"}', 'delay' => 0];
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || $_SERVER['REQUEST_URI'] !== '/repos/sendsmaily/smaily-prestashop-module/releases/latest') {
    $answer = ['status' => 404, 'body' => '{"message":"Not Found"}', 'delay' => 0];
}

if ($answer['delay'] > 0) {
    usleep((int) ($answer['delay'] * 1000000));
}
http_response_code((int) $answer['status']);
header('Content-Type: application/json; charset=utf-8');
echo $answer['body'];

return true;
