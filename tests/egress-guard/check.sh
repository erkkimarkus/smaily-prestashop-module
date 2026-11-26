#!/bin/sh
# Says whether the egress guard (install.sh) is active in the shop container: a page request must
# run with the proxy setting, a tunnel to a Smaily host must reach the mock, a tunnel to the engine
# host must reach the engine mock, a tunnel to the installation report's receiver must reach the
# subscription mock, and any other host must be refused. Prints "guard active" and exits 0, else
# says what is missing and exits 1.
# Run it from the repository root with the stack's Compose variables set; DC as in install.sh.
# With SMAILY_EGRESS_LIVE=1 it checks the allow-list (install.sh) instead: a page request runs with
# the proxy setting, the engine host is tunnelled (one GET of its front page, without a key) and
# any other host is refused; it prints "allow-list active".
dc="${DC:-docker compose}"
if [ "${SMAILY_EGRESS_LIVE:-}" = 1 ]; then
  exec $dc exec -T -u www-data prestashop sh -c '
probe=/var/www/html/smaily-egress-probe.php
printf "<?php echo getenv(\"https_proxy\");" > $probe
seen=$(curl -s --noproxy "*" http://127.0.0.1/smaily-egress-probe.php)
rm -f $probe
[ "$seen" = "http://127.0.0.1:3128" ] || { echo "allow-list NOT active: a page request runs without https_proxy" >&2; exit 1; }
engine=$(curl -s -o /dev/null -w "%{http_connect}" --proxy http://127.0.0.1:3128 https://intelligence.smaily.com/)
[ "$engine" = "200" ] || { echo "allow-list NOT active: the engine host was not tunnelled (proxy answered $engine)" >&2; exit 1; }
other=$(curl -s -o /dev/null -w "%{http_connect}" --proxy http://127.0.0.1:3128 https://example.com/)
[ "$other" = "403" ] || { echo "allow-list NOT active: another host was not refused (proxy answered $other)" >&2; exit 1; }
echo "allow-list active"
'
fi
$dc exec -T -u www-data prestashop sh -c '
probe=/var/www/html/smaily-egress-probe.php
printf "<?php echo getenv(\"https_proxy\");" > $probe
seen=$(curl -s --noproxy "*" http://127.0.0.1/smaily-egress-probe.php)
rm -f $probe
[ "$seen" = "http://127.0.0.1:3128" ] || { echo "guard NOT active: a page request runs without https_proxy" >&2; exit 1; }
mock=$(curl -s -o /dev/null -w "%{http_code}" --proxy http://127.0.0.1:3128 https://guard-check.sendsmaily.net/api/autoresponder.php)
[ "$mock" = "401" ] || { echo "guard NOT active: a Smaily host did not reach the mock (HTTP $mock)" >&2; exit 1; }
engine=$(curl -s -o /dev/null -w "%{http_code}" --proxy http://127.0.0.1:3128 https://intelligence.smaily.com/api/v1/ingest/ping)
[ "$engine" = "401" ] || { echo "guard NOT active: the engine host did not reach the engine mock (HTTP $engine)" >&2; exit 1; }
report=$(curl -s -o /dev/null -w "%{http_code}" --proxy http://127.0.0.1:3128 https://subscription.smaily.com/v1/reports)
[ "$report" = "405" ] || { echo "guard NOT active: the installation report'"'"'s host did not reach the subscription mock (HTTP $report)" >&2; exit 1; }
other=$(curl -s -o /dev/null -w "%{http_connect}" --proxy http://127.0.0.1:3128 https://example.com/)
[ "$other" = "403" ] || { echo "guard NOT active: another host was not refused (proxy answered $other)" >&2; exit 1; }
echo "guard active"
'
