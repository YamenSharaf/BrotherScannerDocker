#!/bin/bash
# $1 = scanner device
# $2 = friendly name

{
  #override environment, as brscan is screwing it up:
  export $(grep -v '^#' /opt/brother/scanner/env.txt | xargs)

  # Report progress to the web UI via a small state file that active.php reads.
  statefile="/tmp/scanner.state"
  set_state() { { echo "$1" >"$statefile"; chmod 666 "$statefile"; } 2>/dev/null || true; }
  set_state scanning_front

  # GUI_RESOLUTION is an optional per-scan override from the web UI (passed via
  # sudo env_keep); falls back to the RESOLUTION env default, then 300.
  resolution="${GUI_RESOLUTION:-${RESOLUTION:-300}}"
  # GUI_MODE is the optional per-scan colour mode (same mechanism); falls back to
  # the MODE env default, then the scanner's default.
  mode="${GUI_MODE:-${MODE:-24bit Color[Fast]}}"

  gm_opts=(-page A4+0+0)
  if [ "$USE_JPEG_COMPRESSION" = "true" ]; then
    gm_opts+=(-compress JPEG -quality 80)
  fi

  device="$1"
  date=$(date +%Y-%m-%d-%H-%M-%S)
  script_dir="/opt/brother/scanner/brscan-skey/script"
  tmp_dir="/tmp/$date"
  filename_base="${tmp_dir}/${date}-front-page"
  tmp_output_file="${filename_base}%04d.pnm"
  output_pdf_file="/scans/${date}.pdf"

  set -e # Exit on error

  mkdir -p "$tmp_dir"
  cd "$tmp_dir"
  filename_base="/tmp/${date}/${date}-front-page"
  output_file="${filename_base}%04d.pnm"
  echo "filename: $tmp_output_file"

  function scan_cmd() {
    # `brother4:net1;dev0` device name gets passed to scanimage, which it refuses as an invalid device name for some reason.
    # Let's use the default scanner for now
    # scanimage -l 0 -t 0 -x 215 -y 297 --device-name="$1" --resolution="$2" --batch="$3"
    scanimage -l 0 -t 0 -x 215 -y 297 --format=pnm --mode "$mode" --resolution="$2" --batch="$3"
  }

  if [ "$(which usleep 2>/dev/null)" != '' ]; then
    usleep 100000
  else
    sleep 0.1
  fi
  scan_cmd "$device" "$resolution" "$tmp_output_file"
  if [ ! -s "${filename_base}0001.pnm" ]; then
    if [ "$(which usleep 2>/dev/null)" != '' ]; then
      usleep 1000000
    else
      sleep 1
    fi
    scan_cmd "$device" "$resolution" "$tmp_output_file"
  fi

  # Duplex (default): wait ~120s for the rear-pages button before converting.
  # Single-sided (GUI_SIMPLEX=1): skip the wait and convert immediately.
  (
    if [ "${GUI_SIMPLEX:-}" != "1" ]; then
      set_state waiting
      if [ "$(which usleep 2>/dev/null)" != '' ]; then
        usleep 120000000
      else
        sleep 120
      fi
    fi

    (
      echo "converting to PDF for $date..."
      set_state processing
      # Privacy mode (GUI_SKIP_SAVE=1): build the PDF in /tmp and never write it
      # to /scans; deliver it, keeping a local copy ONLY if delivery fails so a
      # scan is never lost.
      if [ "${GUI_SKIP_SAVE:-}" = "1" ]; then
        build_pdf="${tmp_dir}/${date}.pdf"
      else
        build_pdf="$output_pdf_file"
      fi
      gm convert ${gm_opts[@]} "$filename_base"*.pnm "$build_pdf"
      php /var/www/html/lib/notify.php "${date}.pdf (front) scanned" || true
      set_state delivering
      if [ "${GUI_SKIP_SAVE:-}" = "1" ]; then
        if php /var/www/html/lib/deliver.php "$build_pdf" "${GUI_RECIPIENTS:-}"; then
          echo "skip-save: delivered, not keeping a local copy for $date"
          set_state sent
          cd /scans || exit
          rm -rf "$tmp_dir"
          exit 0
        fi
        echo "skip-save: delivery failed/none for $date — keeping local copy as fallback"
        mv "$build_pdf" "$output_pdf_file"
      fi
      ${script_dir}/trigger_inotify.sh "${SSH_USER}" "${SSH_PASSWORD}" "${SSH_HOST}" "${SSH_PATH}" "${output_pdf_file}"
      if [ "${GUI_SKIP_SAVE:-}" != "1" ]; then
        php /var/www/html/lib/deliver.php "$output_pdf_file" "${GUI_RECIPIENTS:-}" || true
      fi
	  ${script_dir}/sendtoftps.sh \
            "${FTP_USER}" \
            "${FTP_PASSWORD}" \
            "${FTP_HOST}" \
            "${FTP_PATH}" \
            "${output_pdf_file}"
			
      echo "cleaning up for $date..."
      cd /scans || exit
      rm -rf "$tmp_dir"

      if [ -z "${OCR_SERVER}" ] || [ -z "${OCR_PORT}" ] || [ -z "${OCR_PATH}" ]; then
        echo "OCR environment variables not set, skipping OCR."
        set_state done
      else
        echo "starting OCR for $date..."
        (
          set_state ocr
          curl -F "userfile=@${output_pdf_file}" -H "Expect:" -o "/scans/${date}-ocr.pdf" "${OCR_SERVER}":"${OCR_PORT}"/"${OCR_PATH}"
          ${script_dir}/trigger_inotify.sh "${SSH_USER}" "${SSH_PASSWORD}" "${SSH_HOST}" "${SSH_PATH}" "${date}-ocr.pdf"
          php /var/www/html/lib/notify.php "${date}-ocr.pdf (front) OCR finished" || true
          ${script_dir}/sendtoftps.sh \
            "${FTP_USER}" \
            "${FTP_PASSWORD}" \
            "${FTP_HOST}" \
            "${FTP_PATH}" \
            "/scans/${date}-ocr.pdf"

          if [ "${REMOVE_ORIGINAL_AFTER_OCR}" == "true" ]; then
		    if [ -f "/scans/${date}-ocr.pdf" ]; then
              rm ${output_pdf_file}
			fi
          fi
          set_state done
        ) &
      fi
    ) &
  ) &
  echo $! >scan_pid
  echo "conversion process for $date is running in PID: $(cat scan_pid)"

} >>/var/log/scanner.log 2>&1
