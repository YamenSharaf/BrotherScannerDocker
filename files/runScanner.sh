#!/bin/bash
echo "setting up user & logfile:"

if [[ $NAME == *" "* ]]; then
  echo "Do not use spaces in NAME!"
  exit -1
fi

if [[ -z "${NAME}" ]]; then
  NAME="Scanner"
fi

# Resolve the uid/gid that scans run as and are owned by.
# NOTE: bash's $UID is a readonly builtin equal to the CONTAINER's uid, so it
# does NOT reflect a `-e UID=...` value. Read the real environment via printenv.
# Prefer the unambiguous PUID/PGID; fall back to legacy UID/GID; default 1000.
REQ_UID="$(printenv PUID || printenv UID || echo 1000)"
REQ_GID="$(printenv PGID || printenv GID || echo 1000)"
[[ -z "$REQ_UID" ]] && REQ_UID=1000
[[ -z "$REQ_GID" ]] && REQ_GID=1000

# Ensure a group with the requested gid exists (fine if it already does, e.g. gid 0).
getent group "$REQ_GID" >/dev/null || groupadd --gid "$REQ_GID" "$NAME" 2>/dev/null || true

# Determine the user scans run as. If the requested uid is already taken (e.g.
# uid 0 = root), reuse that account instead of failing to create a duplicate —
# this is what previously broke NAME=root (adduser failed, so no user existed at
# the uid the web layer sudo'd to, and every scan silently failed).
if getent passwd "$REQ_UID" >/dev/null; then
  RUN_USER="$(getent passwd "$REQ_UID" | cut -d: -f1)"
  echo "uid $REQ_UID already exists; scans will run as existing user '$RUN_USER'"
else
  adduser "$NAME" --uid "$REQ_UID" --gid "$REQ_GID" --disabled-password --force-badname --gecos ""
  RUN_USER="$NAME"
fi
RUN_UID="$REQ_UID"
echo "scans run as user '$RUN_USER' (uid $RUN_UID, gid $REQ_GID)"

mkdir -p /scans
chmod 777 /scans
touch /var/log/scanner.log
chown "$RUN_USER" /var/log/scanner.log
# env.txt is re-sourced by the scan scripts; drop UID (readonly in bash) so they
# don't hit a harmless "UID: readonly variable" error when re-exporting it.
env | grep -v '^UID=' >/opt/brother/scanner/env.txt
chmod -R 777 /opt/brother

# Runtime config store for the admin dashboard + notifications. Seeded from env
# on first run (Telegram etc.); afterwards the dashboard owns it. Persisted only
# if /config is mounted as a volume. Must be writable by the web layer (www-data).
mkdir -p /config
php /var/www/html/lib/seed.php || true
chown -R www-data /config 2>/dev/null || true
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
su - "$RUN_USER" -c "/usr/bin/brsaneconfig4 -a name=$NAME model=$MODEL ip=$IPADDRESS"
su - "$RUN_USER" -c "/usr/bin/brscan-skey"
echo "-----"

echo "setting up webserver:"
if [ "$WEBSERVER" == "true" ]; then
  # Let the web layer pass a per-scan resolution to the scan scripts through
  # sudo (scan.php sets GUI_RESOLUTION via putenv; env_keep preserves it).
  echo "Defaults env_keep += \"GUI_RESOLUTION\"" >>/etc/sudoers
  echo "www-data ALL=($RUN_USER) NOPASSWD:ALL" >>/etc/sudoers

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
  # Detect the resolutions this scanner supports, for the GUI selector. Prefer an
  # explicit RESOLUTIONS override; else parse `scanimage -A` (the
  # "--resolution 100|150|...|9600dpi" line); else fall back to a sane list.
  RES_LIST="$(printenv RESOLUTIONS)"
  [[ -z "$RES_LIST" ]] && RES_LIST="$(scanimage -A 2>/dev/null | sed -nE 's/.*--resolution[[:space:]]+([0-9|]+)dpi.*/\1/p' | head -1 | tr '|' ',')"
  [[ -z "$RES_LIST" ]] && RES_LIST="100,200,300,400,600"
  echo "supported resolutions: $RES_LIST"

  php_squote() { local s=${1//\\/\\\\}; s=${s//\'/\\\'}; printf "'%s'" "$s"; }
  emit_kv() { echo "  $(php_squote "$1") => $(php_squote "$2"),"; }
  {
    echo "<?php"
    echo "\$UID = ${RUN_UID};"
    echo "\$ENV = array("
    emit_kv MODEL "$MODEL"
    emit_kv NAME "$NAME"
    emit_kv RESOLUTION "$RESOLUTION"
    emit_kv RESOLUTIONS "$RES_LIST"
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
    # Admin dashboard password (config.php is executed by php, never served as
    # text, so this is not exposed over HTTP). Prefer ADMIN_PASSWORD_HASH.
    emit_kv ADMIN_PASSWORD "$ADMIN_PASSWORD"
    emit_kv ADMIN_PASSWORD_HASH "$ADMIN_PASSWORD_HASH"
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
