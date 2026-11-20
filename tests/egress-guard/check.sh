#!/bin/sh
# Says whether the egress guard (install.sh) is active in the shop container: a page request must
# run with the proxy setting, a tunnel to a Smaily host must reach the mock, and any other host
# must be refused. Prints "guard active" and exits 0, else says what is missing and exits 1.
# Run it from the repository root with the stack's Compose variables set; DC as in install.sh.
dc="${DC:-docker compose}"
$dc exec -T -u www-data prestashop sh -c '
probe=/var/www/html/smaily-egress-probe.php
printf "<?php echo getenv(\"https_proxy\");" > $probe
seen=$(curl -s --noproxy "*" http://127.0.0.1/smaily-egress-probe.php)
rm -f $probe
[ "$seen" = "http://127.0.0.1:3128" ] || { echo "guard NOT active: a page request runs without https_proxy" >&2; exit 1; }
mock=$(curl -s -o /dev/null -w "%{http_code}" --proxy http://127.0.0.1:3128 https://guard-check.sendsmaily.net/api/autoresponder.php)
[ "$mock" = "401" ] || { echo "guard NOT active: a Smaily host did not reach the mock (HTTP $mock)" >&2; exit 1; }
other=$(curl -s -o /dev/null -w "%{http_connect}" --proxy http://127.0.0.1:3128 https://example.com/)
[ "$other" = "403" ] || { echo "guard NOT active: another host was not refused (proxy answered $other)" >&2; exit 1; }
echo "guard active"
'
