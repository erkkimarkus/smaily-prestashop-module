#!/bin/sh
# Installs the egress guard in the shop container of a dev stack (docs/DEV_ENVIRONMENT.md, section
# 4a), so that no request the shop's PHP makes leaves the container:
#  - the Smaily mock (tests/Integration/Fixtures/mock-smaily/router.php) on 127.0.0.1:8099, behind
#    a TLS front for *.sendsmaily.net on 127.0.0.1:8443 (nginx, with a CA this script makes and
#    trusts in the container only);
#  - a CONNECT proxy on 127.0.0.1:3128 (proxy.php) that tunnels any *.sendsmaily.net:443 to that
#    front and refuses every other destination;
#  - PHP-FPM's https_proxy and http_proxy pointing at the proxy.
#
# Run it from the repository root, with the stack's Compose variables set, BEFORE the first admin
# request. MOCK_USER and MOCK_PASSWORD are the only credentials the mock accepts: invented values.
# DC names the Compose command when it is not `docker compose`.
set -e
: "${MOCK_USER:?set MOCK_USER to an invented API user}"
: "${MOCK_PASSWORD:?set MOCK_PASSWORD to an invented API password}"
here=$(dirname "$0")
dc="${DC:-docker compose}"
$dc cp "$here/proxy.php" prestashop:/tmp/smaily-egress-proxy.php
$dc exec -T -u root prestashop sh -c "mkdir -p /tmp/smaily-mock"
$dc cp "$here/nginx.conf" prestashop:/tmp/smaily-mock/nginx.conf
$dc exec -T -u root prestashop sh -e -c '
cd /tmp/smaily-mock
if [ ! -f ca.pem ]; then
  openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.pem -days 30 -subj "/CN=Smaily egress guard test CA" -addext "keyUsage=critical,keyCertSign,cRLSign" 2>/dev/null
  openssl req -newkey rsa:2048 -nodes -keyout leaf.key -out leaf.csr -subj "/CN=*.sendsmaily.net" 2>/dev/null
  printf "subjectAltName=DNS:*.sendsmaily.net\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\n" > leaf.ext
  openssl x509 -req -in leaf.csr -CA ca.pem -CAkey ca.key -CAcreateserial -out leaf.pem -days 30 -extfile leaf.ext 2>/dev/null
  cp ca.pem /usr/local/share/ca-certificates/smaily-egress-guard-ca.crt
  update-ca-certificates >/dev/null 2>&1 || true
fi
# Some images do not rebuild the bundle: the CA goes into it directly.
grep -q "$(sed -n 2p ca.pem)" /etc/ssl/certs/ca-certificates.crt || cat ca.pem >> /etc/ssl/certs/ca-certificates.crt
chown -R www-data /tmp/smaily-mock
# The flashlight php-fpm.conf includes no php-fpm.d/: its last section is the pool [www], so the
# settings are appended to the file itself. A file in php-fpm.d/ would never be read.
grep -q "smaily-egress" /usr/local/etc/php-fpm.conf || printf "\n[www]\n; smaily-egress\nenv[https_proxy] = http://127.0.0.1:3128\nenv[http_proxy] = http://127.0.0.1:3128\nenv[no_proxy] = localhost,127.0.0.1\n" >> /usr/local/etc/php-fpm.conf
'
$dc exec -T -u www-data -e MOCK_USER="$MOCK_USER" -e MOCK_PASSWORD="$MOCK_PASSWORD" prestashop sh -c '
  php -r "file_put_contents(\"/tmp/smaily-mock/state.json\", json_encode([\"credentials\" => [\"username\" => getenv(\"MOCK_USER\"), \"password\" => getenv(\"MOCK_PASSWORD\")], \"workflows\" => [[\"id\" => 11, \"title\" => \"Welcome\", \"trigger_type\" => \"form_submitted\", \"is_enabled\" => true, \"name\" => \"Welcome\", \"status\" => \"active\"]]]));"
  [ -f /tmp/smaily-mock/nginx.pid ] && kill -0 $(cat /tmp/smaily-mock/nginx.pid) 2>/dev/null || nginx -c /tmp/smaily-mock/nginx.conf
  kill -USR2 $(pgrep -f "[p]hp-fpm: master")
'
# Long-running helpers: detached, so they outlive this exec.
$dc exec -T -u www-data prestashop sh -c "pgrep -f '[p]hp -S 127.0.0.1:8099'" >/dev/null \
  || $dc exec -d -u www-data -e SMAILY_MOCK_STATE=/tmp/smaily-mock/state.json prestashop php -S 127.0.0.1:8099 /smaily/tests/Integration/Fixtures/mock-smaily/router.php
$dc exec -T -u www-data prestashop sh -c "pgrep -f '[s]maily-egress-proxy'" >/dev/null \
  || $dc exec -d -u www-data prestashop php /tmp/smaily-egress-proxy.php
sleep 2
"$here/check.sh"
