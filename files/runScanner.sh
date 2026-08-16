#!/bin/bash
echo "setting up user & logfile:"

if [[ $NAME == *" "* ]]; then
  echo "Do not use spaces in NAME!"
  exit -1
fi

if [[ -z {$NAME} ]]; then
  $NAME="Scanner"
fi

# if running as root, create default user. If UID is set, use that
if [[ ${UID} == 0 ]]; then
  USERID=1000
else
  USERID=$UID
fi
if [[ -z ${GID} ]]; then
  GROUPID=1000
else
  GROUPID=$GID
fi

groupadd --gid "$GROUPID" NAS
adduser "$NAME" --uid $USERID --gid "$GROUPID" --disabled-password --force-badname --gecos ""
mkdir -p /scans
chmod 777 /scans
touch /var/log/scanner.log
chown "$NAME" /var/log/scanner.log
env >/opt/brother/scanner/env.txt
chmod -R 777 /opt/brother
echo "-----"

echo "setting up interface:"
subnet=$(echo "$IPADDRESS" | sed 's/\([0-9]*\.[0-9]*\.\)[0-9]*\.[0-9]*/\1/')
interface=$(ip addr show | grep -B10 "$subnet" | grep mtu | tail -1 | sed 's/[0-9]*: \(.*\): .*/\1/')
sed -i 's/^eth=.*//' /opt/brother/scanner/brscan-skey/brscan-skey.config
# if found an interface for scanner subnet. Will use this to contact scanner.
if [[ -z "$interface" ]]; then
  # if scanner subnet (roughly) not found in interfaces, assuming network_mode="host" is not set and using Docker default interface.
  interface="eth0"
fi
echo "eth=$interface" >>/opt/brother/scanner/brscan-skey/brscan-skey.config
echo "using interface: $interface"
echo "-----"

echo "setting up host IP:"
sed -i 's/^ip_address=.*//' /opt/brother/scanner/brscan-skey/brscan-skey.config
if [[ -z "$HOST_IPADDRESS" ]]; then
  echo "no host IP configured, using default discovery"
else
  echo "ip_address=$HOST_IPADDRESS" >>/opt/brother/scanner/brscan-skey/brscan-skey.config
fi
echo "-----"

echo "whole config:"
cat /opt/brother/scanner/brscan-skey/brscan-skey.config
echo "-----"

echo "starting scanner drivers..."
su - "$NAME" -c "/usr/bin/brsaneconfig4 -a name=$NAME model=$MODEL ip=$IPADDRESS"
su - "$NAME" -c "/usr/bin/brscan-skey"
echo "-----"

echo "setting up webserver:"
if [ "$WEBSERVER" == "true" ]; then
  echo "www-data ALL=($NAME) NOPASSWD:ALL" >>/etc/sudoers

  echo "starting webserver for API & GUI..."
  # settings.php reads its configuration from the $ENV array we write here.
  # We cannot rely on getenv() in the web layer: lighttpd's FastCGI only
  # forwards a small allowlist of environment variables to php-cgi
  # (bin-copy-environment), so the container's `-e` variables never reach PHP.
  # The boot script has the full environment, so we capture what the GUI needs.
  #
  # Notes:
  #  - config.php is executed by php (never served as text), so values are not
  #    exposed; we still deliberately omit secrets (passwords, tokens, SSH_*).
  #  - Values are emitted as safely-escaped PHP single-quoted strings, so a
  #    label containing spaces/quotes can no longer produce an unparseable file
  #    (the footgun of the previous generator).
  #  - $USERID (root -> 1000) fixes the previous $UID-vs-USERID mismatch, where
  #    the API could sudo to a user id that did not match the created user.
  php_squote() { local s=${1//\\/\\\\}; s=${s//\'/\\\'}; printf "'%s'" "$s"; }
  emit_kv() { echo "  $(php_squote "$1") => $(php_squote "$2"),"; }
  {
    echo "<?php"
    echo "\$UID = ${USERID};"
    echo "\$ENV = array("
    emit_kv MODEL "$MODEL"
    emit_kv NAME "$NAME"
    emit_kv RESOLUTION "$RESOLUTION"
    emit_kv OCR_SERVER "$OCR_SERVER"
    emit_kv OCR_PORT "$OCR_PORT"
    emit_kv OCR_PATH "$OCR_PATH"
    emit_kv FTP_HOST "$FTP_HOST"
    emit_kv FTP_USER "$FTP_USER"
    emit_kv TELEGRAM_CHATID "$TELEGRAM_CHATID"
    emit_kv REMOVE_BLANK_THRESHOLD "$REMOVE_BLANK_THRESHOLD"
    emit_kv USE_JPEG_COMPRESSION "$USE_JPEG_COMPRESSION"
    emit_kv ENABLE_GUI_SCANTOIMAGE "$ENABLE_GUI_SCANTOIMAGE"
    emit_kv ENABLE_GUI_SCANTOOCR "$ENABLE_GUI_SCANTOOCR"
    echo ");"
  } >/var/www/html/config.php
  chown www-data /var/www/html/config.php
  if [[ -z ${PORT} ]]; then
    PORT=80
  fi
  echo "running on port $PORT"
  sed -i "s/server.port\W*= 80/server.port = $PORT/" /etc/lighttpd/lighttpd.conf
  /usr/sbin/lighttpd -f /etc/lighttpd/lighttpd.conf
  echo "webserver started"
else
  echo "webserver not configured"
fi
echo "-----"

echo "capabilities:"
scanimage -A

echo "startup successful"
while true; do
  tail -f /var/log/scanner.log
done
exit 0
